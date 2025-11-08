/**
 * TexoLink Gutenberg Editor Integration
 */

(function(wp) {
    const { registerPlugin } = wp.plugins;
    const { PluginSidebar, PluginSidebarMoreMenuItem } = wp.editPost;
    const { PanelBody, Button, Spinner, Notice } = wp.components;
    const { createElement: el, Fragment, useState, useEffect } = wp.element;
    const { useSelect } = wp.data;
    
    const TexoLinkSidebar = () => {
        const [suggestions, setSuggestions] = useState([]);
        const [loading, setLoading] = useState(false);
        const [error, setError] = useState(null);
        const [syncing, setSyncing] = useState(false);
        
        const postId = useSelect((select) => {
            return select('core/editor').getCurrentPostId();
        });
        
        const postTitle = useSelect((select) => {
            return select('core/editor').getEditedPostAttribute('title');
        });
        
        // Load suggestions
        const loadSuggestions = () => {
            if (!postId) return;
            
            setLoading(true);
            setError(null);
            
            jQuery.ajax({
                url: texolinkEditor.ajaxUrl,
                method: 'POST',
                data: {
                    action: 'texolink_get_suggestions',
                    nonce: texolinkEditor.nonce,
                    post_id: postId
                },
                success: (response) => {
                    if (response.success) {
                        setSuggestions(response.data || []);
                    } else {
                        setError(response.data || 'Failed to load suggestions');
                    }
                    setLoading(false);
                },
                error: () => {
                    setError('Connection error');
                    setLoading(false);
                }
            });
        };
        
        // Sync post to backend
        const syncPost = () => {
            if (!postId) return;
            
            setSyncing(true);
            
            jQuery.ajax({
                url: texolinkEditor.ajaxUrl,
                method: 'POST',
                data: {
                    action: 'texolink_sync_post',
                    nonce: texolinkEditor.nonce,
                    post_id: postId
                },
                success: (response) => {
                    setSyncing(false);
                    if (response.success) {
                        // Reload suggestions after sync
                        setTimeout(loadSuggestions, 1000);
                    } else {
                        setError(response.data || 'Failed to sync post');
                    }
                },
                error: () => {
                    setSyncing(false);
                    setError('Connection error');
                }
            });
        };
        
        // Apply a link suggestion
        const applyLink = (suggestion) => {
            if (!suggestion.wordpress_target_id) {
                alert('Target post not found in WordPress');
                return;
            }
            
            if (!confirm(`Insert link to "${suggestion.target_title}"?`)) {
                return;
            }
            
            jQuery.ajax({
                url: texolinkEditor.ajaxUrl,
                method: 'POST',
                data: {
                    action: 'texolink_apply_link',
                    nonce: texolinkEditor.nonce,
                    post_id: postId,
                    target_id: suggestion.wordpress_target_id,
                    anchor_text: suggestion.primary_anchor
                },
                success: (response) => {
                    if (response.success) {
                        alert('Link inserted! Save your post to keep the changes.');
                        // Reload the editor
                        window.location.reload();
                    } else {
                        alert('Error: ' + (response.data || 'Failed to insert link'));
                    }
                },
                error: () => {
                    alert('Connection error');
                }
            });
        };
        
        // Load suggestions on mount
        useEffect(() => {
            if (postId) {
                loadSuggestions();
            }
        }, [postId]);
        
        return el(
            Fragment,
            {},
            el(
                PluginSidebarMoreMenuItem,
                {
                    target: 'texolink-sidebar',
                    icon: 'admin-links'
                },
                'TexoLink'
            ),
            el(
                PluginSidebar,
                {
                    name: 'texolink-sidebar',
                    title: 'TexoLink AI',
                    icon: 'admin-links'
                },
                el(
                    'div',
                    { className: 'texolink-sidebar-content' },
                    
                    // Header
                    el(
                        'div',
                        { className: 'texolink-sidebar-header' },
                        el('h3', {}, 'AI Link Suggestions'),
                        el('p', { className: 'description' }, 
                            'Smart internal links for: ' + (postTitle || 'your post')
                        )
                    ),
                    
                    // Sync Button
                    el(
                        'div',
                        { className: 'texolink-sidebar-actions' },
                        el(
                            Button,
                            {
                                isPrimary: true,
                                isBusy: syncing,
                                onClick: syncPost,
                                disabled: syncing || loading
                            },
                            syncing ? 'Syncing...' : 'Analyze with AI'
                        )
                    ),
                    
                    // Error Notice
                    error && el(
                        Notice,
                        {
                            status: 'error',
                            isDismissible: true,
                            onRemove: () => setError(null)
                        },
                        error
                    ),
                    
                    // Loading State
                    loading && el(
                        'div',
                        { className: 'texolink-loading' },
                        el(Spinner),
                        el('p', {}, 'Loading suggestions...')
                    ),
                    
                    // Suggestions List
                    !loading && suggestions.length > 0 && el(
                        PanelBody,
                        { title: `${suggestions.length} Suggestions Found`, initialOpen: true },
                        suggestions.map((suggestion, index) => 
                            el(
                                'div',
                                { 
                                    key: index,
                                    className: 'texolink-suggestion'
                                },
                                el(
                                    'div',
                                    { className: 'suggestion-header' },
                                    el('strong', {}, suggestion.target_title),
                                    el(
                                        'span',
                                        { 
                                            className: 'suggestion-score',
                                            style: {
                                                background: suggestion.relevance_score >= 0.7 ? '#10b981' :
                                                           suggestion.relevance_score >= 0.5 ? '#3b82f6' : '#64748b',
                                                color: 'white',
                                                padding: '2px 8px',
                                                borderRadius: '12px',
                                                fontSize: '11px',
                                                fontWeight: '600'
                                            }
                                        },
                                        Math.round(suggestion.relevance_score * 100) + '%'
                                    )
                                ),
                                el('p', { className: 'suggestion-reason' }, suggestion.reason),
                                el(
                                    'div',
                                    { className: 'suggestion-anchor' },
                                    el('small', {}, 'Anchor: '),
                                    el('code', {}, suggestion.primary_anchor)
                                ),
                                el(
                                    Button,
                                    {
                                        isSecondary: true,
                                        isSmall: true,
                                        onClick: () => applyLink(suggestion),
                                        style: { marginTop: '10px' }
                                    },
                                    'Insert Link'
                                )
                            )
                        )
                    ),
                    
                    // Empty State
                    !loading && suggestions.length === 0 && !error && el(
                        'div',
                        { className: 'texolink-empty' },
                        el('p', {}, 'No suggestions yet.'),
                        el('p', { className: 'description' }, 
                            'Click "Analyze with AI" to get smart link recommendations!'
                        )
                    )
                )
            )
        );
    };
    
    // Register the plugin
    registerPlugin('texolink', {
        render: TexoLinkSidebar
    });
    
})(window.wp);
