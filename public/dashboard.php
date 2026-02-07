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
    <!-- Page Header with Date -->
    <div class="page-header">
        <div>
            <h1 class="page-title">📊 Dashboard</h1>
            <p class="page-subtitle" id="todayDate">วันนี้</p>
        </div>
    </div>

    <!-- Today's Highlight -->
    <div class="today-highlight">
        <div class="highlight-card revenue">
            <div class="highlight-icon">💰</div>
            <div class="highlight-content">
                <div class="highlight-label">ยอดขายวันนี้</div>
                <div class="highlight-value" id="todayRevenue">฿0</div>
                <div class="highlight-compare" id="revenueCompare">-</div>
            </div>
        </div>
        <div class="highlight-card orders">
            <div class="highlight-icon">📦</div>
            <div class="highlight-content">
                <div class="highlight-label">Orders วันนี้</div>
                <div class="highlight-value" id="todayOrders">0</div>
                <div class="highlight-compare" id="ordersCompare">-</div>
            </div>
        </div>
        <div class="highlight-card slips">
            <div class="highlight-icon">🧾</div>
            <div class="highlight-content">
                <div class="highlight-label">Slips รอตรวจ</div>
                <div class="highlight-value urgent" id="pendingSlips">0</div>
                <a href="payment-history.php?status=pending" class="highlight-action">ตรวจเลย →</a>
            </div>
        </div>
        <div class="highlight-card weekly">
            <div class="highlight-icon">📈</div>
            <div class="highlight-content">
                <div class="highlight-label">ยอดสัปดาห์นี้</div>
                <div class="highlight-value" id="weeklyRevenue">฿0</div>
                <div class="highlight-compare" id="weeklyCompare">-</div>
            </div>
        </div>
    </div>

    <!-- Action Required -->
    <div class="action-required mt-4">
        <h3 class="section-title">⚠️ งานรอดำเนินการ</h3>
        <div class="action-grid">
            <a href="payment-history.php?status=pending" class="action-item pending">
                <span class="action-count" id="actionPendingSlips">0</span>
                <span class="action-label">Slips รอตรวจ</span>
            </a>
            <a href="payment-history.php?status=verifying" class="action-item verifying">
                <span class="action-count" id="actionVerifyingSlips">0</span>
                <span class="action-label">กำลังตรวจสอบ</span>
            </a>
            <a href="orders.php?status=confirmed" class="action-item ship">
                <span class="action-count" id="actionOrdersToShip">0</span>
                <span class="action-label">รอจัดส่ง</span>
            </a>
            <a href="orders.php?status=awaiting_payment" class="action-item awaiting">
                <span class="action-count" id="actionOrdersAwaiting">0</span>
                <span class="action-label">รอชำระเงิน</span>
            </a>
        </div>
    </div>

    <!-- Revenue Chart -->
    <div class="card mt-4">
        <div class="card-header">
            <h3 class="card-title">📈 ยอดขาย 7 วันล่าสุด</h3>
        </div>
        <div class="card-body">
            <div style="height: 250px;">
                <canvas id="revenueChart"></canvas>
            </div>
        </div>
    </div>

    <!-- Two Column: Pending Slips & Recent Orders -->
    <div class="dashboard-two-col mt-4">
        <!-- Pending Slips -->
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">🧾 Slips รอตรวจสอบ</h3>
                <a href="payment-history.php?status=pending" class="btn btn-sm btn-primary">ดูทั้งหมด</a>
            </div>
            <div class="card-body">
                <div class="table-container table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>เลขที่</th>
                                <th>ยอด</th>
                                <th>สถานะ</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody id="pendingSlipsBody">
                            <tr>
                                <td colspan="4" style="text-align: center; padding: 2rem; color: var(--color-gray);">
                                    กำลังโหลด...
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Recent Orders -->
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">📦 Orders ล่าสุด</h3>
                <a href="orders.php" class="btn btn-sm btn-outline">ดูทั้งหมด</a>
            </div>
            <div class="card-body">
                <div class="table-container table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>เลขที่</th>
                                <th>ยอด</th>
                                <th>สถานะ</th>
                            </tr>
                        </thead>
                        <tbody id="recentOrdersBody">
                            <tr>
                                <td colspan="3" style="text-align: center; padding: 2rem; color: var(--color-gray);">
                                    กำลังโหลด...
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</main>

<style>
/* Dashboard Styles */
.today-highlight {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 1rem;
}

.highlight-card {
    background: white;
    border-radius: 12px;
    padding: 1.25rem;
    display: flex;
    align-items: flex-start;
    gap: 1rem;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    border-left: 4px solid #e2e8f0;
}

.highlight-card.revenue { border-left-color: #38a169; }
.highlight-card.orders { border-left-color: #3182ce; }
.highlight-card.slips { border-left-color: #e53e3e; }
.highlight-card.weekly { border-left-color: #805ad5; }

.highlight-icon {
    font-size: 2rem;
}

.highlight-label {
    font-size: 0.875rem;
    color: #718096;
    margin-bottom: 0.25rem;
}

.highlight-value {
    font-size: 1.75rem;
    font-weight: 700;
    color: #1a202c;
}

.highlight-value.urgent {
    color: #e53e3e;
}

.highlight-compare {
    font-size: 0.75rem;
    margin-top: 0.25rem;
}

.highlight-compare.up { color: #38a169; }
.highlight-compare.down { color: #e53e3e; }
.highlight-compare.same { color: #718096; }

.highlight-action {
    font-size: 0.75rem;
    color: #3182ce;
    text-decoration: none;
    margin-top: 0.25rem;
    display: inline-block;
}

.highlight-action:hover {
    text-decoration: underline;
}

/* Action Required */
.section-title {
    font-size: 1rem;
    font-weight: 600;
    margin-bottom: 1rem;
    color: #2d3748;
}

.action-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 0.75rem;
}

.action-item {
    background: white;
    border-radius: 8px;
    padding: 1rem;
    text-align: center;
    text-decoration: none;
    border: 2px solid #e2e8f0;
    transition: all 0.2s;
}

.action-item:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.1);
}

.action-item.pending { border-color: #feb2b2; background: #fff5f5; }
.action-item.verifying { border-color: #fbd38d; background: #fffaf0; }
.action-item.ship { border-color: #9ae6b4; background: #f0fff4; }
.action-item.awaiting { border-color: #90cdf4; background: #ebf8ff; }

.action-count {
    display: block;
    font-size: 1.5rem;
    font-weight: 700;
    color: #1a202c;
}

.action-label {
    display: block;
    font-size: 0.75rem;
    color: #718096;
    margin-top: 0.25rem;
}

.dashboard-two-col {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 1.5rem;
}

.card-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
}

/* Responsive */
@media (max-width: 1200px) {
    .today-highlight {
        grid-template-columns: repeat(2, 1fr);
    }
    .action-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}

@media (max-width: 768px) {
    .today-highlight {
        grid-template-columns: 1fr;
    }
    .action-grid {
        grid-template-columns: repeat(2, 1fr);
    }
    .dashboard-two-col {
        grid-template-columns: 1fr;
    }
    .highlight-value {
        font-size: 1.5rem;
    }
    .highlight-card {
        padding: 1rem;
    }
}

@media (max-width: 480px) {
    .action-grid {
        grid-template-columns: repeat(2, 1fr);
    }
    .action-count {
        font-size: 1.25rem;
    }
}
</style>

<?php
$extra_scripts = [
    'https://cdn.jsdelivr.net/npm/chart.js',
    '../assets/js/dashboard.js'
];

$inline_script = <<<'JAVASCRIPT'
// Load subscription status (legacy function - keeping for compatibility)
async function loadSubscriptionStatus() {
    const statusEl = document.getElementById('subscriptionStatus');
    if (!statusEl) return; // Element removed in new design
    
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
