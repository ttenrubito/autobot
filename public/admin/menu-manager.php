<?php
/**
 * Admin Menu Manager - Manage user menu configurations
 * Admin only access
 */
define('INCLUDE_CHECK', true);

$page_title = "จัดการเมนู User - Admin Portal";
$current_page = "menu-config";

include('../../includes/admin/header.php');
include('../../includes/admin/sidebar.php');
?>

<main class="main-content">
    <div class="page-header">
        <div>
            <h1 class="page-title">🔧 จัดการเมนู User</h1>
            <p class="page-subtitle">กำหนดเมนูที่ user แต่ละคนสามารถเห็นได้</p>
        </div>
    </div>

    <!-- User List -->
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">รายชื่อ Users</h3>
            <div style="font-size: 0.9rem; color: var(--color-gray);">
                คลิก "จัดการเมนู" เพื่อกำหนดเมนูสำหรับ user นั้นๆ
            </div>
        </div>
        <div class="card-body">
            <div id="usersContainer" class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Email</th>
                            <th>ชื่อ</th>
                            <th>สถานะ</th>
                            <th>Config Status</th>
                            <th style="text-align: center;">จัดการ</th>
                        </tr>
                    </thead>
                    <tbody id="usersTableBody">
                        <tr>
                            <td colspan="6" style="text-align:center;padding:2rem;">
                                <div class="spinner" style="margin:0 auto 1rem;"></div>
                                กำลังโหลดข้อมูล...
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</main>

<!-- Menu Configuration Modal -->
<div id="menuConfigModal" class="modal modal-ui" style="display:none;">
    <div class="modal-overlay" onclick="closeMenuConfigModal()"></div>
    <div class="modal-dialog" style="max-width: 700px;">
        <div class="modal-content-wrapper">
            <div class="modal-header-custom">
                <h2 class="modal-title-custom">จัดการเมนู: <span id="modalUserEmail"></span></h2>
                <button class="modal-close-custom" onclick="closeMenuConfigModal()">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                        <path d="M18 6L6 18M6 6l12 12" stroke-width="2" stroke-linecap="round"/>
                    </svg>
                </button>
            </div>
            <div class="modal-body-custom">
                <div class="menu-config-notice">
                    <strong>📌 หมายเหตุ:</strong> เลือกเฉพาะเมนูที่ต้องการให้ user คนนี้เห็น หากไม่ tick จะถูกซ่อน
                </div>

                <form id="menuConfigForm">
                    <input type="hidden" id="modalUserEmailInput">
                    
                    <div id="menuCheckboxContainer" class="menu-checkbox-list">
                        <!-- Rendered by JS -->
                    </div>

                    <div class="modal-actions" style="margin-top: 2rem; display: flex; gap: 1rem; justify-content: flex-end;">
                        <button type="button" class="btn btn-secondary" onclick="closeMenuConfigModal()">ยกเลิก</button>
                        <button type="button" class="btn btn-warning" onclick="resetToDefault()">รีเซ็ตเป็นค่าเริ่มต้น</button>
                        <button type="submit" class="btn btn-primary">บันทึก</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Toast Notification -->
<div id="toast" class="toast"></div>

<style>
.spinner {
    width: 48px;
    height: 48px;
    border: 4px solid var(--color-border);
    border-top-color: var(--color-primary);
    border-radius: 50%;
    animation: spin 0.8s linear infinite;
}

@keyframes spin {
    to { transform: rotate(360deg); }
}

.config-badge {
    padding: 0.25rem 0.75rem;
    border-radius: 12px;
    font-size: 0.75rem;
    font-weight: 600;
}

.config-custom {
    background: #e3f2fd;
    color: #1976d2;
}

.config-default {
    background: #f5f5f5;
    color: #757575;
}

.menu-config-notice {
    background: #fff3cd;
    border-left: 4px solid #ffc107;
    padding: 1rem;
    margin-bottom: 1.5rem;
    border-radius: 4px;
    font-size: 0.9rem;
}

.menu-checkbox-list {
    display: grid;
    gap: 0.75rem;
}

.menu-checkbox-item {
    display: flex;
    align-items: center;
    padding: 1rem;
    background: var(--color-background);
    border: 2px solid var(--color-border);
    border-radius: 12px;
    transition: all 0.2s ease;
}

.menu-checkbox-item:hover {
    border-color: var(--color-primary);
    background: #f8f9fa;
}

.menu-checkbox-item input[type="checkbox"] {
    width: 20px;
    height: 20px;
    margin-right: 1rem;
    cursor: pointer;
}

.menu-checkbox-label {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    flex: 1;
    cursor: pointer;
}

.menu-icon {
    font-size: 1.5rem;
}

.menu-info {
    display: flex;
    flex-direction: column;
    gap: 0.25rem;
}

.menu-name {
    font-weight: 600;
    font-size: 1rem;
}

.menu-url {
    font-size: 0.85rem;
    color: var(--color-gray);
}

.toast {
    position: fixed;
    bottom: 2rem;
    right: 2rem;
    padding: 1rem 1.5rem;
    background: #323232;
    color: white;
    border-radius: 8px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.3);
    opacity: 0;
    transform: translateY(20px);
    transition: all 0.3s ease;
    z-index: 10000;
    pointer-events: none;
}

.toast.show {
    opacity: 1;
    transform: translateY(0);
    pointer-events: all;
}

.toast.success {
    background: #4caf50;
}

.toast.error {
    background: #f44336;
}
</style>

<?php
$extra_scripts = [
    'assets/js/admin/menu-manager.js'
];

if (file_exists(__DIR__ . '/../../includes/admin/footer.php')) {
    include(__DIR__ . '/../../includes/admin/footer.php');
} else {
    include(__DIR__ . '/../../includes/customer/footer.php');
}
?>
