<?php
/**
 * TexoLink Dashboard Page
 */

if (!defined('ABSPATH')) {
    exit;
}

$api_client = new TexoLink_API_Client();
$status = $api_client->get_status();

// Safely get overview data with error handling
$overview = null;
$orphaned = array();

if ($status['connected']) {
    try {
        $overview = $api_client->get_overview();
        $orphaned = $api_client->get_orphaned_posts();
    } catch (Exception $e) {
        // Silently fail - backend might not have data yet
        error_log('TexoLink Dashboard Error: ' . $e->getMessage());
    }
}

// Get WordPress stats
$post_count = wp_count_posts('post');
$page_count = wp_count_posts('page');
$total_content = $post_count->publish + $page_count->publish;

// Get internal link stats from WordPress database
$wp_link_stats = texolink_get_link_stats();
?>

<div class="wrap texolink-dashboard">
    <h1 class="wp-heading-inline">
        <?php echo esc_html(get_admin_page_title()); ?>
    </h1>
    
    <?php if (!$status['connected']): ?>
        <div class="notice notice-error">
            <p>
                <strong><?php _e('Not Connected', 'texolink'); ?></strong>
                <?php _e('Unable to connect to TexoLink API. Please check your settings.', 'texolink'); ?>
                <a href="<?php echo admin_url('admin.php?page=texolink-settings'); ?>"><?php _e('Go to Settings', 'texolink'); ?></a>
            </p>
        </div>
    <?php else: ?>
        <div class="notice notice-success">
            <p>
                <strong><?php _e('Connected', 'texolink'); ?></strong>
                <?php _e('TexoLink AI is analyzing your content!', 'texolink'); ?>
            </p>
        </div>
    <?php endif; ?>

    <!-- Stats Grid -->
    <div class="texolink-stats-grid">
        <!-- Health Score -->
        <div class="texolink-stat-card texolink-stat-primary">
            <div class="stat-icon">
                <span class="dashicons dashicons-shield-alt"></span>
            </div>
            <div class="stat-content">
                <div class="stat-value">
                    <?php 
                    // Calculate health score based on actual WordPress links
                    $health_score = 0;
                    if ($total_content > 0) {
                        $target_links = $total_content * 3; // Target: 3 links per post
                        $health_score = min(100, round(($wp_link_stats['total_internal_links'] / max($target_links, 1)) * 100));
                    }
                    echo $health_score . '%';
                    ?>
                </div>
                <div class="stat-label"><?php _e('Site Health Score', 'texolink'); ?></div>
                <div class="stat-progress">
                    <div class="progress-bar" style="width: <?php echo $health_score; ?>%; background: <?php 
                        echo $health_score >= 70 ? '#10b981' : ($health_score >= 40 ? '#f59e0b' : '#ef4444'); 
                    ?>;"></div>
                </div>
            </div>
        </div>

        <!-- Total Posts -->
        <div class="texolink-stat-card">
            <div class="stat-icon">
                <span class="dashicons dashicons-admin-post"></span>
            </div>
            <div class="stat-content">
                <div class="stat-value"><?php echo $overview && isset($overview['total_posts']) ? $overview['total_posts'] : $total_content; ?></div>
                <div class="stat-label"><?php _e('Total Posts', 'texolink'); ?></div>
                <div class="stat-subtitle">
                    <?php printf(__('%d published', 'texolink'), $total_content); ?>
                </div>
            </div>
        </div>

        <!-- Internal Links -->
        <div class="texolink-stat-card">
            <div class="stat-icon">
                <span class="dashicons dashicons-admin-links"></span>
            </div>
            <div class="stat-content">
                <div class="stat-value"><?php echo $wp_link_stats['total_internal_links']; ?></div>
                <div class="stat-label"><?php _e('Internal Links', 'texolink'); ?></div>
                <div class="stat-subtitle">
                    <?php printf(__('%s avg per post', 'texolink'), $wp_link_stats['average_links_per_post']); ?>
                </div>
            </div>
        </div>

        <!-- Orphaned Posts -->
        <div class="texolink-stat-card texolink-stat-warning">
            <div class="stat-icon">
                <span class="dashicons dashicons-warning"></span>
            </div>
            <div class="stat-content">
                <div class="stat-value"><?php echo $wp_link_stats['orphaned_posts']; ?></div>
                <div class="stat-label"><?php _e('Orphaned Posts', 'texolink'); ?></div>
                <div class="stat-subtitle">
                    <?php _e('Need inbound links', 'texolink'); ?>
                </div>
            </div>
        </div>
    </div>

    <div class="texolink-dashboard-content">
<!-- Quick Actions -->
<div class="texolink-panel">
    <h2><?php _e('Quick Actions', 'texolink'); ?></h2>
    
    <!-- PRIMARY: Generate Suggestions (Big Hero Button) -->
    <div class="texolink-primary-action">
        <button id="generate-suggestions-btn" class="button button-primary button-hero texolink-mega-button" <?php echo !$status['connected'] ? 'disabled' : ''; ?>>
            <span class="dashicons dashicons-networking"></span>
            <div class="button-content">
                <div class="button-title"><?php _e('Analyze & Generate Link Suggestions', 'texolink'); ?></div>
                <div class="button-subtitle"><?php _e('Syncs posts & generates AI suggestions automatically (20-30 min)', 'texolink'); ?></div>
            </div>
        </button>
        
        <!-- Enhanced status display -->
        <div id="generation-status" class="generation-status-display"></div>
    </div>
    
    <!-- SECONDARY: Manual controls (collapsed) -->
    <details class="texolink-advanced-controls" style="margin-top: 20px;">
        <summary style="cursor: pointer; padding: 10px; background: #f8fafc; border-radius: 4px; font-weight: 600; color: #64748b;">
            <span class="dashicons dashicons-admin-tools"></span>
            <?php _e('Advanced: Manual Content Sync', 'texolink'); ?>
        </summary>
        <div class="texolink-analyze-section" style="margin-top: 15px; padding: 15px; background: #f8fafc; border-radius: 4px;">
            <p class="description" style="margin-bottom: 10px;">
                <?php _e('Usually not needed - the main button above does this automatically. Use only if you want to sync posts without generating suggestions.', 'texolink'); ?>
            </p>
            <div class="analyze-controls">
                <div class="post-type-selector">
                    <label for="analyze-post-types">
                        <span class="dashicons dashicons-filter"></span>
                        <?php _e('Content Type:', 'texolink'); ?>
                    </label>
                    <select id="analyze-post-types" class="post-type-select">
                        <option value="all" selected><?php _e('All Types', 'texolink'); ?></option>
                        <?php
                        // Get all public post types
                        $post_types = get_post_types(array('public' => true), 'objects');
                        foreach ($post_types as $post_type) {
                            if ($post_type->name !== 'attachment') {
                                $count = wp_count_posts($post_type->name);
                                $total = isset($count->publish) ? $count->publish : 0;
                                echo '<option value="' . esc_attr($post_type->name) . '">';
                                echo esc_html($post_type->labels->name) . ' (' . number_format($total) . ')';
                                echo '</option>';
                            }
                        }
                        ?>
                    </select>
                </div>
                
                <button id="analyze-all-posts-btn" class="button button-secondary" <?php echo !$status['connected'] ? 'disabled' : ''; ?>>
                    <span class="dashicons dashicons-update"></span>
                    <?php _e('Sync Posts Only', 'texolink'); ?>
                </button>
            </div>
        </div>
    </details>
    
    <div class="texolink-actions" style="margin-top: 20px;">
        <a href="<?php echo admin_url('admin.php?page=texolink-suggestions'); ?>" class="button button-secondary button-hero">
            <span class="dashicons dashicons-admin-links"></span>
            <?php _e('View Suggestions', 'texolink'); ?>
        </a>
        <a href="<?php echo admin_url('edit.php'); ?>" class="button button-secondary button-hero">
            <span class="dashicons dashicons-edit"></span>
            <?php _e('View Posts', 'texolink'); ?>
        </a>
        <a href="<?php echo admin_url('admin.php?page=texolink-settings'); ?>" class="button button-secondary button-hero">
            <span class="dashicons dashicons-admin-settings"></span>
            <?php _e('Settings', 'texolink'); ?>
        </a>
    </div>
</div>
        <!-- Recent Activity -->
        <div class="texolink-panel">
            <h2><?php _e('Recent Activity', 'texolink'); ?></h2>
            <?php
            global $wpdb;
            $recent_links = $wpdb->get_results(
    "SELECT * FROM {$wpdb->prefix}texolink_inserted_links 
    ORDER BY inserted_at DESC 
    LIMIT 10"
);
            ?>
            
            <?php if (empty($recent_links)): ?>
                <p class="texolink-empty">
                    <?php _e('No links created yet. Start by analyzing your posts!', 'texolink'); ?>
                </p>
            <?php else: ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php _e('Source Post', 'texolink'); ?></th>
                            <th><?php _e('Target Post', 'texolink'); ?></th>
                            <th><?php _e('Anchor Text', 'texolink'); ?></th>
                            <th><?php _e('inserted At', 'texolink'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recent_links as $link): ?>
                            <tr>
                                <td>
                                    <a href="<?php echo get_edit_post_link($link->source_post_id); ?>" target="_blank" rel="noopener noreferrer">
                                        <?php echo get_the_title($link->source_post_id); ?>
                                    </a>
                                </td>
                                <td>
                                    <a href="<?php echo get_edit_post_link($link->target_post_id); ?>" target="_blank" rel="noopener noreferrer">
                                        <?php echo get_the_title($link->target_post_id); ?>
                                    </a>
                                </td>
                                <td><code><?php echo esc_html($link->anchor_text); ?></code></td>
                                <td><code><?php echo esc_html($link->inserted_at); ?><code></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <!-- Orphaned Posts -->
        <?php if (!empty($orphaned)): ?>
        <div class="texolink-panel texolink-panel-warning">
            <h2>
                <span class="dashicons dashicons-warning"></span>
                <?php _e('Orphaned Posts Needing Attention', 'texolink'); ?>
            </h2>
            <p class="description">
                <?php _e('These posts have no inbound links and are hard for users to discover.', 'texolink'); ?>
            </p>
            <ul class="texolink-post-list">
                <?php foreach (array_slice($orphaned, 0, 5) as $post): ?>
                    <?php 
                    $wp_post_id = $wpdb->get_var($wpdb->prepare(
                        "SELECT ID FROM {$wpdb->posts} WHERE post_title = %s LIMIT 1",
                        $post['title']
                    ));
                    ?>
                    <li>
                        <a href="<?php echo get_edit_post_link($wp_post_id); ?>">
                            <?php echo esc_html($post['title']); ?>
                        </a>
                        <span class="post-meta"><?php echo number_format($post['word_count']); ?> words</span>
                    </li>
                <?php endforeach; ?>
            </ul>
            <?php if (count($orphaned) > 5): ?>
                <p class="texolink-more">
                    <?php printf(__('...and %d more', 'texolink'), count($orphaned) - 5); ?>
                </p>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- Tips & Recommendations -->
        <div class="texolink-panel texolink-panel-info">
            <h2>
                <span class="dashicons dashicons-lightbulb"></span>
                <?php _e('Recommendations', 'texolink'); ?>
            </h2>
            <ul class="texolink-tips">
                <?php if ($health_score < 50): ?>
                    <li class="tip-critical">
                        <strong><?php _e('Critical:', 'texolink'); ?></strong>
                        <?php _e('Your site health score is low. Focus on creating more internal links between related content.', 'texolink'); ?>
                    </li>
                <?php endif; ?>
                
                <?php if ($overview && isset($overview['orphaned_posts']) && $overview['orphaned_posts'] > 0): ?>
                    <li class="tip-warning">
                        <strong><?php _e('Important:', 'texolink'); ?></strong>
                        <?php printf(__('Fix %d orphaned posts...'), $wp_link_stats['orphaned_posts']); ?>
                    </li>
                <?php endif; ?>
                
                <?php if ($overview && isset($overview['optimization']['self_contained_posts']) && $overview['optimization']['self_contained_posts'] > 0): ?>
                    <li class="tip-info">
                        <strong><?php _e('Suggestion:', 'texolink'); ?></strong>
                        <?php printf(__('Add outbound links to %d self-contained posts to improve content discoverability.', 'texolink'), $overview['optimization']['self_contained_posts']); ?>
                    </li>
                <?php endif; ?>
                
                <?php if ($health_score >= 80): ?>
                    <li class="tip-success">
                        <strong><?php _e('Great job!', 'texolink'); ?></strong>
                        <?php _e('Your internal linking structure is healthy. Keep maintaining it by analyzing new posts.', 'texolink'); ?>
                    </li>
                <?php endif; ?>
                
                <li class="tip-info">
                    <span class="dashicons dashicons-info"></span>
                    <?php _e('Aim for 3-5 relevant internal links per post for optimal SEO performance.', 'texolink'); ?>
                </li>
            </ul>
        </div>
    </div>
</div>

<!-- Progress Modal -->
<div id="texolink-progress-modal" class="texolink-modal" style="display: none;">
    <div class="texolink-modal-overlay"></div>
    <div class="texolink-modal-content">
        <div class="modal-header">
            <h2><?php _e('Analyzing Content', 'texolink'); ?></h2>
        </div>
        <div class="modal-body">
            <div class="progress-info">
                <div class="progress-stats">
                    <span id="progress-current">0</span> / <span id="progress-total">0</span> <span id="progress-type-label"><?php _e('items', 'texolink'); ?></span>
                </div>
                <div class="progress-percentage" id="progress-percentage">0%</div>
            </div>
            <div class="progress-bar-wrapper">
                <div class="progress-bar" id="modal-progress-bar"></div>
            </div>
            <div class="progress-status" id="progress-status">
                <?php _e('Initializing...', 'texolink'); ?>
            </div>
            <div class="progress-details">
                <div class="detail-item">
                    <span class="detail-label"><?php _e('Success:', 'texolink'); ?></span>
                    <span class="detail-value success" id="success-count">0</span>
                </div>
                <div class="detail-item">
                    <span class="detail-label"><?php _e('Errors:', 'texolink'); ?></span>
                    <span class="detail-value error" id="error-count">0</span>
                </div>
            </div>
        </div>
        <div class="modal-footer">
            <button id="close-modal-btn" class="button button-secondary" disabled>
                <?php _e('Close', 'texolink'); ?>
            </button>
            <a href="<?php echo admin_url('admin.php?page=texolink'); ?>" id="refresh-dashboard-btn" class="button button-primary" style="display: none;">
                <?php _e('Refresh Dashboard', 'texolink'); ?>
            </a>
        </div>
    </div>
</div>

<style>
.texolink-modal {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    z-index: 100000;
}

.texolink-modal-overlay {
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0, 0, 0, 0.7);
}

.texolink-modal-content {
    position: relative;
    background: white;
    max-width: 600px;
    margin: 100px auto;
    border-radius: 8px;
    box-shadow: 0 10px 40px rgba(0, 0, 0, 0.3);
}

.texolink-modal .modal-header {
    padding: 20px 25px;
    border-bottom: 1px solid #e2e8f0;
}

.texolink-modal .modal-header h2 {
    margin: 0;
    font-size: 20px;
}

.texolink-modal .modal-body {
    padding: 25px;
}

.progress-info {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 15px;
}

.progress-stats {
    font-size: 16px;
    font-weight: 600;
    color: #1e293b;
}

.progress-percentage {
    font-size: 24px;
    font-weight: 700;
    color: #2271b1;
}

.progress-bar-wrapper {
    height: 8px;
    background: #e2e8f0;
    border-radius: 4px;
    overflow: hidden;
    margin-bottom: 20px;
}

.progress-bar-wrapper .progress-bar {
    height: 100%;
    background: linear-gradient(90deg, #2271b1, #135e96);
    transition: width 0.3s ease;
    width: 0%;
}

.progress-status {
    padding: 15px;
    background: #f8fafc;
    border-radius: 6px;
    margin-bottom: 20px;
    font-size: 14px;
    color: #64748b;
    min-height: 50px;
}

.progress-details {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 15px;
}

.detail-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 12px 15px;
    background: #f8fafc;
    border-radius: 6px;
}

.detail-label {
    font-size: 14px;
    color: #64748b;
}

.detail-value {
    font-size: 20px;
    font-weight: 700;
}

.detail-value.success {
    color: #10b981;
}

.detail-value.error {
    color: #ef4444;
}

.texolink-modal .modal-footer {
    padding: 20px 25px;
    border-top: 1px solid #e2e8f0;
    display: flex;
    gap: 10px;
    justify-content: flex-end;
}

@keyframes spin {
    to { transform: rotate(360deg); }
}

#analyze-all-posts-btn .dashicons {
    transition: transform 0.3s;
}

#analyze-all-posts-btn:disabled {
    opacity: 0.6;
    cursor: not-allowed;
}

#analyze-all-posts-btn.analyzing .dashicons {
    animation: spin 1s linear infinite;
}
</style>

<script>
jQuery(document).ready(function($) {
    let totalPosts = 0;
    let processedPosts = 0;
    let successCount = 0;
    let errorCount = 0;
    let allPostIds = [];
    let isProcessing = false;
    
    // Update button text based on selected post type
    $('#analyze-post-types').on('change', function() {
        const selectedOption = $(this).find('option:selected').text();
        const buttonText = selectedOption === 'All Types' ? 
            '<?php _e('Analyze Content', 'texolink'); ?>' : 
            '<?php _e('Analyze', 'texolink'); ?> ' + selectedOption;
        
        $('#analyze-all-posts-btn').contents().last()[0].textContent = ' ' + buttonText;
    });
    
    // Analyze All Posts button
    $('#analyze-all-posts-btn').on('click', function() {
        if (isProcessing) return;
        
        const selectedType = $('#analyze-post-types').val();
        const selectedLabel = $('#analyze-post-types option:selected').text();
        
        const confirmMessage = selectedType === 'all' ?
            '<?php _e('This will analyze ALL your published content (posts, pages, and custom post types). This may take several minutes. Continue?', 'texolink'); ?>' :
            '<?php _e('This will analyze all published', 'texolink'); ?> ' + selectedLabel + '. <?php _e('Continue?', 'texolink'); ?>';
        
        if (!confirm(confirmMessage)) {
            return;
        }
        
        startFullAnalysis(selectedType, selectedLabel);
    });
    
    // Close modal button
    $('#close-modal-btn').on('click', function() {
        $('#texolink-progress-modal').fadeOut();
    });
    
    function startFullAnalysis(postType, postTypeLabel) {
        isProcessing = true;
        processedPosts = 0;
        successCount = 0;
        errorCount = 0;
        allPostIds = [];
        
        // Update modal title
        const modalTitle = postType === 'all' ? 
            '<?php _e('Analyzing All Content', 'texolink'); ?>' : 
            '<?php _e('Analyzing', 'texolink'); ?> ' + postTypeLabel;
        $('#texolink-progress-modal .modal-header h2').text(modalTitle);
        
        // Disable button and show loading state
        $('#analyze-all-posts-btn')
            .prop('disabled', true)
            .addClass('analyzing');
        
        // Show modal
        $('#texolink-progress-modal').fadeIn();
        $('#progress-status').text('<?php _e('Fetching content...', 'texolink'); ?>');
        
        // Fetch all posts
        fetchAllPosts(0, postType);
    }
    
    function fetchAllPosts(offset, postType) {
        $.ajax({
            url: ajaxurl,
            method: 'POST',
            data: {
                action: 'texolink_get_all_posts',
                nonce: '<?php echo wp_create_nonce('texolink_nonce'); ?>',
                offset: offset,
                limit: 100,
                post_type: postType
            },
            success: function(response) {
                if (response.success && response.data.post_ids) {
                    allPostIds = allPostIds.concat(response.data.post_ids);
                    totalPosts = response.data.total;
                    
                    $('#progress-total').text(totalPosts);
                    
                    if (response.data.has_more) {
                        // Fetch more posts
                        fetchAllPosts(offset + 100, postType);
                    } else {
                        // All posts fetched, start processing
                        if (totalPosts === 0) {
                            showError('<?php _e('No published content found to analyze', 'texolink'); ?>');
                            return;
                        }
                        $('#progress-status').text('<?php _e('Starting analysis...', 'texolink'); ?>');
                        processNextPost(0);
                    }
                } else {
                    showError('<?php _e('Failed to fetch content', 'texolink'); ?>');
                }
            },
            error: function() {
                showError('<?php _e('Connection error while fetching content', 'texolink'); ?>');
            }
        });
    }
    
    function processNextPost(index) {
        if (index >= allPostIds.length) {
            // All done!
            finishAnalysis();
            return;
        }
        
        const postId = allPostIds[index];
        
        // Update status
        $('#progress-status').html('<?php _e('Analyzing content ID:', 'texolink'); ?> <strong>' + postId + '</strong>');
        
        // Make AJAX request
        $.ajax({
            url: ajaxurl,
            method: 'POST',
            data: {
                action: 'texolink_sync_post',
                nonce: '<?php echo wp_create_nonce('texolink_nonce'); ?>',
                post_id: postId
            },
            success: function(response) {
                processedPosts++;
                
                if (response.success) {
                    successCount++;
                } else {
                    errorCount++;
                }
                
                updateProgress();
                
                // Process next post with small delay
                setTimeout(function() {
                    processNextPost(index + 1);
                }, 300);
            },
            error: function() {
                processedPosts++;
                errorCount++;
                updateProgress();
                
                // Process next post
                setTimeout(function() {
                    processNextPost(index + 1);
                }, 300);
            }
        });
    }
    
    function updateProgress() {
        const percentage = Math.round((processedPosts / totalPosts) * 100);
        
        $('#progress-current').text(processedPosts);
        $('#progress-percentage').text(percentage + '%');
        $('#modal-progress-bar').css('width', percentage + '%');
        $('#success-count').text(successCount);
        $('#error-count').text(errorCount);
    }
    
    function finishAnalysis() {
        isProcessing = false;
        
        $('#analyze-all-posts-btn')
            .prop('disabled', false)
            .removeClass('analyzing');
        
        $('#progress-status').html(
            '<strong style="color: #10b981;"><?php _e('Analysis Complete!', 'texolink'); ?></strong><br>' +
            '<?php _e('Successfully analyzed', 'texolink'); ?> ' + successCount + ' ' + '<?php _e('items', 'texolink'); ?>. ' +
            (errorCount > 0 ? errorCount + ' <?php _e('errors occurred', 'texolink'); ?>.' : '')
        );
        
        $('#close-modal-btn').prop('disabled', false);
        $('#refresh-dashboard-btn').show();
    }
    
    function showError(message) {
        isProcessing = false;
        
        $('#analyze-all-posts-btn')
            .prop('disabled', false)
            .removeClass('analyzing');
        
        $('#progress-status').html('<strong style="color: #ef4444;"><?php _e('Error:', 'texolink'); ?></strong> ' + message);
        $('#close-modal-btn').prop('disabled', false);
    }
});
</script>

<style>
.texolink-dashboard {
    max-width: 1400px;
}

.texolink-analyze-section {
    margin-bottom: 20px;
    padding: 20px;
    background: #f8fafc;
    border-radius: 6px;
    border: 1px solid #e2e8f0;
}

.analyze-controls {
    display: flex;
    align-items: center;
    gap: 15px;
    flex-wrap: wrap;
}

.post-type-selector {
    display: flex;
    align-items: center;
    gap: 10px;
}

.post-type-selector label {
    display: flex;
    align-items: center;
    gap: 5px;
    font-weight: 600;
    color: #1e293b;
    margin: 0;
}

.post-type-selector label .dashicons {
    color: #64748b;
}

.post-type-select {
    min-width: 200px;
    padding: 8px 12px;
    border: 1px solid #d1d5db;
    border-radius: 6px;
    background: white;
    font-size: 14px;
    cursor: pointer;
    transition: border-color 0.2s;
}

.post-type-select:hover {
    border-color: #2271b1;
}

.post-type-select:focus {
    outline: none;
    border-color: #2271b1;
    box-shadow: 0 0 0 1px #2271b1;
}

#analyze-all-posts-btn {
    flex-shrink: 0;
}

/* Primary Action Mega Button */
.texolink-primary-action {
    margin-bottom: 20px;
}

.texolink-mega-button {
    width: 100%;
    min-height: 80px;
    display: flex !important;
    align-items: center;
    justify-content: center;
    gap: 15px;
    font-size: 16px;
    padding: 20px 30px;
    background: linear-gradient(135deg, #2271b1 0%, #1557a0 100%);
    border: none;
    box-shadow: 0 4px 12px rgba(34, 113, 177, 0.3);
    transition: all 0.3s ease;
}

.texolink-mega-button:hover {
    background: linear-gradient(135deg, #1557a0 0%, #0d4280 100%);
    box-shadow: 0 6px 20px rgba(34, 113, 177, 0.4);
    transform: translateY(-2px);
}

.texolink-mega-button .dashicons {
    font-size: 32px;
    width: 32px;
    height: 32px;
}

.texolink-mega-button .button-content {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: 4px;
}

.texolink-mega-button .button-title {
    font-size: 18px;
    font-weight: 700;
    line-height: 1.2;
}

.texolink-mega-button .button-subtitle {
    font-size: 13px;
    opacity: 0.9;
    font-weight: 400;
    line-height: 1.3;
}

.texolink-mega-button:disabled {
    opacity: 0.5;
    cursor: not-allowed;
    transform: none;
}

/* Enhanced Status Display */
.generation-status-display {
    display: none;
    margin-top: 20px;
    padding: 20px;
    background: white;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
}

.generation-status-display.active {
    display: block;
}

.generation-status-display .status-header {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 15px;
    font-size: 16px;
    font-weight: 600;
    color: #1e293b;
}

.generation-status-display .status-header .spinner {
    float: none;
    margin: 0;
}

.generation-status-display .progress-bar-container {
    height: 8px;
    background: #e2e8f0;
    border-radius: 4px;
    overflow: hidden;
    margin-bottom: 15px;
}

.generation-status-display .progress-bar-fill {
    height: 100%;
    background: linear-gradient(90deg, #2271b1, #10b981);
    transition: width 0.5s ease;
    border-radius: 4px;
}

.generation-status-display .status-details {
    display: flex;
    flex-direction: column;
    gap: 8px;
    font-size: 14px;
}

.generation-status-display .status-row {
    display: flex;
    justify-content: space-between;
    padding: 8px 12px;
    background: #f8fafc;
    border-radius: 4px;
}

.generation-status-display .status-label {
    color: #64748b;
    font-weight: 500;
}

.generation-status-display .status-value {
    color: #1e293b;
    font-weight: 600;
}

.generation-status-display .current-task {
    padding: 12px;
    background: #f0f9ff;
    border-left: 4px solid #2271b1;
    border-radius: 4px;
    margin-top: 10px;
    font-size: 13px;
    color: #0369a1;
}

/* Advanced Controls */
.texolink-advanced-controls summary {
    list-style: none;
    display: flex;
    align-items: center;
    gap: 8px;
}

.texolink-advanced-controls summary::-webkit-details-marker {
    display: none;
}

.texolink-advanced-controls summary::before {
    content: '▶';
    display: inline-block;
    margin-right: 5px;
    transition: transform 0.2s;
}

.texolink-advanced-controls[open] summary::before {
    transform: rotate(90deg);
}

.texolink-advanced-controls summary:hover {
    background: #e2e8f0;
}

.texolink-stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 20px;
    margin: 30px 0;
}

.texolink-stat-card {
    background: white;
    border: 1px solid #ddd;
    border-radius: 8px;
    padding: 20px;
    display: flex;
    gap: 15px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.05);
    transition: box-shadow 0.3s;
}

.texolink-stat-card:hover {
    box-shadow: 0 4px 12px rgba(0,0,0,0.1);
}

.texolink-stat-primary {
    border-left: 4px solid #2271b1;
}

.texolink-stat-warning {
    border-left: 4px solid #f59e0b;
}

.stat-icon {
    flex-shrink: 0;
}

.stat-icon .dashicons {
    font-size: 40px;
    width: 40px;
    height: 40px;
    color: #2271b1;
}

.texolink-stat-warning .stat-icon .dashicons {
    color: #f59e0b;
}

.stat-content {
    flex: 1;
}

.stat-value {
    font-size: 32px;
    font-weight: 700;
    line-height: 1;
    margin-bottom: 5px;
    color: #1e293b;
}

.stat-label {
    font-size: 14px;
    color: #64748b;
    margin-bottom: 5px;
}

.stat-subtitle {
    font-size: 12px;
    color: #94a3b8;
}

.stat-progress {
    margin-top: 10px;
    height: 6px;
    background: #e2e8f0;
    border-radius: 3px;
    overflow: hidden;
}

.progress-bar {
    height: 100%;
    transition: width 0.5s ease;
}

.texolink-dashboard-content {
    display: grid;
    grid-template-columns: 1fr;
    gap: 20px;
}

.texolink-panel {
    background: white;
    border: 1px solid #ddd;
    border-radius: 8px;
    padding: 20px;
}

.texolink-panel h2 {
    margin-top: 0;
    padding-bottom: 15px;
    border-bottom: 2px solid #e2e8f0;
    display: flex;
    align-items: center;
    gap: 8px;
}

.texolink-panel-warning {
    border-left: 4px solid #f59e0b;
}

.texolink-panel-info {
    border-left: 4px solid #3b82f6;
}

.texolink-actions {
    display: flex;
    gap: 15px;
    flex-wrap: wrap;
}

.texolink-actions .button-hero {
    display: inline-flex;
    align-items: center;
    gap: 8px;
}

.texolink-empty {
    color: #94a3b8;
    font-style: italic;
    padding: 20px;
    text-align: center;
}

.texolink-post-list {
    list-style: none;
    margin: 15px 0;
    padding: 0;
}

.texolink-post-list li {
    padding: 12px;
    border-bottom: 1px solid #e2e8f0;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.texolink-post-list li:last-child {
    border-bottom: none;
}

.texolink-post-list .post-meta {
    color: #94a3b8;
    font-size: 12px;
}

.texolink-more {
    text-align: center;
    color: #64748b;
    margin: 10px 0 0;
}

.texolink-tips {
    list-style: none;
    margin: 15px 0;
    padding: 0;
}

.texolink-tips li {
    padding: 12px 15px;
    margin-bottom: 10px;
    border-radius: 6px;
    border-left: 4px solid;
}

.tip-critical {
    background: #fef2f2;
    border-color: #ef4444;
    color: #991b1b;
}

.tip-warning {
    background: #fffbeb;
    border-color: #f59e0b;
    color: #92400e;
}

.tip-info {
    background: #eff6ff;
    border-color: #3b82f6;
    color: #1e40af;
}

.tip-success {
    background: #f0fdf4;
    border-color: #10b981;
    color: #065f46;
}

.texolink-score {
    display: inline-block;
    padding: 4px 8px;
    background: #dbeafe;
    color: #1e40af;
    border-radius: 4px;
    font-size: 12px;
    font-weight: 600;
}
</style>
