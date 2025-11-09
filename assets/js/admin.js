/**
 * TexoLink Admin JavaScript
 */

(function($) {
    'use strict';

    // Wait for DOM ready
    $(document).ready(function() {
        
        // Guard: Ensure WordPress globals exist
        if (typeof ajaxurl === 'undefined' || typeof texolinkSettings === 'undefined') {
            console.warn('TexoLink: Missing ajaxurl or texolinkSettings - admin JS not loaded');
            return;
        }
        
        // Initialize tooltips if available
        if (typeof $.fn.tooltip !== 'undefined') {
            $('[data-toggle="tooltip"]').tooltip();
        }

        // Handle AJAX errors globally
        $(document).ajaxError(function(event, jqxhr, settings, thrownError) {
            console.error('TexoLink AJAX Error:', thrownError);
        });

        // Add smooth scrolling for anchor links
        $('a[href^="#"]').on('click', function(e) {
            var target = $(this.getAttribute('href'));
            if (target.length) {
                e.preventDefault();
                $('html, body').stop().animate({
                    scrollTop: target.offset().top - 100
                }, 500);
            }
        });

        // Confirm before dangerous actions
        $('.texolink-confirm').on('click', function(e) {
            if (!confirm('Are you sure you want to do this?')) {
                e.preventDefault();
                return false;
            }
        });

        // Auto-dismiss notices after 5 seconds
        setTimeout(function() {
            $('.notice.is-dismissible').fadeOut();
        }, 5000);

        // Log when TexoLink admin JS is loaded
        console.log('TexoLink Admin JS loaded');
        
        
        /**
 * Professional Generation Progress Tracker
 * Add this to admin.js to replace the current Generate button code
 */

// Global variable to track polling
let generationPollInterval = null;
let currentJobId = null;

$('#generate-suggestions-btn').off('click').on('click', function() {
    const $btn = $(this);
    const $status = $('#generation-status');
    
    if (!confirm('Generate link suggestions for all posts?\n\nThis will:\n• Check for new posts and sync if needed\n• Generate suggestions only for posts that need them\n• Skip posts that already have suggestions\n\nTakes 1-30 minutes depending on how much is new.\n\nContinue?')) {
        return;
    }
    
    // Immediate visual feedback
    $btn.prop('disabled', true);
    
    // Update button appearance
    const $btnContent = $btn.find('.button-content');
    if ($btnContent.length) {
        $btnContent.find('.button-title').text('⏳ Checking Posts...');
        $btnContent.find('.button-subtitle').text('Comparing WordPress and Railway databases...');
    }
    
    // Show styled status
    $status.addClass('active').html(`
        <div class="status-header">
            <div class="spinner is-active"></div>
            <span>🔍 Checking for Updates</span>
        </div>
        <div class="progress-bar-container">
            <div class="progress-bar-fill" style="width: 10%"></div>
        </div>
        <div class="status-details">
            <div class="status-row">
                <span class="status-label">Status</span>
                <span class="status-value">Analyzing...</span>
            </div>
        </div>
        <div class="current-task">
            Checking WordPress post count vs Railway database...
        </div>
    `);
    
    // Get WordPress and Railway counts
    checkPostCounts($btn, $status);
});

/**
 * Check if sync is needed, then proceed
 */
function checkPostCounts($btn, $status) {
    // Step 1: Get WordPress post count
    $.ajax({
        url: ajaxurl,
        method: 'POST',
        data: {
            action: 'texolink_count_posts',
            nonce: texolinkSettings.nonce
        },
        success: function(wpResponse) {
            const wpPostCount = wpResponse.data || 0;
            
            // Step 2: Get Railway post count for THIS site
            $.ajax({
                url: texolinkSettings.apiUrl + '/posts?site_domain=' + (texolinkSettings.siteDomain || window.location.hostname),
                method: 'GET',
                success: function(railwayResponse) {
                    const railwayPostCount = railwayResponse.total || 0;
                    
                    console.log('WP:', wpPostCount, 'Railway:', railwayPostCount);

                    if (railwayPostCount !== wpPostCount || railwayPostCount === 0) {
                        // Need to sync! (counts don't match OR Railway is empty)
                        const missing = Math.abs(wpPostCount - railwayPostCount);
                        $status.html(
                            '<div class="spinner is-active" style="float: left; margin-right: 10px;"></div>' +
                            '<strong>Step 1/2: Syncing Posts</strong><br>' +
                            'Found ' + railwayPostCount + ' of ' + wpPostCount + ' posts. Syncing ' + missing + ' posts...'
                        );

                        // Trigger sync and wait for completion
                        startSyncWithProgress($btn, $status, wpPostCount, railwayPostCount);

                    } else {
                        // All synced! Go straight to generation
                        $status.addClass('active').html(`
                            <div class="status-header">
                                <div class="spinner is-active"></div>
                                <span>⚡ Preparing AI Engine</span>
                            </div>
                            <div class="progress-bar-container">
                                <div class="progress-bar-fill" style="width: 5%"></div>
                            </div>
                            <div class="status-details">
                                <div class="status-row">
                                    <span class="status-label">Step 1: Post Sync</span>
                                    <span class="status-value">✓ Complete</span>
                                </div>
                                <div class="status-row">
                                    <span class="status-label">Step 2: AI Generation</span>
                                    <span class="status-value">Starting...</span>
                                </div>
                            </div>
                            <div class="current-task">
                                Connecting to Railway backend and initializing AI models...
                            </div>
                        `);

                        startSuggestionGenerationWithProgress($btn, $status);
                    }
                },
                error: function() {
                    $status.html('❌ Error checking Railway posts');
                    $btn.prop('disabled', false).text('Generate Suggestions for All Posts');
                }
            });
        },
        error: function() {
            $status.html('❌ Error checking WordPress posts');
            $btn.prop('disabled', false).text('Generate Suggestions for All Posts');
        }
    });
}

/**
 * Start sync and poll for completion
 * Uses the existing working sync code (texolink_sync_post)
 */
function startSyncWithProgress($btn, $status, targetCount, startCount) {
    let allPostIds = [];
    let processedPosts = 0;
    let successCount = 0;
    let errorCount = 0;

    // Step 1: Fetch all post IDs
    fetchAllPostIds(0);

    function fetchAllPostIds(offset) {
        $.ajax({
            url: ajaxurl,
            method: 'POST',
            data: {
                action: 'texolink_get_all_posts',
                nonce: texolinkSettings.nonce,
                offset: offset,
                limit: 100,
                post_type: 'all'
            },
            success: function(response) {
                if (response.success && response.data.post_ids) {
                    allPostIds = allPostIds.concat(response.data.post_ids);

                    if (response.data.has_more) {
                        // Fetch more posts
                        fetchAllPostIds(offset + 100);
                    } else {
                        // All post IDs fetched, start syncing
                        console.log('Starting sync of ' + allPostIds.length + ' posts to Railway...');
                        syncNextPost(0);
                    }
                } else {
                    $status.html('❌ Error getting WordPress posts');
                    $btn.prop('disabled', false);
                }
            },
            error: function() {
                $status.html('❌ Error getting WordPress posts');
                $btn.prop('disabled', false);
            }
        });
    }

    // Step 2: Sync each post using the working texolink_sync_post action
    function syncNextPost(index) {
        if (index >= allPostIds.length) {
            // All done!
            console.log('Sync complete! Synced: ' + successCount + ', Failed: ' + errorCount);

            $status.addClass('active').html(`
                <div class="status-header">
                    <div class="spinner is-active"></div>
                    <span>⚡ Launching AI Generation</span>
                </div>
                <div class="progress-bar-container">
                    <div class="progress-bar-fill" style="width: 50%"></div>
                </div>
                <div class="status-details">
                    <div class="status-row">
                        <span class="status-label">Step 1: Post Sync</span>
                        <span class="status-value">✓ ${successCount} posts synced</span>
                    </div>
                    <div class="status-row">
                        <span class="status-label">Step 2: AI Generation</span>
                        <span class="status-value">Starting in 2 seconds...</span>
                    </div>
                </div>
                <div class="current-task">
                    🚀 Preparing to analyze ${successCount} posts and generate thousands of link suggestions!
                </div>
            `);

            // Auto-start generation after sync completes!
            setTimeout(function() {
                startSuggestionGenerationWithProgress($btn, $status);
            }, 2000);
            return;
        }

        const postId = allPostIds[index];
        const progress = Math.round(((index + 1) / allPostIds.length) * 100);

        // Update progress UI
        $status.html(
            '<strong>Step 1/2: Syncing Posts</strong><br>' +
            createProgressBar(progress, 'Syncing Posts') +
            '<div style="margin-top: 10px;">' +
            (index + 1) + ' / ' + allPostIds.length + ' posts synced<br>' +
            '<em>Syncing post ID: ' + postId + '</em>' +
            '</div>'
        );

        // Use the existing working sync action
        $.ajax({
            url: ajaxurl,
            method: 'POST',
            data: {
                action: 'texolink_sync_post',
                nonce: texolinkSettings.nonce,
                post_id: postId
            },
            success: function(response) {
                processedPosts++;
                if (response.success) {
                    successCount++;
                } else {
                    errorCount++;
                }
                // Continue to next post with small delay
                setTimeout(function() {
                    syncNextPost(index + 1);
                }, 100);
            },
            error: function() {
                processedPosts++;
                errorCount++;
                // Continue to next post
                setTimeout(function() {
                    syncNextPost(index + 1);
                }, 100);
            }
        });
    }
}

/**
 * Start suggestion generation WITH real-time progress tracking
 */
function startSuggestionGenerationWithProgress($btn, $status) {
    $.ajax({
        url: texolinkSettings.apiUrl + '/suggestions/generate',
        method: 'POST',
        contentType: 'application/json',
        data: JSON.stringify({
            site_domain: texolinkSettings.siteDomain || window.location.hostname,
            regenerate: false
        }),
        timeout: 10000, // 10 second timeout to get job_id
        success: function(response) {
            if (response.job_id) {
                currentJobId = response.job_id;
                console.log('Generation started, job ID:', currentJobId);
                
                // Start polling for progress
                pollGenerationStatus($btn, $status);
            }
        },
        error: function(xhr) {
            // Even if timeout, try to get latest job
            pollGenerationStatus($btn, $status);
        }
    });
}

function pollGenerationStatus($btn, $status) {
    let pollCount = 0;
    const maxPolls = 400;  // 400 * 3s = 20 minutes max
    
    // Wait 2 seconds before first poll (let job start)
    setTimeout(function() {
        generationPollInterval = setInterval(function() {
            pollCount++;
            
            $.ajax({
    url: texolinkSettings.apiUrl + '/generation/status/latest',
    method: 'GET',
    data: {
        site_domain: texolinkSettings.siteDomain || window.location.hostname
    },
    success: function(response) {
        console.log('Poll response:', response); // ADD THIS to see what Railway sends
        
        if (response.success && response.job) {
            const job = response.job;
            
            // Update status display
            updateProgressUI($status, job);
            
            // Check if complete
            if (job.status === 'complete') {
                clearInterval(generationPollInterval);
                showCompletionUI($btn, $status, job);
            }
            
            // Check if failed
            if (job.status === 'failed') {
                clearInterval(generationPollInterval);
                showErrorUI($btn, $status, job);
            }
        } else {
            console.log('Unexpected response format:', response);
        }
    },
    error: function(xhr, status, error) {
        console.log('Poll error:', xhr.status, error);
        if (xhr.responseJSON) {
            console.error('Railway error:', xhr.responseJSON);
        } else if (xhr.responseText) {
            console.error('Error text:', xhr.responseText);
        }
    }
});
            
            // Safety: stop after max polls
            if (pollCount >= maxPolls) {
                clearInterval(generationPollInterval);
                $status.html(
                    '⏱️ <strong>Generation is taking longer than expected.</strong><br>' +
                    'It may still be running in the background.<br>' +
                    '<a href="admin.php?page=texolink-suggestions">Check Link Suggestions</a> to see if results appeared.'
                );
                $btn.prop('disabled', false).text('Generate Suggestions for All Posts');
            }
        }, 3000); // Poll every 3 seconds
    }, 2000); // Wait 2 seconds before starting to poll
}

/**
 * Update UI with current progress
 */
function updateProgressUI($status, job) {
    let phaseText = '';
    let phaseIcon = '🔄';
    if (job.phase === 'embeddings') {
        phaseText = 'Analyzing Content with AI';
        phaseIcon = '🧠';
    } else if (job.phase === 'suggestions') {
        phaseText = 'Generating Link Suggestions';
        phaseIcon = '🔗';
    } else {
        phaseText = 'Initializing...';
        phaseIcon = '⚡';
    }
    
    // Calculate more accurate progress
    const totalSteps = job.total_batches || 1;
    const currentStep = job.current_batch || 0;
    const progressPercent = job.progress_percent || Math.round((currentStep / totalSteps) * 100);
    
    $status.addClass('active').html(`
        <div class="status-header">
            <div class="spinner is-active"></div>
            <span>${phaseIcon} ${phaseText}</span>
        </div>
        
        <div class="progress-bar-container">
            <div class="progress-bar-fill" style="width: ${progressPercent}%"></div>
        </div>
        
        <div class="status-details">
            <div class="status-row">
                <span class="status-label">Progress</span>
                <span class="status-value">${progressPercent}% Complete</span>
            </div>
            <div class="status-row">
                <span class="status-label">Current Batch</span>
                <span class="status-value">${currentStep} of ${totalSteps}</span>
            </div>
            ${job.embeddings_generated > 0 ? `
            <div class="status-row">
                <span class="status-label">Posts Analyzed</span>
                <span class="status-value">${job.embeddings_generated.toLocaleString()}</span>
            </div>
            ` : ''}
            ${job.suggestions_generated > 0 ? `
            <div class="status-row">
                <span class="status-label">Suggestions Generated</span>
                <span class="status-value">${job.suggestions_generated.toLocaleString()}</span>
            </div>
            ` : ''}
            ${job.eta_human ? `
            <div class="status-row">
                <span class="status-label">Est. Time Remaining</span>
                <span class="status-value">${job.eta_human}</span>
            </div>
            ` : ''}
        </div>
        
        ${job.current_post_title ? `
        <div class="current-task">
            📄 Currently processing: <strong>${escapeHtml(job.current_post_title)}</strong>
        </div>
        ` : ''}
    `);
}

/**
 * Show completion UI
 */
function showCompletionUI($btn, $status, job) {
    const suggestionsGenerated = job.suggestions_generated || 0;
    const postsProcessed = job.posts_processed || 0;
    
    // Check if nothing was generated (already up to date)
    const isAlreadyUpToDate = suggestionsGenerated === 0 && postsProcessed === 0;
    
    if (isAlreadyUpToDate) {
        // Show "already up to date" message
        $status.addClass('active').html(`
            <div class="status-header" style="color: #10b981;">
                ✓ <span>Already Up to Date!</span>
            </div>
            
            <div class="progress-bar-container">
                <div class="progress-bar-fill" style="width: 100%; background: #10b981;"></div>
            </div>
            
            <div class="status-details">
                <div class="status-row">
                    <span class="status-label">Status</span>
                    <span class="status-value">All posts synced ✓</span>
                </div>
                <div class="status-row">
                    <span class="status-label">Suggestions</span>
                    <span class="status-value">All posts have suggestions ✓</span>
                </div>
            </div>
            
            <div class="current-task" style="background: #f0fdf4; border-color: #10b981;">
                ✨ <strong>Everything is current!</strong> All posts are synced and have AI-generated link suggestions. 
                Add new posts and run again to generate more suggestions.
            </div>
            
            <div style="text-align: center; margin-top: 20px;">
                <a href="admin.php?page=texolink-suggestions" class="button button-primary button-hero">
                    <span class="dashicons dashicons-admin-links"></span>
                    View Your Link Suggestions
                </a>
            </div>
        `);
        
        // Update button
        const $btnContent = $btn.find('.button-content');
        if ($btnContent.length) {
            $btnContent.find('.button-title').text('✓ All Up to Date');
            $btnContent.find('.button-subtitle').text('Add new posts and click again to generate more suggestions');
        }
    } else {
        // Show normal completion with new suggestions
        $status.addClass('active').html(`
            <div class="status-header" style="color: #10b981;">
                ✅ <span>Generation Complete!</span>
            </div>
            
            <div class="progress-bar-container">
                <div class="progress-bar-fill" style="width: 100%; background: #10b981;"></div>
            </div>
            
            <div class="status-details">
                <div class="status-row">
                    <span class="status-label">Posts Analyzed</span>
                    <span class="status-value">${(job.embeddings_generated || 0).toLocaleString()}</span>
                </div>
                <div class="status-row">
                    <span class="status-label">New Suggestions</span>
                    <span class="status-value">+${suggestionsGenerated.toLocaleString()}</span>
                </div>
                <div class="status-row">
                    <span class="status-label">Posts Processed</span>
                    <span class="status-value">${postsProcessed.toLocaleString()}</span>
                </div>
            </div>
            
            <div style="text-align: center; margin-top: 20px;">
                <a href="admin.php?page=texolink-suggestions" class="button button-primary button-hero">
                    <span class="dashicons dashicons-admin-links"></span>
                    View ${suggestionsGenerated.toLocaleString()} New Link Suggestions
                </a>
            </div>
        `);
        
        // Update button text
        const $btnContent = $btn.find('.button-content');
        if ($btnContent.length) {
            $btnContent.find('.button-title').text('✓ Generation Complete');
            $btnContent.find('.button-subtitle').text('Click "View Suggestions" above to see your new AI-generated links');
        } else {
            $btn.html('<span class="dashicons dashicons-yes"></span> Generate Again');
        }
    }
    
    $btn.prop('disabled', false);
}

/**
 * Show error UI
 */
function showErrorUI($btn, $status, job) {
    $status.html(
        '❌ <strong>Generation Failed</strong><br><br>' +
        '<div style="background: #f8d7da; border-left: 4px solid #dc3545; padding: 12px; margin-top: 10px;">' +
        'Error: ' + (job.error_message || 'Unknown error') + '<br><br>' +
        'Please try again or contact support if the issue persists.' +
        '</div>'
    );
    
    $btn.prop('disabled', false).text('Generate Suggestions for All Posts');
}

/**
 * Create a professional progress bar
 */
function createProgressBar(percent, phase) {
    let color = '#0073aa'; // WordPress blue
    
    if (phase === 'complete') {
        color = '#46b450'; // Green
    }
    
    return '<div style="background: #f0f0f0; border-radius: 4px; height: 24px; overflow: hidden; position: relative;">' +
        '<div style="background: ' + color + '; height: 100%; width: ' + percent + '%; transition: width 0.3s ease;"></div>' +
        '<div style="position: absolute; top: 0; left: 0; right: 0; bottom: 0; display: flex; align-items: center; justify-content: center; color: #000; font-weight: 600; font-size: 12px;">' +
        percent + '%' +
        '</div>' +
        '</div>';
}
        
        // ====================================================================
        // RESET ALL DATA BUTTON (WordPress + Railway)
        // ====================================================================
        
        $('#reset-all-data-btn').off('click').on('click', function() {
            const $btn = $(this);
            const $status = $('#reset-status');
            
            if (!confirm('⚠️ WARNING: Delete ALL data?\n\nThis will delete:\n• All link suggestions\n• All embeddings\n• All keywords\n• All cache\n\nFrom BOTH WordPress AND Railway backend.\n\nYou will need to re-analyze all posts and re-generate suggestions.\n\nAre you absolutely sure?')) {
                return;
            }
            
            // Double confirmation
            if (!confirm('This cannot be undone. Are you REALLY sure?')) {
                return;
            }
            
            $btn.prop('disabled', true).text('Resetting...');
            $status.show()
                .css('background', '#fff3cd')
                .css('border-left', '4px solid #ffc107')
                .html('<div class="spinner is-active" style="float: left; margin-right: 10px;"></div> Clearing WordPress cache...');
            
            // Step 1: Clear only TexoLink cache keys (not all storage!)
            ['texolink_main_suggestions_cache_v2', 'texolink_suggestions_cache', 'texolink_main_suggestions_cache_v1']
                .forEach(function(key) {
                    try { sessionStorage.removeItem(key); } catch(e) {}
                    try { localStorage.removeItem(key); } catch(e) {}
                });
            
            // Step 2: Call Railway to clear backend data
            const domain = texolinkSettings.siteDomain || window.location.hostname;
            
            $.ajax({
                url: texolinkSettings.apiUrl + '/sites/' + domain + '/clear',
                method: 'POST',
                contentType: 'application/json',
                timeout: 30000,
                success: function(response) {
                    $status
                        .css('background', '#d4edda')
                        .css('border-left', '4px solid #28a745')
                        .html(
                            '✓ <strong>All data cleared successfully!</strong><br>' +
                            'Deleted: ' + response.deleted.posts + ' posts, ' +
                            response.deleted.suggestions + ' suggestions, ' +
                            response.deleted.keywords + ' keywords, ' +
                            response.deleted.embeddings + ' embeddings<br>' +
                            '<em>Click "Generate Suggestions" to rebuild everything.</em>'
                        );
                    
                    $btn.prop('disabled', false).text('Reset All TexoLink Data');
                },
                error: function(xhr, status, error) {
                    // Safely extract error message (prevent XSS)
                    const errorMsg = (xhr.responseJSON && xhr.responseJSON.error) || error || 'Unknown error';
                    
                    $status
                        .css('background', '#f8d7da')
                        .css('border-left', '4px solid #dc3545')
                        .text('❌ Error clearing Railway data: ' + errorMsg);
                    
                    $btn.prop('disabled', false).text('Reset All TexoLink Data');
                }
            });
        });
        
        // Cleanup: Clear polling interval on page unload to prevent memory leaks
        $(window).on('beforeunload', function() {
            if (generationPollInterval) {
                clearInterval(generationPollInterval);
                console.log('TexoLink: Cleaned up polling interval');
            }
        });
        
        /**
         * Helper: Escape HTML to prevent XSS
         */
        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
        
    }); // End document.ready

})(jQuery);