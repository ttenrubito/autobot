<?php
/**
 * Admin Reports Page
 */
define('INCLUDE_CHECK', true);

$page_title = "รายงาน - Admin Panel";
$current_page = "reports";

include('../../includes/admin/header.php');
include('../../includes/admin/sidebar.php');
?>

<main class="main-content">
    <!-- Page Header -->
    <div class="page-header">
        <h1 class="page-title"><i class="fas fa-chart-line"></i> รายงานและสถิติ</h1>
        <p class="page-subtitle">ภาพรวมรายได้ การเติบโต และการใช้งาน</p>
    </div>

    <!-- Date Range Filter -->
    <div class="card mb-4">
        <div class="card-body">
            <div class="row align-items-end">
                <div class="col-3">
                    <label class="form-label">ช่วงเวลา</label>
                    <select id="dateRange" class="form-control" onchange="loadReports()">
                        <option value="today">วันนี้</option>
                        <option value="week">7 วันที่ผ่านมา</option>
                        <option value="month" selected>30 วันที่ผ่านมา</option>
                        <option value="quarter">90 วันที่ผ่านมา</option>
                        <option value="year">1 ปีที่ผ่านมา</option>
                        <option value="custom">กำหนดเอง</option>
                    </select>
                </div>
                <div class="col-3" id="customDateStart" style="display: none;">
                    <label class="form-label">วันเริ่มต้น</label>
                    <input type="date" id="startDate" class="form-control">
                </div>
                <div class="col-3" id="customDateEnd" style="display: none;">
                    <label class="form-label">วันสิ้นสุด</label>
                    <input type="date" id="endDate" class="form-control">
                </div>
                <div class="col-3">
                    <button onclick="loadReports()" class="btn btn-primary">
                        <i class="fas fa-sync"></i> รีเฟรช
                    </button>
                    <button onclick="exportReport()" class="btn btn-outline">
                        <i class="fas fa-download"></i> ส่งออก
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Revenue Overview -->
    <div class="row">
        <div class="col-3">
            <div class="stat-card">
                <div class="stat-icon primary"><i class="fas fa-dollar-sign"></i></div>
                <div class="stat-content">
                    <div class="stat-label">รายได้รวม</div>
                    <div class="stat-value" id="totalRevenue">-</div>
                    <small class="stat-change" id="revenueChange"></small>
                </div>
            </div>
        </div>
        <div class="col-3">
            <div class="stat-card">
                <div class="stat-icon success"><i class="fas fa-receipt"></i></div>
                <div class="stat-content">
                    <div class="stat-label">ใบแจ้งหนี้ชำระแล้ว</div>
                    <div class="stat-value" id="paidInvoices">-</div>
                    <small class="stat-change" id="invoiceChange"></small>
                </div>
            </div>
        </div>
        <div class="col-3">
            <div class="stat-card">
                <div class="stat-icon info"><i class="fas fa-users"></i></div>
                <div class="stat-content">
                    <div class="stat-label">Subscription ที่ Active</div>
                    <div class="stat-value" id="activeSubscriptions">-</div>
                    <small class="stat-change" id="subscriptionChange"></small>
                </div>
            </div>
        </div>
        <div class="col-3">
            <div class="stat-card">
                <div class="stat-icon warning"><i class="fas fa-chart-line"></i></div>
                <div class="stat-content">
                    <div class="stat-label">MRR (รายได้ต่อเดือน)</div>
                    <div class="stat-value" id="mrr">-</div>
                    <small class="stat-change" id="mrrChange"></small>
                </div>
            </div>
        </div>
    </div>

    <!-- Charts Row -->
    <div class="row mt-4">
        <div class="col-8">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title"><i class="fas fa-chart-bar"></i> กราฟรายได้</h3>
                </div>
                <div class="card-body">
                    <canvas id="revenueChart" height="80"></canvas>
                </div>
            </div>
        </div>
        <div class="col-4">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title"><i class="fas fa-chart-pie"></i> แพ็คเกจยอดนิยม</h3>
                </div>
                <div class="card-body">
                    <canvas id="packageChart" height="200"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- Subscription Analysis -->
    <div class="row mt-4">
        <div class="col-6">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title"><i class="fas fa-user-clock"></i> การเติบโตของ Subscription</h3>
                </div>
                <div class="card-body">
                    <canvas id="subscriptionGrowthChart" height="150"></canvas>
                </div>
            </div>
        </div>
        <div class="col-6">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title"><i class="fas fa-percentage"></i> อัตราการยกเลิก (Churn Rate)</h3>
                </div>
                <div class="card-body">
                    <canvas id="churnChart" height="150"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- Top Customers & Package Performance -->
    <div class="row mt-4">
        <div class="col-6">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title"><i class="fas fa-star"></i> ลูกค้ารายได้สูงสุด</h3>
                </div>
                <div class="card-body">
                    <div class="table-container">
                        <table>
                            <thead>
                                <tr>
                                    <th>ชื่อ</th>
                                    <th>แพ็คเกจ</th>
                                    <th>รายได้รวม</th>
                                    <th>สถานะ</th>
                                </tr>
                            </thead>
                            <tbody id="topCustomersTable">
                                <tr class="table-loading">
                                    <td colspan="4">กำลังโหลด...</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title"><i class="fas fa-box"></i> ประสิทธิภาพแพ็คเกจ</h3>
                </div>
                <div class="card-body">
                    <div class="table-container">
                        <table>
                            <thead>
                                <tr>
                                    <th>แพ็คเกจ</th>
                                    <th>จำนวนลูกค้า</th>
                                    <th>รายได้รวม</th>
                                    <th>% ของรายได้</th>
                                </tr>
                            </thead>
                            <tbody id="packagePerformanceTable">
                                <tr class="table-loading">
                                    <td colspan="4">กำลังโหลด...</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Usage Statistics -->
    <div class="card mt-4">
        <div class="card-header">
            <h3 class="card-title"><i class="fas fa-chart-area"></i> สถิติการใช้งาน API</h3>
        </div>
        <div class="card-body">
            <canvas id="usageChart" height="60"></canvas>
        </div>
    </div>
</main>

<?php
$extra_scripts = ['https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js'];
$inline_script = <<<'JAVASCRIPT'
let revenueChart, packageChart, subscriptionGrowthChart, churnChart, usageChart;

document.addEventListener('DOMContentLoaded', () => {
    // Handle custom date range
    document.getElementById('dateRange').addEventListener('change', function() {
        const customFields = this.value === 'custom';
        document.getElementById('customDateStart').style.display = customFields ? 'block' : 'none';
        document.getElementById('customDateEnd').style.display = customFields ? 'block' : 'none';
        
        if (!customFields) {
            loadReports();
        }
    });
    
    loadReports();
});

async function loadReports() {
    const token = localStorage.getItem('admin_token');
    if (!token) {
        window.location.href = 'login.html';
        return;
    }

    const dateRange = document.getElementById('dateRange').value;
    let url = `/api/admin/reports/summary.php?range=${dateRange}`;
    
    if (dateRange === 'custom') {
        const startDate = document.getElementById('startDate').value;
        const endDate = document.getElementById('endDate').value;
        if (!startDate || !endDate) {
            alert('กรุณาเลือกวันที่เริ่มต้นและสิ้นสุด');
            return;
        }
        url += `&start=${startDate}&end=${endDate}`;
    }

    try {
        const response = await fetch(url, {
            headers: { 'Authorization': 'Bearer ' + token }
        });
        const result = await response.json();

        if (result.success) {
            displayReportData(result.data);
        } else {
            console.error('Failed to load reports:', result.message);
        }
    } catch (error) {
        console.error('Report load error:', error);
    }
}

function displayReportData(data) {
    // Update overview stats
    document.getElementById('totalRevenue').textContent = formatCurrency(data.overview.total_revenue || 0);
    document.getElementById('paidInvoices').textContent = formatNumber(data.overview.paid_invoices || 0);
    document.getElementById('activeSubscriptions').textContent = formatNumber(data.overview.active_subscriptions || 0);
    document.getElementById('mrr').textContent = formatCurrency(data.overview.mrr || 0);
    
    // Update change indicators
    updateChangeIndicator('revenueChange', data.overview.revenue_change || 0);
    updateChangeIndicator('invoiceChange', data.overview.invoice_change || 0);
    updateChangeIndicator('subscriptionChange', data.overview.subscription_change || 0);
    updateChangeIndicator('mrrChange', data.overview.mrr_change || 0);

    // Update charts
    updateRevenueChart(data.revenue_trend || []);
    updatePackageChart(data.package_distribution || []);
    updateSubscriptionGrowthChart(data.subscription_growth || []);
    updateChurnChart(data.churn_data || []);
    updateUsageChart(data.usage_trend || []);
    
    // Update tables
    updateTopCustomers(data.top_customers || []);
    updatePackagePerformance(data.package_performance || []);
}

function updateChangeIndicator(elementId, change) {
    const element = document.getElementById(elementId);
    const isPositive = change >= 0;
    const icon = isPositive ? '↑' : '↓';
    const colorClass = isPositive ? 'text-success' : 'text-danger';
    
    element.textContent = `${icon} ${Math.abs(change).toFixed(1)}%`;
    element.className = `stat-change ${colorClass}`;
}

function updateRevenueChart(data) {
    const ctx = document.getElementById('revenueChart');
    
    if (revenueChart) {
        revenueChart.destroy();
    }
    
    revenueChart = new Chart(ctx, {
        type: 'line',
        data: {
            labels: data.map(d => d.date),
            datasets: [{
                label: 'รายได้',
                data: data.map(d => d.amount),
                borderColor: 'rgb(75, 192, 192)',
                backgroundColor: 'rgba(75, 192, 192, 0.1)',
                tension: 0.4,
                fill: true
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: (context) => 'รายได้: ฿' + context.parsed.y.toLocaleString()
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        callback: (value) => '฿' + value.toLocaleString()
                    }
                }
            }
        }
    });
}

function updatePackageChart(data) {
    const ctx = document.getElementById('packageChart');
    
    if (packageChart) {
        packageChart.destroy();
    }
    
    packageChart = new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels: data.map(d => d.name),
            datasets: [{
                data: data.map(d => d.count),
                backgroundColor: [
                    'rgba(255, 99, 132, 0.8)',
                    'rgba(54, 162, 235, 0.8)',
                    'rgba(255, 206, 86, 0.8)',
                    'rgba(75, 192, 192, 0.8)',
                    'rgba(153, 102, 255, 0.8)',
                ]
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'bottom'
                }
            }
        }
    });
}

function updateSubscriptionGrowthChart(data) {
    const ctx = document.getElementById('subscriptionGrowthChart');
    
    if (subscriptionGrowthChart) {
        subscriptionGrowthChart.destroy();
    }
    
    subscriptionGrowthChart = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: data.map(d => d.date),
            datasets: [{
                label: 'ลูกค้าใหม่',
                data: data.map(d => d.new),
                backgroundColor: 'rgba(75, 192, 192, 0.8)'
            }, {
                label: 'ยกเลิก',
                data: data.map(d => d.cancelled),
                backgroundColor: 'rgba(255, 99, 132, 0.8)'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: { beginAtZero: true }
            }
        }
    });
}

function updateChurnChart(data) {
    const ctx = document.getElementById('churnChart');
    
    if (churnChart) {
        churnChart.destroy();
    }
    
    churnChart = new Chart(ctx, {
        type: 'line',
        data: {
            labels: data.map(d => d.date),
            datasets: [{
                label: 'อัตราการยกเลิก (%)',
                data: data.map(d => d.rate),
                borderColor: 'rgb(255, 99, 132)',
                backgroundColor: 'rgba(255, 99, 132, 0.1)',
                tension: 0.4,
                fill: true
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: {
                    beginAtZero: true,
                    max: 100,
                    ticks: {
                        callback: (value) => value + '%'
                    }
                }
            }
        }
    });
}

function updateUsageChart(data) {
    const ctx = document.getElementById('usageChart');
    
    if (usageChart) {
        usageChart.destroy();
    }
    
    usageChart = new Chart(ctx, {
        type: 'line',
        data: {
            labels: data.map(d => d.date),
            datasets: [{
                label: 'API Calls',
                data: data.map(d => d.count),
                borderColor: 'rgb(153, 102, 255)',
                backgroundColor: 'rgba(153, 102, 255, 0.1)',
                tension: 0.4,
                fill: true
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        callback: (value) => value.toLocaleString()
                    }
                }
            }
        }
    });
}

function updateTopCustomers(customers) {
    const tbody = document.getElementById('topCustomersTable');
    
    if (customers.length === 0) {
        tbody.innerHTML = '<tr><td colspan="4" class="text-center text-muted">ไม่มีข้อมูล</td></tr>';
        return;
    }
    
    tbody.innerHTML = customers.map(customer => `
        <tr>
            <td><strong>${customer.name}</strong><br><small>${customer.email}</small></td>
            <td>${customer.package_name || '-'}</td>
            <td><strong>฿${parseFloat(customer.total_revenue).toLocaleString()}</strong></td>
            <td><span class="badge badge-${customer.status === 'active' ? 'success' : 'danger'}">${customer.status}</span></td>
        </tr>
    `).join('');
}

function updatePackagePerformance(data) {
    const tbody = document.getElementById('packagePerformanceTable');
    const totalRevenue = data.reduce((sum, pkg) => sum + parseFloat(pkg.revenue), 0);
    
    if (data.length === 0) {
        tbody.innerHTML = '<tr><td colspan="4" class="text-center text-muted">ไม่มีข้อมูล</td></tr>';
        return;
    }
    
    tbody.innerHTML = data.map(pkg => {
        const percentage = totalRevenue > 0 ? ((pkg.revenue / totalRevenue) * 100).toFixed(1) : 0;
        return `
            <tr>
                <td><strong>${pkg.name}</strong></td>
                <td>${pkg.customer_count}</td>
                <td><strong>฿${parseFloat(pkg.revenue).toLocaleString()}</strong></td>
                <td>
                    <div style="display: flex; align-items: center; gap: 0.5rem;">
                        <div style="flex-grow: 1; height: 8px; background: #eee; border-radius: 4px; overflow: hidden;">
                            <div style="width: ${percentage}%; height: 100%; background: linear-gradient(90deg, #4CAF50, #2196F3);"></div>
                        </div>
                        <span style="min-width: 50px; text-align: right;">${percentage}%</span>
                    </div>
                </td>
            </tr>
        `;
    }).join('');
}

function exportReport() {
    alert('ฟีเจอร์ส่งออกรายงานกำลังพัฒนา');
}
JAVASCRIPT;

include('../../includes/admin/footer.php');
?>
