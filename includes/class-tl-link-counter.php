<?php
/**
 * TexoLink Link Counter v2.2
 * Handles AJAX requests for link count data
 */

class TL_Link_Counter {
    
    /**
     * Register AJAX handlers
     */
    public static function init() {
        add_action('wp_ajax_tl_get_link_counts', [__CLASS__, 'ajax_get_link_counts']);
    }
    
    /**
     * AJAX handler for getting link counts
     */
    public static function ajax_get_link_counts() {
        // Verify nonce
        check_ajax_referer('texolink_nonce', 'nonce');
        
        $post_ids = sanitize_text_field($_POST['post_ids']);
        
        if (!$post_ids) {
            wp_send_json_error(['message' => 'Invalid parameters']);
            return;
        }
        
        // Parse post IDs
        $ids = array_map('intval', explode(',', $post_ids));
        
        // Get link counts for each post
        $counts = [];
        
        foreach ($ids as $post_id) {
            // Return both inbound (links TO this post) and outbound (links FROM this post)
            $counts[$post_id] = [
                'inbound' => self::count_inbound_links($post_id),
                'outbound' => self::count_outbound_links($post_id)
            ];
        }
        
        wp_send_json_success($counts);
    }
    
    /**
     * Count inbound links TO a target post
     * 
     * @param int $target_post_id The post receiving links
     * @return int Number of inbound links
     */
    public static function count_inbound_links($target_post_id) {
        global $wpdb;
        
        // Query all published posts
        $posts = get_posts([
            'post_type' => 'post',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'fields' => 'ids',
            'exclude' => [$target_post_id]
        ]);
        
        $link_count = 0;
        $target_url = get_permalink($target_post_id);
        
        // Check each post's content for links to target
        foreach ($posts as $post_id) {
            $content = get_post_field('post_content', $post_id);
            
            // Count links to target URL
            $pattern = '/<a[^>]+href=["\']' . preg_quote($target_url, '/') . '["\'][^>]*>/i';
            preg_match_all($pattern, $content, $matches);
            
            $link_count += count($matches[0]);
        }
        
        return $link_count;
    }
    
    /**
     * Count outbound links FROM a source post
     * 
     * @param int $source_post_id The post containing links
     * @return int Number of outbound links
     */
    public static function count_outbound_links($source_post_id) {
        global $wpdb;
        
        $content = get_post_field('post_content', $source_post_id);
        
        if (!$content) {
            return 0;
        }
        
        // Get site URL to identify internal links
        $site_url = get_site_url();
        
        // Count all internal links in the content
        $pattern = '/<a[^>]+href=["\']' . preg_quote($site_url, '/') . '[^"\']+["\'][^>]*>/i';
        preg_match_all($pattern, $content, $matches);
        
        return count($matches[0]);
    }
    
    /**
     * Get link count statistics for a specific post
     * 
     * @param int $post_id Post ID to analyze
     * @return array Statistics about the post's links
     */
    public static function get_post_link_stats($post_id) {
        return [
            'post_id' => $post_id,
            'inbound' => self::count_inbound_links($post_id),
            'outbound' => self::count_outbound_links($post_id),
            'title' => get_the_title($post_id),
            'url' => get_permalink($post_id)
        ];
    }
    
    /**
     * Check if a post has reached its link limits
     * 
     * @param int $post_id Post ID to check
     * @param int $max_inbound Maximum inbound links allowed (0 = no limit)
     * @param int $max_outbound Maximum outbound links allowed (0 = no limit)
     * @return array Status of each limit
     */
    public static function check_link_limits($post_id, $max_inbound = 0, $max_outbound = 0) {
        $inbound_count = self::count_inbound_links($post_id);
        $outbound_count = self::count_outbound_links($post_id);
        
        return [
            'inbound' => [
                'count' => $inbound_count,
                'limit' => $max_inbound,
                'reached' => ($max_inbound > 0 && $inbound_count >= $max_inbound),
                'percentage' => $max_inbound > 0 ? round(($inbound_count / $max_inbound) * 100) : 0
            ],
            'outbound' => [
                'count' => $outbound_count,
                'limit' => $max_outbound,
                'reached' => ($max_outbound > 0 && $outbound_count >= $max_outbound),
                'percentage' => $max_outbound > 0 ? round(($outbound_count / $max_outbound) * 100) : 0
            ]
        ];
    }
    
    /**
     * Filter suggestions based on link limits
     * 
     * @param array $suggestions List of suggestion objects
     * @param int $source_post_id Source post ID
     * @param int $max_inbound Max inbound links per post
     * @param int $max_outbound Max outbound links per post
     * @return array Filtered suggestions
     */
    public static function filter_by_limits($suggestions, $source_post_id, $max_inbound = 0, $max_outbound = 0) {
        if ($max_inbound == 0 && $max_outbound == 0) {
            return $suggestions; // No limits set
        }
        
        $filtered = [];
        
        foreach ($suggestions as $suggestion) {
            $target_id = $suggestion['target_post_id'];
            
            // Check if target post has reached inbound limit
            if ($max_inbound > 0) {
                $inbound = self::count_inbound_links($target_id);
                if ($inbound >= $max_inbound) {
                    continue; // Skip this suggestion
                }
            }
            
            // Check if source post has reached outbound limit
            if ($max_outbound > 0) {
                $outbound = self::count_outbound_links($source_post_id);
                if ($outbound >= $max_outbound) {
                    continue; // Skip this suggestion
                }
            }
            
            $filtered[] = $suggestion;
        }
        
        return $filtered;
    }
    
    /**
     * Get comprehensive link report for all posts
     * 
     * @return array Link statistics for all posts
     */
    public static function get_site_link_report() {
        $posts = get_posts([
            'post_type' => 'post',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'fields' => 'ids'
        ]);
        
        $report = [];
        
        foreach ($posts as $post_id) {
            $report[] = self::get_post_link_stats($post_id);
        }
        
        // Sort by inbound links (most linked first)
        usort($report, function($a, $b) {
            return $b['inbound'] - $a['inbound'];
        });
        
        return $report;
    }
}

// Initialize
TL_Link_Counter::init();