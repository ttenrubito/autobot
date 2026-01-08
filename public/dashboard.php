<?php
/**
 * Customer Dashboard
 */
define('INCLUDE_CHECK', true);

$page_title = "Dashboard - AI Automation";
$current_page = "dashboard";

include('../includes/customer/header.php');
include('../includes/customer/sidebar.php');
?>

<!-- Main Content -->
<main class="main-content">
    <!-- Page Header -->
    <div class="page-header">
        <div>
            <h1 class="page-title">Dashboard</h1>
            <p class="page-subtitle">ภาพรวมการใช้งานและสถิติของคุณ</p>
        </div>
        <!-- Subscription Status -->
        <div id="subscriptionStatus" class="subscription-status" style="display: none;">
            <!-- Populated by JavaScript -->
        </div>
    </div>

    <!-- Overview Stats -->
    <div class="row">
        <div class="col-4">
            <div class="stat-card">
                <div class="stat-icon primary">🤖</div>
                <div class="stat-content">
                    <div class="stat-label">บริการทั้งหมด</div>
                    <div class="stat-value" id="totalServices">-</div> ช
                </div>
            </div>
        </div>
        <div class="col-4">
            <div class="stat-card">
                <div class="stat-icon secondary">💬</div>
                <div class="stat-content">
                    <div class="stat-label">ข้อความ Bot วันนี้</div>
                    <div class="stat-value" id="botMessagesToday">-</div>
                </div>
            </div>
        </div>
        <div class="col-4">
            <div class="stat-card">
                <div class="stat-icon info">🔌</div>
                <div class="stat-content">
                    <div class="stat-label">API Calls วันนี้</div>
                    <div class="stat-value" id="apiCallsToday">-</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Usage Trend Chart -->
    <div class="card mt-4">
        <div class="card-header">
            <h3 class="card-title">แนวโน้มการใช้งาน (7 วันล่าสุด)</h3>
            <p class="card-subtitle">การเปรียบเทียบ API Calls และ Bot Messages</p>
        </div>
        <div class="card-body">
            <div style="height: 300px;">
                <canvas id="usageTrendChart"></canvas>
            </div>
        </div>
    </div>

    <!-- Two Column Layout -->
    <div class="row mt-4">
        <!-- Service Breakdown -->
        <div class="col-8">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">บริการของคุณ</h3>
                    <p class="card-subtitle">สถานะและการใช้งานของแต่ละบริการ</p>
                </div>
                <div class="card-body">
                    <div class="table-container">
                        <table>
                            <thead>
                                <tr>
                                    <th>ชื่อบริการ</th>
                                    <th>ประเภท</th>
                                    <th>แพลตฟอร์ม</th>
                                    <th>สถานะ</th>
                                    <th>ข้อความวันนี้</th>
                                    <th>API Calls วันนี้</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody id="serviceBreakdownBody">
                                <tr>
                                    <td colspan="7" style="text-align: center; padding: 2rem; color: var(--color-gray);">
                                        กำลังโหลด...
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Recent Activities -->
        <div class="col-4">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">กิจกรรมล่าสุด</h3>
                    <p class="card-subtitle">บันทึกการทำงานของคุณ</p>
                </div>
                <div class="card-body" style="max-height: 400px; overflow-y: auto;">
                    <div id="recentActivities">
                        <div style="text-align: center; padding: 2rem; color: var(--color-gray);">
                            กำลังโหลด...
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>

<?php
$extra_scripts = [
    'https://cdn.jsdelivr.net/npm/chart.js',
    '../assets/js/dashboard.js'
];

$inline_script = <<<'JAVASCRIPT'
// Load subscription status
async function loadSubscriptionStatus() {
    const token = localStorage.getItem('auth_token');
    if (!token) return;

    try {
        const response = await fetch(API_ENDPOINTS.PAYMENT_SUBSCRIPTION_STATUS, {
            headers: {
                'Authorization': 'Bearer ' + token
            }
        });

        if (!response.ok) return;

        const result = await response.json();
        if (!result.success || !result.data.has_subscription) return;

        const data = result.data;
        const statusEl = document.getElementById('subscriptionStatus');

        let html = '';

        if (data.status === 'trial') {
            // Trial Period
            const days = data.trial_days_remaining;
            html = `
                <div class="trial-badge" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 0.75rem 1.5rem; border-radius: 8px; display: inline-flex; align-items: center; gap: 0.5rem; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                    <i class="fas fa-clock"></i>
                    <span style="font-weight: 600;">Trial Period: ${days}/7 วัน</span>
                    <span style="opacity: 0.9; margin-left: 0.5rem;">สิ้นสุด ${new Date(data.trial_end_date).toLocaleDateString('th-TH')}</span>
                </div>
            `;
        } else if (data.status === 'active') {
            // Active Subscription
            html = `
                <div class="active-badge" style="background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%); color: white; padding: 0.75rem 1.5rem; border-radius: 8px; display: inline-flex; align-items: center; gap: 0.5rem; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                    <i class="fas fa-check-circle"></i>
                    <span style="font-weight: 600;">${data.plan_name}</span>
                    <span style="opacity: 0.9; margin-left: 0.5rem;">รอบถัดไป: ${new Date(data.next_billing_date).toLocaleDateString('th-TH')}</span>
                </div>
            `;
        } else if (data.status === 'paused') {
            // Paused (Payment Failed)
            html = `
                <div class="paused-badge" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); color: white; padding: 0.75rem 1.5rem; border-radius: 8px; display: inline-flex; align-items: center; gap: 0.5rem; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                    <i class="fas fa-exclamation-triangle"></i>
                    <span style="font-weight: 600;">Subscription Paused</span>
                    <a href="#" onclick="window.location.href = PAGES.USER_PAYMENT; return false;" style="color: white; text-decoration: underline; margin-left: 0.5rem;">อัปเดตบัตร</a>
                </div>
            `;
        }

        if (html) {
            statusEl.innerHTML = html;
            statusEl.style.display = 'block';
        }

    } catch (error) {
        console.error('Failed to load subscription status:', error);
    }
}

document.addEventListener('DOMContentLoaded', loadSubscriptionStatus);
JAVASCRIPT;

include('../includes/customer/footer.php');
?>
