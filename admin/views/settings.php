<?php
/**
 * TexoLink Settings Page v2.1
 * Enhanced with Target Keywords + Link Intelligence
 */

if (!defined('ABSPATH')) {
    exit;
}

// Handle settings save
if (isset($_POST['texolink_save_settings']) && check_admin_referer('texolink_settings', 'texolink_nonce')) {
    texolink_save_settings();
    // Mark that settings changed
    update_option('texolink_settings_changed', true);
    
    // Show regeneration notice
    echo '<div class="notice notice-warning is-dismissible">';
    echo '<p><strong>⚠️ Generation settings updated!</strong></p>';
    echo '<p>Click <a href="' . admin_url('admin.php?page=texolink') . '" class="button button-primary">Regenerate Now</a> to apply changes (20-30 min)</p>';
    echo '<p><small>💡 Filtering settings (max links, blacklist) apply instantly. Generation settings require regeneration.</small></p>';
    echo '</div>';
}

// Handle sync to backend
if (isset($_POST['texolink_sync_settings']) && check_admin_referer('texolink_settings', 'texolink_nonce')) {
    texolink_sync_to_backend();
}


// Get current settings
$api_url = get_option('texolink_api_url', '');
$site_description = get_option('texolink_site_description', '');
$min_word_count = get_option('texolink_min_word_count', 2);
$max_word_count = get_option('texolink_max_word_count', 4);
$prefer_proper_nouns = get_option('texolink_prefer_proper_nouns', true);
$blacklist = get_option('texolink_blacklist', '');
$target_keywords = get_option('texolink_target_keywords', '');
$max_inbound_links = get_option('texolink_max_inbound_links', 10);
$max_outbound_links = get_option('texolink_max_outbound_links', 5);
$debug_mode = get_option('texolink_debug_mode', 0);

// Test connection
$api_client = new TexoLink_API_Client();
$connection_status = $api_client->test_connection();

// Get stats
global $wpdb;
$table_name = $wpdb->prefix . 'texolink_posts';
$suggestions_table = $wpdb->prefix . 'texolink_suggestions';

$analyzed_count = 0;
$suggestions_count = 0;

if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name) {
    $analyzed_count = $wpdb->get_var("SELECT COUNT(*) FROM $table_name");
}

if ($wpdb->get_var("SHOW TABLES LIKE '$suggestions_table'") == $suggestions_table) {
    $suggestions_count = $wpdb->get_var("SELECT COUNT(*) FROM $suggestions_table");
}
?>

<div class="wrap">
    <h1>⚙️ <?php echo esc_html(get_admin_page_title()); ?></h1>
    <?php
// Check if settings have changed since last generation
global $wpdb;
$table = $wpdb->prefix . 'texolink_inserted_links';
$last_gen = $wpdb->get_var("SELECT MAX(inserted_at) FROM $table");
$settings_changed = get_option('texolink_settings_changed', false);
?>

<div class="notice notice-info" style="margin: 20px 0; padding: 15px; border-left: 4px solid #2271b1;">
    <?php if ($last_gen): ?>
        <p><strong>📊 Last Generated:</strong> <?php echo human_time_diff(strtotime($last_gen), current_time('timestamp')); ?> ago</p>
    <?php else: ?>
        <p><strong>📊 Status:</strong> No suggestions generated yet</p>
    <?php endif; ?>
    
    <?php if ($settings_changed): ?>
        <p style="color: #d63638; font-weight: 600;">
            ⚠️ Settings changed! <a href="<?php echo admin_url('admin.php?page=texolink'); ?>">Regenerate to apply changes →</a>
        </p>
    <?php endif; ?>
</div>
    <?php settings_errors('texolink_messages'); ?>
    
    <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 20px; margin-top: 20px;">
        <!-- Main Settings Column -->
        <div>
            <form method="post" action="">
                <?php wp_nonce_field('texolink_settings', 'texolink_nonce'); ?>
                
                <!-- API Configuration Section -->
                <div class="texolink-settings-section">
                    <h2>🔌 API Configuration</h2>
                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row">
                                <label for="texolink_api_url">Backend API URL</label>
                            </th>
                            <td>
                                <input type="url" 
                                       id="texolink_api_url" 
                                       name="texolink_api_url" 
                                       value="<?php echo esc_attr($api_url); ?>" 
                                       class="regular-text" 
                                       placeholder="https://app.texolink.com" 
                                       required>
                                <p class="description">Your TexoLink backend API URL (without trailing slash)</p>
                                <?php if ($connection_status): ?>
                                    <p class="description" style="color: #46b450;">
                                        <span class="dashicons dashicons-yes-alt"></span> Connected successfully
                                    </p>
                                <?php else: ?>
                                    <p class="description" style="color: #dc3232;">
                                        <span class="dashicons dashicons-warning"></span> Not connected - check your URL
                                    </p>
                                <?php endif; ?>
                            </td>
                        </tr>
                    </table>
                </div>
                
                <!-- Target Keywords Section (NEW!) -->
                <div class="texolink-settings-section" style="margin-top: 30px; border-left: 4px solid #4caf50;">
                    <h2>🎯 Target Keywords (Try and Find These)</h2>
                    <p style="color: #666; margin-bottom: 20px;">
                        <strong>NEW!</strong> Tell TexoLink which keywords you want to rank for. The system will heavily boost anchor text containing these keywords, helping you build topical authority and rank for your target terms.
                    </p>
                    
                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row">
                                <label for="texolink_target_keywords">Industry Keywords You Want to Rank For</label>
                            </th>
                            <td>
                                <textarea id="texolink_target_keywords" 
                                          name="texolink_target_keywords" 
                                          rows="4" 
                                          class="large-text"
                                          placeholder="wordpress seo, internal linking, content optimization, seo best practices"><?php echo esc_textarea($target_keywords); ?></textarea>
                                <p class="description">
                                    <strong>Enter keywords separated by commas.</strong> These are the terms you want to rank for in search engines.
                                </p>
                                <p class="description" style="background: #e8f5e9; padding: 10px; border-radius: 4px; margin-top: 10px;">
                                    <strong>💡 How it works:</strong> When generating anchor text, phrases containing these keywords get a <strong>+30 point boost</strong>!<br>
                                    Example: If you enter "WordPress SEO", anchors like "WordPress SEO best practices" will be prioritized over generic terms.
                                </p>
                                <p class="description">
                                    <strong>Examples:</strong><br>
                                    • Blog niche: "healthy recipes, meal prep, nutrition tips"<br>
                                    • SaaS: "project management software, team collaboration, workflow automation"<br>
                                    • Local business: "plumber in [city], emergency plumbing, drain cleaning"
                                </p>
                            </td>
                        </tr>
                    </table>
                </div>
                
                <!-- Link Intelligence Section (NEW!) -->
                <div class="texolink-settings-section" style="margin-top: 30px; border-left: 4px solid #2196f3;">
                    <h2>🔗 Link Intelligence & Limits</h2>
                    <h2 style="background: #f0fdf4; padding: 12px; margin: 30px 0 10px; border-left: 4px solid #10b981;">
    ⚡ Display Filters <span style="color: #10b981; font-size: 14px;">(Apply Instantly)</span>
</h2>
<p style="color: #666; margin-bottom: 20px;">These settings filter what you see in Link Suggestions. Changes take effect immediately.</p>
                    <p style="color: #666; margin-bottom: 20px;">
                        <strong>NEW!</strong> Control how many links can point to and from each post. Prevents over-optimization and helps distribute links evenly across your site.
                    </p>
                    
                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row">
                                <label for="texolink_max_inbound_links">Max Inbound Links Per Post</label>
                            </th>
                            <td>
                                <input type="number" 
                                       id="texolink_max_inbound_links" 
                                       name="texolink_max_inbound_links" 
                                       value="<?php echo esc_attr($max_inbound_links); ?>" 
                                       min="1" 
                                       max="50"
                                       style="width: 100px;">
                                <span style="color: #666;">links pointing TO each post/page</span>
                                <p class="description">
                                    Maximum number of internal links that can point TO each post. 
                                    <strong>Recommended: 5-15</strong> depending on site size.
                                </p>
                                <p class="description">
                                    When a post reaches this limit, it will stop appearing in suggestions to prevent over-optimization.
                                </p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label for="texolink_max_outbound_links">Max Outbound Links Per Post</label>
                            </th>
                            <td>
                                <input type="number" 
                                       id="texolink_max_outbound_links" 
                                       name="texolink_max_outbound_links" 
                                       value="<?php echo esc_attr($max_outbound_links); ?>" 
                                       min="1" 
                                       max="20"
                                       style="width: 100px;">
                                <span style="color: #666;">links going FROM each post/page</span>
                                <p class="description">
                                    Maximum number of internal links that can go FROM each post. 
                                    <strong>Recommended: 3-7</strong> for natural linking.
                                </p>
                                <p class="description">
                                    When a post reaches this limit, no more suggestions will be shown for it.
                                </p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label for="texolink_debug_mode">Debug Mode</label>
                            </th>
                            <td>
                                <label for="texolink_debug_mode">
                                    <input type="checkbox" 
                                           id="texolink_debug_mode" 
                                           name="texolink_debug_mode" 
                                           value="1" 
                                           <?php checked($debug_mode, 1); ?> />
                                    <span>Enable detailed console logging for troubleshooting</span>
                                </label>
                                <p class="description">
                                    🔍 When enabled, TexoLink will output detailed debug information to your browser's console (F12). 
                                    This is helpful when diagnosing issues or working with support.
                                </p>
                                <p class="description" style="color: #d63638;">
                                    <strong>Note:</strong> Leave this OFF in production unless troubleshooting. Debug logs can slow down the interface slightly.
                                </p>
                            </td>
                        </tr>
                    </table>
                    
                    <div style="background: #e3f2fd; padding: 15px; border-radius: 4px; margin-top: 15px;">
                        <p style="margin: 0; font-size: 13px; line-height: 1.6;">
                            <strong>📊 Dynamic Updates:</strong> If you increase these limits later, posts that were previously "full" will automatically show suggestions again! The system tracks current link counts in real-time.
                        </p>
                    </div>
                </div>
                
                <!-- Anchor Text Intelligence Section -->
                <div class="texolink-settings-section" style="margin-top: 30px;">
                    <h2>✍️ Anchor Text Intelligence</h2>
                    <h2 style="background: #f0f6fc; padding: 12px; margin: 20px 0 10px; border-left: 4px solid #2271b1;">
    🎯 AI Generation Settings <span style="color: #d63638; font-size: 14px;">(Requires Regeneration)</span>
</h2>
<p style="color: #666; margin-bottom: 20px;">These settings affect how Railway generates suggestions. Changes require clicking "Analyze & Generate" button.</p>
<p style="color: #666; margin-bottom: 20px;">These settings filter what you see in Link Suggestions. Changes take effect immediately.</p>
                    <p style="color: #666; margin-bottom: 20px;">
                        Control how TexoLink suggests anchor text for your internal links. These settings help create professional, natural-looking links that enhance SEO.
                    </p>
                    
                    <table class="form-table" role="presentation">
                        <!-- Site Description -->
                        <tr>
                            <th scope="row">
                                <label for="texolink_site_description">What is your site about?</label>
                            </th>
                            <td>
                                <textarea id="texolink_site_description" 
                                          name="texolink_site_description" 
                                          rows="3" 
                                          class="large-text"
                                          placeholder="e.g., WordPress tutorials, SEO guides, and digital marketing strategies for small businesses"><?php echo esc_textarea($site_description); ?></textarea>
                                <p class="description">
                                    Helps the AI understand your site's context and suggest more relevant anchor text.
                                    <strong>Be specific!</strong> Include your niche, topics, and target audience.
                                </p>
                            </td>
                        </tr>
                        
                        <!-- Word Count Range -->
                        <tr>
                            <th scope="row">
                                <label>Anchor Text Length</label>
                            </th>
                            <td>
                                <div style="display: flex; align-items: center; gap: 10px;">
                                    <label for="texolink_min_word_count">Min:</label>
                                    <input type="number" 
                                           id="texolink_min_word_count" 
                                           name="texolink_min_word_count" 
                                           value="<?php echo esc_attr($min_word_count); ?>" 
                                           min="1" 
                                           max="10" 
                                           style="width: 70px;">
                                    
                                    <label for="texolink_max_word_count">Max:</label>
                                    <input type="number" 
                                           id="texolink_max_word_count" 
                                           name="texolink_max_word_count" 
                                           value="<?php echo esc_attr($max_word_count); ?>" 
                                           min="1" 
                                           max="10" 
                                           style="width: 70px;">
                                    <span style="color: #666;">words</span>
                                </div>
                                <p class="description">
                                    Recommended: <strong>2-4 words</strong> for professional, natural anchor text.
                                    Single-word anchors can look spammy.
                                </p>
                            </td>
                        </tr>
                        
                        <!-- Prefer Proper Nouns -->
                        <tr>
                            <th scope="row">
                                <label for="texolink_prefer_proper_nouns">Anchor Text Style</label>
                            </th>
                            <td>
                                <fieldset>
                                    <label>
                                        <input type="checkbox" 
                                               id="texolink_prefer_proper_nouns" 
                                               name="texolink_prefer_proper_nouns" 
                                               value="1" 
                                               <?php checked($prefer_proper_nouns, true); ?>>
                                        <strong>Prefer proper nouns</strong> (company names, product names, brands, etc.)
                                    </label>
                                    <p class="description">
                                        When enabled, TexoLink will favor capitalized terms like "WordPress," "Google Analytics," "iPhone" as anchor text.
                                        This creates more specific, professional links.
                                    </p>
                                </fieldset>
                            </td>
                        </tr>
                        
                        <!-- Blacklist -->
                        <tr>
                            <th scope="row">
                                <label for="texolink_blacklist">Never Suggest These Words - (Blacklist)</label>
                            </th>
                            <td>
                                <textarea id="texolink_blacklist" 
                                          name="texolink_blacklist" 
                                          rows="6" 
                                          class="large-text code"
                                          placeholder="One word per line, e.g.:&#10;content&#10;automated&#10;click here&#10;read more"><?php echo esc_textarea($blacklist); ?></textarea>
                                <p class="description">
                                    Add words or phrases you never want as anchor text (one per line). 
                                    <strong>Default includes:</strong> content, post, article, click, here, automated, SEO, etc.
                                </p>
                                <p class="description">
                                    <strong>💡 Tip:</strong> Add generic words, navigation terms, or overused phrases from your content.
                                </p>
                            </td>
                        </tr>
                    </table>
                </div>
                
                <!-- Submit Buttons -->
                <div style="margin-top: 30px; padding: 20px; background: #f0f0f1; border-radius: 4px;">
                    <p class="submit" style="margin: 0;">
                        <button type="submit" name="texolink_save_settings" class="button button-primary button-large">
                            💾 Save All Settings
                        </button>
                        
                        <button type="submit" name="texolink_sync_settings" class="button button-secondary" style="margin-left: 10px;">
                            🔄 Save & Sync to Backend
                        </button>
                    </p>
                    <p class="description" style="margin: 10px 0 0 0;">
                        <strong>Save:</strong> Stores settings locally.<br>
                        <strong>Save & Sync:</strong> Stores locally AND syncs to your backend API for immediate effect on new suggestions.
                    </p>
                </div>
            </form>
        </div>
        
        <!-- Sidebar Column -->
        <div>
            <!-- Quick Stats -->
            <div class="texolink-settings-section">
                <h3>📊 Quick Stats</h3>
                <ul style="list-style: none; padding: 0; margin: 0;">
                    <li style="padding: 10px 0; border-bottom: 1px solid #ddd;">
                        <strong><?php echo number_format($analyzed_count); ?></strong> posts analyzed
                    </li>
                    <li style="padding: 10px 0; border-bottom: 1px solid #ddd;">
                        <strong><?php echo number_format($suggestions_count); ?></strong> suggestions generated
                    </li>
                    <li style="padding: 10px 0;">
                        API Status: <strong style="color: <?php echo $connection_status ? '#46b450' : '#dc3232'; ?>;">
                            <?php echo $connection_status ? 'Connected' : 'Not Configured'; ?>
                        </strong>
                    </li>
                </ul>
            </div>
            
            <!-- Link Limits Guide -->
            <div class="texolink-settings-section" style="margin-top: 20px; background: #e3f2fd; border-left: 4px solid #2196f3;">
                <h3 style="color: #1976d2;">🔗 Link Limit Guidelines</h3>
                <ul style="font-size: 13px; line-height: 1.6;">
                    <li><strong>Small site</strong> (< 50 posts): 5 in / 3 out</li>
                    <li><strong>Medium site</strong> (50-200 posts): 10 in / 5 out</li>
                    <li><strong>Large site</strong> (200+ posts): 15 in / 7 out</li>
                    <li>Adjust based on your content depth</li>
                    <li>Cornerstone content can have more inbound</li>
                </ul>
            </div>
            
            <!-- Anchor Text Tips -->
            <div class="texolink-settings-section" style="margin-top: 20px;">
                <h3>💡 Anchor Text Best Practices</h3>
                <ul style="font-size: 13px; line-height: 1.6;">
                    <li><strong>2-4 words</strong> is ideal for natural links</li>
                    <li>Avoid <strong>generic phrases</strong> like "click here"</li>
                    <li>Use <strong>descriptive terms</strong> from your content</li>
                    <li><strong>Proper nouns</strong> make great anchors</li>
                    <li>Blacklist overused words in your niche</li>
                    <li>Never use the exact post title as anchor</li>
                    <li><strong>Target keywords</strong> help you rank faster!</li>
                </ul>
            </div>
            
            <!-- What's New -->
            <div class="texolink-settings-section" style="margin-top: 20px; background: #e8f5e9; border-left: 4px solid #4caf50;">
                <h3 style="color: #2e7d32;">🎉 New in v2.1</h3>
                <ul style="font-size: 13px; line-height: 1.6; color: #333;">
                    <li><strong>Target Keywords</strong> - Rank for YOUR terms with +30 point boost</li>
                    <li><strong>Link Limits</strong> - Control max inbound/outbound links per post</li>
                    <li><strong>Real-Time Counting</strong> - See current link counts on every suggestion</li>
                    <li><strong>Large AI Model</strong> - 560MB spaCy model for better accuracy</li>
                    <li><strong>Dynamic Updates</strong> - Increase limits anytime, suggestions reappear</li>
                </ul>
            </div>

<!-- DANGER ZONE - RESET BUTTONS -->
<div class="card" style="max-width: 600px; margin: 20px 0; border-left: 4px solid #dc3545;">
    <h2>⚠️ Danger Zone</h2>
    
    <p><strong>Reset WordPress Cache:</strong> Clear local cache and reload data from Railway.</p>
    <button id="reset-local-cache-btn" class="button">
        Reset Local Cache
    </button>
    
    <hr style="margin: 20px 0;">
    
    <p><strong>Reset All Data (WordPress + Railway):</strong> This will delete ALL suggestions, embeddings, and keywords from both WordPress AND Railway backend. You'll need to re-generate everything.</p>
    <button id="reset-all-data-btn" class="button button-danger" style="background: #dc3545; border-color: #dc3545; color: white;">
        Reset All TexoLink Data
    </button>
    
    <div id="reset-status" style="display: none; margin-top: 15px; padding: 12px;">
        <!-- Reset status messages appear here -->
    </div>
</div>

<style>
.button-danger:hover {
    background: #c82333 !important;
    border-color: #bd2130 !important;
}
</style>
        </div>
    </div>
</div>

<style>
.texolink-settings-section {
    background: white;
    padding: 20px;
    border: 1px solid #ccd0d4;
    border-radius: 4px;
    box-shadow: 0 1px 1px rgba(0,0,0,.04);
}
.texolink-settings-section h2 {
    margin-top: 0;
    padding-bottom: 10px;
    border-bottom: 1px solid #ddd;
}
.texolink-settings-section h3 {
    margin-top: 0;
    font-size: 14px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
</style>

<?php

/**
 * Save settings to WordPress options
 */
function texolink_save_settings() {
    // Sanitize and save all settings
    update_option('texolink_api_url', esc_url_raw(rtrim($_POST['texolink_api_url'], '/')));
    update_option('texolink_site_description', sanitize_textarea_field($_POST['texolink_site_description']));
    update_option('texolink_min_word_count', absint($_POST['texolink_min_word_count']));
    update_option('texolink_max_word_count', absint($_POST['texolink_max_word_count']));
    update_option('texolink_prefer_proper_nouns', isset($_POST['texolink_prefer_proper_nouns']));
    update_option('texolink_blacklist', sanitize_textarea_field($_POST['texolink_blacklist']));
    
    // NEW: Save target keywords and link limits
    update_option('texolink_target_keywords', sanitize_textarea_field($_POST['texolink_target_keywords']));
    update_option('texolink_max_inbound_links', absint($_POST['texolink_max_inbound_links']));
    update_option('texolink_max_outbound_links', absint($_POST['texolink_max_outbound_links']));
    
    // NEW: Save debug mode
    update_option('texolink_debug_mode', isset($_POST['texolink_debug_mode']) ? 1 : 0);
    
    add_settings_error(
        'texolink_messages',
        'texolink_message',
        'Settings saved successfully!',
        'success'
    );
}

/**
 * Save settings AND sync to backend API
 */
function texolink_sync_to_backend() {
    // First save locally
    texolink_save_settings();
    
    // Then sync to backend
    $api_url = get_option('texolink_api_url');
    
    if (empty($api_url)) {
        add_settings_error(
            'texolink_messages',
            'texolink_message',
            'Please configure your API URL first!',
            'error'
        );
        return;
    }
    
    // Prepare settings data
    $blacklist_text = get_option('texolink_blacklist', '');
    $blacklist_array = array_filter(array_map('trim', explode("\n", $blacklist_text)));
    
    // NEW: Parse target keywords
    $target_keywords_text = get_option('texolink_target_keywords', '');
    $target_keywords_array = array_filter(array_map('trim', explode(',', $target_keywords_text)));
    
    $settings_data = array(
        'site_domain' => parse_url(get_site_url(), PHP_URL_HOST),
        'site_description' => get_option('texolink_site_description', ''),
        'min_word_count' => absint(get_option('texolink_min_word_count', 2)),
        'max_word_count' => absint(get_option('texolink_max_word_count', 4)),
        'prefer_proper_nouns' => (bool) get_option('texolink_prefer_proper_nouns', true),
        'blacklist' => $blacklist_array,
        // NEW FIELDS:
        'target_keywords' => $target_keywords_array,
        'max_inbound_links' => absint(get_option('texolink_max_inbound_links', 10)),
        'max_outbound_links' => absint(get_option('texolink_max_outbound_links', 5))
    );
    
    // Send to backend
    $response = wp_remote_post($api_url . '/settings', array(
        'headers' => array('Content-Type' => 'application/json'),
        'body' => json_encode($settings_data),
        'timeout' => 15
    ));
    
    if (is_wp_error($response)) {
        add_settings_error(
            'texolink_messages',
            'texolink_message',
            'Settings saved locally, but failed to sync to backend: ' . $response->get_error_message(),
            'warning'
        );
        return;
    }
    
    $body = json_decode(wp_remote_retrieve_body($response), true);
    
    if (isset($body['success']) && $body['success']) {
        add_settings_error(
            'texolink_messages',
            'texolink_message',
            '✅ Settings saved and synced to backend successfully!',
            'success'
        );
    } else {
        add_settings_error(
            'texolink_messages',
            'texolink_message',
            'Settings saved locally, but sync failed: ' . ($body['error'] ?? 'Unknown error'),
            'warning'
        );
    }
}
