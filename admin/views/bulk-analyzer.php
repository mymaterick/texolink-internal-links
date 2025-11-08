<?php
/**
 * TexoLink Bulk Analyzer Page
 */

if (!defined('ABSPATH')) {
    exit;
}

// Get published posts and pages with pagination
$paged = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
$per_page = 50; // Load 50 at a time for better performance

$args = array(
    'post_type' => array('post', 'page'),
    'post_status' => 'publish',
    'posts_per_page' => $per_page,
    'paged' => $paged,
    'orderby' => 'date',
    'order' => 'DESC'
);

$query = new WP_Query($args);
$posts = $query->posts;
$total_posts = $query->found_posts;
$total_pages = $query->max_num_pages;
$api_client = new TexoLink_API_Client();
$connection_status = $api_client->test_connection();
?>

<div class="wrap texolink-bulk-analyzer">
    <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
    
    <?php if (!$connection_status): ?>
        <div class="notice notice-error">
            <p>
                <strong><?php _e('Not Connected', 'texolink'); ?></strong>
                <?php _e('Unable to connect to TexoLink API. Please check your settings.', 'texolink'); ?>
                <a href="<?php echo admin_url('admin.php?page=texolink-settings'); ?>"><?php _e('Go to Settings', 'texolink'); ?></a>
            </p>
        </div>
    <?php endif; ?>

    <div class="texolink-bulk-header">
        <div class="bulk-info">
            <h2><?php _e('Analyze Your Content', 'texolink'); ?></h2>
            <p class="description">
                <?php printf(
                    __('Showing %d-%d of %d published posts and pages. Select which ones to analyze with AI.', 'texolink'),
                    (($paged - 1) * $per_page) + 1,
                    min($paged * $per_page, $total_posts),
                    $total_posts
                ); ?>
            </p>
        </div>
        
        <div class="bulk-actions-top">
            <button class="button button-secondary" id="select-all">
                <span class="dashicons dashicons-yes"></span>
                <?php _e('Select All', 'texolink'); ?>
            </button>
            <button class="button button-secondary" id="deselect-all">
                <span class="dashicons dashicons-no"></span>
                <?php _e('Deselect All', 'texolink'); ?>
            </button>
            <button class="button button-primary button-large" id="start-analysis" <?php echo !$connection_status ? 'disabled' : ''; ?>>
                <span class="dashicons dashicons-update"></span>
                <?php _e('Start Analysis', 'texolink'); ?>
            </button>
        </div>
    </div>

    <!-- Progress Bar -->
    <div class="texolink-progress-container" style="display: none;">
        <div class="progress-header">
            <h3 id="progress-status"><?php _e('Analyzing...', 'texolink'); ?></h3>
            <span id="progress-text">0 / 0</span>
        </div>
        <div class="progress-bar-wrapper">
            <div class="progress-bar" id="progress-bar" style="width: 0%"></div>
        </div>
        <div class="progress-details">
            <div id="current-post"></div>
            <div id="progress-log"></div>
        </div>
    </div>

    <!-- Results Summary -->
    <div class="texolink-results-summary" style="display: none;">
        <h3><?php _e('Analysis Complete!', 'texolink'); ?></h3>
        <div class="results-grid">
            <div class="result-card">
                <div class="result-number" id="success-count">0</div>
                <div class="result-label"><?php _e('Successfully Analyzed', 'texolink'); ?></div>
            </div>
            <div class="result-card">
                <div class="result-number" id="links-found">0</div>
                <div class="result-label"><?php _e('Link Suggestions Found', 'texolink'); ?></div>
            </div>
            <div class="result-card">
                <div class="result-number" id="error-count">0</div>
                <div class="result-label"><?php _e('Errors', 'texolink'); ?></div>
            </div>
        </div>
        <div class="results-actions">
            <a href="<?php echo admin_url('admin.php?page=texolink'); ?>" class="button button-primary button-large">
                <?php _e('View Dashboard', 'texolink'); ?>
            </a>
            <button class="button button-secondary button-large" id="reset-analysis">
                <?php _e('Analyze More Posts', 'texolink'); ?>
            </button>
        </div>
    </div>

    <!-- Post Selection Grid -->
    <div class="texolink-post-grid" id="post-grid">
        <?php if (empty($posts)): ?>
            <div class="no-posts">
                <span class="dashicons dashicons-admin-post"></span>
                <h3><?php _e('No Posts Found', 'texolink'); ?></h3>
                <p><?php _e('Create some posts first, then come back to analyze them!', 'texolink'); ?></p>
                <a href="<?php echo admin_url('post-new.php'); ?>" class="button button-primary">
                    <?php _e('Create New Post', 'texolink'); ?>
                </a>
            </div>
        <?php else: ?>
            <?php foreach ($posts as $post): ?>
                <?php
                $word_count = str_word_count(wp_strip_all_tags($post->post_content));
                $has_links = preg_match_all('/<a[^>]+href/i', $post->post_content);
                $backend_post = $api_client->get_post_by_wordpress_id($post->ID);
                $is_synced = !empty($backend_post);
                ?>
                <div class="post-card" data-post-id="<?php echo $post->ID; ?>">
                    <label class="post-card-inner">
                        <input type="checkbox" class="post-checkbox" value="<?php echo $post->ID; ?>" checked />
                        
                        <div class="post-status">
                            <?php if ($is_synced): ?>
                                <span class="status-badge synced" title="<?php _e('Already synced', 'texolink'); ?>">
                                    <span class="dashicons dashicons-cloud-saved"></span>
                                </span>
                            <?php else: ?>
                                <span class="status-badge new" title="<?php _e('Not yet synced', 'texolink'); ?>">
                                    <span class="dashicons dashicons-cloud-upload"></span>
                                </span>
                            <?php endif; ?>
                        </div>
                        
                        <div class="post-content">
                            <h4><?php echo esc_html($post->post_title); ?></h4>
                            
                            <div class="post-meta">
                                <span class="post-type">
                                    <span class="dashicons dashicons-<?php echo $post->post_type === 'page' ? 'admin-page' : 'admin-post'; ?>"></span>
                                    <?php echo ucfirst($post->post_type); ?>
                                </span>
                                <span class="word-count">
                                    <span class="dashicons dashicons-text-page"></span>
                                    <?php echo number_format($word_count); ?> words
                                </span>
                                <span class="link-count">
                                    <span class="dashicons dashicons-admin-links"></span>
                                    <?php echo $has_links; ?> links
                                </span>
                            </div>
                            
                            <div class="post-date">
                                <?php echo human_time_diff(strtotime($post->post_date), current_time('timestamp')) . ' ago'; ?>
                            </div>
                        </div>
                        
                        <div class="post-actions">
                            <a href="<?php echo get_edit_post_link($post->ID); ?>" class="button button-small" onclick="event.stopPropagation();">
                                <?php _e('Edit', 'texolink'); ?>
                            </a>
                        </div>
                    </label>
                    
                    <div class="post-result" style="display: none;">
                        <div class="result-icon"></div>
                        <div class="result-message"></div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
    
    <?php if ($total_pages > 1): ?>
    <div class="tablenav bottom">
        <div class="tablenav-pages">
            <span class="displaying-num"><?php printf(__('%s items', 'texolink'), number_format_i18n($total_posts)); ?></span>
            <?php
            echo paginate_links(array(
                'base' => add_query_arg('paged', '%#%'),
                'format' => '',
                'prev_text' => __('&laquo; Previous'),
                'next_text' => __('Next &raquo;'),
                'total' => $total_pages,
                'current' => $paged
            ));
            ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<style>
.texolink-bulk-analyzer {
    max-width: 1400px;
}

.texolink-bulk-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin: 20px 0;
    padding: 20px;
    background: white;
    border: 1px solid #ddd;
    border-radius: 8px;
}

.bulk-info h2 {
    margin: 0 0 5px 0;
}

.bulk-actions-top {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
}

.texolink-progress-container {
    background: white;
    border: 1px solid #ddd;
    border-radius: 8px;
    padding: 30px;
    margin: 20px 0;
}

.progress-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 15px;
}

.progress-header h3 {
    margin: 0;
}

#progress-text {
    font-size: 18px;
    font-weight: 600;
    color: #2271b1;
}

.progress-bar-wrapper {
    height: 30px;
    background: #e2e8f0;
    border-radius: 15px;
    overflow: hidden;
    margin-bottom: 20px;
}

.progress-bar {
    height: 100%;
    background: linear-gradient(90deg, #2271b1 0%, #3b82f6 100%);
    transition: width 0.3s ease;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-weight: 600;
}

.progress-details {
    background: #f8fafc;
    border-radius: 6px;
    padding: 15px;
    max-height: 300px;
    overflow-y: auto;
}

#current-post {
    font-weight: 600;
    color: #1e293b;
    margin-bottom: 10px;
    padding: 10px;
    background: white;
    border-radius: 4px;
}

#progress-log {
    font-size: 12px;
    color: #64748b;
}

#progress-log .log-entry {
    padding: 5px 10px;
    margin: 5px 0;
    border-left: 3px solid;
    background: white;
    border-radius: 4px;
}

#progress-log .log-success {
    border-color: #10b981;
}

#progress-log .log-error {
    border-color: #ef4444;
    color: #991b1b;
}

.texolink-results-summary {
    background: white;
    border: 1px solid #ddd;
    border-radius: 8px;
    padding: 30px;
    margin: 20px 0;
    text-align: center;
}

.texolink-results-summary h3 {
    margin-top: 0;
    color: #10b981;
    font-size: 24px;
}

.results-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 20px;
    margin: 30px 0;
}

.result-card {
    background: #f8fafc;
    padding: 20px;
    border-radius: 8px;
    border: 2px solid #e2e8f0;
}

.result-number {
    font-size: 48px;
    font-weight: 700;
    color: #2271b1;
}

.result-label {
    color: #64748b;
    margin-top: 5px;
}

.results-actions {
    display: flex;
    justify-content: center;
    gap: 15px;
    margin-top: 30px;
}

.texolink-post-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
    gap: 20px;
    margin: 20px 0;
}

.no-posts {
    grid-column: 1 / -1;
    text-align: center;
    padding: 60px 20px;
    background: white;
    border: 2px dashed #ddd;
    border-radius: 8px;
}

.no-posts .dashicons {
    font-size: 64px;
    width: 64px;
    height: 64px;
    color: #cbd5e1;
}

.no-posts h3 {
    color: #475569;
    margin: 20px 0 10px;
}

.post-card {
    background: white;
    border: 2px solid #e2e8f0;
    border-radius: 8px;
    transition: all 0.2s;
    position: relative;
}

.post-card:hover {
    border-color: #2271b1;
    box-shadow: 0 4px 12px rgba(34, 113, 177, 0.1);
}

.post-card.analyzing {
    border-color: #f59e0b;
    background: #fffbeb;
}

.post-card.success {
    border-color: #10b981;
    background: #f0fdf4;
}

.post-card.error {
    border-color: #ef4444;
    background: #fef2f2;
}

.post-card-inner {
    display: block;
    padding: 20px;
    cursor: pointer;
}

.post-checkbox {
    position: absolute;
    top: 15px;
    left: 15px;
    width: 20px;
    height: 20px;
    cursor: pointer;
}

.post-status {
    position: absolute;
    top: 15px;
    right: 15px;
}

.status-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 28px;
    height: 28px;
    border-radius: 50%;
}

.status-badge.synced {
    background: #d1fae5;
    color: #059669;
}

.status-badge.new {
    background: #dbeafe;
    color: #2563eb;
}

.status-badge .dashicons {
    font-size: 16px;
    width: 16px;
    height: 16px;
}

.post-content {
    margin: 10px 0 0 30px;
}

.post-content h4 {
    margin: 0 0 10px 0;
    font-size: 16px;
    line-height: 1.4;
    color: #1e293b;
}

.post-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    margin-bottom: 10px;
    font-size: 13px;
    color: #64748b;
}

.post-meta > span {
    display: flex;
    align-items: center;
    gap: 4px;
}

.post-meta .dashicons {
    font-size: 16px;
    width: 16px;
    height: 16px;
}

.post-date {
    font-size: 12px;
    color: #94a3b8;
}

.post-actions {
    margin-top: 15px;
    padding-top: 15px;
    border-top: 1px solid #e2e8f0;
}

.post-result {
    padding: 15px 20px;
    border-top: 1px solid #e2e8f0;
}

.post-result .result-icon {
    display: inline-block;
    margin-right: 8px;
}

.post-result .result-message {
    display: inline-block;
    font-size: 13px;
}

/* Animations */
@keyframes pulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.5; }
}

.post-card.analyzing .post-card-inner {
    animation: pulse 2s ease-in-out infinite;
}
</style>

<script>
jQuery(document).ready(function($) {
    let totalPosts = 0;
    let processedPosts = 0;
    let successCount = 0;
    let errorCount = 0;
    let totalSuggestions = 0;
    
    // Select/Deselect all
    $('#select-all').on('click', function() {
        $('.post-checkbox').prop('checked', true);
    });
    
    $('#deselect-all').on('click', function() {
        $('.post-checkbox').prop('checked', false);
    });
    
    // Start analysis
    $('#start-analysis').on('click', function() {
        const selectedPosts = $('.post-checkbox:checked').map(function() {
            return $(this).val();
        }).get();
        
        if (selectedPosts.length === 0) {
            alert('<?php _e('Please select at least one post to analyze.', 'texolink'); ?>');
            return;
        }
        
        if (!confirm('<?php printf(__('Analyze %s posts with TexoLink AI?', 'texolink'), "' + selectedPosts.length + '"); ?>')) {
            return;
        }
        
        startBulkAnalysis(selectedPosts);
    });
    
    // Reset and analyze more
    $('#reset-analysis').on('click', function() {
        location.reload();
    });
    
    function startBulkAnalysis(postIds) {
        totalPosts = postIds.length;
        processedPosts = 0;
        successCount = 0;
        errorCount = 0;
        totalSuggestions = 0;
        
        // Hide post grid and show progress
        $('#post-grid').fadeOut();
        $('.texolink-bulk-header').fadeOut();
        $('.texolink-progress-container').fadeIn();
        
        // Process posts one by one
        processNextPost(postIds, 0);
    }
    
    function processNextPost(postIds, index) {
        if (index >= postIds.length) {
            // All done!
            showResults();
            return;
        }
        
        const postId = postIds[index];
        const $postCard = $(`.post-card[data-post-id="${postId}"]`);
        const postTitle = $postCard.find('h4').text();
        
        // Update UI
        $postCard.addClass('analyzing');
        $('#current-post').text('<?php _e('Analyzing:', 'texolink'); ?> ' + postTitle);
        
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
                    $postCard.removeClass('analyzing').addClass('success');
                    $postCard.find('.post-result').show().html(
                        '<span class="dashicons dashicons-yes-alt" style="color: #10b981;"></span> ' +
                        '<span><?php _e('Successfully analyzed', 'texolink'); ?></span>'
                    );
                    addLogEntry(postTitle, 'success');
                    
                    // Get suggestions count
                    getSuggestionsCount(postId);
                } else {
                    errorCount++;
                    $postCard.removeClass('analyzing').addClass('error');
                    $postCard.find('.post-result').show().html(
                        '<span class="dashicons dashicons-warning" style="color: #ef4444;"></span> ' +
                        '<span><?php _e('Error:', 'texolink'); ?> ' + (response.data || 'Unknown error') + '</span>'
                    );
                    addLogEntry(postTitle + ' - Error: ' + (response.data || 'Unknown'), 'error');
                }
                
                updateProgress();
                
                // Process next post
                setTimeout(function() {
                    processNextPost(postIds, index + 1);
                }, 500);
            },
            error: function() {
                processedPosts++;
                errorCount++;
                $postCard.removeClass('analyzing').addClass('error');
                $postCard.find('.post-result').show().html(
                    '<span class="dashicons dashicons-warning" style="color: #ef4444;"></span> ' +
                    '<span><?php _e('Connection error', 'texolink'); ?></span>'
                );
                addLogEntry(postTitle + ' - Connection error', 'error');
                updateProgress();
                
                // Process next post
                setTimeout(function() {
                    processNextPost(postIds, index + 1);
                }, 500);
            }
        });
    }
    
    function getSuggestionsCount(postId) {
        $.ajax({
            url: ajaxurl,
            method: 'POST',
            data: {
                action: 'texolink_get_suggestions',
                nonce: '<?php echo wp_create_nonce('texolink_nonce'); ?>',
                post_id: postId
            },
            success: function(response) {
                if (response.success && response.data) {
                    totalSuggestions += response.data.length;
                    $('#links-found').text(totalSuggestions);
                }
            }
        });
    }
    
    function updateProgress() {
        const percentage = Math.round((processedPosts / totalPosts) * 100);
        $('#progress-bar').css('width', percentage + '%');
        $('#progress-text').text(processedPosts + ' / ' + totalPosts);
    }
    
    function addLogEntry(message, type) {
        const logClass = type === 'error' ? 'log-error' : 'log-success';
        const icon = type === 'error' ? '✗' : '✓';
        $('#progress-log').prepend(
            '<div class="log-entry ' + logClass + '">' + icon + ' ' + message + '</div>'
        );
        
        // Keep only last 20 entries
        $('.log-entry:gt(19)').remove();
    }
    
    function showResults() {
        $('#progress-status').text('<?php _e('Analysis Complete!', 'texolink'); ?>');
        $('#success-count').text(successCount);
        $('#error-count').text(errorCount);
        $('#links-found').text(totalSuggestions);
        
        setTimeout(function() {
            $('.texolink-progress-container').fadeOut(function() {
                $('.texolink-results-summary').fadeIn();
                $('#post-grid').fadeIn();
            });
        }, 1000);
    }
});
</script>
