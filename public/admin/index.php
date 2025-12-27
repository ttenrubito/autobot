<?php
/**
 * Admin Dashboard
 */
define('INCLUDE_CHECK', true);

$page_title = "Admin Panel - AI Automation";
$current_page = "dashboard";

include('../../includes/admin/header.php');
include('../../includes/admin/sidebar.php');
?>

<!-- Main Content -->
<main class="main-content">
    <!-- Page Header -->
    <div class="page-header">
        <h1 class="page-title"><i class="fas fa-tachometer-alt"></i> Admin Dashboard</h1>
        <p class="page-subtitle">ภาพรวมระบบและสถิติทั้งหมด</p>
    </div>

    <!-- Admin Stats -->
    <div class="row">
        <div class="col-3">
            <div class="stat-card">
                <div class="stat-icon primary"><i class="fas fa-users"></i></div>
                <div class="stat-content">
                    <div class="stat-label">ลูกค้าทั้งหมด</div>
                    <div class="stat-value" id="totalCustomers">-</div>
                </div>
            </div>
        </div>
        <div class="col-3">
            <div class="stat-card">
                <div class="stat-icon secondary"><i class="fas fa-robot"></i></div>
                <div class="stat-content">
                    <div class="stat-label">บริการที่ใช้งาน</div>
                    <div class="stat-value" id="totalServices">-</div>
                </div>
            </div>
        </div>
        <div class="col-3">
            <div class="stat-card">
                <div class="stat-icon info"><i class="fas fa-dollar-sign"></i></div>
                <div class="stat-content">
                    <div class="stat-label">รายได้เดือนนี้</div>
                    <div class="stat-value" id="monthlyRevenue">-</div>
                </div>
            </div>
        </div>
        <div class="col-3">
            <div class="stat-card">
                <div class="stat-icon warning"><i class="fas fa-chart-line"></i></div>
                <div class="stat-content">
                    <div class="stat-label">API Calls วันนี้</div>
                    <div class="stat-value" id="todayRequests">-</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Quick Actions -->
    <div class="card mt-4">
        <div class="card-header">
            <h3 class="card-title"><i class="fas fa-bolt"></i> การทำงานด่วน</h3>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-3">
                    <a href="customers.php?action=new" class="btn btn-primary btn-block btn-lg">
                        <i class="fas fa-user-plus"></i><br>เพิ่มลูกค้าใหม่
                    </a>
                </div>
                <div class="col-3">
                    <a href="packages.php?action=new" class="btn btn-secondary btn-block btn-lg">
                        <i class="fas fa-box-open"></i><br>สร้างแพ็คเกจ
                    </a>
                </div>
                <div class="col-3">
                    <a href="invoices.php?action=new" class="btn btn-info btn-block btn-lg">
                        <i class="fas fa-file-invoice-dollar"></i><br>สร้างใบแจ้งหนี้
                    </a>
                </div>
                <div class="col-3">
                    <a href="reports.php" class="btn btn-outline btn-block btn-lg">
                        <i class="fas fa-chart-bar"></i><br>ดูรายงาน
                    </a>
                </div>
            </div>

            <!-- System Actions -->
            <hr class="divider">
            <h5 class="section-subtitle"><i class="fas fa-cog"></i> การจัดการระบบ</h5>
            <div class="row">
                <div class="col-3">
                    <button onclick="triggerManualBilling()" class="btn btn-warning btn-block btn-lg"
                        id="manualBillingBtn">
                        <i class="fas fa-bolt"></i><br>ตัดเงินรอบบิล (Manual)
                    </button>
                </div>
                <div class="col-3">
                    <button onclick="alert('Coming soon')" class="btn btn-outline btn-block btn-lg" disabled>
                        <i class="fas fa-sync"></i><br>Sync ข้อมูล
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Recent Customers & Activities -->
    <div class="row mt-4">
        <div class="col-8">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title"><i class="fas fa-users"></i> ลูกค้าล่าสุด</h3>
                </div>
                <div class="card-body">
                    <div class="table-container">
                        <table>
                            <thead>
                                <tr>
                                    <th>ชื่อ</th>
                                    <th>อีเมล</th>
                                    <th>แพ็คเกจ</th>
                                    <th>สถานะ</th>
                                    <th>วันที่สมัคร</th>
                                    <th>การจัดการ</th>
                                </tr>
                            </thead>
                            <tbody id="recentCustomers">
                                <tr class="table-loading">
                                    <td colspan="6">กำลังโหลด...</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-4">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title"><i class="fas fa-bell"></i> การแจ้งเตือน</h3>
                </div>
                <div class="card-body card-body--scrollable">
                    <div id="adminNotifications">
                        <div class="text-center text-muted py-2">
                            ไม่มีการแจ้งเตือนใหม่
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>

<!-- Manual Billing Result Modal -->
<div id="billingModal" class="modal-backdrop" style="display:none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0, 0, 0, 0.6); z-index: 9999; align-items: center; justify-content: center;">
    <div class="modal-content modal--wide" style="background: white; border-radius: 12px; box-shadow: 0 20px 60px rgba(0,0,0,0.3); max-width: 900px; max-height: 90vh; overflow-y: auto; margin: 20px;">
        <div class="modal-header" style="border-bottom: 1px solid var(--color-light-3); padding: 1.5rem;">
            <h3 id="billingModalTitle" class="modal-title" style="margin: 0; display: flex; align-items: center; gap: 0.5rem;">
                <i class="fas fa-chart-line"></i>
                <span>ผลการตัดเงินรอบบิล (Manual)</span>
            </h3>
            <button class="modal-close" onclick="closeBillingModal()" aria-label="ปิดหน้าต่าง" style="background: none; border: none; font-size: 2rem; line-height: 1; cursor: pointer; color: var(--color-gray);">&times;</button>
        </div>
        <div class="modal-body" id="billingResults" style="padding: 1.5rem; min-height: 200px;">
            <div class="text-center py-2">
                <i class="fas fa-spinner fa-spin modal-spinner" style="font-size:2.5rem;"></i>
                <p class="mt-2">กำลังประมวลผล...</p>
            </div>
        </div>
    </div>
</div>

<?php
// Inline script for manual billing functionality
$inline_script = <<<'JAVASCRIPT'
// Load Dashboard Data on Page Load
document.addEventListener('DOMContentLoaded', async () => {
    await loadDashboardStats();
});

// Load Dashboard Statistics from API
async function loadDashboardStats() {
    const token = localStorage.getItem('admin_token');
    if (!token) {
        window.location.href = 'login.html';
        return;
    }

    try {
        // Use the correct endpoint key defined in path-config.js
        const response = await fetch(API_ENDPOINTS.ADMIN_STATS, {
            headers: {
                'Authorization': 'Bearer ' + token,
                'Content-Type': 'application/json'
            }
        });

        const result = await response.json();

        if (result.success) {
            displayDashboardData(result.data);
        } else {
            console.error('Failed to load dashboard stats:', result.message);
        }
    } catch (error) {
        console.error('Dashboard load error:', error);
    }
}

function displayDashboardData(data) {
    // Map to fields returned by api/admin/dashboard/stats.php (now returns 'overview' object)
    const overview = data.overview || data;
    document.getElementById('totalCustomers').textContent = formatNumber(overview.total_customers || 0);
    document.getElementById('totalServices').textContent = formatNumber(overview.total_services || 0);
    document.getElementById('monthlyRevenue').textContent = formatCurrency(overview.monthly_revenue || 0);
    document.getElementById('todayRequests').textContent = formatNumber(overview.today_requests || 0);

    // Recent customers list
    displayRecentCustomers(data.recentCustomers || []);

    // Currently stats.php does not return notifications; guard with empty array
    displayNotifications(data.notifications || []);
}

function displayRecentCustomers(customers) {
    const tbody = document.getElementById('recentCustomers');
    
    if (!customers || customers.length === 0) {
        tbody.innerHTML = '<tr><td colspan="6" class="text-center text-muted">ไม่มีข้อมูลลูกค้า</td></tr>';
        return;
    }

    tbody.innerHTML = '';
    
    customers.forEach(customer => {
        const row = document.createElement('tr');
        
        const statusBadge = customer.status === 'active'
            ? '<span class="badge badge-success">Active</span>'
            : '<span class="badge badge-danger">Inactive</span>';
        
        const packageName = customer.package_name || '<span class="text-muted">ไม่มีแพ็คเกจ</span>';
        
        row.innerHTML = `
            <td><strong>${customer.full_name}</strong></td>
            <td>${customer.email}</td>
            <td>${packageName}</td>
            <td>${statusBadge}</td>
            <td>${formatDate(customer.created_at).split(' ')[0]}</td>
            <td>
                <a href="customers.php?id=${customer.id}" class="btn btn-sm btn-primary">
                    <i class="fas fa-eye"></i> ดู
                </a>
            </td>
        `;
        
        tbody.appendChild(row);
    });
}

function displayNotifications(notifications) {
    const container = document.getElementById('adminNotifications');
    
    if (!notifications || notifications.length === 0) {
        container.innerHTML = '<div class="text-center text-muted py-2">ไม่มีการแจ้งเตือนใหม่</div>';
        return;
    }

    container.innerHTML = '';
    
    notifications.forEach(notif => {
        const div = document.createElement('div');
        div.className = `alert alert-${notif.type} mb-2`;
        div.style.padding = '0.75rem';
        
        div.innerHTML = `
            <div style="display: flex; gap: 0.5rem;">
                <i class="fas ${notif.icon}"></i>
                <div style="flex: 1;">
                    <strong>${notif.message}</strong>
                    ${notif.action ? `<br><small>${notif.action}</small>` : ''}
                </div>
            </div>
        `;
        
        container.appendChild(div);
    });
}

// Manual Billing Trigger
async function triggerManualBilling() {
    if (!confirm('คุณต้องการรัน Billing Cycle แบบ Manual ใช่หรือไม่?\n\nระบบจะตัดเงินสำหรับ subscriptions ที่ถึงกำหนดวันนี้')) {
        return;
    }

    const token = localStorage.getItem('admin_token');
    if (!token) {
        alert('กรุณา login ใหม่');
        return;
    }

    const billingModal = document.getElementById('billingModal');
    const billingResults = document.getElementById('billingResults');
    const btn = document.getElementById('manualBillingBtn'); // Assuming there's a button with this ID

    // Show modal with loading
    billingModal.style.display = 'flex';
    billingResults.innerHTML = `
        <div class="text-center py-2">
            <i class="fas fa-spinner fa-spin" style="font-size: 3rem;"></i>
            <p class="mt-2">กำลังประมวลผล...</p>
        </div>
    `;

    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> กำลังประมวลผล...';
    }

    try {
        const response = await fetch(API_ENDPOINTS.ADMIN_TRIGGER_BILLING, {
            method: 'POST',
            headers: {
                'Authorization': 'Bearer ' + token,
                'Content-Type': 'application/json'
            }
        });

        const result = await response.json();

        if (result.success) {
            displayBillingResults(result.data);
        } else {
            billingResults.innerHTML = `
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle"></i> ${result.message}
                </div>
            `;
        }

    } catch (error) {
        billingResults.innerHTML = `
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle"></i> เกิดข้อผิดพลาด: ${error.message}
            </div>
        `;
    }
}

function displayBillingResults(data) {
    const billingResults = document.getElementById('billingResults');

    let html = `
        <div class="mb-2">
            <div class="row">
                <div class="col-4">
                    <div class="stat-card">
                        <div class="stat-content">
                            <div class="stat-label">ทั้งหมด</div>
                            <div class="stat-value">${data.total || 0}</div>
                        </div>
                    </div>
                </div>
                <div class="col-4">
                    <div class="stat-card">
                        <div class="stat-content">
                            <div class="stat-label text-success">สำเร็จ</div>
                            <div class="stat-value text-success">${data.successful || 0}</div>
                        </div>
                    </div>
                </div>
                <div class="col-4">
                    <div class="stat-card">
                        <div class="stat-content">
                            <div class="stat-label text-danger">ล้มเหลว</div>
                            <div class="stat-value text-danger">${data.failed || 0}</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    `;

    if (data.details && data.details.length > 0) {
        html += `
            <h5 class="mt-2 mb-1">รายละเอียด:</h5>
            <div class="table-container" style="max-height: 400px; overflow-y: auto;">
                <table>
                    <thead>
                        <tr>
                            <th>ลูกค้า</th>
                            <th>แพ็คเกจ</th>
                            <th>สถานะ</th>
                            <th>จำนวนเงิน</th>
                            <th>Invoice</th>
                        </tr>
                    </thead>
                    <tbody>
        `;

        data.details.forEach(detail => {
            let statusBadge = '';
            if (detail.status === 'success') {
                statusBadge = '<span class="badge badge-success">สำเร็จ</span>';
            } else {
                statusBadge = '<span class="badge badge-danger">ล้มเหลว</span>';
            }

            html += `
                <tr>
                    <td>${detail.email || '-'}</td>
                    <td>${detail.package || '-'}</td>
                    <td>${statusBadge}</td>
                    <td>${detail.amount ? formatCurrency(detail.amount) : '-'}</td>
                    <td><small>${detail.invoice_number || detail.error || '-'}</small></td>
                </tr>
            `;
        });

        html += `
                    </tbody>
                </table>
            </div>
        `;
    } else {
        html += '<p class="text-center text-muted mt-2">ไม่มี subscription ที่ต้องตัดเงินในวันนี้</p>';
    }

    billingResults.innerHTML = html;
}

function closeBillingModal() {
    const billingModal = document.getElementById('billingModal');
    billingModal.style.display = 'none';
    // Reload stats
    loadDashboardStats();
}

// Close modal when clicking outside
window.addEventListener('click', function (event) {
    const modal = document.getElementById('billingModal');
    if (event.target === modal) {
        closeBillingModal();
    }
});
JAVASCRIPT;

include('../../includes/admin/footer.php');
?>
