/**
 * Admin Menu Manager JavaScript
 * Handles user menu configuration management
 */

// Available menu items (default list)
const AVAILABLE_MENUS = [
    { id: 'dashboard', label: 'Dashboard', icon: '📊', url: 'dashboard.php' },
    { id: 'services', label: 'บริการของฉัน', icon: '🤖', url: 'services.php' },
    { id: 'usage', label: 'การใช้งาน', icon: '📈', url: 'usage.php' },
    { id: 'payment', label: 'ชำระเงิน', icon: '💳', url: 'payment.php' },
    { id: 'billing', label: 'ใบแจ้งหนี้', icon: '📄', url: 'billing.php' },
    { id: 'chat_history', label: 'ประวัติการสนทนา', icon: '💬', url: 'chat-history.php' },
    { id: 'conversations', label: 'แชทกับลูกค้า', icon: '💭', url: 'conversations.php' },
    { id: 'addresses', label: 'ที่อยู่จัดส่ง', icon: '📍', url: 'addresses.php' },
    { id: 'orders', label: 'คำสั่งซื้อ', icon: '📦', url: 'orders.php' },
    { id: 'payment_history', label: 'ประวัติการชำระ(ตรวจ)', icon: '💰', url: 'payment-history.php' },
    { id: 'campaigns', label: 'จัดการแคมเปญ', icon: '🎯', url: 'campaigns.php' },
    { id: 'line_applications', label: 'ใบสมัคร LINE', icon: '📋', url: 'line-applications.php' },
    { id: 'cases', label: 'Case Inbox', icon: '📥', url: 'cases.php' },
    { id: 'savings', label: 'ออมเงิน', icon: '🐷', url: 'savings.php' },
    { id: 'installments', label: 'ผ่อนชำระ', icon: '📅', url: 'installments.php' },
    { id: 'deposits', label: 'มัดจำสินค้า', icon: '💎', url: 'deposits.php' },
    { id: 'pawns', label: 'ฝากจำนำ', icon: '🏆', url: 'pawns.php' },
    { id: 'repairs', label: 'งานซ่อม', icon: '🔧', url: 'repairs.php' },
    { id: 'profile', label: 'โปรไฟล์', icon: '👤', url: 'profile.php' },
];

let currentUserEmail = null;
let allUsers = [];
let allConfigs = [];

// Initialize
document.addEventListener('DOMContentLoaded', () => {
    loadUsers();

    // Form submit
    document.getElementById('menuConfigForm').addEventListener('submit', async (e) => {
        e.preventDefault();
        await saveMenuConfig();
    });
});

// Load all users
async function loadUsers() {
    try {
        const apiUrl = (typeof PATH !== 'undefined' && PATH.api)
            ? PATH.api('api/user/profile.php') // Or create a dedicated endpoint
            : '/api/user/profile.php';

        // For now, we'll use a direct database query endpoint
        // You might need to create a dedicated admin/users.php API

        // Temporary: Load from hardcoded or fetch all users
        // In production, create /api/admin/users.php

        showToast('กำลังโหลดข้อมูล users...', 'info');

        // TODO: Replace with actual endpoint
        // For demo, using placeholder
        allUsers = await fetchAllUsers();
        await loadMenuConfigs();

        renderUsersTable();
    } catch (error) {
        console.error('Failed to load users:', error);
        showToast('ไม่สามารถโหลดข้อมูล users ได้', 'error');
    }
}

// Fetch all users (placeholder - replace with real API)
async function fetchAllUsers() {
    try {
        const apiUrl = (typeof API_ENDPOINTS !== 'undefined' && API_ENDPOINTS.ADMIN_USERS)
            ? API_ENDPOINTS.ADMIN_USERS
            : (typeof PATH !== 'undefined' && PATH.api)
                ? PATH.api('api/admin/users.php')
                : '/autobot/api/admin/users.php';

        const token = localStorage.getItem('admin_token');
        const response = await fetch(apiUrl, {
            method: 'GET',
            headers: {
                'Authorization': 'Bearer ' + token
            }
        });

        if (response.ok) {
            const result = await response.json();

            // Handle both old and new response formats
            if (result.ok && result.data && result.data.users) {
                return result.data.users;
            } else if (result.success && result.data && result.data.users) {
                return result.data.users;
            }
        } else {
            console.error('Users API returned non-OK status:', response.status);
        }
    } catch (e) {
        console.error('Failed to fetch users:', e);
    }

    return [];
}

// Load all menu configs
async function loadMenuConfigs() {
    try {
        const apiUrl = (typeof API_ENDPOINTS !== 'undefined' && API_ENDPOINTS.ADMIN_USER_MENU_CONFIG)
            ? API_ENDPOINTS.ADMIN_USER_MENU_CONFIG
            : (typeof PATH !== 'undefined' && PATH.api)
                ? PATH.api('api/admin/user-menu-config.php')
                : '/autobot/api/admin/user-menu-config.php';

        const token = localStorage.getItem('admin_token');
        const response = await fetch(apiUrl, {
            method: 'GET',
            headers: {
                'Authorization': 'Bearer ' + token
            }
        });

        if (response.ok) {
            const result = await response.json();
            allConfigs = result.data?.configs || [];
        }
    } catch (error) {
        console.error('Failed to load configs:', error);
    }
}

// Render users table
function renderUsersTable() {
    const tbody = document.getElementById('usersTableBody');

    if (allUsers.length === 0) {
        tbody.innerHTML = `
            <tr>
                <td colspan="6" style="text-align:center;padding:2rem;">
                    <div style="color: var(--color-gray);">
                        ไม่พบข้อมูล users<br>
                        <small>กำลังรอการสร้าง API endpoint สำหรับดึงรายชื่อ users</small>
                    </div>
                </td>
            </tr>
        `;
        return;
    }

    tbody.innerHTML = allUsers.map(user => {
        const config = allConfigs.find(c => c.user_email === user.email);
        const hasCustomConfig = !!config;

        return `
            <tr>
                <td>${user.id}</td>
                <td><strong>${escapeHtml(user.email)}</strong></td>
                <td>${escapeHtml(user.full_name || '-')}</td>
                <td><span class="badge badge-${user.status === 'active' ? 'success' : 'secondary'}">${user.status}</span></td>
                <td>
                    <span class="config-badge config-${hasCustomConfig ? 'custom' : 'default'}">
                        ${hasCustomConfig ? '✓ Custom Config' : 'Default'}
                    </span>
                </td>
                <td style="text-align:center;">
                    <button class="btn btn-sm btn-primary" onclick="openMenuConfigModal('${escapeHtml(user.email)}')">
                        จัดการเมนู
                    </button>
                </td>
            </tr>
        `;
    }).join('');
}

// Open menu config modal
async function openMenuConfigModal(userEmail) {
    currentUserEmail = userEmail;

    document.getElementById('modalUserEmail').textContent = userEmail;
    document.getElementById('modalUserEmailInput').value = userEmail;

    // Load current config
    const config = allConfigs.find(c => c.user_email === userEmail);
    const enabledMenus = config?.menu_items?.menus || [];

    // Render checkboxes
    const container = document.getElementById('menuCheckboxContainer');
    container.innerHTML = AVAILABLE_MENUS.map(menu => {
        const isEnabled = enabledMenus.length === 0 ||
            enabledMenus.some(m => m.id === menu.id && m.enabled);

        return `
            <div class="menu-checkbox-item">
                <input 
                    type="checkbox" 
                    id="menu_${menu.id}" 
                    value="${menu.id}"
                    ${isEnabled ? 'checked' : ''}
                >
                <label for="menu_${menu.id}" class="menu-checkbox-label">
                    <span class="menu-icon">${menu.icon}</span>
                    <div class="menu-info">
                        <div class="menu-name">${escapeHtml(menu.label)}</div>
                        <div class="menu-url">${escapeHtml(menu.url)}</div>
                    </div>
                </label>
            </div>
        `;
    }).join('');

    // Show modal
    document.getElementById('menuConfigModal').style.display = 'flex';
}

// Close modal
function closeMenuConfigModal() {
    document.getElementById('menuConfigModal').style.display = 'none';
    currentUserEmail = null;
}

// Save menu config
async function saveMenuConfig() {
    if (!currentUserEmail) return;

    // Get selected menus
    const selectedMenus = [];
    AVAILABLE_MENUS.forEach(menu => {
        const checkbox = document.getElementById(`menu_${menu.id}`);
        selectedMenus.push({
            ...menu,
            enabled: checkbox.checked
        });
    });

    try {
        const apiUrl = (typeof API_ENDPOINTS !== 'undefined' && API_ENDPOINTS.ADMIN_USER_MENU_CONFIG)
            ? API_ENDPOINTS.ADMIN_USER_MENU_CONFIG
            : (typeof PATH !== 'undefined' && PATH.api)
                ? PATH.api('api/admin/user-menu-config.php')
                : '/autobot/api/admin/user-menu-config.php';

        const token = localStorage.getItem('admin_token');
        const response = await fetch(apiUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Authorization': 'Bearer ' + token
            },
            body: JSON.stringify({
                user_email: currentUserEmail,
                menu_items: {
                    menus: selectedMenus
                },
                is_active: 1
            })
        });

        const result = await response.json();

        if (result.ok) {
            showToast('บันทึกการตั้งค่าเมนูสำเร็จ', 'success');
            closeMenuConfigModal();

            // Reload configs
            await loadMenuConfigs();
            renderUsersTable();
        } else {
            showToast('เกิดข้อผิดพลาด: ' + (result.error || 'Unknown error'), 'error');
        }
    } catch (error) {
        console.error('Save error:', error);
        showToast('ไม่สามารถบันทึกได้: ' + error.message, 'error');
    }
}

// Reset to default
async function resetToDefault() {
    if (!currentUserEmail) return;

    if (!confirm(`ยืนยันการรีเซ็ตเมนูของ ${currentUserEmail} กลับเป็นค่าเริ่มต้น?`)) {
        return;
    }

    try {
        const apiUrl = (typeof API_ENDPOINTS !== 'undefined' && API_ENDPOINTS.ADMIN_USER_MENU_CONFIG)
            ? API_ENDPOINTS.ADMIN_USER_MENU_CONFIG
            : (typeof PATH !== 'undefined' && PATH.api)
                ? PATH.api('api/admin/user-menu-config.php')
                : '/autobot/api/admin/user-menu-config.php';

        const token = localStorage.getItem('admin_token');
        const response = await fetch(apiUrl, {
            method: 'DELETE',
            headers: {
                'Content-Type': 'application/json',
                'Authorization': 'Bearer ' + token
            },
            body: JSON.stringify({
                user_email: currentUserEmail
            })
        });

        const result = await response.json();

        if (result.ok) {
            showToast('รีเซ็ตกลับเป็นค่าเริ่มต้นสำเร็จ', 'success');
            closeMenuConfigModal();

            // Reload configs
            await loadMenuConfigs();
            renderUsersTable();
        } else {
            showToast('เกิดข้อผิดพลาด: ' + (result.error || 'Unknown error'), 'error');
        }
    } catch (error) {
        console.error('Reset error:', error);
        showToast('ไม่สามารถรีเซ็ตได้: ' + error.message, 'error');
    }
}

// Show toast notification
function showToast(message, type = 'info') {
    const toast = document.getElementById('toast');
    toast.textContent = message;
    toast.className = `toast ${type} show`;

    setTimeout(() => {
        toast.classList.remove('show');
    }, 3000);
}

// Escape HTML
function escapeHtml(str) {
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}
