<?php
/**
 * TexoLink Gutenberg Integration
 */

if (!defined('ABSPATH')) {
    exit;
}

class TexoLink_Gutenberg {
    
    /**
     * Constructor
     */
    public function __construct() {
        add_action('enqueue_block_editor_assets', array($this, 'enqueue_editor_assets'));
    }
    
    /**
     * Enqueue Gutenberg editor assets
     */
    public function enqueue_editor_assets() {
        // Check if we're in the block editor
        if (!function_exists('get_current_screen')) {
            return;
        }
        
        $screen = get_current_screen();
        if (!$screen || !$screen->is_block_editor()) {
            return;
        }
        
        // Enqueue editor script
        wp_enqueue_script(
            'texolink-editor',
            TEXOLINK_PLUGIN_URL . 'assets/js/editor.js',
            array(
                'wp-plugins',
                'wp-edit-post',
                'wp-element',
                'wp-components',
                'wp-data',
                'jquery'
            ),
            TEXOLINK_VERSION,
            true
        );
        
        // Enqueue editor styles
        wp_enqueue_style(
            'texolink-editor',
            TEXOLINK_PLUGIN_URL . 'assets/css/editor.css',
            array(),
            TEXOLINK_VERSION
        );
        
        // Pass data to JavaScript
        wp_localize_script('texolink-editor', 'texolinkEditor', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('texolink_nonce'),
            'postId' => get_the_ID()
        ));
    }
}

// Initialize
new TexoLink_Gutenberg();
