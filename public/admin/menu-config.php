<?php
/**
 * Admin Menu Customization
 */
define('INCLUDE_CHECK', true);
$page_title = "Menu Customization - Admin Panel";
$current_page = "menu-config";
include('../../includes/admin/header.php');
include('../../includes/admin/sidebar.php');
?>

<main class="main-content">
    <div class="page-header">
        <h1 class="page-title"><i class="fas fa-sliders-h"></i> Menu Customization</h1>
        <p class="page-subtitle">จัดการ menu สำหรับแต่ละลูกค้า</p>
    </div>

    <div class="card">
        <div class="card-body">
            <div class="row">
                <div class="col-4">
                    <label><strong>เลือกลูกค้า:</strong></label>
                    <select id="userSelect" class="form-control" onchange="loadUserMenu()">
                        <option value="">-- เลือกลูกค้า --</option>
                    </select>
                </div>
            </div>
        </div>
    </div>

    <div id="menuEditor" style="display: none;">
        <div class="card mt-4">
            <div class="card-header">
                <h3 class="card-title">จัดการ Menu สำหรับ: <span id="selectedUserName"></span></h3>
            </div>
            <div class="card-body">
                <p class="text-muted">เลือก menu items ที่ต้องการแสดงให้ลูกค้า:</p>
                
                <div id="menuItems" class="row">
                    <!-- Menu items will be loaded here -->
                </div>

                <div style="margin-top: 2rem;">
                    <button class="btn btn-primary" onclick="saveMenuConfig()">
                        <i class="fas fa-save"></i> บันทึก
                    </button>
                    <button class="btn btn-secondary" onclick="resetToDefault()">
                        <i class="fas fa-undo"></i> รีเซ็ตเป็นค่าเริ่มต้น
                    </button>
                </div>
            </div>
        </div>
    </div>
</main>

<?php
$inline_script = <<<'JAVASCRIPT'
const defaultMenus = [
    { id: 'dashboard', label: 'Dashboard', icon: '📊', enabled: true },
    { id: 'chat_history', label: 'ประวัติการสนทนา', icon: '💬', enabled: true },
    { id: 'orders', label: 'คำสั่งซื้อ', icon: '📦', enabled: true },
    { id: 'addresses', label: 'ที่อยู่จัดส่ง', icon: '📍', enabled: true },
    { id: 'payment_history', label: 'ประวัติการชำระ', icon: '💰', enabled: true },
    { id: 'services', label: 'บริการ', icon: '🤖', enabled: false },
    { id: 'usage', label: 'การใช้งาน', icon: '📈', enabled: false },
    { id: 'billing', label: 'ใบแจ้งหนี้', icon: '📄', enabled: false },
    { id: 'profile', label: 'โปรไฟล์', icon: '👤', enabled: true }
];

let currentUserId = null;
let currentMenuConfig = [];

async function loadUsers() {
    const token = localStorage.getItem('admin_token');
    
    try {
        const response = await fetch('/api/admin/users?limit=100', {
            headers: { 'Authorization': 'Bearer ' + token }
        });

        const result = await response.json();
        if (result.success) {
            const select = document.getElementById('userSelect');
            select.innerHTML = '<option value="">-- เลือกลูกค้า --</option>' +
                result.data.users.map(u => `<option value="${u.id}" data-email="${u.email}">${u.full_name} (${u.email})</option>`).join('');
        }
    } catch (error) {
        console.error('Error:', error);
    }
}

async function loadUserMenu() {
    const select = document.getElementById('userSelect');
    currentUserId = select.value;
    
    if (!currentUserId) {
        document.getElementById('menuEditor').style.display = 'none';
        return;
    }
    
    const userEmail = select.options[select.selectedIndex].dataset.email;
    document.getElementById('selectedUserName').textContent = userEmail;
    
    const token = localStorage.getItem('admin_token');
    
    try {
        const response = await fetch(`/api/admin/menu-config/${currentUserId}`, {
            headers: { 'Authorization': 'Bearer ' + token }
        });

        const result = await response.json();
        if (result.success && result.data.menu_items) {
            currentMenuConfig = result.data.menu_items.menus || defaultMenus;
        } else {
            currentMenuConfig = [...defaultMenus];
        }
        
        renderMenuItems();
        document.getElementById('menuEditor').style.display = 'block';
    } catch (error) {
        console.error('Error:', error);
        currentMenuConfig = [...defaultMenus];
        renderMenuItems();
        document.getElementById('menuEditor').style.display = 'block';
    }
}

function renderMenuItems() {
    const container = document.getElementById('menuItems');
    container.innerHTML = currentMenuConfig.map((menu, index) => `
        <div class="col-4" style="margin-bottom: 1rem;">
            <div style="border: 2px solid ${menu.enabled ? 'var(--color-primary)' : 'var(--color-border)'}; padding: 1rem; border-radius: 8px;">
                <label style="display: flex; align-items: center; cursor: pointer;">
                    <input type="checkbox" ${menu.enabled ? 'checked' : ''} onchange="toggleMenu(${index})" style="margin-right: 0.5rem;">
                    <span style="font-size: 1.5rem; margin-right: 0.5rem;">${menu.icon}</span>
                    <strong>${menu.label}</strong>
                </label>
            </div>
        </div>
    `).join('');
}

function toggleMenu(index) {
    currentMenuConfig[index].enabled = !currentMenuConfig[index].enabled;
    renderMenuItems();
}

function resetToDefault() {
    if (!confirm('ต้องการรีเซ็ต menu เป็นค่าเริ่มต้นใช่หรือไม่?')) return;
    currentMenuConfig = [...defaultMenus];
    renderMenuItems();
}

async function saveMenuConfig() {
    if (!currentUserId) return;
    
    const token = localStorage.getItem('admin_token');
    const userEmail = document.getElementById('userSelect').options[document.getElementById('userSelect').selectedIndex].dataset.email;
    
    try {
        const response = await fetch('/api/admin/menu-config', {
            method: 'POST',
            headers: {
                'Authorization': 'Bearer ' + token,
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                user_email: userEmail,
                menu_items: { menus: currentMenuConfig }
            })
        });

        const result = await response.json();
        if (result.success) {
            alert('บันทึก menu configuration เรียบร้อย');
        } else {
            alert('เกิดข้อผิดพลาด: ' + result.message);
        }
    } catch (error) {
        console.error('Error:', error);
        alert('เกิดข้อผิดพลาด');
    }
}

document.addEventListener('DOMContentLoaded', loadUsers);
JAVASCRIPT;

include('../../includes/admin/footer.php');
?>
