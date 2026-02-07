/**
 * Dashboard JavaScript
 * Business-focused dashboard for e-commerce chatbot
 */

// Require authentication
requireAuth();

// Load user info and stats on page load
document.addEventListener('DOMContentLoaded', async () => {
    await loadUserInfo();
    setTodayDate();
    await loadDashboardStats();
});

// Set today's date in header
function setTodayDate() {
    const dateEl = document.getElementById('todayDate');
    if (dateEl) {
        const today = new Date();
        const options = { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' };
        dateEl.textContent = today.toLocaleDateString('th-TH', options);
    }
}

// Load user information
async function loadUserInfo() {
    const userData = getUserData();
    if (!userData) return;

    const userNameEl = document.getElementById('userName');
    const userEmailEl = document.getElementById('userEmail');
    const userAvatarEl = document.getElementById('userAvatar');

    if (userNameEl) userNameEl.textContent = userData.full_name || userData.email;
    if (userEmailEl) userEmailEl.textContent = userData.email;

    if (userAvatarEl) {
        const initial = (userData.full_name || userData.email).charAt(0).toUpperCase();
        userAvatarEl.textContent = initial;
    }
}

// Load dashboard statistics
async function loadDashboardStats() {
    console.log('🔧 [dashboard] Loading dashboard stats...');

    if (typeof showLoading === 'function') showLoading();

    try {
        if (typeof API_ENDPOINTS === 'undefined' || !API_ENDPOINTS.DASHBOARD_STATS) {
            throw new Error('API_ENDPOINTS.DASHBOARD_STATS is not defined');
        }

        const response = await apiCall(API_ENDPOINTS.DASHBOARD_STATS);
        console.log('📊 [dashboard] API response:', response);

        if (response && response.success) {
            const data = response.data;
            
            // Update Today's Highlight
            updateTodayHighlight(data.today || {});
            
            // Update Weekly Stats
            updateWeeklyStats(data.weekly || {});
            
            // Update Action Items
            updateActionItems(data.action_items || {});
            
            // Update Revenue Chart
            updateRevenueChart(data.usage_trend || []);
            
            // Update Pending Slips Table
            updatePendingSlips(data.pending_slips_list || []);
            
            // Update Recent Orders
            updateRecentOrders(data.recent_orders || []);
        } else if (response && response.status === 401) {
            // Token expired - will be handled by apiCall redirect
            console.warn('⚠️ [dashboard] Token expired, redirecting to login...');
        } else {
            console.warn('⚠️ [dashboard] API returned no success:', response);
        }
    } catch (error) {
        console.error('❌ [dashboard] Failed to load dashboard stats:', error);
        if (typeof showToast === 'function') {
            showToast('ไม่สามารถโหลดข้อมูลได้', 'error');
        }
    } finally {
        if (typeof hideLoading === 'function') hideLoading();
    }
}

// Update Today's Highlight cards
function updateTodayHighlight(today) {
    const setEl = (id, value) => {
        const el = document.getElementById(id);
        if (el) el.textContent = value;
    };

    // Today's Revenue
    setEl('todayRevenue', formatCurrency(today.revenue || 0));
    setEl('todayOrders', formatNumber(today.orders || 0));
    setEl('pendingSlips', formatNumber(today.pending_slips || 0));

    // Revenue comparison
    const revenueCompareEl = document.getElementById('revenueCompare');
    if (revenueCompareEl) {
        const diff = (today.revenue || 0) - (today.revenue_yesterday || 0);
        if (diff > 0) {
            revenueCompareEl.textContent = `+${formatCurrency(diff)} จากเมื่อวาน`;
            revenueCompareEl.className = 'highlight-compare up';
        } else if (diff < 0) {
            revenueCompareEl.textContent = `${formatCurrency(diff)} จากเมื่อวาน`;
            revenueCompareEl.className = 'highlight-compare down';
        } else {
            revenueCompareEl.textContent = 'เท่ากับเมื่อวาน';
            revenueCompareEl.className = 'highlight-compare same';
        }
    }

    // Orders comparison
    const ordersCompareEl = document.getElementById('ordersCompare');
    if (ordersCompareEl) {
        const diff = (today.orders || 0) - (today.orders_yesterday || 0);
        if (diff > 0) {
            ordersCompareEl.textContent = `+${diff} จากเมื่อวาน`;
            ordersCompareEl.className = 'highlight-compare up';
        } else if (diff < 0) {
            ordersCompareEl.textContent = `${diff} จากเมื่อวาน`;
            ordersCompareEl.className = 'highlight-compare down';
        } else {
            ordersCompareEl.textContent = 'เท่ากับเมื่อวาน';
            ordersCompareEl.className = 'highlight-compare same';
        }
    }
}

// Update Weekly Stats
function updateWeeklyStats(weekly) {
    const weeklyRevenueEl = document.getElementById('weeklyRevenue');
    const weeklyCompareEl = document.getElementById('weeklyCompare');
    
    if (weeklyRevenueEl) {
        weeklyRevenueEl.textContent = formatCurrency(weekly.this_week_revenue || 0);
    }
    
    if (weeklyCompareEl) {
        const thisWeek = weekly.this_week_revenue || 0;
        const lastWeek = weekly.last_week_revenue || 0;
        const diff = thisWeek - lastWeek;
        
        if (diff > 0) {
            weeklyCompareEl.textContent = `+${formatCurrency(diff)} vs สัปดาห์ที่แล้ว`;
            weeklyCompareEl.className = 'highlight-compare up';
        } else if (diff < 0) {
            weeklyCompareEl.textContent = `${formatCurrency(diff)} vs สัปดาห์ที่แล้ว`;
            weeklyCompareEl.className = 'highlight-compare down';
        } else {
            weeklyCompareEl.textContent = 'เท่ากับสัปดาห์ที่แล้ว';
            weeklyCompareEl.className = 'highlight-compare same';
        }
    }
}

// Update Action Items counts
function updateActionItems(actions) {
    const setEl = (id, value) => {
        const el = document.getElementById(id);
        if (el) el.textContent = formatNumber(value || 0);
    };

    setEl('actionPendingSlips', actions.pending_slips || 0);
    setEl('actionVerifyingSlips', actions.verifying_slips || 0);
    setEl('actionOrdersToShip', actions.orders_to_ship || 0);
    setEl('actionOrdersAwaiting', actions.orders_awaiting_payment || 0);
    
    // Also update the highlight card
    const pendingSlipsEl = document.getElementById('pendingSlips');
    if (pendingSlipsEl) {
        pendingSlipsEl.textContent = formatNumber(actions.pending_slips || 0);
    }
}

// Update Revenue Chart
function updateRevenueChart(trendData) {
    const ctx = document.getElementById('revenueChart');
    if (!ctx) return;

    const dates = trendData.map(d => formatShortDate(d.date)).reverse();
    const revenues = trendData.map(d => parseFloat(d.revenue) || 0).reverse();
    const orders = trendData.map(d => parseInt(d.orders) || 0).reverse();

    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: dates,
            datasets: [
                {
                    label: 'ยอดขาย (บาท)',
                    data: revenues,
                    backgroundColor: 'rgba(56, 161, 105, 0.8)',
                    borderColor: '#38a169',
                    borderWidth: 1,
                    yAxisID: 'y'
                },
                {
                    label: 'Orders',
                    data: orders,
                    type: 'line',
                    borderColor: '#3182ce',
                    backgroundColor: 'rgba(49, 130, 206, 0.1)',
                    tension: 0.4,
                    yAxisID: 'y1'
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'bottom'
                }
            },
            scales: {
                y: {
                    type: 'linear',
                    position: 'left',
                    beginAtZero: true,
                    ticks: {
                        callback: function(value) {
                            return '฿' + value.toLocaleString();
                        }
                    }
                },
                y1: {
                    type: 'linear',
                    position: 'right',
                    beginAtZero: true,
                    grid: {
                        drawOnChartArea: false
                    }
                }
            }
        }
    });
}

// Update Pending Slips Table
function updatePendingSlips(slips) {
    const tbody = document.getElementById('pendingSlipsBody');
    if (!tbody) return;

    tbody.innerHTML = '';

    if (!slips || slips.length === 0) {
        tbody.innerHTML = `
            <tr>
                <td colspan="4" style="text-align: center; padding: 2rem; color: var(--color-gray);">
                    <div style="font-size: 2rem; margin-bottom: 0.5rem;">✅</div>
                    <div>ไม่มี slip รอตรวจ</div>
                </td>
            </tr>
        `;
        return;
    }

    slips.forEach(slip => {
        const row = document.createElement('tr');
        row.innerHTML = `
            <td title="${slip.order_number || ''}">${slip.payment_no || '-'}</td>
            <td>${formatCurrency(slip.amount || 0)}</td>
            <td>${getSlipStatusBadge(slip.status)}</td>
            <td>
                <a href="payment-history.php?id=${slip.id}" class="btn btn-sm btn-primary">ตรวจ</a>
            </td>
        `;
        tbody.appendChild(row);
    });
}

// Helper: Format short date (for chart labels)
function formatShortDate(dateStr) {
    if (!dateStr) return '';
    const d = new Date(dateStr);
    return d.toLocaleDateString('th-TH', { day: 'numeric', month: 'short' });
}

// Helper: Get slip status badge
function getSlipStatusBadge(status) {
    const badges = {
        'pending': '<span class="badge badge-warning">รอตรวจ</span>',
        'verifying': '<span class="badge badge-info">กำลังตรวจ</span>',
        'verified': '<span class="badge badge-success">ผ่านแล้ว</span>',
        'rejected': '<span class="badge badge-danger">ไม่ผ่าน</span>'
    };
    return badges[status] || `<span class="badge">${status}</span>`;
}

// Update recent orders table
function updateRecentOrders(orders) {
    const tbody = document.getElementById('recentOrdersBody');
    if (!tbody) return;

    tbody.innerHTML = '';

    if (!orders || orders.length === 0) {
        tbody.innerHTML = `
            <tr>
                <td colspan="3" style="text-align: center; padding: 2rem; color: var(--color-gray);">
                    <div style="font-size: 2rem; margin-bottom: 0.5rem;">📦</div>
                    <div>ยังไม่มีคำสั่งซื้อ</div>
                </td>
            </tr>
        `;
        return;
    }

    orders.forEach(order => {
        const row = document.createElement('tr');
        row.innerHTML = `
            <td><a href="orders.php?id=${order.id}" title="${order.customer_name || ''}">${order.order_number}</a></td>
            <td>${formatCurrency(order.total_amount || 0)}</td>
            <td>${getStatusBadge(order.status)}</td>
        `;
        tbody.appendChild(row);
    });
}

// Helper: Get status badge HTML
function getStatusBadge(status) {
    const badges = {
        'pending': '<span class="badge badge-warning">รอดำเนินการ</span>',
        'awaiting_payment': '<span class="badge badge-info">รอชำระ</span>',
        'confirmed': '<span class="badge badge-primary">ยืนยันแล้ว</span>',
        'processing': '<span class="badge badge-info">กำลังจัดส่ง</span>',
        'shipped': '<span class="badge badge-secondary">จัดส่งแล้ว</span>',
        'delivered': '<span class="badge badge-success">สำเร็จ</span>',
        'completed': '<span class="badge badge-success">สำเร็จ</span>',
        'cancelled': '<span class="badge badge-danger">ยกเลิก</span>'
    };
    return badges[status] || `<span class="badge">${status}</span>`;
}
