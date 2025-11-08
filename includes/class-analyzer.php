<?php
/**
 * TexoLink Analyzer
 * 
 * Handles local analysis and coordination with backend API
 */

if (!defined('ABSPATH')) {
    exit;
}

class TexoLink_Analyzer {
    
    /**
     * API client instance
     */
    private $api_client;
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->api_client = new TexoLink_API_Client();
    }
    
    /**
     * Get link suggestions for a post
     */
    public function get_suggestions($post_id) {
        // Get suggestions from API
        $suggestions = $this->api_client->get_suggestions($post_id);
        
        if (empty($suggestions)) {
            return array();
        }
        
        // Enhance suggestions with WordPress data
        $enhanced_suggestions = array();
        
        foreach ($suggestions as $suggestion) {
            // Try to find the WordPress post by title or URL
            $target_wp_id = $this->find_wordpress_post($suggestion);
            
            if ($target_wp_id) {
                $suggestion['wordpress_target_id'] = $target_wp_id;
                $suggestion['edit_link'] = get_edit_post_link($target_wp_id);
                $suggestion['permalink'] = get_permalink($target_wp_id);
                $enhanced_suggestions[] = $suggestion;
            }
        }
        
        return $enhanced_suggestions;
    }
    
    /**
     * Find WordPress post from suggestion data
     */
    private function find_wordpress_post($suggestion) {
        global $wpdb;
        
        // Try to find by title
        if (!empty($suggestion['target_title'])) {
            $post_id = $wpdb->get_var($wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts} WHERE post_title = %s AND post_status = 'publish' LIMIT 1",
                $suggestion['target_title']
            ));
            
            if ($post_id) {
                return $post_id;
            }
        }
        
        // Try to find by URL
        if (!empty($suggestion['target_url'])) {
            $post_id = url_to_postid($suggestion['target_url']);
            if ($post_id) {
                return $post_id;
            }
        }
        
        return false;
    }
    
    /**
     * Analyze a single post
     */
    public function analyze_post($post_id) {
        // Sync to backend
        $result = $this->api_client->sync_post($post_id);
        
        if (is_wp_error($result)) {
            return array(
                'success' => false,
                'error' => $result->get_error_message()
            );
        }
        
        // Get suggestions
        $suggestions = $this->get_suggestions($post_id);
        
        return array(
            'success' => true,
            'post_id' => $post_id,
            'suggestions_count' => count($suggestions),
            'suggestions' => $suggestions
        );
    }
    
    /**
     * Batch analyze multiple posts
     */
    public function batch_analyze($post_ids) {
        $results = array();
        
        foreach ($post_ids as $post_id) {
            $results[$post_id] = $this->analyze_post($post_id);
            
            // Small delay to avoid overwhelming the API
            usleep(100000); // 0.1 seconds
        }
        
        return $results;
    }
    
    /**
     * Get post analysis status
     */
    public function get_post_status($post_id) {
        $backend_post = $this->api_client->get_post_by_wordpress_id($post_id);
        
        if (!$backend_post) {
            return array(
                'synced' => false,
                'has_keywords' => false,
                'has_embeddings' => false,
                'last_analyzed' => null
            );
        }
        
        return array(
            'synced' => true,
            'has_keywords' => !empty($backend_post['keywords']),
            'has_embeddings' => !empty($backend_post['embedding']),
            'last_analyzed' => $backend_post['updated_at'] ?? null
        );
    }
    
    /**
     * Re-analyze all posts
     */
    public function reanalyze_all() {
        $args = array(
            'post_type' => array('post', 'page'),
            'post_status' => 'publish',
            'posts_per_page' => -1
        );
        
        $posts = get_posts($args);
        $post_ids = wp_list_pluck($posts, 'ID');
        
        return $this->batch_analyze($post_ids);
    }
}
