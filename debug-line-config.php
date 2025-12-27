<?php
/**
 * LINE Configuration Debug Tool
 * Shows current LINE channel configuration for troubleshooting
 */

require_once __DIR__ . '/includes/Database.php';

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html>
<head>
    <title>LINE Channel Configuration Debug</title>
    <style>
        body { font-family: monospace; padding: 20px; background: #f5f5f5; }
        .section { background: white; padding: 15px; margin: 15px 0; border-radius: 8px; border-left: 4px solid #06c755; }
        .success { color: #10b981; }
        .error { color: #ef4444; }
        .warning { color: #f59e0b; }
        h2 { color: #06c755; }
        pre { background: #f9f9f9; padding: 10px; border-radius: 4px; overflow-x: auto; }
        .status { padding: 5px 10px; border-radius: 4px; display: inline-block; }
        .status.ok { background: #d1fae5; color: #065f46; }
        .status.fail { background: #fee2e2; color: #991b1b; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 8px; text-align: left; border-bottom: 1px solid #ddd; }
        th { background: #f9f9f9; }
    </style>
</head>
<body>
    <h1>🔍 LINE Channel Configuration Debug</h1>
    
    <?php
    try {
        $db = Database::getInstance();
        
        // Check database connection
        echo '<div class="section">';
        echo '<h2>✅ Database Connection</h2>';
        echo '<p class="success">Connected successfully</p>';
        echo '</div>';
        
        // Get LINE channels
        $channels = $db->query("
            SELECT id, user_id, name, type, inbound_api_key, bot_profile_id, status, config, created_at
            FROM customer_channels 
            WHERE type = 'line' AND is_deleted = 0
        ");
        
        echo '<div class="section">';
        echo '<h2>📱 LINE Channels (' . count($channels) . ' found)</h2>';
        
        if (empty($channels)) {
            echo '<p class="warning">⚠️ No LINE channels configured yet!</p>';
            echo '<p>Please add a LINE channel in the admin panel first.</p>';
        } else {
            foreach ($channels as $channel) {
                echo '<div style="margin-bottom: 20px; padding: 15px; background: #fafafa; border-radius: 6px;">';
                echo '<h3>' . htmlspecialchars($channel['name']) . ' <span class="status ' . ($channel['status'] === 'active' ? 'ok' : 'fail') . '">' . $channel['status'] . '</span></h3>';
                
                echo '<table>';
                echo '<tr><th>Field</th><th>Value</th><th>Status</th></tr>';
                
                // Channel ID
                echo '<tr><td><strong>Channel ID</strong></td><td>' . $channel['id'] . '</td><td class="success">✓</td></tr>';
                
                // User ID
                echo '<tr><td><strong>User ID</strong></td><td>' . $channel['user_id'] . '</td><td class="success">✓</td></tr>';
                
                // Inbound API Key
                $hasApiKey = !empty($channel['inbound_api_key']);
                echo '<tr><td><strong>Inbound API Key</strong></td><td>' . 
                     ($hasApiKey ? htmlspecialchars(substr($channel['inbound_api_key'], 0, 20)) . '...' : '<span class="error">NOT SET</span>') . 
                     '</td><td class="' . ($hasApiKey ? 'success' : 'error') . '">' . ($hasApiKey ? '✓' : '✗') . '</td></tr>';
                
                // Bot Profile
                $hasBotProfile = !empty($channel['bot_profile_id']);
                echo '<tr><td><strong>Bot Profile ID</strong></td><td>' . 
                     ($hasBotProfile ? $channel['bot_profile_id'] : '<span class="warning">Not assigned (will use default)</span>') . 
                     '</td><td class="' . ($hasBotProfile ? 'success' : 'warning') . '">' . ($hasBotProfile ? '✓' : '⚠') . '</td></tr>';
                
                // Webhook URL
                $webhookUrl = 'https://autobot.boxdesign.in.th/api/webhooks/line.php';
                echo '<tr><td><strong>Webhook URL</strong></td><td><code>' . $webhookUrl . '</code></td><td>📋 Copy this to LINE Console</td></tr>';
                
                // Configuration
                $config = json_decode($channel['config'], true);
                $hasChannelSecret = !empty($config['channel_secret']);
                $hasAccessToken = !empty($config['channel_access_token']);
                
                echo '<tr><td><strong>Channel Secret</strong></td><td>' . 
                     ($hasChannelSecret ? '<span class="success">✓ Configured (' . strlen($config['channel_secret']) . ' chars)</span>' : '<span class="error">✗ NOT SET</span>') . 
                     '</td><td class="' . ($hasChannelSecret ? 'success' : 'error') . '">' . ($hasChannelSecret ? '✓' : '✗') . '</td></tr>';
                
                echo '<tr><td><strong>Channel Access Token</strong></td><td>' . 
                     ($hasAccessToken ? '<span class="success">✓ Configured (' . strlen($config['channel_access_token']) . ' chars)</span>' : '<span class="error">✗ NOT SET</span>') . 
                     '</td><td class="' . ($hasAccessToken ? 'success' : 'error') . '">' . ($hasAccessToken ? '✓' : '✗') . '</td></tr>';
                
                echo '<tr><td><strong>Created At</strong></td><td>' . $channel['created_at'] . '</td><td class="success">✓</td></tr>';
                
                echo '</table>';
                
                // Configuration JSON (masked)
                echo '<h4>📄 Configuration JSON</h4>';
                $maskedConfig = $config;
                if (isset($maskedConfig['channel_secret'])) {
                    $maskedConfig['channel_secret'] = substr($maskedConfig['channel_secret'], 0, 10) . '...[MASKED]';
                }
                if (isset($maskedConfig['channel_access_token'])) {
                    $maskedConfig['channel_access_token'] = substr($maskedConfig['channel_access_token'], 0, 20) . '...[MASKED]';
                }
                echo '<pre>' . json_encode($maskedConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . '</pre>';
                
                // Status Summary
                echo '<h4>📊 Status Summary</h4>';
                $allGood = $hasApiKey && $hasChannelSecret && $hasAccessToken && ($channel['status'] === 'active');
                
                if ($allGood) {
                    echo '<p class="success"><strong>✅ Configuration looks good!</strong></p>';
                    echo '<p>Next steps:</p>';
                    echo '<ol>';
                    echo '<li>Copy webhook URL: <code>' . $webhookUrl . '</code></li>';
                    echo '<li>Go to LINE Developers Console → Messaging API tab</li>';
                    echo '<li>Paste webhook URL and click "Verify"</li>';
                    echo '<li>Enable "Use webhook" (toggle to green)</li>';
                    echo '<li>Disable "Auto-reply messages"</li>';
                    echo '<li>Send a test message to your LINE bot</li>';
                    echo '</ol>';
                } else {
                    echo '<p class="error"><strong>❌ Configuration issues found:</strong></p>';
                    echo '<ul>';
                    if (!$hasApiKey) echo '<li class="error">Missing inbound API key</li>';
                    if (!$hasChannelSecret) echo '<li class="error">Missing channel secret</li>';
                    if (!$hasAccessToken) echo '<li class="error">Missing channel access token</li>';
                    if ($channel['status'] !== 'active') echo '<li class="error">Channel is not active</li>';
                    echo '</ul>';
                    echo '<p>Please fix these issues in the admin panel.</p>';
                }
                
                echo '</div>';
            }
        }
        echo '</div>';
        
        // Check bot profiles
        $profiles = $db->query("
            SELECT id, user_id, name, handler_key, is_default, is_active
            FROM customer_bot_profiles 
            WHERE is_deleted = 0
        ");
        
        echo '<div class="section">';
        echo '<h2>🤖 Bot Profiles (' . count($profiles) . ' found)</h2>';
        
        if (empty($profiles)) {
            echo '<p class="warning">⚠️ No bot profiles configured. System will use default handler.</p>';
        } else {
            echo '<table>';
            echo '<tr><th>ID</th><th>Name</th><th>Handler</th><th>Default</th><th>Active</th></tr>';
            foreach ($profiles as $profile) {
                echo '<tr>';
                echo '<td>' . $profile['id'] . '</td>';
                echo '<td>' . htmlspecialchars($profile['name']) . '</td>';
                echo '<td>' . htmlspecialchars($profile['handler_key']) . '</td>';
                echo '<td>' . ($profile['is_default'] ? '✓' : '-') . '</td>';
                echo '<td>' . ($profile['is_active'] ? '✓' : '✗') . '</td>';
                echo '</tr>';
            }
            echo '</table>';
        }
        echo '</div>';
        
        // System info
        echo '<div class="section">';
        echo '<h2>⚙️ System Information</h2>';
        echo '<table>';
        echo '<tr><td><strong>PHP Version</strong></td><td>' . phpversion() . '</td></tr>';
        echo '<tr><td><strong>Server Time</strong></td><td>' . date('Y-m-d H:i:s') . '</td></tr>';
        echo '<tr><td><strong>Log Directory</strong></td><td>' . realpath(__DIR__ . '/logs') . '</td></tr>';
        $logFile = __DIR__ . '/logs/app-' . date('Y-m-d') . '.log';
        echo '<tr><td><strong>Today\'s Log File</strong></td><td>' . (file_exists($logFile) ? '✓ Exists' : '✗ Not created yet') . '</td></tr>';
        echo '</table>';
        echo '</div>';
        
    } catch (Exception $e) {
        echo '<div class="section">';
        echo '<h2 class="error">❌ Error</h2>';
        echo '<p class="error">Failed to retrieve configuration: ' . htmlspecialchars($e->getMessage()) . '</p>';
        echo '</div>';
    }
    ?>
    
    <div class="section">
        <h2>📖 Helpful Commands</h2>
        <p><strong>View latest logs:</strong></p>
        <pre>tail -f /opt/lampp/htdocs/autobot/logs/app-$(date +%Y-%m-%d).log</pre>
        
        <p><strong>Test webhook locally:</strong></p>
        <pre>curl -X POST http://localhost/autobot/api/webhooks/line.php \
  -H "Content-Type: application/json" \
  -d '{"events":[]}'</pre>
    </div>
    
    <p style="text-align: center; color: #999; margin-top: 30px;">
        LINE Chatbot Debug Tool v1.0 | Generated at <?php echo date('Y-m-d H:i:s'); ?>
    </p>
</body>
</html>
