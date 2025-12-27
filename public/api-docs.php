<?php
/**
 * Customer API Documentation Page
 */
define('INCLUDE_CHECK', true);

$page_title = "API Documentation - AI Automation";
$current_page = "api-docs";

include('../includes/customer/header.php');
include('../includes/customer/sidebar.php');
?>

<main class="main-content">
    <div class="page-header">
        <h1 class="page-title">API Documentation</h1>
        <p class="page-subtitle">คู่มือการใช้งาน API สำหรับ n8n และระบบอื่นๆ</p>
    </div>

    <!-- API Key Section -->
    <div class="card">
        <div class="card-header">
            <h3 class="card-title"><i class="fas fa-key"></i> Your API Key</h3>
            <p class="card-subtitle">ใช้ API Key นี้ในการเรียก API ของเรา</p>
        </div>
        <div class="card-body">
            <div style="display: flex; gap: 1rem; align-items: center; flex-wrap: wrap;">
                <div style="flex: 1; min-width: 300px;">
                    <div style="position: relative;">
                        <input type="password" id="apiKeyInput" class="form-control" readonly
                            style="font-family: monospace; padding-right: 8rem; font-size: 0.875rem;">
                        <div style="position: absolute; right: 0.5rem; top: 50%; transform: translateY(-50%); display: flex; gap: 0.5rem;">
                            <button onclick="toggleApiKey()" class="btn btn-sm btn-outline" title="Show/Hide">
                                <i class="fas fa-eye" id="toggleIcon"></i>
                            </button>
                            <button onclick="copyApiKey()" class="btn btn-sm btn-outline" title="Copy">
                                <i class="fas fa-copy"></i>
                            </button>
                        </div>
                    </div>
                </div>
                <button onclick="regenerateApiKey()" class="btn btn-warning">
                    <i class="fas fa-sync"></i> Regenerate
                </button>
            </div>

            <div style="margin-top: 1rem; padding: 1rem; background: rgba(239, 68, 68, 0.1); border-radius: var(--radius-md); border-left: 4px solid var(--color-danger);">
                <p style="margin: 0; font-size: 0.875rem;">
                    <i class="fas fa-exclamation-triangle"></i>
                    <strong>คำเตือน:</strong> อย่าแชร์ API Key ของคุณกับใครหรือนำไปเผยแพร่ในที่สาธารณะ
                </p>
            </div>
        </div>
    </div>

    <!-- Quick Start -->
    <div class="card mt-4">
        <div class="card-header">
            <h3 class="card-title"><i class="fas fa-rocket"></i> Quick Start</h3>
        </div>
        <div class="card-body">
            <h4 style="margin-top: 0;">วิธีใช้งานกับ n8n</h4>
            <ol style="padding-left: 1.5rem;">
                <li style="margin-bottom: 0.5rem;">เปิด n8n workflow ของคุณ</li>
                <li style="margin-bottom: 0.5rem;">เพิ่ม HTTP Request node</li>
                <li style="margin-bottom: 0.5rem;">ตั้งค่า Headers เพิ่ม <code>X-API-Key</code> ด้วย API Key ของคุณ</li>
                <li style="margin-bottom: 0.5rem;">เลือก Endpoint ที่ต้องการใช้งาน</li>
            </ol>
        </div>
    </div>

    <!-- Google Vision API -->
    <div class="card mt-4">
        <div class="card-header" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white;">
            <h3 class="card-title" style="color: white; margin: 0;">
                <i class="fas fa-eye"></i> Google Vision API
            </h3>
        </div>
        <div class="card-body">
            <h4><i class="fas fa-tags"></i> Label Detection</h4>
            <p>ตรวจจับและระบุวัตถุ, สถานที่, กิจกรรมในรูปภาพ</p>

            <div style="background: #1e1e1e; border-radius: var(--radius-md); padding: 1.5rem; margin: 1rem 0;">
                <pre><code>POST https://yourdomain.com/api/gateway/vision/labels
Headers:
  X-API-Key: your_api_key_here
  Content-Type: application/json

Body:
{
  "image": {
    "content": "base64_encoded_image"
  }
}</code></pre>
            </div>

            <hr style="margin: 2rem 0;">

            <h4><i class="fas fa-font"></i> Text Detection (OCR)</h4>
            <p>แยกข้อความจากรูปภาพ</p>

            <div style="background: #1e1e1e; border-radius: var(--radius-md); padding: 1.5rem; margin: 1rem 0;">
                <pre><code>POST https://yourdomain.com/api/gateway/vision/text
Headers:
  X-API-Key: your_api_key_here
  Content-Type: application/json

Body:
{
  "image": {
    "content": "base64_encoded_image"
  }
}</code></pre>
            </div>
        </div>
    </div>

    <!-- Google Natural Language API -->
    <div class="card mt-4">
        <div class="card-header" style="background: linear-gradient(135deg, #10b981 0%, #059669 100%); color: white;">
            <h3 class="card-title" style="color: white; margin: 0;">
                <i class="fas fa-brain"></i> Google Natural Language API
            </h3>
        </div>
        <div class="card-body">
            <h4><i class="fas fa-heart"></i> Sentiment Analysis</h4>
            <p>วิเคราะห์ความรู้สึกของข้อความ</p>

            <div style="background: #1e1e1e; border-radius: var(--radius-md); padding: 1.5rem; margin: 1rem 0;">
                <pre><code>POST https://yourdomain.com/api/gateway/language/sentiment
Headers:
  X-API-Key: your_api_key_here
  Content-Type: application/json

Body:
{
  "text": "I love this product! It's amazing!"
}</code></pre>
            </div>

            <hr style="margin: 2rem 0;">

            <h4><i class="fas fa-project-diagram"></i> Entity Extraction</h4>
            <p>แยกและจำแนก entities จากข้อความ</p>

            <div style="background: #1e1e1e; border-radius: var(--radius-md); padding: 1.5rem; margin: 1rem 0;">
                <pre><code>POST https://yourdomain.com/api/gateway/language/entities
Headers:
  X-API-Key: your_api_key_here
  Content-Type: application/json

Body:
{
  "text": "Google was founded in California by Larry Page and Sergey Brin"
}</code></pre>
            </div>
        </div>
    </div>

    <!-- Rate Limits -->
    <div class="card mt-4">
        <div class="card-header">
            <h3 class="card-title"><i class="fas fa-tachometer-alt"></i> Rate Limits</h3>
        </div>
        <div class="card-body">
            <div id="rateLimitsContainer">
                <p style="color: var(--color-gray);">กำลังโหลด...</p>
            </div>
        </div>
    </div>

    <!-- Error Codes -->
    <div class="card mt-4">
        <div class="card-header">
            <h3 class="card-title"><i class="fas fa-exclamation-circle"></i> Error Codes</h3>
        </div>
        <div class="card-body">
            <table>
                <thead>
                    <tr>
                        <th>Code</th>
                        <th>Message</th>
                        <th>Description</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>401</td>
                        <td>Unauthorized</td>
                        <td>API key ไม่ถูกต้องหรือหมดอายุ</td>
                    </tr>
                    <tr>
                        <td>403</td>
                        <td>Forbidden</td>
                        <td>ไม่มีสิทธิ์เข้าถึง service นี้</td>
                    </tr>
                    <tr>
                        <td>429</td>
                        <td>Too Many Requests</td>
                        <td>เกิน rate limit</td>
                    </tr>
                    <tr>
                        <td>503</td>
                        <td>Service Unavailable</td>
                        <td>Service ถูกปิดชั่วคราว</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</main>

<?php
$extra_scripts = [
    'https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/highlight.min.js'
];

$extra_css = [
    'https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/styles/github-dark.min.css'
];

$inline_script = <<<'JAVASCRIPT'
let apiKeyData = null;
let isApiKeyVisible = false;

document.addEventListener('DOMContentLoaded', async () => {
    await loadApiKey();
    if (typeof hljs !== 'undefined') {
        hljs.highlightAll();
    }
});

async function loadApiKey() {
    try {
        const response = await apiCall(API_ENDPOINTS.USER_API_KEY);
        
        if (response && response.success) {
            apiKeyData = response.data;
            const apiKey = apiKeyData.api_key?.api_key || 'No API key generated';
            document.getElementById('apiKeyInput').value = apiKey;
            
            if (apiKeyData.services) {
                displayRateLimits(apiKeyData.services);
            }
        }
    } catch (error) {
        console.error('Failed to load API key:', error);
    }
}

function displayRateLimits(services) {
    const container = document.getElementById('rateLimitsContainer');
    
    if (!services || services.length === 0) {
        container.innerHTML = '<p>ไม่มี API services ที่เปิดใช้งาน</p>';
        return;
    }
    
    let html = '<table><thead><tr><th>Service</th><th>Daily Limit</th><th>Monthly Limit</th><th>Status</th></tr></thead><tbody>';
    
    services.forEach(service => {
        html += `
            <tr>
                <td><strong>${service.service_name}</strong></td>
                <td>${service.daily_limit ? formatNumber(service.daily_limit) : 'Unlimited'}</td>
                <td>${service.monthly_limit ? formatNumber(service.monthly_limit) : 'Unlimited'}</td>
                <td>
                    ${service.is_enabled
                        ? '<span class="badge badge-success">Enabled</span>'
                        : '<span class="badge badge-danger">Disabled</span>'}
                </td>
            </tr>
        `;
    });
    
    html += '</tbody></table>';
    container.innerHTML = html;
}

function toggleApiKey() {
    const input = document.getElementById('apiKeyInput');
    const icon = document.getElementById('toggleIcon');
    isApiKeyVisible = !isApiKeyVisible;
    input.type = isApiKeyVisible ? 'text' : 'password';
    icon.className = isApiKeyVisible ? 'fas fa-eye-slash' : 'fas fa-eye';
}

async function copyApiKey() {
    const input = document.getElementById('apiKeyInput');
    await navigator.clipboard.writeText(input.value);
    showToast('API Key copied to clipboard!', 'success');
}

async function regenerateApiKey() {
    if (!confirm('คุณต้องการสร้าง API Key ใหม่ใช่หรือไม่? API Key เก่าจะไม่สามารถใช้งานได้อีก')) {
        return;
    }
    
    showLoading();
    
    try {
        const response = await apiCall(API_ENDPOINTS.USER_REGENERATE_KEY, {
            method: 'POST'
        });
        
        if (response && response.success) {
            apiKeyData.api_key.api_key = response.data.api_key;
            document.getElementById('apiKeyInput').value = response.data.api_key;
            showToast('API Key regenerated successfully', 'success');
        } else {
            showToast(response.message || 'Failed to regenerate', 'error');
        }
    } catch (error) {
        console.error('Regenerate error:', error);
        showToast('เกิดข้อผิดพลาด', 'error');
    } finally {
        hideLoading();
    }
}
JAVASCRIPT;

include('../includes/customer/footer.php');
?>
