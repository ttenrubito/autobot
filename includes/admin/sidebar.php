<?php
/**
 * Admin Panel - Sidebar Include
 * Reusable sidebar navigation component
 */

// Set current page for active state highlighting
if (!isset($current_page)) {
    $current_page = '';
}
?>
<!-- Mobile Menu Toggle -->
<button class="mobile-menu-toggle" id="mobileMenuToggle" aria-label="Toggle Menu">
    <i class="fas fa-bars"></i>
</button>

<!-- Mobile Overlay -->
<div class="mobile-overlay" id="mobileOverlay"></div>

<!-- Sidebar -->
<aside class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <a href="#" onclick="window.location.href = PAGES.ADMIN_DASHBOARD; return false;" class="sidebar-logo">
            <img id="sidebarLogo" alt="AI Automation Logo" 
                 style="max-width: 100%; height: auto; max-height: 50px; padding: 0.5rem; border-radius: 8px;">
        </a>
        <p class="sidebar-subtitle">AI Automation Management</p>
    </div>

    <div class="sidebar-user">
        <div class="sidebar-user-avatar sidebar-user-avatar--danger" id="adminAvatar">A</div>
        <div class="sidebar-user-info">
            <div class="sidebar-user-name" id="adminName">Admin User</div>
            <div class="sidebar-user-email" id="adminEmail">admin@aiautomation.com</div>
        </div>
    </div>

    <nav>
        <ul class="sidebar-nav">
            <li class="sidebar-nav-item">
                <a href="#" onclick="window.location.href = PAGES.ADMIN_DASHBOARD; return false;" 
                   class="sidebar-nav-link <?php echo ($current_page === 'dashboard') ? 'active' : ''; ?>">
                    <span class="sidebar-nav-icon"><i class="fas fa-tachometer-alt"></i></span>
                    <span>Dashboard</span>
                </a>
            </li>
            <li class="sidebar-nav-item">
                <a href="#" onclick="window.location.href = PAGES.ADMIN_CUSTOMERS; return false;" 
                   class="sidebar-nav-link <?php echo ($current_page === 'customers') ? 'active' : ''; ?>">
                    <span class="sidebar-nav-icon"><i class="fas fa-users"></i></span>
                    <span>ลูกค้า</span>
                </a>
            </li>
            <li class="sidebar-nav-item">
                <a href="#" onclick="window.location.href = PAGES.ADMIN_PACKAGES; return false;" 
                   class="sidebar-nav-link <?php echo ($current_page === 'packages') ? 'active' : ''; ?>">
                    <span class="sidebar-nav-icon"><i class="fas fa-box"></i></span>
                    <span>แพ็คเกจ</span>
                </a>
            </li>
            <li class="sidebar-nav-item">
                <a href="#" onclick="window.location.href = PAGES.ADMIN_SERVICES_PAGE; return false;" 
                   class="sidebar-nav-link <?php echo ($current_page === 'services') ? 'active' : ''; ?>">
                    <span class="sidebar-nav-icon"><i class="fas fa-robot"></i></span>
                    <span>บริการ</span>
                </a>
            </li>
            <li class="sidebar-nav-item">
                <a href="#" onclick="window.location.href = PAGES.ADMIN_KNOWLEDGE_BASE; return false;" 
                   class="sidebar-nav-link <?php echo ($current_page === 'knowledge-base') ? 'active' : ''; ?>">
                    <span class="sidebar-nav-icon"><i class="fas fa-database"></i></span>
                    <span>Knowledge Base</span>
                </a>
            </li>
            <li class="sidebar-nav-item">
                <a href="#" onclick="window.location.href = PAGES.ADMIN_INVOICES_PAGE; return false;" 
                   class="sidebar-nav-link <?php echo ($current_page === 'invoices') ? 'active' : ''; ?>">
                    <span class="sidebar-nav-icon"><i class="fas fa-file-invoice"></i></span>
                    <span>ใบแจ้งหนี้</span>
                </a>
            </li>
            <li class="sidebar-nav-item">
                <a href="#" onclick="window.location.href = PAGES.ADMIN_REPORTS; return false;" 
                   class="sidebar-nav-link <?php echo ($current_page === 'reports') ? 'active' : ''; ?>">
                    <span class="sidebar-nav-icon"><i class="fas fa-chart-line"></i></span>
                    <span>รายงาน</span>
                </a>
            </li>
            <li class="sidebar-nav-item">
                <a href="#" onclick="window.location.href = PAGES.ADMIN_CHAT_LOGS; return false;" 
                   class="sidebar-nav-link <?php echo ($current_page === 'chat-logs') ? 'active' : ''; ?>">
                    <span class="sidebar-nav-icon"><i class="fas fa-comments"></i></span>
                    <span>Chat Logs</span>
                </a>
            </li>
            <li class="sidebar-nav-item">
                <a href="#" onclick="window.location.href = (typeof PAGES !== 'undefined' && PAGES.ADMIN_MENU_CONFIG) ? PAGES.ADMIN_MENU_CONFIG : 'menu-manager.php'; return false;" 
                   class="sidebar-nav-link <?php echo ($current_page === 'menu-config' || $current_page === 'menu_manager') ? 'active' : ''; ?>">
                    <span class="sidebar-nav-icon"><i class="fas fa-sliders-h"></i></span>
                    <span>Menu Customization</span>
                </a>
            </li>
            <li class="sidebar-nav-item">
                <a href="#" onclick="window.location.href = PAGES.ADMIN_SETTINGS; return false;" 
                   class="sidebar-nav-link <?php echo ($current_page === 'settings') ? 'active' : ''; ?>">
                    <span class="sidebar-nav-icon"><i class="fas fa-cog"></i></span>
                    <span>ตั้งค่า</span>
                </a>
            </li>
            <li class="sidebar-nav-item sidebar-nav-item--spacer">
                <a href="#" onclick="window.location.href = PAGES.USER_DASHBOARD; return false;" 
                   class="sidebar-nav-link sidebar-nav-link--primary">
                    <span class="sidebar-nav-icon"><i class="fas fa-arrow-left"></i></span>
                    <span>กลับหน้าลูกค้า</span>
                </a>
            </li>
            <li class="sidebar-nav-item">
                <a href="#" onclick="logout(); return false;" class="sidebar-nav-link sidebar-nav-link--danger">
                    <span class="sidebar-nav-icon"><i class="fas fa-sign-out-alt"></i></span>
                    <span>ออกจากระบบ</span>
                </a>
            </li>
        </ul>
    </nav>
    
    <script>
        // Set logo dynamically using PATH helper
        if (typeof PATH !== 'undefined') {
            document.getElementById('sidebarLogo').src = PATH.image('logo3.png');
        }
    </script>
</aside>
