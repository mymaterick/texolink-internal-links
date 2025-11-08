<?php
/**
 * TexoLink API Client - ENHANCED SAAS VERSION
 * 
 * Now syncs site configuration to Railway backend
 * Railway does ALL filtering and intelligence
 */

if (!defined('ABSPATH')) {
    exit;
}

class TexoLink_API_Client {
    
    /**
     * API base URL
     */
    private $api_url;
    
    /**
     * API key
     */
    private $api_key;
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->api_url = get_option('texolink_api_url', 'http://localhost:5000/api');
        $this->api_key = get_option('texolink_api_key', '');
    }
    
    /**
     * Test API connection
     */
    public function test_connection() {
        // Simple health check
        $response = $this->request('GET', '/health');
        
        if (is_wp_error($response)) {
            return false;
        }
        
        return isset($response['status']) && $response['status'] === 'healthy';
    }
    
    /**
     * Sync site configuration to backend
     * This is the NEW method that sends all settings to Railway
     * 
     * Called when:
     * - User saves settings
     * - User clicks "Save & Sync to Backend"
     * - Plugin activation
     */
    public function sync_site_config() {
        $site_url = get_site_url();
        $domain = parse_url($site_url, PHP_URL_HOST);
        
        $data = array(
            'domain' => $domain,
            'site_url' => $site_url,
            
            // Anchor Text Intelligence
            'site_description' => get_option('texolink_site_description', ''),
            'min_word_count' => (int) get_option('texolink_min_word_count', 2),
            'max_word_count' => (int) get_option('texolink_max_word_count', 4),
            'prefer_proper_nouns' => (bool) get_option('texolink_prefer_proper_nouns', true),
            'blacklist' => get_option('texolink_blacklist', ''),
            
            // Target Keywords
            'target_keywords' => get_option('texolink_target_keywords', ''),
            
            // Link Intelligence & Limits
            'max_inbound_links' => (int) get_option('texolink_max_inbound_links', 10),
            'max_outbound_links' => (int) get_option('texolink_max_outbound_links', 5),
        );
        
        error_log('TexoLink: Syncing site config to Railway - ' . json_encode($data));
        
        return $this->request('POST', '/sites', $data);
    }
    
    /**
     * Get site configuration from backend
     */
    public function get_site_config() {
        $site_url = get_site_url();
        $domain = parse_url($site_url, PHP_URL_HOST);
        
        return $this->request('GET', '/sites/' . urlencode($domain));
    }
    
    /**
     * Sync a post to the backend - ENHANCED VERSION
     * Now includes site_domain so Railway knows which site config to use
     */
    public function sync_post($post_id) {
        $post = get_post($post_id);
        
        if (!$post) {
            return false;
        }
        
        $site_url = get_site_url();
        $domain = parse_url($site_url, PHP_URL_HOST);
        
        // Prepare post data
        $data = array(
            'site_domain' => $domain,  // NEW! Links post to site
            'title' => $post->post_title,
            'content' => wp_strip_all_tags($post->post_content),
            'full_content' => $post->post_content,
            'url' => get_permalink($post_id),
            'wordpress_id' => $post_id,
            'status' => 'published',
            'word_count' => str_word_count(wp_strip_all_tags($post->post_content))
        );
        
        // Check if post already exists in backend
        $existing = $this->get_post_by_wordpress_id($post_id);
        
        if ($existing) {
            // Update existing post
            return $this->request('PUT', '/posts/' . $existing['id'], $data);
        } else {
            // Create new post
            return $this->request('POST', '/posts', $data);
        }
    }
    
    /**
     * Get post from backend by WordPress ID
     */
    public function get_post_by_wordpress_id($wordpress_id) {
        $response = $this->request('GET', '/posts?wordpress_id=' . $wordpress_id);
        
        if (is_wp_error($response) || empty($response['posts'])) {
            return false;
        }
        
        return $response['posts'][0];
    }
    
    /**
     * Get link suggestions for a post
     * Railway now returns PRE-FILTERED suggestions based on site config
     * No filtering needed on WordPress side!
     */
    public function get_suggestions($post_id) {
        // First, ensure post is synced
        $backend_post = $this->get_post_by_wordpress_id($post_id);
        
        if (!$backend_post) {
            // Sync the post first
            $this->sync_post($post_id);
            $backend_post = $this->get_post_by_wordpress_id($post_id);
        }
        
        if (!$backend_post) {
            return array();
        }
        
        // Get suggestions - Railway does ALL filtering!
        $response = $this->request('GET', '/posts/' . $backend_post['id'] . '/suggestions');
        
        if (is_wp_error($response)) {
            return array();
        }
        
        return $response['suggestions'] ?? array();
    }
    
    /**
     * Get suggestions for multiple posts (batch request)
     * Used by progressive loading in suggestions.js
     */
    public function get_suggestions_batch($post_ids) {
        $data = array('post_ids' => $post_ids);
        
        $response = $this->request('POST', '/suggestions/batch', $data);
        
        if (is_wp_error($response)) {
            return array();
        }
        
        return $response['suggestions'] ?? array();
    }
    
    /**
     * Create an internal link
     */
    public function create_link($source_post_id, $target_post_id, $anchor_text, $relevance_score = 0) {
        // Get backend post IDs
        $source = $this->get_post_by_wordpress_id($source_post_id);
        $target = $this->get_post_by_wordpress_id($target_post_id);
        
        if (!$source || !$target) {
            return false;
        }
        
        $data = array(
            'source_post_id' => $source['id'],
            'target_post_id' => $target['id'],
            'anchor_text' => $anchor_text,
            'link_type' => 'manual',
            'relevance_score' => $relevance_score
        );
        
        return $this->request('POST', '/links', $data);
    }
    
    /**
     * Get site overview report
     */
    public function get_overview() {
        return $this->request('GET', '/reports/overview');
    }
    
    /**
     * Get orphaned posts
     */
    public function get_orphaned_posts() {
        $response = $this->request('GET', '/reports/orphaned');
        
        if (is_wp_error($response)) {
            return array();
        }
        
        return $response['orphaned_posts'] ?? array();
    }
    
    /**
     * Batch sync multiple posts
     */
    public function batch_sync($post_ids) {
        $results = array();
        
        foreach ($post_ids as $post_id) {
            $results[$post_id] = $this->sync_post($post_id);
        }
        
        return $results;
    }
    
    /**
     * Make an API request
     */
    private function request($method, $endpoint, $data = array()) {
        $url = trailingslashit($this->api_url) . ltrim($endpoint, '/');
        
        $args = array(
            'method' => $method,
            'timeout' => 30,
            'headers' => array(
                'Content-Type' => 'application/json',
            ),
        );
        
        // Add API key if set
        if (!empty($this->api_key)) {
            $args['headers']['Authorization'] = 'Bearer ' . $this->api_key;
        }
        
        // Add body for POST/PUT requests
        if (in_array($method, array('POST', 'PUT', 'PATCH')) && !empty($data)) {
            $args['body'] = json_encode($data);
        }
        
        // Make request
        $response = wp_remote_request($url, $args);
        
        // Check for errors
        if (is_wp_error($response)) {
            error_log('TexoLink API Error: ' . $response->get_error_message());
            return $response;
        }
        
        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        
        // Check response code
        if ($code < 200 || $code >= 300) {
            error_log('TexoLink API Error: HTTP ' . $code . ' - ' . $body);
            return new WP_Error('api_error', 'API returned error code: ' . $code);
        }
        
        // Parse JSON
        $data = json_decode($body, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log('TexoLink API Error: Invalid JSON response');
            return new WP_Error('invalid_json', 'Invalid JSON response from API');
        }
        
        return $data;
    }
    
    /**
     * Get API status
     */
    public function get_status() {
        $connected = $this->test_connection();
        
        return array(
            'connected' => $connected,
            'api_url' => $this->api_url,
            'has_api_key' => !empty($this->api_key),
        );
    }
}
