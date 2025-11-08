<?php
/**
 * Link Suggestions Page View
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}
?>

<div class="wrap texolink-suggestions-page">
    <h1>
        <?php echo esc_html__('Link Suggestions', 'texolink'); ?>
        <button type="button" class="button button-primary" id="refresh-suggestions">
            <span class="dashicons dashicons-update"></span> Refresh
        </button>
    </h1>
    
    <div class="texolink-stats-cards">
        <div class="stats-card">
            <div class="stats-icon">🔗</div>
            <div class="stats-content">
                <div class="stats-value" id="total-suggestions">-</div>
                <div class="stats-label">Total Suggestions</div>
            </div>
        </div>
        <div class="stats-card">
            <div class="stats-icon">✅</div>
            <div class="stats-content">
                <div class="stats-value" id="suggestions-used">0</div>
                <div class="stats-label">Links Inserted</div>
            </div>
        </div>
        <div class="stats-card">
            <div class="stats-icon">⚡</div>
            <div class="stats-content">
                <div class="stats-value" id="high-quality">-</div>
                <div class="stats-label">High Quality (>70%)</div>
            </div>
        </div>
    </div>
    
    <div class="texolink-filter-info" style="background: #f0f6fc; border-left: 4px solid #2271b1; padding: 12px 15px; margin: 20px 0 10px; border-radius: 3px;">
        <strong>📊 Filter Tips:</strong> Use word count filters to find anchors with specific lengths (e.g., 2-4 words). 
        Use the keyword filter to find anchors containing specific terms (e.g., "SEO", "guide", "tutorial").
    </div>
    
    <div class="texolink-filters">
        <select id="filter-quality">
            <option value="all">All Suggestions</option>
            <option value="high">High Quality (>70%)</option>
            <option value="medium">Medium Quality (40-70%)</option>
            <option value="low">Low Quality (<40%)</option>
        </select>
        
        <select id="filter-post">
            <option value="all">All Posts</option>
        </select>
        
        <input type="number" id="filter-min-words" placeholder="Min words" min="1" style="width: 100px;" title="Minimum number of words in suggested anchor text" />
        
        <input type="number" id="filter-max-words" placeholder="Max words" min="1" style="width: 100px;" title="Maximum number of words in suggested anchor text" />
        
        <input type="text" id="filter-keyword" placeholder="Contains word..." style="width: 150px;" title="Show only suggestions where anchor contains this word (e.g., 'SEO')" />
        
        <input type="text" id="search-suggestions" placeholder="Search posts..." />
        
        <button type="button" class="button" id="clear-filters" style="margin-left: auto;">Clear Filters</button>
    </div>
    
    <div id="suggestions-loading" class="loading-spinner">
        <div class="spinner"></div>
        <p>Loading suggestions...</p>
    </div>
    
    <div id="suggestions-container" style="display:none;">
        <table class="wp-list-table widefat fixed striped texolink-suggestions-table">
            <thead>
    <tr>
        <th class="sortable" data-sort="source">
            Source Post <span class="sort-arrow">⇅</span>
        </th>
        <th class="sortable" data-sort="target">
            Suggested Link To <span class="sort-arrow">⇅</span>
        </th>
        <th class="sortable" data-sort="similarity">
            Similarity <span class="sort-arrow">⇅</span>
        </th>
        <th class="sortable" data-sort="anchor">
            Suggested Anchor <span class="sort-arrow">⇅</span>
        </th>
        <th>Actions</th>
    </tr>
</thead>
            <tbody id="suggestions-tbody">
                <!-- Suggestions will be loaded here via JavaScript -->
            </tbody>
        </table>
        
        <div id="load-more-container" style="text-align: center; padding: 20px; display: none;">
            <button type="button" class="button button-primary button-large" id="load-more-btn">
                <span class="dashicons dashicons-download" style="margin-top: 3px;"></span>
                Load More (10)
            </button>
            <button type="button" class="button button-secondary button-large" id="show-all-btn" style="margin-left: 10px;">
                Show All Remaining
            </button>
            <div style="margin-top: 10px; color: #666; font-size: 13px;">
                <span id="showing-count">Showing 0</span> of <span id="total-filtered">0</span> suggestions
            </div>
        </div>
    </div>
    
    <div id="no-suggestions" style="display:none;" class="notice notice-info">
        <p><strong>No suggestions found.</strong> Try analyzing more posts or adjusting your filters.</p>
    </div>
</div>

<style>
.texolink-suggestions-page {
    max-width: 1400px;
}

.texolink-suggestions-page h1 .button {
    margin-left: 10px;
    vertical-align: middle;
}

/* Stats Cards */
.texolink-stats-cards {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 20px;
    margin: 20px 0;
}

.stats-card {
    background: white;
    border: 1px solid #ddd;
    border-radius: 8px;
    padding: 20px;
    display: flex;
    align-items: center;
    gap: 15px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.05);
}

.stats-icon {
    font-size: 32px;
    line-height: 1;
}

.stats-content {
    flex: 1;
}

.stats-value {
    font-size: 32px;
    font-weight: bold;
    color: #2271b1;
    line-height: 1.2;
}

.stats-label {
    font-size: 13px;
    color: #666;
    margin-top: 5px;
}

/* Filters */
.texolink-filters {
    background: white;
    border: 1px solid #ddd;
    padding: 15px;
    margin: 20px 0;
    border-radius: 4px;
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
}

.texolink-filters select,
.texolink-filters input[type="text"],
.texolink-filters input[type="number"] {
    max-width: 250px;
}

/* Loading Spinner */
.loading-spinner {
    text-align: center;
    padding: 60px 20px;
    background: white;
    border: 1px solid #ddd;
    border-radius: 4px;
}

.loading-spinner .spinner {
    display: inline-block;
    width: 40px;
    height: 40px;
    border: 4px solid #f3f3f3;
    border-top: 4px solid #2271b1;
    border-radius: 50%;
    animation: spin 1s linear infinite;
}

@keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}

/* Suggestions Table */
.texolink-suggestions-table {
    margin-top: 20px;
    background: white;
}

.texolink-suggestions-table th {
    font-weight: 600;
}

.column-source {
    width: 30%;
}

.column-target {
    width: 30%;
}

.column-score {
    width: 12%;
}

.column-anchor {
    width: 18%;
}

.column-actions {
    width: 10%;
    text-align: center;
}

/* Similarity Badges */
.similarity-badge {
    display: inline-block;
    padding: 4px 12px;
    border-radius: 12px;
    font-size: 13px;
    font-weight: 600;
    color: white;
}

.similarity-badge.high {
    background: #00a32a;
}

.similarity-badge.medium {
    background: #f0b849;
}

.similarity-badge.low {
    background: #999;
}

/* Action Buttons */
.button-small {
    padding: 4px 12px;
    height: auto;
    font-size: 13px;
    line-height: 1.5;
}

.badge-inserted {
    display: inline-block;
    padding: 4px 12px;
    background: #00a32a;
    color: white;
    border-radius: 12px;
    font-size: 13px;
    font-weight: 600;
}

/* Row Actions */
.row-actions {
    margin-top: 5px;
}

.row-actions span {
    padding: 0 5px 0 0;
}

.row-actions a {
    color: #2271b1;
    text-decoration: none;
    font-size: 13px;
}

.row-actions a:hover {
    color: #135e96;
}

/* Code styling for anchor text */
code {
    background: #f5f5f5;
    padding: 2px 6px;
    border-radius: 3px;
    font-size: 12px;
}

/* Responsive */
@media (max-width: 782px) {
    .texolink-stats-cards {
        grid-template-columns: 1fr;
    }
    
    .texolink-filters {
        flex-direction: column;
    }
    
    .texolink-filters select,
    .texolink-filters input[type="text"],
    .texolink-filters input[type="number"] {
        max-width: 100%;
    }
}

/* Sortable table headers */
.sortable {
    cursor: pointer;
    user-select: none;
    transition: background-color 0.2s;
}

.sortable:hover {
    background-color: #f0f0f0;
}

.sort-arrow {
    font-size: 12px;
    color: #999;
    margin-left: 5px;
    display: inline-block;
    width: 12px;
    text-align: center;
}

.sortable:hover .sort-arrow {
    color: #2271b1;
}

.sort-arrow.active {
    color: #2271b1;
    font-weight: bold;
}
</style>
