<?php
/**
 * Customer Portal - Sidebar Include
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
        <a href="#" onclick="window.location.href = (window.PAGES && PAGES.USER_DASHBOARD) ? PAGES.USER_DASHBOARD : 'dashboard.php'; return false;" class="sidebar-logo">
            <img id="sidebarLogo" src="" alt="AI Automation Logo" 
                 style="max-width: 100%; height: auto; max-height: 50px; padding: 0.5rem; border-radius: 8px;">
        </a>
        <p style="font-size: 0.75rem; color: var(--color-gray);">AI Automation Platform</p>
    </div>

    <div class="sidebar-user">
        <div class="sidebar-user-info">
            <div class="sidebar-user-name" id="userName">Loading...</div>
            <div class="sidebar-user-email" id="userEmail"></div>
        </div>
    </div>

    <nav>
        <ul class="sidebar-nav" id="customerSidebarNav">
            <li class="sidebar-nav-item" data-menu="dashboard">
                <a href="dashboard.php" class="sidebar-nav-link <?php echo ($current_page === 'dashboard') ? 'active' : ''; ?>">
                    <span class="sidebar-nav-icon">📊</span>
                    <span>Dashboard</span>
                </a>
            </li>
            <li class="sidebar-nav-item" data-menu="services">
                <a href="services.php" class="sidebar-nav-link <?php echo ($current_page === 'services') ? 'active' : ''; ?>">
                    <span class="sidebar-nav-icon">🤖</span>
                    <span>บริการของฉัน</span>
                </a>
            </li>
            <li class="sidebar-nav-item" data-menu="usage">
                <a href="usage.php" class="sidebar-nav-link <?php echo ($current_page === 'usage') ? 'active' : ''; ?>">
                    <span class="sidebar-nav-icon">📈</span>
                    <span>การใช้งาน</span>
                </a>
            </li>
            <li class="sidebar-nav-item" data-menu="payment">
                <a href="payment.php" class="sidebar-nav-link <?php echo ($current_page === 'payment') ? 'active' : ''; ?>">
                    <span class="sidebar-nav-icon">💳</span>
                    <span>ชำระเงิน</span>
                </a>
            </li>
            <li class="sidebar-nav-item" data-menu="billing">
                <a href="billing.php" class="sidebar-nav-link <?php echo ($current_page === 'billing') ? 'active' : ''; ?>">
                    <span class="sidebar-nav-icon">📄</span>
                    <span>ใบแจ้งหนี้</span>
                </a>
            </li>
            <li class="sidebar-nav-item" data-menu="chat_history">
                <a href="chat-history.php" class="sidebar-nav-link <?php echo ($current_page === 'chat_history') ? 'active' : ''; ?>">
                    <span class="sidebar-nav-icon">💬</span>
                    <span>ประวัติการสนทนา</span>
                </a>
            </li>
            <li class="sidebar-nav-item" data-menu="conversations">
                <a href="conversations.php" class="sidebar-nav-link <?php echo ($current_page === 'conversations') ? 'active' : ''; ?>">
                    <span class="sidebar-nav-icon">💭</span>
                    <span>แชทกับลูกค้า</span>
                </a>
            </li>
            <li class="sidebar-nav-item" data-menu="addresses">
                <a href="addresses.php" class="sidebar-nav-link <?php echo ($current_page === 'addresses') ? 'active' : ''; ?>">
                    <span class="sidebar-nav-icon">📍</span>
                    <span>ที่อยู่จัดส่ง</span>
                </a>
            </li>
            <li class="sidebar-nav-item" data-menu="orders">
                <a href="orders.php" class="sidebar-nav-link <?php echo ($current_page === 'orders') ? 'active' : ''; ?>">
                    <span class="sidebar-nav-icon">📦</span>
                    <span>คำสั่งซื้อ</span>
                </a>
            </li>
            <li class="sidebar-nav-item" data-menu="payment_history">
                <a href="payment-history.php" class="sidebar-nav-link <?php echo ($current_page === 'payment_history') ? 'active' : ''; ?>">
                    <span class="sidebar-nav-icon">💰</span>
                    <span>ประวัติการชำระ / ตรวจสลิป</span>
                </a>
            </li>
            <li class="sidebar-nav-item" data-menu="profile">
                <a href="profile.php" class="sidebar-nav-link <?php echo ($current_page === 'profile') ? 'active' : ''; ?>">
                    <span class="sidebar-nav-icon">👤</span>
                    <span>โปรไฟล์</span>
                </a>
            </li>
            <li class="sidebar-nav-item" style="margin-top: auto; padding-top: 2rem;" data-menu="logout">
                <a href="#" onclick="logout(); return false;" class="sidebar-nav-link" style="color: var(--color-danger);">
                    <span class="sidebar-nav-icon">🚪</span>
                    <span>ออกจากระบบ</span>
                </a>
            </li>
        </ul>
    </nav>
</aside>

<script>
    // Set logo path dynamically when PATH is loaded
    if (typeof PATH !== 'undefined' && PATH.image) {
        const logo = document.getElementById('sidebarLogo');
        if (logo) {
            // PATH.image() should receive a path relative to images root
            logo.src = PATH.image('logo3.png');
        }
    }

    // NOTE: Previously we hid some menus (including payment_history) for specific emails.
    // That policy has been removed so that all users can see and use the payment history / slip review page.
</script>
