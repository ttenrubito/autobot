/**
 * Dashboard JavaScript
 * Loads and displays dashboard statistics
 */

// Require authentication
requireAuth();

// Load user info and stats on page load
document.addEventListener('DOMContentLoaded', async () => {
    await loadUserInfo();
    await loadDashboardStats();
});

// Load user information
async function loadUserInfo() {
    const userData = getUserData();
    if (!userData) return;

    // Update sidebar user info with null checks
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
    console.log('🔧 [dashboard] API endpoint:', API_ENDPOINTS?.DASHBOARD_STATS);

    // Safe fallback for showLoading
    if (typeof showLoading === 'function') {
        showLoading();
    } else {
        console.log('⏳ [dashboard] Loading...');
    }

    try {
        // Check if API_ENDPOINTS exists
        if (typeof API_ENDPOINTS === 'undefined' || !API_ENDPOINTS.DASHBOARD_STATS) {
            throw new Error('API_ENDPOINTS.DASHBOARD_STATS is not defined');
        }

        // Use centralized endpoint so it correctly resolves to /api/dashboard/stats.php
        const response = await apiCall(API_ENDPOINTS.DASHBOARD_STATS);

        console.log('📊 [dashboard] API response:', response);

        if (response && response.success) {
            const data = response.data;
            console.log('✅ [dashboard] Data received:', {
                overview: data.overview,
                usage_trend_count: data.usage_trend?.length || 0,
                service_breakdown_count: data.service_breakdown?.length || 0,
                recent_activities_count: data.recent_activities?.length || 0
            });

            // Update overview cards
            updateOverviewCards(data.overview || {});

            // Update usage trend chart
            updateUsageTrendChart(data.usage_trend || []);

            // Update service breakdown table
            updateServiceBreakdown(data.service_breakdown || []);

            // Update recent activities
            updateRecentActivities(data.recent_activities || []);
        } else {
            console.warn('⚠️ [dashboard] API returned no success:', response);
            // Show empty states
            updateOverviewCards({});
            updateServiceBreakdown([]);
            updateRecentActivities([]);
        }
    } catch (error) {
        console.error('❌ [dashboard] Failed to load dashboard stats:', error);

        // Safe fallback for showToast
        if (typeof showToast === 'function') {
            showToast('ไม่สามารถโหลดข้อมูลได้', 'error');
        } else {
            console.error('❌ [dashboard] Cannot show toast - showToast not defined');
        }

        // Show empty states on error
        updateOverviewCards({});
        updateServiceBreakdown([]);
        updateRecentActivities([]);
    } finally {
        // Safe fallback for hideLoading
        if (typeof hideLoading === 'function') {
            hideLoading();
        } else {
            console.log('✅ [dashboard] Loading complete');
        }
    }
}

// Update overview stat cards
function updateOverviewCards(overview) {
    const totalServicesEl = document.getElementById('totalServices');
    const botMessagesEl = document.getElementById('botMessagesToday');
    const apiCallsEl = document.getElementById('apiCallsToday');
    const currentMonthCostEl = document.getElementById('currentMonthCost');

    if (totalServicesEl) {
        totalServicesEl.textContent = formatNumber(overview.total_services);
    }
    if (botMessagesEl) {
        botMessagesEl.textContent = formatNumber(overview.bot_messages_today);
    }
    if (apiCallsEl) {
        apiCallsEl.textContent = formatNumber(overview.api_calls_today);
    }
    // current_month_cost is optional and some layouts may not have this card
    if (currentMonthCostEl && overview.current_month_cost !== undefined) {
        currentMonthCostEl.textContent = formatCurrency(overview.current_month_cost);
    }
}

// Update usage trend chart (using Chart.js)
function updateUsageTrendChart(trendData) {
    const ctx = document.getElementById('usageTrendChart');

    if (!ctx) return;

    const dates = trendData.map(d => d.date).reverse();
    const apiCalls = trendData.map(d => parseInt(d.api_calls) || 0).reverse();
    const botMessages = trendData.map(d => parseInt(d.bot_messages) || 0).reverse();

    new Chart(ctx, {
        type: 'line',
        data: {
            labels: dates,
            datasets: [
                {
                    label: 'API Calls',
                    data: apiCalls,
                    borderColor: '#2563eb',
                    backgroundColor: 'rgba(37, 99, 235, 0.1)',
                    tension: 0.4
                },
                {
                    label: 'Bot Messages',
                    data: botMessages,
                    borderColor: '#10b981',
                    backgroundColor: 'rgba(16, 185, 129, 0.1)',
                    tension: 0.4
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
                    beginAtZero: true
                }
            }
        }
    });
}

// Update service breakdown table
function updateServiceBreakdown(services) {
    const tbody = document.getElementById('serviceBreakdownBody');

    if (!tbody) return;

    tbody.innerHTML = '';

    // Handle empty state
    if (!services || services.length === 0) {
        tbody.innerHTML = `
            <tr>
                <td colspan="7" style="text-align: center; padding: 2rem; color: var(--color-gray);">
                    <div style="font-size: 2rem; margin-bottom: 0.5rem;">🤖</div>
                    <div>ยังไม่มีบริการในระบบ</div>
                    <a href="services.php" class="btn btn-primary btn-sm" style="margin-top: 1rem;">เพิ่มบริการ</a>
                </td>
            </tr>
        `;
        return;
    }

    console.log('🎨 [dashboard] Rendering', services.length, 'services');

    services.forEach(service => {
        const row = document.createElement('tr');

        const statusBadge = service.status === 'active'
            ? '<span class="badge badge-success">Active</span>'
            : '<span class="badge badge-warning">Paused</span>';

        row.innerHTML = `
            <td><strong>${service.service_name}</strong></td>
            <td>${service.service_type}</td>
            <td>${service.platform || '-'}</td>
            <td>${statusBadge}</td>
            <td>${formatNumber(service.today_messages || 0)}</td>
            <td>${formatNumber(service.today_api_calls || 0)}</td>
            <td>
                <a href="services.html?id=${service.id}" class="btn btn-sm btn-outline">
                    ดูรายละเอียด
                </a>
            </td>
        `;

        tbody.appendChild(row);
    });
}

// Update recent activities
function updateRecentActivities(activities) {
    const container = document.getElementById('recentActivities');

    if (!container) return;

    container.innerHTML = '';

    // Handle empty state
    if (!activities || activities.length === 0) {
        container.innerHTML = `
            <div style="text-align: center; padding: 2rem; color: var(--color-gray);">
                <div style="font-size: 2rem; margin-bottom: 0.5rem;">📋</div>
                <div>ไม่มีกิจกรรมล่าสุด</div>
            </div>
        `;
        return;
    }

    console.log('📝 [dashboard] Rendering', activities.length, 'activities');

    activities.slice(0, 10).forEach(activity => {
        const item = document.createElement('div');
        item.className = 'activity-item';
        item.style.cssText = 'padding: 0.75rem; border-bottom: 1px solid var(--color-light-3);';

        item.innerHTML = `
            <div style="display: flex; justify-content: space-between; align-items: start;">
                <div>
                    <strong>${activity.action}</strong>
                    ${activity.resource_type ? `<span style="color: var(--color-gray); font-size: 0.875rem;"> - ${activity.resource_type}</span>` : ''}
                </div>
                <span style="color: var(--color-gray); font-size: 0.875rem;">
                    ${formatRelativeTime(activity.created_at)}
                </span>
            </div>
        `;

        container.appendChild(item);
    });
}
