<?php
/**
 * AJAX handlers for TexoLink admin operations
 */

// Prevent direct access
if (!defined('ABSPATH')) exit;

/**
 * Count total published posts (for progress calculation)
 */
add_action('wp_ajax_texolink_count_posts', 'texolink_count_posts_handler');
function texolink_count_posts_handler() {
    // Verify nonce
    if (!check_ajax_referer('texolink_nonce', 'nonce', false)) {
        wp_send_json_error(['message' => 'Invalid security token'], 403);
        return;
    }
    
    // Verify user permissions
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Insufficient permissions'], 403);
        return;
    }
    
    try {
        // Count published posts
        $count = wp_count_posts('post');
        $total = isset($count->publish) ? (int)$count->publish : 0;
        
        wp_send_json_success(['total_posts' => $total]);
        
    } catch (Exception $e) {
        error_log('TexoLink count posts error: ' . $e->getMessage());
        wp_send_json_error(['message' => 'Failed to count posts'], 500);
    }
}

/**
 * Check generation job status (existing endpoint - just for reference)
 */
add_action('wp_ajax_texolink_check_generation_status', 'texolink_check_generation_status_handler');
function texolink_check_generation_status_handler() {
    if (!check_ajax_referer('texolink_nonce', 'nonce', false)) {
        wp_send_json_error(['message' => 'Invalid security token'], 403);
        return;
    }
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Insufficient permissions'], 403);
        return;
    }
    
    $site_id = get_option('texolink_site_id');
    if (!$site_id) {
        wp_send_json_error(['message' => 'Site not registered'], 400);
        return;
    }
    
    try {
        $api_url = get_option('texolink_api_url', 'https://your-railway-url.railway.app');
        $response = wp_remote_get(
            $api_url . '/api/generation-status/' . $site_id,
            [
                'timeout' => 10,
                'headers' => [
                    'Content-Type' => 'application/json',
                    'X-API-Key' => get_option('texolink_api_key')
                ]
            ]
        );
        
        if (is_wp_error($response)) {
            throw new Exception($response->get_error_message());
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        wp_send_json_success($body);
        
    } catch (Exception $e) {
        error_log('TexoLink status check error: ' . $e->getMessage());
        wp_send_json_error(['message' => 'Failed to check status'], 500);
    }
}

/**
 * NEW: Get applied suggestions from WordPress database
 * FIXED: Query the correct table (texolink_inserted_links, not texolink_suggestions)
 * Returns list of source_post_id-target_post_id pairs that have been applied
 */
add_action('wp_ajax_texolink_get_applied_suggestions', 'texolink_get_applied_suggestions_handler');
function texolink_get_applied_suggestions_handler() {
    // Verify nonce - matches 'texolink_suggestions' from texolink.php line 332
    if (!check_ajax_referer('texolink_suggestions', 'nonce', false)) {
        wp_send_json_error(['message' => 'Invalid security token'], 403);
        return;
    }
    
    // Verify user permissions
    if (!current_user_can('edit_posts')) {
        wp_send_json_error(['message' => 'Insufficient permissions'], 403);
        return;
    }
    
    global $wpdb;
    
    try {
        // FIXED: Query the texolink_inserted_links table (where links are actually saved)
        $applied = $wpdb->get_results(
            "SELECT DISTINCT source_post_id, target_post_id 
            FROM {$wpdb->prefix}texolink_inserted_links",
            ARRAY_A
        );
        
        // Format as array of "source-target" strings
        $applied_pairs = array();
        foreach ($applied as $row) {
            $applied_pairs[] = $row['source_post_id'] . '-' . $row['target_post_id'];
        }
        
        wp_send_json_success([
            'applied' => $applied_pairs,
            'count' => count($applied_pairs)
        ]);
        
    } catch (Exception $e) {
        error_log('TexoLink get applied suggestions error: ' . $e->getMessage());
        wp_send_json_error(['message' => 'Failed to get applied suggestions'], 500);
    }
}