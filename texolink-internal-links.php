<?php
/**
 * Plugin Name: TexoLink Internal Links
 * Plugin URI: https://texolink.com
 * Description: AI-powered internal link suggestions for WordPress
 * Version: 2.2.4
 * Author: Ricky Carter
 * Author URI: https://texolink.com
 * Text Domain: texolink-internal-links
 * Domain Path: /languages
 *
 * Copyright (c) 2025 Rick Carter / TexoLink
 * All rights reserved.
 *
 * This file is part of TexoLink and is proprietary software.
 * Unauthorized use is prohibited.
 */
// ============================================================================
// AUTO-UPDATE CHECKER - Checks GitHub for new releases
// ============================================================================
require 'plugin-update-checker/plugin-update-checker.php';
use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

$updateChecker = PucFactory::buildUpdateChecker(
    'https://github.com/mymaterick/texolink-internal-links',
    __FILE__,
    'texolink-internal-links'
);

// Enable release assets (so users can download from GitHub releases)
$updateChecker->getVcsApi()->enableReleaseAssets();
// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Plugin constants
define('TEXOLINK_VERSION', '2.2.4');
define('TEXOLINK_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('TEXOLINK_PLUGIN_URL', plugin_dir_url(__FILE__));
define('TEXOLINK_PLUGIN_FILE', __FILE__);

/**
 * Main TexoLink Class
 */
class TexoLink {
    
    /**
     * The single instance of the class
     */
    private static $instance = null;
    
    /**
     * API client instance
     */
    public $api_client;
    
    /**
     * Main TexoLink Instance
     */
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->includes();
        $this->init_hooks();
    }
    
    /**
     * Include required files
     */
    private function includes() {
        require_once TEXOLINK_PLUGIN_DIR . 'includes/class-api-client.php';
        require_once TEXOLINK_PLUGIN_DIR . 'includes/class-analyzer.php';
        require_once TEXOLINK_PLUGIN_DIR . 'includes/class-link-inserter.php';
        require_once TEXOLINK_PLUGIN_DIR . 'includes/class-gutenberg.php';
        require_once TEXOLINK_PLUGIN_DIR . 'includes/class-tl-link-counter.php';
        require_once TEXOLINK_PLUGIN_DIR . 'includes/ajax-handlers.php';
    }
    
    /**
     * Hook into WordPress
     */
    private function init_hooks() {
        // Initialize API client
        $this->api_client = new TexoLink_API_Client();
        
        // Initialize Link Counter
        TL_Link_Counter::init();
        
        // Initialize Suggestions page - now using direct menu registration instead
        // new TexoLink_Suggestions($this->api_client);
        
        // Activation/Deactivation hooks
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
        
        // Init
        add_action('init', array($this, 'init'));
        add_action('admin_init', array($this, 'admin_init'));
        
        // Admin menu
        add_action('admin_menu', array($this, 'admin_menu'));
        
        // Enqueue scripts
        add_action('admin_enqueue_scripts', array($this, 'admin_scripts'));
        add_action('enqueue_block_editor_assets', array($this, 'editor_scripts'));
        
        // AJAX handlers
        add_action('wp_ajax_texolink_get_suggestions', array($this, 'ajax_get_suggestions'));
        add_action('wp_ajax_texolink_apply_link', array($this, 'ajax_apply_link'));
        add_action('wp_ajax_texolink_sync_post', array($this, 'ajax_sync_post'));
        add_action('wp_ajax_texolink_bulk_analyze', array($this, 'ajax_bulk_analyze'));
        add_action('wp_ajax_texolink_get_all_posts', array($this, 'ajax_get_all_posts'));
        add_action('wp_ajax_texolink_insert_link', array($this, 'ajax_insert_link'));

        add_action('wp_ajax_texolink_count_posts', 'texolink_ajax_count_posts');
function texolink_ajax_count_posts() {
    check_ajax_referer('texolink_nonce', 'nonce');
    $count = wp_count_posts('post');
    wp_send_json_success($count->publish);
}
        
        // Post save hook
        add_action('save_post', array($this, 'on_post_save'), 10, 2);
    }
    
    /**
     * Plugin activation
     */
    public function activate() {
        // Create database tables if needed
        $this->create_tables();
        
        // Set default options
        if (!get_option('texolink_api_url')) {
            update_option('texolink_api_url', 'http://localhost:5000/api');
        }
        
        // Schedule cron jobs
        if (!wp_next_scheduled('texolink_daily_analysis')) {
            wp_schedule_event(time(), 'daily', 'texolink_daily_analysis');
        }
        
        flush_rewrite_rules();
    }
    
    /**
     * Plugin deactivation
     */
    public function deactivate() {
        // Clear scheduled events
        wp_clear_scheduled_hook('texolink_daily_analysis');
        flush_rewrite_rules();
    }
    
    /**
     * Create plugin database tables
     */
    private function create_tables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        
        // Suggestions table
        $sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}texolink_suggestions (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            post_id bigint(20) NOT NULL,
            target_post_id bigint(20) NOT NULL,
            anchor_text varchar(255) NOT NULL,
            relevance_score float NOT NULL,
            status varchar(20) DEFAULT 'pending',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY post_id (post_id),
            KEY target_post_id (target_post_id)
        ) $charset_collate;";
        
        // Inserted links tracking table
        $sql2 = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}texolink_inserted_links (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            source_post_id bigint(20) NOT NULL,
            target_post_id bigint(20) NOT NULL,
            anchor_text varchar(255) NOT NULL,
            inserted_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY source_post_id (source_post_id),
            KEY target_post_id (target_post_id)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        dbDelta($sql2);
    }
    
    /**
     * Initialize plugin
     */
    public function init() {
        // Load text domain
        load_plugin_textdomain('texolink', false, dirname(plugin_basename(__FILE__)) . '/languages');
    }
    
    /**
     * Admin initialization
     */
    public function admin_init() {
        // Register settings
        register_setting('texolink_settings', 'texolink_api_url');
        register_setting('texolink_settings', 'texolink_api_key');
        register_setting('texolink_settings', 'texolink_min_similarity');
        register_setting('texolink_settings', 'texolink_max_suggestions');
        register_setting('texolink_settings', 'texolink_auto_sync');
    }
    
    /**
     * Register admin menu
     */
    public function admin_menu() {
        add_menu_page(
            __('TexoLink', 'texolink'),
            __('TexoLink', 'texolink'),
            'manage_options',
            'texolink',
            array($this, 'dashboard_page'),
            'dashicons-admin-links',
            30
        );
        
        add_submenu_page(
            'texolink',
            __('Dashboard', 'texolink'),
            __('Dashboard', 'texolink'),
            'manage_options',
            'texolink',
            array($this, 'dashboard_page')
        );
        
        add_submenu_page(
            'texolink',
            __('Bulk Analyzer', 'texolink'),
            __('Bulk Analyzer', 'texolink'),
            'manage_options',
            'texolink-bulk',
            array($this, 'bulk_page')
        );
        
        add_submenu_page(
            'texolink',
            __('Link Suggestions', 'texolink'),
            __('Link Suggestions', 'texolink'),
            'manage_options',
            'texolink-link-suggest',
            array($this, 'suggestions_page')
        );
        
        add_submenu_page(
            'texolink',
            __('Settings', 'texolink'),
            __('Settings', 'texolink'),
            'manage_options',
            'texolink-settings',
            array($this, 'settings_page')
        );
    }
    
    /**
     * Dashboard page
     */
    public function dashboard_page() {
        require_once TEXOLINK_PLUGIN_DIR . 'admin/views/dashboard.php';
    }
    
    /**
     * Bulk analyzer page
     */
    public function bulk_page() {
        require_once TEXOLINK_PLUGIN_DIR . 'admin/views/bulk-analyzer.php';
    }
    
    /**
     * Link Suggestions page
     */
    public function suggestions_page() {
        require_once TEXOLINK_PLUGIN_DIR . 'admin/views/link-suggestions.php';
    }
    
    /**
     * Settings page
     */
    public function settings_page() {
        require_once TEXOLINK_PLUGIN_DIR . 'admin/views/settings.php';
    }
    
    /**
     * Enqueue admin scripts
     */
    public function admin_scripts($hook) {
        // Only load on TexoLink pages
        if (strpos($hook, 'texolink') === false && $hook !== 'post.php' && $hook !== 'post-new.php') {
            return;
        }
        
        wp_enqueue_style('texolink-admin', TEXOLINK_PLUGIN_URL . 'assets/css/admin.css', array(), TEXOLINK_VERSION);
        wp_enqueue_style('texolink-link-counter', TEXOLINK_PLUGIN_URL . 'assets/css/link-counter-styles.css', array(), TEXOLINK_VERSION);
        wp_enqueue_script('texolink-admin', TEXOLINK_PLUGIN_URL . 'assets/js/admin.js', array('jquery'), TEXOLINK_VERSION, true);
        
        wp_localize_script('texolink-admin', 'texolinkData', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('texolink_nonce'),
            'apiConnected' => $this->api_client->test_connection(),
        ));
        
// Add settings for suggestions page
wp_localize_script('texolink-admin', 'texolinkSettings', array(
    'apiUrl' => get_option('texolink_api_url'),
    'siteDomain' => parse_url(get_site_url(), PHP_URL_HOST),  // ← FIXED!
    'nonce' => wp_create_nonce('texolink_nonce'),
    'max_inbound_links' => intval(get_option('texolink_max_inbound_links', 0)),
    'max_outbound_links' => intval(get_option('texolink_max_outbound_links', 0)),
    'blacklist' => get_option('texolink_blacklist', ''),
    'debug_mode' => intval(get_option('texolink_debug_mode', 0)),
    'enabled_post_types' => get_option('texolink_enabled_post_types', array('post', 'page')),
    'adminSecret' => get_option('texolink_admin_secret', '')
));
        
        // Enqueue Link Suggestions specific scripts
        if ($hook === 'texolink_page_texolink-link-suggest') {
            wp_enqueue_style('texolink-suggestions', 
                TEXOLINK_PLUGIN_URL . 'assets/css/suggestions.css', 
                array(), 
                TEXOLINK_VERSION
            );
            
            wp_enqueue_script('texolink-suggestions', 
                TEXOLINK_PLUGIN_URL . 'assets/js/suggestions.js', 
                array('jquery'), 
                TEXOLINK_VERSION, 
                true
            );
            
            wp_localize_script('texolink-suggestions', 'texolinkSuggestions', array(
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('texolink_suggestions')
            ));
        }
    }
    
    /**
     * Enqueue Gutenberg editor scripts
     */
    public function editor_scripts() {
        wp_enqueue_script(
            'texolink-editor',
            TEXOLINK_PLUGIN_URL . 'assets/js/editor.js',
            array('wp-plugins', 'wp-edit-post', 'wp-element', 'wp-components', 'wp-data'),
            TEXOLINK_VERSION,
            true
        );
        
        wp_localize_script('texolink-editor', 'texolinkEditor', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('texolink_nonce'),
        ));
    }
    
    /**
     * AJAX: Get link suggestions
     */
    public function ajax_get_suggestions() {
        check_ajax_referer('texolink_nonce', 'nonce');
        
        $post_id = intval($_POST['post_id']);
        
        if (!$post_id) {
            wp_send_json_error('Invalid post ID');
        }
        
        $analyzer = new TexoLink_Analyzer();
        $suggestions = $analyzer->get_suggestions($post_id);
        
        wp_send_json_success($suggestions);
    }
    
    /**
     * AJAX: Apply a link
     */
    public function ajax_apply_link() {
        check_ajax_referer('texolink_nonce', 'nonce');
        
        $post_id = intval($_POST['post_id']);
        $target_id = intval($_POST['target_id']);
        $anchor_text = sanitize_text_field($_POST['anchor_text']);
        
        $inserter = new TexoLink_Link_Inserter();
        $result = $inserter->insert_link($post_id, $target_id, $anchor_text);
        
        if ($result) {
            wp_send_json_success('Link inserted successfully');
        } else {
            wp_send_json_error('Failed to insert link');
        }
    }
    
    /**
     * AJAX: Sync post to backend
     */
    public function ajax_sync_post() {
        check_ajax_referer('texolink_nonce', 'nonce');
        
        $post_id = intval($_POST['post_id']);
        $result = $this->api_client->sync_post($post_id);
        
        if ($result) {
            wp_send_json_success('Post synced successfully');
        } else {
            wp_send_json_error('Failed to sync post');
        }
    }
    
    /**
     * AJAX: Bulk analyze posts
     */
    public function ajax_bulk_analyze() {
        check_ajax_referer('texolink_nonce', 'nonce');
        // Clear the settings changed flag since we're regenerating
    delete_option('texolink_settings_changed');
        
        $post_ids = array_map('intval', $_POST['post_ids']);
        
        $results = array();
        foreach ($post_ids as $post_id) {
            $results[$post_id] = $this->api_client->sync_post($post_id);
        }
        
        wp_send_json_success($results);
    }
    
    /**
     * AJAX: Get all posts for analysis
     */
    public function ajax_get_all_posts() {
        check_ajax_referer('texolink_nonce', 'nonce');

        $offset = isset($_POST['offset']) ? intval($_POST['offset']) : 0;
        $limit = isset($_POST['limit']) ? intval($_POST['limit']) : 50;
        $post_type_param = isset($_POST['post_type']) ? $_POST['post_type'] : 'all';
        $full_data = isset($_POST['full_data']) ? (bool)$_POST['full_data'] : false;

        // Determine which post types to query
        if (is_array($post_type_param)) {
            // Array of post types passed from frontend
            $post_types = array_map('sanitize_text_field', $post_type_param);
        } elseif ($post_type_param === 'all') {
            // Get all public post types except attachments
            $post_types = get_post_types(array('public' => true), 'names');
            unset($post_types['attachment']);
            $post_types = array_values($post_types);
        } else {
            // Single post type
            $post_types = array(sanitize_text_field($post_type_param));
        }

        $args = array(
            'post_type' => $post_types,
            'post_status' => 'publish',
            'posts_per_page' => $full_data ? -1 : $limit,  // Get all posts if full_data requested
            'offset' => $full_data ? 0 : $offset,
            'orderby' => 'date',
            'order' => 'DESC',
            'fields' => $full_data ? 'all' : 'ids'
        );

        $query = new WP_Query($args);

        // If full_data requested, return complete post objects
        if ($full_data) {
            $posts = array();
            foreach ($query->posts as $post) {
                $posts[] = array(
                    'id' => $post->ID,
                    'title' => $post->post_title,
                    'content' => $post->post_content,
                    'excerpt' => $post->post_excerpt,
                    'url' => get_permalink($post->ID),
                    'date' => $post->post_date,
                    'modified' => $post->post_modified,
                    'type' => $post->post_type
                );
            }

            wp_send_json_success($posts);
        } else {
            // Original behavior for backwards compatibility
            wp_send_json_success(array(
                'post_ids' => $query->posts,
                'total' => $query->found_posts,
                'has_more' => ($offset + $limit) < $query->found_posts
            ));
        }
    }
    
    /**
     * On post save, sync to backend
     */
    public function on_post_save($post_id, $post) {
        // Check if auto-sync is enabled
        if (!get_option('texolink_auto_sync', true)) {
            return;
        }
        
        // Skip autosaves and revisions
        if (wp_is_post_autosave($post_id) || wp_is_post_revision($post_id)) {
            return;
        }
        
        // Only sync published posts
        if ($post->post_status !== 'publish') {
            return;
        }
        
        // Only sync posts and pages
        if (!in_array($post->post_type, array('post', 'page'))) {
            return;
        }
        
        // Sync to backend
        $this->api_client->sync_post($post_id);
    }
    
    /**
     * AJAX: Insert link into post (FIXED VERSION)
     * This is the improved version that searches for anchor text in the SOURCE post
     */
    public function ajax_insert_link() {
        check_ajax_referer('texolink_suggestions', 'nonce');
        
        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Insufficient permissions');
        }
        
        $source_post_id = intval($_POST['source_post_id']);
        $target_post_id = intval($_POST['target_post_id']);
        $suggested_anchor = sanitize_text_field($_POST['anchor_text']);
        
        $source_post = get_post($source_post_id);
        $target_post = get_post($target_post_id);
        
        if (!$source_post || !$target_post) {
            error_log("TexoLink: Invalid post IDs - Source: $source_post_id (" . ($source_post ? 'found' : 'NOT FOUND') . "), Target: $target_post_id (" . ($target_post ? 'found' : 'NOT FOUND') . ")");
            wp_send_json_error('Could not find posts with IDs ' . $source_post_id . ' and ' . $target_post_id . '. Please re-analyze your content.');
            return;
        }
        
        $target_url = get_permalink($target_post_id);
        
        // Check if link already exists
        if ($this->link_already_exists($source_post->post_content, $target_url)) {
            wp_send_json_error('This link already exists in the post');
            return;
        }
        
        // FIX: Find suitable anchor text IN THE SOURCE POST
        $result = $this->find_and_insert_link(
            $source_post->post_content, 
            $target_post, 
            $target_url, 
            $suggested_anchor
        );
        
        if ($result['success']) {
            // Update post with the new content
            $update_result = wp_update_post(array(
                'ID' => $source_post_id,
                'post_content' => $result['new_content']
            ));
            
            if ($update_result && !is_wp_error($update_result)) {
                // Track in database
                $this->track_inserted_link($source_post_id, $target_post_id, $result['anchor_used']);
                
                wp_send_json_success(array(
                    'message' => 'Link inserted successfully!',
                    'anchor_used' => $result['anchor_used'],
                    'strategy' => $result['strategy'],
                    'edit_url' => get_edit_post_link($source_post_id, 'raw')
                ));
            } else {
                wp_send_json_error('Failed to update post content');
            }
        } else {
            // No suitable anchor found - provide helpful error
            wp_send_json_error(array(
                'message' => 'Could not find suitable text to link in the source post.',
                'details' => $result['error'],
                'suggestion' => 'Try editing the post manually to add a reference to "' . $target_post->post_title . '" and then insert the link.',
                'edit_url' => get_edit_post_link($source_post_id, 'raw')
            ));
        }
    }
    
    /**
     * Find suitable anchor text in source content and create linked version
     * IMPROVED: Now tries longer phrases before falling back to single words
     */
    private function find_and_insert_link($content, $target_post, $target_url, $suggested_anchor) {
        // Strip HTML for searching
        $plain_content = wp_strip_all_tags($content);
        
        // Try multiple strategies to find linkable text (ordered by preference: longest match first)
        $strategies = array();
        
        // Strategy 1: Exact match of suggested anchor
        $strategies[] = array(
            'name' => 'exact_suggested',
            'text' => $suggested_anchor,
            'description' => 'Exact match of suggested anchor'
        );
        
        // Strategy 2: Target post title
        $strategies[] = array(
            'name' => 'target_title',
            'text' => $target_post->post_title,
            'description' => 'Target post title'
        );
        
        // Strategy 3: Multi-word phrase combinations from suggested anchor
        // Try progressively shorter combinations: "xml sitemap strategies" → "xml sitemap" → "sitemap strategies"
        $words = array_values(array_filter(
            explode(' ', strtolower($suggested_anchor)), 
            function($word) { return strlen($word) > 3; }
        ));
        
        $word_count = count($words);
        
        // Try combinations from longest to shortest (but minimum 2 words)
        for ($length = $word_count; $length >= 2; $length--) {
            for ($start = 0; $start <= $word_count - $length; $start++) {
                $phrase = implode(' ', array_slice($words, $start, $length));
                $strategies[] = array(
                    'name' => 'phrase_combo',
                    'text' => $phrase,
                    'description' => 'Phrase combination: ' . $phrase,
                    'priority' => 3 // Higher priority than title words
                );
            }
        }
        
        // Strategy 4: Individual keywords from suggested anchor (IMPORTANT FALLBACK)
        // These should come BEFORE title phrases because they're more relevant
        foreach ($words as $keyword) {
            $strategies[] = array(
                'name' => 'keyword',
                'text' => $keyword,
                'description' => 'Keyword from suggestion: ' . $keyword,
                'priority' => 4 // Still higher priority than title
            );
        }
        
        // Strategy 5: Multi-word phrases from target title
        // Only try these after exhausting suggested anchor words
        $title_words = array_values(array_filter(
            explode(' ', strtolower($target_post->post_title)), 
            function($word) { return strlen($word) > 3; }
        ));
        
        $title_word_count = count($title_words);
        
        // Only try meaningful multi-word title phrases (skip generic single words like "advanced")
        if ($title_word_count >= 2) {
            for ($length = $title_word_count; $length >= 2; $length--) {
                for ($start = 0; $start <= $title_word_count - $length; $start++) {
                    $phrase = implode(' ', array_slice($title_words, $start, $length));
                    
                    // Skip phrases that start with generic/filler words
                    $first_word = $title_words[$start];
                    $generic_words = array('advanced', 'complete', 'ultimate', 'best', 'guide', 'introduction', 'beginner');
                    
                    if (!in_array($first_word, $generic_words)) {
                        $strategies[] = array(
                            'name' => 'title_phrase',
                            'text' => $phrase,
                            'description' => 'Title phrase: ' . $phrase,
                            'priority' => 5
                        );
                    }
                }
            }
        }
        
        // Strategy 6: Individual words from target title (LAST RESORT ONLY)
        // Skip generic filler words entirely
        $generic_words = array('advanced', 'complete', 'ultimate', 'best', 'guide', 'introduction', 'beginner', 'basics');
        foreach ($title_words as $word) {
            // Only add if it's not a generic word
            if (!in_array($word, $generic_words)) {
                $strategies[] = array(
                    'name' => 'title_word',
                    'text' => $word,
                    'description' => 'Title word: ' . $word,
                    'priority' => 6 // Lowest priority
                );
            }
        }
        
        // Try each strategy
        foreach ($strategies as $strategy) {
            $anchor_text = $strategy['text'];
            
            // Skip empty or very short text
            if (empty($anchor_text) || strlen($anchor_text) < 3) {
                continue;
            }
            
            // Check if this text exists in the content (case-insensitive)
            $pos = stripos($plain_content, $anchor_text);
            
            if ($pos !== false) {
                // Found it! Now insert the link in the actual HTML content
                $new_content = $this->insert_link_in_content(
                    $content, 
                    $anchor_text, 
                    $target_url
                );
                
                if ($new_content !== $content) {
                    return array(
                        'success' => true,
                        'new_content' => $new_content,
                        'anchor_used' => $anchor_text,
                        'strategy' => $strategy['description']
                    );
                }
            }
        }
        
        // No suitable anchor found
        return array(
            'success' => false,
            'error' => 'No matching text found in source post. Tried: ' . implode(', ', array_column($strategies, 'text'))
        );
    }
    
    /**
     * Insert link into HTML content, preserving HTML structure
     * SAFE: Only replaces text content, never inside HTML tags or attributes
     */
    private function insert_link_in_content($content, $anchor_text, $target_url) {
        // Load HTML into DOMDocument for safe parsing
        $dom = new DOMDocument();
        
        // Suppress warnings for malformed HTML
        libxml_use_internal_errors(true);
        
        // Add HTML5 doctype and UTF-8 meta tag to handle encoding properly
        $html_content = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body>' . $content . '</body></html>';
        $dom->loadHTML($html_content, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        
        libxml_clear_errors();
        
        // Get the body element
        $body = $dom->getElementsByTagName('body')->item(0);
        
        if (!$body) {
            // Fallback to simple replacement if DOM parsing fails
            return $this->simple_text_replace($content, $anchor_text, $target_url);
        }
        
        // Track if we've made a replacement
        $replaced = false;
        
        // Recursively search text nodes and replace the first match
        $this->replace_text_in_node($body, $anchor_text, $target_url, $replaced);
        
        // If no replacement was made via DOM, try the fallback
        if (!$replaced) {
            return $this->simple_text_replace($content, $anchor_text, $target_url);
        }
        
        // Extract just the body content (remove our wrapper tags)
        $new_content = '';
        foreach ($body->childNodes as $node) {
            $new_content .= $dom->saveHTML($node);
        }
        
        return $new_content;
    }
    
    /**
     * Recursively replace text in DOM nodes (text nodes only, not attributes)
     */
    private function replace_text_in_node($node, $anchor_text, $target_url, &$replaced) {
        if ($replaced) {
            return; // Already replaced, stop recursing
        }
        
        // If this is a text node, try to replace
        if ($node->nodeType === XML_TEXT_NODE) {
            $text = $node->nodeValue;
            
            // Case-insensitive search
            $pos = stripos($text, $anchor_text);
            
            if ($pos !== false && !$replaced) {
                // Found the anchor text in this text node
                
                // Get the actual case-matched text
                $actual_text = substr($text, $pos, strlen($anchor_text));
                
                // Split the text into before, match, and after
                $before = substr($text, 0, $pos);
                $after = substr($text, $pos + strlen($anchor_text));
                
                // Create the new nodes
                $parent = $node->parentNode;
                $doc = $node->ownerDocument;
                
                // Create text node for before
                if ($before !== '') {
                    $beforeNode = $doc->createTextNode($before);
                    $parent->insertBefore($beforeNode, $node);
                }
                
                // Create the link element
                $link = $doc->createElement('a');
                $link->setAttribute('href', esc_url($target_url));
                $link->setAttribute('class', 'texolink-inserted');
                $link->appendChild($doc->createTextNode($actual_text));
                $parent->insertBefore($link, $node);
                
                // Create text node for after
                if ($after !== '') {
                    $afterNode = $doc->createTextNode($after);
                    $parent->insertBefore($afterNode, $node);
                }
                
                // Remove the original text node
                $parent->removeChild($node);
                
                $replaced = true;
                return;
            }
        }
        
        // Recursively process child nodes (but skip script, style, and existing links)
        if ($node->hasChildNodes()) {
            $nodeName = strtolower($node->nodeName);
            
            // Skip these tags - don't add links inside them
            // Headings (h1-h6) should never contain links for SEO best practices
            if (in_array($nodeName, array('script', 'style', 'a', 'code', 'pre', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'button'))) {
                return;
            }
            
            // Process children
            $children = array();
            foreach ($node->childNodes as $child) {
                $children[] = $child;
            }
            
            foreach ($children as $child) {
                $this->replace_text_in_node($child, $anchor_text, $target_url, $replaced);
                if ($replaced) {
                    break;
                }
            }
        }
    }
    
    /**
     * Fallback: Simple text replacement (only in text between tags)
     * This avoids matching inside HTML tag attributes and excluded tags
     */
    private function simple_text_replace($content, $anchor_text, $target_url) {
        // Split content by HTML tags
        $parts = preg_split('/(<[^>]+>)/i', $content, -1, PREG_SPLIT_DELIM_CAPTURE);
        
        $replaced = false;
        $inside_excluded_tag = false;
        $excluded_tags = array('script', 'style', 'a', 'code', 'pre', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'button');
        
        for ($i = 0; $i < count($parts); $i++) {
            // Odd indices are HTML tags, even indices are text
            if ($i % 2 === 1) {
                // This is an HTML tag - check if it's an excluded tag
                $tag_content = $parts[$i];
                
                // Check for opening tags of excluded elements
                foreach ($excluded_tags as $excluded_tag) {
                    if (preg_match('/<' . $excluded_tag . '[\s>]/i', $tag_content)) {
                        $inside_excluded_tag = true;
                        break;
                    }
                    // Check for closing tags
                    if (preg_match('/<\/' . $excluded_tag . '>/i', $tag_content)) {
                        $inside_excluded_tag = false;
                        break;
                    }
                }
            } elseif ($i % 2 === 0 && !$replaced && !$inside_excluded_tag) {
                // This is text content (not an HTML tag) and not inside an excluded tag
                $pos = stripos($parts[$i], $anchor_text);
                
                if ($pos !== false) {
                    // Found it in text content
                    $actual_text = substr($parts[$i], $pos, strlen($anchor_text));
                    $before = substr($parts[$i], 0, $pos);
                    $after = substr($parts[$i], $pos + strlen($anchor_text));
                    
                    $link = '<a href="' . esc_url($target_url) . '" class="texolink-inserted">' . esc_html($actual_text) . '</a>';
                    $parts[$i] = $before . $link . $after;
                    
                    $replaced = true;
                }
            }
        }
        
        return implode('', $parts);
    }
    
    /**
     * Check if a link to the target URL already exists
     */
    private function link_already_exists($content, $target_url) {
        // Check for exact URL match in href attributes
        $pattern = '/href=["\']' . preg_quote($target_url, '/') . '["\']/i';
        return preg_match($pattern, $content) === 1;
    }
    
    /**
     * Track inserted link in database
     */
    private function track_inserted_link($source_id, $target_id, $anchor_text) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'texolink_inserted_links';
        
        $wpdb->insert(
            $table_name,
            array(
                'source_post_id' => $source_id,
                'target_post_id' => $target_id,
                'anchor_text' => $anchor_text,
                'inserted_at' => current_time('mysql')
            ),
            array('%d', '%d', '%s', '%s')
        );
    }
}

/**
 * Get internal link statistics from WordPress database
 * 
 * @return array Statistics including total links, avg per post, orphaned posts
 */
function texolink_get_link_stats() {
    global $wpdb;
    
    $stats = array(
        'total_internal_links' => 0,
        'average_links_per_post' => 0,
        'orphaned_posts' => 0,
        'posts_with_links' => 0
    );
    
    // Count total inserted links from tracking table
    $table_name = $wpdb->prefix . 'texolink_inserted_links';
    
    // Check if table exists
    if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name) {
        $stats['total_internal_links'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table_name");
        $stats['posts_with_links'] = (int) $wpdb->get_var("SELECT COUNT(DISTINCT source_post_id) FROM $table_name");
    }
    
    // Count total published posts
    $total_posts = wp_count_posts('post');
    $total_published = $total_posts->publish;
    
    // Calculate average
    if ($total_published > 0) {
        $stats['average_links_per_post'] = round($stats['total_internal_links'] / $total_published, 1);
    }
    
    // Count orphaned posts (posts with no inbound links)
    if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name) {
        $orphaned_query = "
            SELECT COUNT(*) 
            FROM {$wpdb->posts} 
            WHERE post_type = 'post' 
            AND post_status = 'publish'
            AND ID NOT IN (SELECT DISTINCT target_post_id FROM $table_name WHERE target_post_id IS NOT NULL)
        ";
        $stats['orphaned_posts'] = (int) $wpdb->get_var($orphaned_query);
    } else {
        $stats['orphaned_posts'] = $total_published;
    }
    
    return $stats;
}

/**
 * Initialize the plugin
 */
function texolink() {
    return TexoLink::instance();
}

// Start the plugin
texolink();