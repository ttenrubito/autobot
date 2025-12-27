/**
 * Admin Panel JavaScript
 */

// Check admin authentication (non-blocking)
(function () {
    const token = localStorage.getItem('admin_token');
    if (!token) {
        console.warn('No admin token - redirecting to login');
        // Don't redirect immediately, let page load first
        setTimeout(() => {
            if (!localStorage.getItem('admin_token')) {
                const basePath = (typeof BASE_PATH !== 'undefined') ? BASE_PATH : (window.BASE_PATH_OVERRIDE || '/autobot');
                window.location.href = basePath.replace(/\/$/, '') + '/admin/login.html';
            }
        }, 100);
    }
})();

/**
 * Load dashboard stats on page load
 */
document.addEventListener('DOMContentLoaded', function () {
    console.log('[Admin.js] Loaded');
    console.log('[Admin.js] Path:', window.location.pathname);

    // Check if we're on the dashboard
    if (window.location.pathname.includes('admin/index.html') ||
        window.location.pathname.includes('admin/dashboard.html') ||
        window.location.pathname.endsWith('/admin/')) {
        console.log('[Admin.js] Loading dashboard stats...');
        loadDashboardStats();
    }
});

async function loadDashboardStats() {
    const token = localStorage.getItem('admin_token');
    console.log('[Stats] Token exists:', !!token);

    if (!token) {
        console.error('[Stats] No admin token found');
        // Set default 0 values
        setDefaultStats();
        return;
    }

    try {
        console.log('[Stats] Fetching from API...');

        if (!window.API_ENDPOINTS || !API_ENDPOINTS.ADMIN_STATS) {
            throw new Error('API_ENDPOINTS.ADMIN_STATS is not available (path-config.js not loaded?)');
        }

        const response = await fetch(API_ENDPOINTS.ADMIN_STATS, {
            headers: {
                'Authorization': 'Bearer ' + token
            }
        });

        console.log('[Stats] Response status:', response.status);

        if (!response.ok) {
            throw new Error('API returned ' + response.status);
        }

        const result = await response.json();
        console.log('[Stats] Data received:', result);

        if (result.success && result.data) {
            // Update stat cards
            updateStatCard('totalCustomers', result.data.totalCustomers);
            updateStatCard('totalServices', result.data.activeServices);
            updateStatCard('monthlyRevenue', '฿' + result.data.monthlyRevenue);
            updateStatCard('todayRequests', result.data.todayApiCalls);

            console.log('[Stats] All stat cards updated ✓');

            // Update recent customers table
            if (result.data.recentCustomers && result.data.recentCustomers.length > 0) {
                console.log('[Stats] Updating recent customers:', result.data.recentCustomers.length);
                updateRecentCustomersTable(result.data.recentCustomers);
            } else {
                console.log('[Stats] No recent customers');
                showNoCustomers();
            }
        } else {
            console.error('[Stats] Invalid response format');
            setDefaultStats();
        }
    } catch (error) {
        console.error('[Stats] Error:', error);
        setDefaultStats();
    }
}

function setDefaultStats() {
    updateStatCard('totalCustomers', '0');
    updateStatCard('totalServices', '0');
    updateStatCard('monthlyRevenue', '฿0');
    updateStatCard('todayRequests', '0');
}

function updateStatCard(elementId, value) {
    const element = document.getElementById(elementId);
    if (element) {
        element.textContent = value || '0';
        console.log(`[Stats] Updated ${elementId}:`, value);
    } else {
        console.error(`[Stats] Element not found: ${elementId}`);
    }
}

function updateRecentCustomersTable(customers) {
    const tbody = document.getElementById('recentCustomers');
    if (!tbody) {
        console.error('[Stats] Recent customers tbody not found');
        return;
    }

    tbody.innerHTML = customers.map(customer => `
        <tr>
            <td><strong>${escapeHtml(customer.full_name || customer.email)}</strong></td>
            <td>${escapeHtml(customer.email)}</td>
            <td><span class="badge badge-primary">${escapeHtml(customer.plan_name || 'Free')}</span></td>
            <td><span class="badge badge-${customer.status === 'active' ? 'success' : 'warning'}">${escapeHtml(customer.status)}</span></td>
            <td>${new Date(customer.created_at).toLocaleDateString('th-TH')}</td>
            <td>
                <button class="btn btn-sm btn-outline" onclick="viewCustomer(${customer.id})">
                    <i class="fas fa-eye"></i> ดู
                </button>
            </td>
        </tr>
    `).join('');

    console.log('[Stats] Recent customers table updated ✓');
}

function showNoCustomers() {
    const tbody = document.getElementById('recentCustomers');
    if (tbody) {
        tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;padding:2rem;color:var(--color-gray);">ไม่มีข้อมูลลูกค้า</td></tr>';
    }
}

function viewCustomer(id) {
    window.location.href = `/autobot/admin/customers.html?id=${id}`;
}

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Auto-reload stats every 30 seconds
setInterval(() => {
    if (window.location.pathname.includes('admin/index.html')) {
        console.log('[Stats] Auto-refresh...');
        loadDashboardStats();
    }
}, 30000);
