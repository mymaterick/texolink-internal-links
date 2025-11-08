<?php
/**
 * TexoLink Link Inserter
 * 
 * Handles inserting internal links into post content
 */

if (!defined('ABSPATH')) {
    exit;
}

class TexoLink_Link_Inserter {
    
    /**
     * Insert a link into post content
     * FIXED: Only uses exact anchor text, no fallbacks
     */
    public function insert_link($post_id, $target_post_id, $anchor_text, $context = '') {
        $post = get_post($post_id);
        $target_post = get_post($target_post_id);
        
        if (!$post || !$target_post) {
            return false;
        }
        
        $content = $post->post_content;
        $target_url = get_permalink($target_post_id);
        
        // Validate anchor text
        if (empty($anchor_text)) {
            return false;
        }
        
        // Check if anchor text exists in paragraph content (not headings)
        $result = $this->check_anchor_in_content($content, $anchor_text);
        
        if (!$result['found'] || $result['in_heading']) {
            return false;
        }
        
        // Create the link HTML
        $link_html = sprintf(
            '<a href="%s" class="texolink-link">%s</a>',
            esc_url($target_url),
            esc_html($anchor_text)
        );
        
        // Insert the link
        $new_content = $this->insert_at_position($content, $anchor_text, $link_html);
        
        if ($new_content === $content) {
            return false;
        }
        
        // Update the post
        $result = wp_update_post(array(
            'ID' => $post_id,
            'post_content' => $new_content,
        ));
        
        if (is_wp_error($result)) {
            return false;
        }
        
        // Log the link creation
        $this->log_link($post_id, $target_post_id, $anchor_text);
        
        // Notify backend API
        $api_client = new TexoLink_API_Client();
        $api_client->create_link($post_id, $target_post_id, $anchor_text, 1.0);
        
        return true;
    }
    
    /**
     * Check if anchor text exists in content and not in headings
     */
    private function check_anchor_in_content($content, $anchor_text) {
        $found_anywhere = false;
        $found_in_paragraph = false;
        
        // Load HTML into DOMDocument
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $html_content = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body>' . $content . '</body></html>';
        $dom->loadHTML($html_content, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        
        $body = $dom->getElementsByTagName('body')->item(0);
        
        if (!$body) {
            // Fallback: check in plain text with word boundaries
            $plain_content = wp_strip_all_tags($content);
            $pattern = '/\b' . preg_quote($anchor_text, '/') . '\b/iu';
            $found_anywhere = preg_match($pattern, $plain_content) === 1;
            return array(
                'found' => $found_anywhere,
                'in_heading' => false
            );
        }
        
        // Recursively check all text nodes
        $this->search_anchor_in_nodes($body, $anchor_text, $found_anywhere, $found_in_paragraph);
        
        return array(
            'found' => $found_anywhere,
            'in_heading' => $found_anywhere && !$found_in_paragraph
        );
    }
    
    /**
     * Recursively search for anchor text in DOM nodes
     * Uses word boundaries for exact phrase matching
     */
    private function search_anchor_in_nodes($node, $anchor_text, &$found_anywhere, &$found_in_paragraph) {
        if ($node->nodeType === XML_TEXT_NODE) {
            $text = $node->nodeValue;
            
            // Use regex with word boundaries for exact phrase matching
            $pattern = '/\b' . preg_quote($anchor_text, '/') . '\b/iu';
            
            if (preg_match($pattern, $text)) {
                $found_anywhere = true;
                
                // Check if inside a heading
                $parent = $node->parentNode;
                $in_heading = false;
                
                while ($parent) {
                    $nodeName = strtolower($parent->nodeName);
                    
                    if (in_array($nodeName, array('h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'li', 'ul', 'ol'))) {
                        $in_heading = true;
                        break;
                    }
                    
                    if ($nodeName === 'body') {
                        break;
                    }
                    
                    $parent = $parent->parentNode;
                }
                
                if (!$in_heading) {
                    $found_in_paragraph = true;
                }
            }
        }
        
        // Recursively check child nodes
        if ($node->hasChildNodes()) {
            foreach ($node->childNodes as $child) {
                $this->search_anchor_in_nodes($child, $anchor_text, $found_anywhere, $found_in_paragraph);
            }
        }
    }
    
    /**
     * Insert link at specific position in content
     * Uses DOM parsing to avoid inserting in headings
     */
    private function insert_at_position($content, $anchor_text, $link_html) {
        // Load HTML into DOMDocument
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $html_content = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body>' . $content . '</body></html>';
        $dom->loadHTML($html_content, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        
        $body = $dom->getElementsByTagName('body')->item(0);
        
        if (!$body) {
            // Fallback to simple replacement if DOM parsing fails
            $pattern = '/\b' . preg_quote($anchor_text, '/') . '\b/i';
            return preg_replace($pattern, $link_html, $content, 1);
        }
        
        // Find and replace the text
        $replaced = false;
        $this->replace_text_node($body, $anchor_text, $link_html, $dom, $replaced);
        
        if (!$replaced) {
            // Fallback to simple replacement
            $pattern = '/\b' . preg_quote($anchor_text, '/') . '\b/i';
            return preg_replace($pattern, $link_html, $content, 1);
        }
        
        // Extract just the body content
        $new_content = '';
        foreach ($body->childNodes as $node) {
            $new_content .= $dom->saveHTML($node);
        }
        
        return $new_content;
    }
    
    /**
     * Replace text in DOM node, skipping headings
     * Uses word boundaries for exact phrase matching
     */
    private function replace_text_node($node, $anchor_text, $link_html, $dom, &$replaced) {
        if ($replaced) {
            return;
        }
        
        if ($node->nodeType === XML_TEXT_NODE) {
            $text = $node->nodeValue;
            
            // Use regex with word boundaries for exact phrase matching
            $pattern = '/\b' . preg_quote($anchor_text, '/') . '\b/iu';
            
            if (preg_match($pattern, $text, $matches, PREG_OFFSET_CAPTURE)) {
                // Check if we're inside a heading
                $parent = $node->parentNode;
                $in_heading = false;
                
                while ($parent) {
                    $nodeName = strtolower($parent->nodeName);
                    if (in_array($nodeName, array('h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'li', 'ul', 'ol'))) {
                        $in_heading = true;
                        break;
                    }
                    if ($nodeName === 'body') {
                        break;
                    }
                    $parent = $parent->parentNode;
                }
                
                // Only replace if NOT in heading or list
                if (!$in_heading) {
                    $pos = $matches[0][1];
                    $actual_text = $matches[0][0];
                    $before = substr($text, 0, $pos);
                    $after = substr($text, $pos + strlen($actual_text));
                    
                    $parent = $node->parentNode;
                    
                    if ($before !== '') {
                        $parent->insertBefore($dom->createTextNode($before), $node);
                    }
                    
                    // Parse the link HTML and insert it
                    $temp = $dom->createDocumentFragment();
                    $temp->appendXML($link_html);
                    $parent->insertBefore($temp, $node);
                    
                    if ($after !== '') {
                        $parent->insertBefore($dom->createTextNode($after), $node);
                    }
                    
                    $parent->removeChild($node);
                    $replaced = true;
                    return;
                }
            }
        }
        
        // Recursively process children, skipping certain tags
        if ($node->hasChildNodes()) {
            $nodeName = strtolower($node->nodeName);
            
            if (in_array($nodeName, array('script', 'style', 'a', 'code', 'pre', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'li', 'ul', 'ol'))) {
                return;
            }
            
            $children = array();
            foreach ($node->childNodes as $child) {
                $children[] = $child;
            }
            
            foreach ($children as $child) {
                $this->replace_text_node($child, $anchor_text, $link_html, $dom, $replaced);
                if ($replaced) {
                    break;
                }
            }
        }
    }
    
    /**
     * Batch insert multiple links
     */
    public function batch_insert($links) {
        $results = array();
        
        foreach ($links as $link) {
            $post_id = $link['post_id'];
            $target_id = $link['target_id'];
            $anchor_text = $link['anchor_text'];
            $context = $link['context'] ?? '';
            
            $results[] = array(
                'post_id' => $post_id,
                'target_id' => $target_id,
                'success' => $this->insert_link($post_id, $target_id, $anchor_text, $context)
            );
        }
        
        return $results;
    }
    
    /**
     * Check if a link already exists
     */
    public function link_exists($post_id, $target_post_id) {
        $post = get_post($post_id);
        $target_url = get_permalink($target_post_id);
        
        if (!$post) {
            return false;
        }
        
        return strpos($post->post_content, $target_url) !== false;
    }
    
    /**
     * Remove a link from post content
     */
    public function remove_link($post_id, $target_post_id) {
        $post = get_post($post_id);
        $target_url = get_permalink($target_post_id);
        
        if (!$post) {
            return false;
        }
        
        $content = $post->post_content;
        
        // Remove link but keep anchor text
        $pattern = '/<a[^>]+href=["\']' . preg_quote($target_url, '/') . '["\'][^>]*>(.*?)<\/a>/i';
        $new_content = preg_replace($pattern, '$1', $content);
        
        // Update post
        wp_update_post(array(
            'ID' => $post_id,
            'post_content' => $new_content,
        ));
        
        return true;
    }
    
    /**
     * Get all links in a post
     */
    public function get_post_links($post_id) {
        $post = get_post($post_id);
        
        if (!$post) {
            return array();
        }
        
        // Extract all links from content
        preg_match_all('/<a[^>]+href=["\'](.*?)["\'][^>]*>(.*?)<\/a>/i', $post->post_content, $matches);
        
        $links = array();
        
        for ($i = 0; $i < count($matches[0]); $i++) {
            $url = $matches[1][$i];
            $text = $matches[2][$i];
            
            // Check if it's an internal link
            if ($this->is_internal_link($url)) {
                $target_id = url_to_postid($url);
                
                $links[] = array(
                    'url' => $url,
                    'anchor_text' => strip_tags($text),
                    'target_id' => $target_id,
                    'is_texolink' => strpos($matches[0][$i], 'texolink-link') !== false
                );
            }
        }
        
        return $links;
    }
    
    /**
     * Check if a URL is internal
     */
    private function is_internal_link($url) {
        $home_url = home_url();
        return strpos($url, $home_url) === 0 || strpos($url, '/') === 0;
    }
    
    /**
     * Log link creation
     */
    private function log_link($post_id, $target_post_id, $anchor_text) {
        global $wpdb;
        
        $wpdb->insert(
            $wpdb->prefix . 'texolink_suggestions',
            array(
                'post_id' => $post_id,
                'target_post_id' => $target_post_id,
                'anchor_text' => $anchor_text,
                'relevance_score' => 1.0,
                'status' => 'applied',
                'created_at' => current_time('mysql')
            ),
            array('%d', '%d', '%s', '%f', '%s', '%s')
        );
    }
    
    /**
     * Get link statistics for a post
     */
    public function get_post_stats($post_id) {
        $links = $this->get_post_links($post_id);
        
        return array(
            'total_links' => count($links),
            'texolink_links' => count(array_filter($links, function($link) {
                return $link['is_texolink'];
            })),
            'manual_links' => count(array_filter($links, function($link) {
                return !$link['is_texolink'];
            })),
        );
    }
}
