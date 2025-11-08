<?php
/**
 * TexoLink API Connection Diagnostic Tool
 * Run this from wp-admin or via command line to check Railway connection
 */

// If running from command line, fake WordPress environment
if (php_sapi_name() === 'cli') {
    echo "TexoLink API Connection Diagnostic\n";
    echo "===================================\n\n";
    
    echo "Instructions:\n";
    echo "1. Copy this file to your WordPress root directory\n";
    echo "2. Access it via browser: https://yoursite.com/check-api-connection.php\n";
    echo "   OR add it to your plugin and load WordPress:\n\n";
    
    echo "<?php\n";
    echo "// Add to a WordPress admin page or run with WP-CLI\n";
    echo "require_once('wp-load.php');\n";
    echo "include 'check-api-connection.php';\n";
    echo "?>\n\n";
    exit;
}

// WordPress is loaded, run diagnostics
if (!function_exists('get_option')) {
    die("Error: WordPress not loaded. Please access this via WordPress admin or use wp-load.php");
}

echo "<html><head><title>TexoLink API Diagnostic</title>";
echo "<style>
body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; padding: 20px; background: #f0f0f1; }
.container { max-width: 800px; margin: 0 auto; background: white; padding: 30px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
h1 { color: #1d2327; border-bottom: 2px solid #2271b1; padding-bottom: 10px; }
h2 { color: #2271b1; margin-top: 30px; }
.success { background: #d7f0db; border-left: 4px solid #46b450; padding: 12px; margin: 10px 0; }
.error { background: #f8d7da; border-left: 4px solid #dc3232; padding: 12px; margin: 10px 0; }
.warning { background: #fff8e5; border-left: 4px solid #f0b849; padding: 12px; margin: 10px 0; }
.info { background: #e8f5fc; border-left: 4px solid #2271b1; padding: 12px; margin: 10px 0; }
pre { background: #f6f7f7; padding: 15px; border-radius: 4px; overflow-x: auto; }
code { background: #f6f7f7; padding: 2px 6px; border-radius: 3px; }
.test-item { margin: 15px 0; padding: 15px; background: #f6f7f7; border-radius: 4px; }
</style></head><body>";

echo "<div class='container'>";
echo "<h1>🔍 TexoLink API Connection Diagnostic</h1>";

// Test 1: Check API URL setting
echo "<h2>1. API URL Configuration</h2>";
$api_url = get_option('texolink_api_url', '');

if (empty($api_url)) {
    echo "<div class='error'><strong>❌ ERROR:</strong> API URL is not set!</div>";
    echo "<p>Go to <strong>TexoLink → Settings</strong> and configure your Railway URL.</p>";
} else {
    echo "<div class='success'><strong>✓ API URL is configured:</strong> <code>" . esc_html($api_url) . "</code></div>";
    
    // Check if it's still localhost
    if (strpos($api_url, 'localhost') !== false || strpos($api_url, '127.0.0.1') !== false) {
        echo "<div class='warning'><strong>⚠️ WARNING:</strong> URL points to localhost! This won't work from a live site.</div>";
        echo "<p>Update to your Railway URL (e.g., <code>https://yourapp.railway.app/api</code>)</p>";
    }
}

// Test 2: Test PHP connection
echo "<h2>2. PHP Backend Connection Test</h2>";
if (!empty($api_url)) {
    echo "<div class='test-item'>";
    echo "<strong>Testing connection to:</strong> " . esc_html($api_url) . "/health<br><br>";
    
    $response = wp_remote_get($api_url . '/health', array(
        'timeout' => 10,
        'headers' => array('Accept' => 'application/json')
    ));
    
    if (is_wp_error($response)) {
        echo "<div class='error'><strong>❌ Connection failed:</strong> " . $response->get_error_message() . "</div>";
        echo "<p><strong>Possible causes:</strong></p>";
        echo "<ul>";
        echo "<li>Railway backend is not running</li>";
        echo "<li>URL is incorrect</li>";
        echo "<li>Firewall or network issue</li>";
        echo "</ul>";
    } else {
        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        
        echo "<strong>HTTP Status:</strong> " . $code . "<br>";
        echo "<strong>Response:</strong><pre>" . esc_html($body) . "</pre>";
        
        if ($code >= 200 && $code < 300) {
            echo "<div class='success'><strong>✓ PHP connection successful!</strong></div>";
        } else {
            echo "<div class='error'><strong>❌ HTTP error code " . $code . "</strong></div>";
        }
    }
    echo "</div>";
} else {
    echo "<div class='warning'>Skipped - no API URL configured</div>";
}

// Test 3: Test JavaScript connection (CORS check)
echo "<h2>3. JavaScript/CORS Connection Test</h2>";
if (!empty($api_url)) {
    echo "<div class='test-item'>";
    echo "<p>Testing if browser can access Railway directly (required for Link Suggestions page)...</p>";
    echo "<div id='js-test-result'>Testing...</div>";
    echo "<script>
    fetch('" . esc_js($api_url) . "/health')
        .then(response => {
            if (response.ok) {
                document.getElementById('js-test-result').innerHTML = '<div class=\"success\"><strong>✓ JavaScript connection successful!</strong> Browser can access Railway.</div>';
            } else {
                document.getElementById('js-test-result').innerHTML = '<div class=\"error\"><strong>❌ HTTP ' + response.status + '</strong> - Server responded but with error code.</div>';
            }
        })
        .catch(error => {
            document.getElementById('js-test-result').innerHTML = '<div class=\"error\"><strong>❌ CORS or network error:</strong> ' + error.message + '</div>' +
                '<p><strong>This is the problem!</strong> The browser cannot access Railway directly.</p>' +
                '<p><strong>Solution:</strong> Your Railway backend needs to allow CORS from your WordPress domain.</p>' +
                '<p>In your Railway Python Flask app, add:</p><pre>from flask_cors import CORS\\nCORS(app, origins=[\"' + window.location.origin + '\"])</pre>';
        });
    </script>";
    echo "</div>";
} else {
    echo "<div class='warning'>Skipped - no API URL configured</div>";
}

// Test 4: Check for posts in backend
echo "<h2>4. Backend Data Check</h2>";
if (!empty($api_url)) {
    echo "<div class='test-item'>";
    $response = wp_remote_get($api_url . '/posts', array(
        'timeout' => 10,
        'headers' => array('Accept' => 'application/json')
    ));
    
    if (!is_wp_error($response)) {
        $body = json_decode(wp_remote_retrieve_body($response), true);
        $post_count = isset($body['posts']) ? count($body['posts']) : 0;
        
        if ($post_count > 0) {
            echo "<div class='success'><strong>✓ Found " . $post_count . " posts in backend</strong></div>";
        } else {
            echo "<div class='warning'><strong>⚠️ No posts found in backend</strong></div>";
            echo "<p>You may need to sync posts from the Bulk Analyzer page.</p>";
        }
    } else {
        echo "<div class='error'>Could not check backend data: " . $response->get_error_message() . "</div>";
    }
    echo "</div>";
}

// Test 5: Localized script check
echo "<h2>5. JavaScript Configuration Check</h2>";
echo "<div class='test-item'>";
echo "<p>Checking if the API URL is properly passed to JavaScript...</p>";
echo "<script>
if (typeof texolinkSettings !== 'undefined' && texolinkSettings.apiUrl) {
    document.write('<div class=\"success\"><strong>✓ JavaScript has API URL:</strong> ' + texolinkSettings.apiUrl + '</div>');
} else {
    document.write('<div class=\"error\"><strong>❌ JavaScript does not have API URL!</strong> This means the script localization failed.</div>');
    document.write('<p>The plugin needs to call wp_localize_script() to pass the URL to JavaScript.</p>');
}
</script>";
echo "</div>";

// Recommendations
echo "<h2>📋 Summary & Recommendations</h2>";
echo "<div class='info'>";
echo "<strong>Quick Fix Checklist:</strong><br><br>";
echo "1. ✓ Set your Railway URL in <strong>TexoLink → Settings</strong><br>";
echo "   Example: <code>https://texolink-production.up.railway.app/api</code><br><br>";
echo "2. ✓ Enable CORS in your Railway Flask app (see test #3 above)<br><br>";
echo "3. ✓ Sync posts from <strong>TexoLink → Bulk Analyzer</strong><br><br>";
echo "4. ✓ Test the Link Suggestions page<br>";
echo "</div>";

echo "</div></body></html>";
?>
