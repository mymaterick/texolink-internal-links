/**
 * TexoLink Link Suggestions - LOAD ALL VERSION
 * Loads everything Railway provides and displays it immediately
 * All filtering happens in Railway backend
 */

jQuery(document).ready(function($) {
    // Debug mode - controlled by WordPress settings
    const DEBUG = typeof texolinkSettings !== 'undefined' && texolinkSettings.debug_mode == 1;
    
    /**
     * Debug logging function - only logs when debug mode is enabled
     */
    function debugLog(message, ...args) {
        if (DEBUG) {
            // Format message with emoji prefix for easy scanning
            if (typeof message === 'string') {
                console.log('🔍 TexoLink Debug:', message, ...args);
            } else {
                console.log('🔍 TexoLink Debug:', message, ...args);
            }
        }
    }
    
    // Log debug mode status on load
    if (DEBUG) {
        console.log('');
        console.log('═══════════════════════════════════════════════════');
        console.log('🔍 TexoLink Debug Mode ENABLED');
        console.log('═══════════════════════════════════════════════════');
        console.log('');
    }
    
    let allSuggestions = [];
    let insertedLinks = new Set();
    
    // Progressive loading state (still load in batches for performance)
    let allPosts = [];
    let currentPostIndex = 0;
    let isLoadingMore = false;
    const postsPerBatch = 20;  // Increased batch size
    
    
    // Load applied status on page load
    loadAppliedStatus();
    
    // Load suggestions on page load
    loadSuggestions();
    
    // Refresh button - reload suggestions
    $('#refresh-suggestions').on('click', function() {
        loadSuggestions();
    });
    
    // Filters - ALL client-side filters
    $('#filter-quality').on('change', function() {
        applyAllFilters();
    });
    
    $('#filter-post').on('change', function() {
        applyAllFilters();
    });
    
    $('#filter-min-words').on('input', function() {
        applyAllFilters();
    });
    
    $('#filter-max-words').on('input', function() {
        applyAllFilters();
    });
    
    $('#filter-keyword').on('keyup', function() {
        applyAllFilters();
    });
    
    $('#search-suggestions').on('keyup', function() {
        applyAllFilters();
    });
    
    // Clear filters button
    $('#clear-filters').on('click', function() {
        $('#filter-quality').val('all');
        $('#filter-post').val('all');
        $('#filter-min-words').val('');
        $('#filter-max-words').val('');
        $('#filter-keyword').val('');
        $('#search-suggestions').val('');
        applyAllFilters();
    });
    
    // ====================================================================
    // SORTING FUNCTIONALITY
    // ====================================================================
    let sortDirection = {};
    
    $('.sortable').on('click', function() {
        const sortKey = $(this).data('sort');
        const $arrow = $(this).find('.sort-arrow');
        
        // Toggle direction
        sortDirection[sortKey] = sortDirection[sortKey] === 'asc' ? 'desc' : 'asc';
        const isAsc = sortDirection[sortKey] === 'asc';
        
        // Update ALL arrows to default
        $('.sort-arrow').removeClass('active').text('⇅');
        
        // Update THIS arrow to show direction
        $arrow.addClass('active').text(isAsc ? '↑' : '↓');
        
        debugLog('Sorting by:', sortKey, isAsc ? 'ascending' : 'descending');
        
        // Sort allSuggestions array
        allSuggestions.sort((a, b) => {
            let valA, valB;
            
            switch(sortKey) {
                case 'source':
                    valA = a.source_title || '';
                    valB = b.source_title || '';
                    break;
                case 'target':
                    valA = a.target_title || '';
                    valB = b.target_title || '';
                    break;
                case 'similarity':
                    valA = parseFloat(a.relevance_score) || 0;
                    valB = parseFloat(b.relevance_score) || 0;
                    break;
                case 'anchor':
                    valA = a.primary_anchor || a.suggested_anchors[0] || '';
                    valB = b.primary_anchor || b.suggested_anchors[0] || '';
                    break;
            }
            
            // String comparison
            if (typeof valA === 'string') {
                return isAsc 
                    ? valA.localeCompare(valB)
                    : valB.localeCompare(valA);
            }
            
            // Number comparison
            return isAsc ? valA - valB : valB - valA;
        });
        
        debugLog('   → Sorted', allSuggestions.length, 'suggestions');
        
        // Re-apply filters (which calls displayAllSuggestions)
        applyAllFilters();
    });
    
    /**
     * Load applied status from WordPress database
     * NEW FUNCTION - loads all previously inserted links
     */
    function loadAppliedStatus(callback) {
        debugLog('loadAppliedStatus() called');
        debugLog('   → ajaxUrl:', texolinkSuggestions.ajaxUrl);
        debugLog('   → nonce:', texolinkSuggestions.nonce ? 'present' : 'MISSING');
        
        $.ajax({
            url: texolinkSuggestions.ajaxUrl,
            method: 'POST',
            data: {
                action: 'texolink_get_applied_suggestions',
                nonce: texolinkSuggestions.nonce
            },
            success: function(response) {
                debugLog('✓ AJAX success! Full response:', response);
                
                if (response.success) {
                    debugLog('   → response.success = true');
                    debugLog('   → response.data:', response.data);
                    
                    if (response.data.applied) {
                        debugLog('   → response.data.applied exists:', response.data.applied.length, 'items');
                        debugLog('   → First 5 items:', response.data.applied.slice(0, 5));
                        
                        // PHP already returns array of "sourceId-targetId" strings
                        insertedLinks = new Set(response.data.applied);
                        debugLog('   ✅ SUCCESS! Loaded', insertedLinks.size, 'inserted links from database');
                        debugLog('   → insertedLinks Set sample:', Array.from(insertedLinks).slice(0, 5));
                    } else {
                        console.warn('⚠️ TexoLink: response.data.applied is MISSING or falsy');
                    }
                } else {
                    console.error('❌ TexoLink: response.success = false', response.data);
                }
                
                if (callback) callback();
            },
            error: function(xhr, status, error) {
                console.error('❌ TexoLink: AJAX ERROR loading applied status');
                console.error('   → Status:', status);
                console.error('   → Error:', error);
                console.error('   → XHR responseText:', xhr.responseText);
                if (callback) callback();
            }
        });
    }
    
    /**
     * Apply ALL client-side filters
     */
    function applyAllFilters() {
        const quality = $('#filter-quality').val();
        const postId = $('#filter-post').val();
        const minWords = parseInt($('#filter-min-words').val()) || 0;
        const maxWords = parseInt($('#filter-max-words').val()) || 999;
        const keyword = $('#filter-keyword').val().toLowerCase().trim();
        const search = $('#search-suggestions').val().toLowerCase().trim();
        
        debugLog('applyAllFilters() called');
        debugLog('   → Starting with', allSuggestions.length, 'suggestions');
        debugLog('   → Filters: quality=' + quality + ', post=' + postId + ', words=' + minWords + '-' + maxWords + ', keyword="' + keyword + '", search="' + search + '"');
        
        let filtered = allSuggestions;
        const initialCount = filtered.length;
        
        // Filter by quality (relevance score)
        if (quality !== 'all') {
            filtered = filtered.filter(function(s) {
                const score = s.relevance_score || 0;
                if (quality === 'high') return score >= 0.7;
                if (quality === 'medium') return score >= 0.4 && score < 0.7;
                if (quality === 'low') return score < 0.4;
                return true;
            });
        }
        
        // Filter by post if selected
        if (postId !== 'all') {
            filtered = filtered.filter(s => s.source_post_id == postId);
        }
        
        // Filter by anchor text word count
        filtered = filtered.filter(function(s) {
            const anchorText = s.primary_anchor || s.suggested_anchors[0] || '';
            const wordCount = anchorText.trim().split(/\s+/).length;
            return wordCount >= minWords && wordCount <= maxWords;
        });
        
        // Filter by keyword in anchor text
        if (keyword) {
            filtered = filtered.filter(function(s) {
                const anchorText = (s.primary_anchor || s.suggested_anchors[0] || '').toLowerCase();
                return anchorText.includes(keyword);
            });
        }
        
        // Filter by search text in post titles
        if (search) {
            filtered = filtered.filter(function(s) {
                const titleMatch = (s.source_title || '').toLowerCase().includes(search) ||
                                 (s.target_title || '').toLowerCase().includes(search);
                return titleMatch;
            });
        }
        
        debugLog('   ✓ Filters applied:', initialCount, '→', filtered.length, 'suggestions');
        
        // Display ALL filtered results
        displayAllSuggestions(filtered);
    }
    
    /**
     * Load all suggestions from Railway backend
     * Backend does ALL filtering - we just display!
     */
    function loadSuggestions() {
        debugLog('loadSuggestions() called - fetching posts from Railway');
        
        $('#suggestions-loading').show();
        $('#suggestions-container').hide();
        $('#no-suggestions').hide();
        
        // Reset state
        allSuggestions = [];
        currentPostIndex = 0;
        allPosts = [];
        isLoadingMore = false;
        
        debugLog('   → Fetching posts from:', texolinkSettings.apiUrl + '/posts');
        
        // Get all posts list
        $.ajax({
            url: texolinkSettings.apiUrl + '/posts',
            method: 'GET',
            timeout: 30000,
            success: function(response) {
                debugLog('✓ Posts loaded successfully');
                debugLog('   → Found', response.posts ? response.posts.length : 0, 'posts');
                
                allPosts = response.posts || [];
                populatePostFilter(allPosts);
                
                if (allPosts.length === 0) {
                    debugLog('⚠️ No posts found - showing empty state');
                    $('#suggestions-loading').hide();
                    $('#no-suggestions').show();
                    return;
                }
                
                debugLog('   → Starting batch loading process...');
                // Load suggestions progressively
                loadNextBatch();
            },
            error: function(xhr, status, error) {
                console.error('❌ TexoLink: Error loading posts from Railway');
                console.error('   → Status:', status);
                console.error('   → Error:', error);
                console.error('   → API URL:', texolinkSettings.apiUrl + '/posts');
                $('#suggestions-loading').hide();
                alert('Error loading posts. Please check API connection.');
            }
        });
    }
    
    /**
     * Load suggestions for next batch of posts
     * Railway returns ONLY valid, filtered suggestions
     */
    function loadNextBatch() {
        if (isLoadingMore || currentPostIndex >= allPosts.length) {
            // All done - reload applied status and display everything!
            debugLog('✓ All batches loaded!');
            debugLog('   → Total suggestions collected:', allSuggestions.length);
            debugLog('   → Reloading applied status before display...');
            
            loadAppliedStatus(function() {
                $('#suggestions-loading').hide();
                
                if (allSuggestions.length === 0) {
                    debugLog('⚠️ No suggestions to display');
                    $('#no-suggestions').show();
                } else {
                    debugLog('✓ Displaying', allSuggestions.length, 'suggestions');
                    $('#suggestions-container').show();
                    displayAllSuggestions(allSuggestions);
                    updateStats();
                }
            });
            
            return;
        }
        
        isLoadingMore = true;
        
        // Get next batch of posts
        const batch = allPosts.slice(currentPostIndex, currentPostIndex + postsPerBatch);
        const batchIds = batch.map(p => p.wordpress_id);
        
        debugLog(`Loading batch: posts ${currentPostIndex + 1}-${currentPostIndex + batch.length} of ${allPosts.length}`);
        debugLog('   → Batch post IDs:', batchIds.slice(0, 5), '...');
        
        // Update progress
        const percent = Math.round((currentPostIndex / allPosts.length) * 100);
        $('#suggestions-loading').html(
            '<div class="spinner is-active"></div>' +
            '<p>Loading suggestions from Railway backend...</p>' +
            '<p style="color: #666; font-size: 13px;">Posts ' + (currentPostIndex + 1) + '-' + 
            (currentPostIndex + batch.length) + ' of ' + allPosts.length + ' (' + percent + '%)</p>' +
            '<p style="color: #2271b1; font-size: 12px;">✓ Railway pre-filters all suggestions</p>' +
            '<p style="color: #666; font-size: 12px;">Loaded ' + allSuggestions.length + ' suggestions so far...</p>'
        );
        
        // Load suggestions for this batch
        $.ajax({
            url: texolinkSettings.apiUrl + '/suggestions/batch',
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({
                post_ids: batchIds,
                site_domain: texolinkSettings.siteDomain || window.location.hostname
            }),
            timeout: 60000,
            success: function(response) {
                // Railway already filtered everything - just add to our list
                const newSuggestions = response.suggestions || [];
                allSuggestions = allSuggestions.concat(newSuggestions);
                
                debugLog(`✓ Batch complete: +${newSuggestions.length} suggestions (${allSuggestions.length} total)`);
                
                // Move to next batch
                currentPostIndex += batch.length;
                isLoadingMore = false;
                
                // Continue loading
                loadNextBatch();
            },
            error: function(xhr, status, error) {
                console.error('❌ TexoLink: Error loading batch from Railway');
                console.error('   → Status:', status);
                console.error('   → Error:', error);
                console.error('   → Batch posts:', currentPostIndex, '-', currentPostIndex + batch.length);
                isLoadingMore = false;
                
                // Continue with next batch despite error
                currentPostIndex += batch.length;
                loadNextBatch();
            }
        });
    }
    
    /**
     * Display ALL suggestions at once
     * No pagination - show everything!
     */
    function displayAllSuggestions(suggestions) {
        // DEBUG: Show insertedLinks state
        debugLog('displayAllSuggestions() called');
        debugLog('   → Displaying', suggestions.length, 'suggestions');
        debugLog('   → insertedLinks Set size:', insertedLinks.size);
        if (DEBUG && insertedLinks.size > 0) {
            debugLog('   → Sample insertedLinks:', Array.from(insertedLinks).slice(0, 10));
        }
        
        // Clear existing rows
        $('#suggestions-tbody').empty();
        
        if (suggestions.length === 0) {
            $('#no-suggestions').show();
            $('#suggestions-container').hide();
            return;
        }
        
        debugLog(`Building ${suggestions.length} table rows`);
        
        // Build all rows at once
        suggestions.forEach(function(suggestion, index) {
            const anchorText = suggestion.primary_anchor || suggestion.suggested_anchors[0] || '';
            const score = Math.round((suggestion.relevance_score || 0) * 100);
            const qualityClass = score >= 70 ? 'badge-high' : score >= 40 ? 'badge-medium' : 'badge-low';
            
            const key = suggestion.source_post_id + '-' + suggestion.target_post_id;
            const isInserted = insertedLinks.has(key);
            
            // DEBUG: First 3 suggestions only
            if (DEBUG && index < 3) {
                debugLog(`   → Row #${index}: "${suggestion.source_title}" → "${suggestion.target_title}"`);
                debugLog(`      Key: "${key}", isInserted: ${isInserted}`);
            }
            
            const row = `
                <tr data-source="${suggestion.source_post_id}" data-target="${suggestion.target_post_id}">
                    <td class="column-source">
                        ${escapeHtml(suggestion.source_title)}
                        <div class="row-actions">
                            <a href="/wp-admin/post.php?post=${suggestion.source_post_id}&action=edit" target="_blank">Edit</a>
                        </div>
                    </td>
                    <td class="column-target">
                        ${escapeHtml(suggestion.target_title)}
                        <div class="row-actions">
                            <a href="/?p=${suggestion.target_post_id}" target="_blank">View</a>
                        </div>
                    </td>
                    <td class="column-score">
                        <span class="quality-badge ${qualityClass}">${score}%</span>
                    </td>
                    <td class="column-anchor">
                        <strong>${escapeHtml(anchorText)}</strong>
                    </td>
                    <td class="column-actions">
                        ${isInserted ? 
                            '<span class="badge-inserted">✓ Inserted</span>' :
                            `<button class="button button-primary button-small insert-link-btn" 
                                    data-source="${suggestion.source_post_id}" 
                                    data-target="${suggestion.target_post_id}" 
                                    data-anchor="${escapeHtml(anchorText)}">
                                Insert Link
                            </button>`
                        }
                    </td>
                </tr>
            `;
            
            $('#suggestions-tbody').append(row);
        });
        
        // Hide load more buttons (not needed!)
        $('#load-more-container').hide();
        
        // Bind insert button handlers
        $('.insert-link-btn').off('click').on('click', function() {
            insertLink($(this));
        });
        
        // Load link counts
        if (typeof texolinkSettings !== 'undefined' && 
            (texolinkSettings.max_inbound_links > 0 || texolinkSettings.max_outbound_links > 0)) {
            loadLinkCounts();
        }
        
        // Update count display
        updateStats();
    }
    
    function populatePostFilter(posts) {
        const $select = $('#filter-post');
        $select.empty().append('<option value="all">All Posts</option>');
        
        posts.forEach(function(post) {
            $select.append(`<option value="${post.wordpress_id}">${escapeHtml(post.title)}</option>`);
        });
    }
    
    function insertLink($button) {
        const sourceId = $button.data('source');
        const targetId = $button.data('target');
        const anchorText = $button.data('anchor');
        
        debugLog('insertLink() called');
        debugLog('   → Source Post ID:', sourceId);
        debugLog('   → Target Post ID:', targetId);
        debugLog('   → Anchor Text:', anchorText);
        
        if (!confirm('Insert link "' + anchorText + '" into this post?')) {
            debugLog('   → User cancelled insertion');
            return;
        }
        
        $button.prop('disabled', true).text('Inserting...');
        
        $.ajax({
            url: texolinkSuggestions.ajaxUrl,
            method: 'POST',
            data: {
                action: 'texolink_insert_link',
                nonce: texolinkSuggestions.nonce,
                source_post_id: sourceId,
                target_post_id: targetId,
                anchor_text: anchorText
            },
            success: function(response) {
                debugLog('✓ Insert link AJAX response:', response);
                
                if (response.success) {
                    debugLog('   ✅ Link inserted successfully!');
                    debugLog('   → Response:', response.data);
                    
                    alert(response.data.message);
                    const key = sourceId + '-' + targetId;
                    insertedLinks.add(key);
                    debugLog('   → Added to insertedLinks Set:', key);
                    debugLog('   → insertedLinks size now:', insertedLinks.size);
                    
                    $button.closest('td').html('<span class="badge-inserted">✓ Inserted</span>');
                    updateStats();
                } else {
                    console.error('❌ TexoLink: Insert link failed');
                    console.error('   → Error:', response.data);
                    alert('Error: ' + response.data);
                    $button.prop('disabled', false).text('Insert Link');
                }
            },
            error: function(xhr, status, error) {
                console.error('❌ TexoLink: AJAX error inserting link');
                console.error('   → Status:', status);
                console.error('   → Error:', error);
                console.error('   → XHR responseText:', xhr.responseText);
                alert('Error inserting link. Please try again.');
                $button.prop('disabled', false).text('Insert Link');
            }
        });
    }
    
    function updateStats() {
        const total = allSuggestions.length;
        const highQuality = allSuggestions.filter(s => s.relevance_score >= 0.7).length;
        const inserted = insertedLinks.size;
        
        $('#total-suggestions').text(total);
        $('#high-quality').text(highQuality);
        $('#suggestions-used').text(inserted);
    }
    
    /**
     * Load link counts for displayed suggestions
     */
    function loadLinkCounts() {
        if (!texolinkSettings || (!texolinkSettings.max_inbound_links && !texolinkSettings.max_outbound_links)) {
            return;
        }
        
        const allPostIds = [];
        const rowData = [];
        
        $('#suggestions-tbody tr').each(function() {
            const $row = $(this);
            const sourceId = $row.data('source');
            const targetId = $row.data('target');
            
            if (targetId && !allPostIds.includes(targetId)) {
                allPostIds.push(targetId);
            }
            if (sourceId && !allPostIds.includes(sourceId)) {
                allPostIds.push(sourceId);
            }
            
            rowData.push({ source: sourceId, target: targetId });
        });
        
        if (allPostIds.length === 0) return;
        
        $.ajax({
            url: ajaxurl,
            method: 'POST',
            data: {
                action: 'tl_get_link_counts',
                post_ids: allPostIds.join(','),
                nonce: texolinkSettings.nonce
            },
            success: function(response) {
                if (response.success && response.data) {
                    const combinedCounts = {};
                    rowData.forEach(function(row) {
                        const key = row.source + '-' + row.target;
                        combinedCounts[key] = {
                            inbound: response.data[row.target] ? response.data[row.target].inbound : 0,
                            outbound: response.data[row.source] ? response.data[row.source].outbound : 0
                        };
                    });
                    updateLinkCountBadges(combinedCounts);
                }
            },
            error: function(error) {
                console.error('❌ TexoLink: Error loading link counts:', error);
            }
        });
    }
    
    /**
     * Update link count badges in the table
     */
    function updateLinkCountBadges(combinedCounts) {
        $('#suggestions-tbody tr').each(function() {
            const $row = $(this);
            const sourceId = $row.data('source');
            const targetId = $row.data('target');
            const key = sourceId + '-' + targetId;
            const $btn = $row.find('.insert-link-btn');
            
            if (!combinedCounts[key]) return;
            
            const counts = combinedCounts[key];
            const maxInbound = parseInt(texolinkSettings.max_inbound_links) || 0;
            const maxOutbound = parseInt(texolinkSettings.max_outbound_links) || 0;
            
            const inboundLimit = maxInbound > 0 && counts.inbound >= maxInbound;
            const outboundLimit = maxOutbound > 0 && counts.outbound >= maxOutbound;
            
            if (inboundLimit || outboundLimit) {
                $btn.prop('disabled', true).text('Limit Reached').addClass('button-disabled');
            }
            
            let badge = '<div class="link-count-badge" style="margin-top: 5px; font-size: 11px;">';
            badge += '<span style="color: #666;" title="Links TO this post">↓' + counts.inbound;
            if (maxInbound > 0) {
                badge += '/' + maxInbound;
                if (inboundLimit) badge += ' ⚠️';
            }
            badge += '</span> ';
            badge += '<span style="color: #666;" title="Links FROM source post">↑' + counts.outbound;
            if (maxOutbound > 0) {
                badge += '/' + maxOutbound;
                if (outboundLimit) badge += ' ⚠️';
            }
            badge += '</span>';
            badge += '</div>';
            
            $row.find('.column-score .link-count-badge').remove();
            $row.find('.column-score').append(badge);
        });
    }
    
    function escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
});