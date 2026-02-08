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
        <a href="#"
            onclick="window.location.href = (window.PAGES && PAGES.USER_DASHBOARD) ? PAGES.USER_DASHBOARD : 'dashboard.php'; return false;"
            class="sidebar-logo">
            <img id="sidebarLogo" src="" alt="AI Automation Logo"
                style="max-width: 100%; height: auto; max-height: 50px; padding: 0.5rem; border-radius: 8px;">
        </a>
        <!-- Subscription Progress Bar - Reserved space to prevent layout shift -->
        <div id="subscriptionBadge" class="subscription-badge-container">
            <!-- Skeleton placeholder shown while loading -->
            <div id="subscriptionSkeleton" class="subscription-skeleton">
                <div class="skeleton-label"></div>
                <div class="skeleton-bar"></div>
                <div class="skeleton-text"></div>
            </div>
            <!-- Actual content (hidden until loaded) -->
            <div id="subscriptionContent" class="subscription-content" style="opacity: 0;">
                <div class="subscription-label">📅 วันใช้งานคงเหลือ</div>
                <div class="subscription-progress-bg">
                    <div id="subscriptionProgress" class="subscription-progress-fill"></div>
                </div>
                <div id="subscriptionText" class="subscription-text">-</div>
                <!-- Renew button (shown when days < 14) -->
                <a id="renewButton" href="payment.php" class="subscription-renew-btn" style="display: none;">
                    🔄 ต่ออายุ
                </a>
            </div>
        </div>
    </div>

    <style>
        /* Subscription Badge - Reserved space to prevent layout shift */
        .subscription-badge-container {
            padding: 0.5rem 0.8rem;
            margin-top: 0.3rem;
            min-height: 52px;
            /* Reserve fixed height */
            position: relative;
        }

        /* Skeleton placeholder */
        .subscription-skeleton {
            position: absolute;
            top: 0.5rem;
            left: 0.8rem;
            right: 0.8rem;
            transition: opacity 0.3s ease;
        }

        .subscription-skeleton .skeleton-label {
            height: 10px;
            width: 80px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 4px;
            margin-bottom: 0.3rem;
            animation: skeleton-pulse 1.5s ease-in-out infinite;
        }

        .subscription-skeleton .skeleton-bar {
            height: 8px;
            width: 100%;
            background: rgba(255, 255, 255, 0.15);
            border-radius: 4px;
            margin-bottom: 0.25rem;
            animation: skeleton-pulse 1.5s ease-in-out infinite;
            animation-delay: 0.1s;
        }

        .subscription-skeleton .skeleton-text {
            height: 12px;
            width: 60px;
            margin: 0 auto;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 4px;
            animation: skeleton-pulse 1.5s ease-in-out infinite;
            animation-delay: 0.2s;
        }

        @keyframes skeleton-pulse {

            0%,
            100% {
                opacity: 0.4;
            }

            50% {
                opacity: 0.8;
            }
        }

        /* Actual subscription content */
        .subscription-content {
            transition: opacity 0.4s ease;
        }

        .subscription-content.visible {
            opacity: 1 !important;
        }

        .subscription-label {
            font-size: 0.65rem;
            color: rgba(255, 255, 255, 0.7);
            margin-bottom: 0.3rem;
        }

        .subscription-progress-bg {
            background: rgba(255, 255, 255, 0.2);
            border-radius: 4px;
            height: 8px;
            overflow: hidden;
        }

        .subscription-progress-fill {
            height: 100%;
            border-radius: 4px;
            transition: width 0.5s ease, background 0.3s ease;
            width: 0%;
        }

        .subscription-text {
            font-size: 0.7rem;
            font-weight: 600;
            margin-top: 0.25rem;
            text-align: center;
            color: #fff;
        }

        /* Renew button - shows when subscription is low */
        .subscription-renew-btn {
            display: block;
            margin-top: 0.5rem;
            padding: 0.35rem 0.6rem;
            background: linear-gradient(135deg, #f59e0b, #d97706);
            color: white;
            text-align: center;
            border-radius: 6px;
            font-size: 0.7rem;
            font-weight: 600;
            text-decoration: none;
            transition: all 0.2s ease;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.15);
        }

        .subscription-renew-btn:hover {
            background: linear-gradient(135deg, #d97706, #b45309);
            transform: translateY(-1px);
            box-shadow: 0 3px 6px rgba(0, 0, 0, 0.2);
            color: white;
            text-decoration: none;
        }

        /* ========================================
           Menu Loading State - Prevent flash
           ======================================== */

        /* Hide actual menu items while loading */
        .sidebar-nav.menu-loading .sidebar-nav-item {
            opacity: 0;
            visibility: hidden;
            height: 0;
            padding: 0;
            margin: 0;
            overflow: hidden;
            transition: none;
        }

        /* Show skeleton while loading */
        .menu-skeleton-container {
            padding: 0.5rem 0;
            list-style: none;
        }

        .menu-skeleton-item {
            height: 40px;
            background: rgba(255, 255, 255, 0.08);
            border-radius: 8px;
            margin: 0.4rem 0.75rem;
            animation: skeleton-pulse 1.5s ease-in-out infinite;
        }

        .menu-skeleton-item.short {
            width: 70%;
        }

        /* When menu is ready, hide skeleton and show items */
        .sidebar-nav.menu-ready .menu-skeleton-container {
            display: none;
        }

        .sidebar-nav.menu-ready .sidebar-nav-item {
            opacity: 1;
            visibility: visible;
            height: auto;
            padding: inherit;
            margin: inherit;
            overflow: visible;
            transition: opacity 0.3s ease, transform 0.3s ease;
        }

        /* Staggered animation for menu items */
        .sidebar-nav.menu-ready .sidebar-nav-item:nth-child(1) {
            transition-delay: 0.02s;
        }

        .sidebar-nav.menu-ready .sidebar-nav-item:nth-child(2) {
            transition-delay: 0.04s;
        }

        .sidebar-nav.menu-ready .sidebar-nav-item:nth-child(3) {
            transition-delay: 0.06s;
        }

        .sidebar-nav.menu-ready .sidebar-nav-item:nth-child(4) {
            transition-delay: 0.08s;
        }

        .sidebar-nav.menu-ready .sidebar-nav-item:nth-child(5) {
            transition-delay: 0.10s;
        }

        .sidebar-nav.menu-ready .sidebar-nav-item:nth-child(6) {
            transition-delay: 0.12s;
        }

        .sidebar-nav.menu-ready .sidebar-nav-item:nth-child(7) {
            transition-delay: 0.14s;
        }

        .sidebar-nav.menu-ready .sidebar-nav-item:nth-child(8) {
            transition-delay: 0.16s;
        }

        .sidebar-nav.menu-ready .sidebar-nav-item:nth-child(9) {
            transition-delay: 0.18s;
        }

        .sidebar-nav.menu-ready .sidebar-nav-item:nth-child(10) {
            transition-delay: 0.20s;
        }

        .sidebar-nav.menu-ready .sidebar-nav-item:nth-child(11) {
            transition-delay: 0.22s;
        }

        .sidebar-nav.menu-ready .sidebar-nav-item:nth-child(12) {
            transition-delay: 0.24s;
        }

        .sidebar-nav.menu-ready .sidebar-nav-item:nth-child(13) {
            transition-delay: 0.26s;
        }
    </style>

    <div class="sidebar-user">
        <div class="sidebar-user-info">
            <div class="sidebar-user-name" id="userName">-</div>
            <div class="sidebar-user-email" id="userEmail"></div>
        </div>
    </div>

    <nav>
        <!-- Menu items hidden initially, shown after permission check -->
        <ul class="sidebar-nav menu-loading" id="customerSidebarNav">
            <!-- Menu Skeleton (shown while loading) -->
            <li class="menu-skeleton-container" id="menuSkeleton">
                <div class="menu-skeleton-item"></div>
                <div class="menu-skeleton-item"></div>
                <div class="menu-skeleton-item"></div>
                <div class="menu-skeleton-item short"></div>
                <div class="menu-skeleton-item"></div>
                <div class="menu-skeleton-item short"></div>
                <div class="menu-skeleton-item"></div>
            </li>
            <!-- Actual menu items -->
            <!-- ========== กลุ่ม 1: ภาพรวม ========== -->
            <li class="sidebar-nav-item" data-menu="dashboard">
                <a href="<?php echo public_url('dashboard.php'); ?>"
                    class="sidebar-nav-link <?php echo ($current_page === 'dashboard') ? 'active' : ''; ?>">
                    <span class="sidebar-nav-icon">📊</span>
                    <span>Dashboard</span>
                </a>
            </li>

            <!-- ========== กลุ่ม 2: การสื่อสารกับลูกค้า ========== -->
            <li class="sidebar-nav-item" data-menu="chat_history">
                <a href="<?php echo public_url('chat-history.php'); ?>"
                    class="sidebar-nav-link <?php echo ($current_page === 'chat_history') ? 'active' : ''; ?>">
                    <span class="sidebar-nav-icon">💬</span>
                    <span>ประวัติการสนทนา</span>
                </a>
            </li>

            <!-- ========== กลุ่ม 3: การขายและสั่งซื้อ ========== -->
            <li class="sidebar-nav-item" data-menu="cases">
                <a href="<?php echo public_url('cases.php'); ?>" class="sidebar-nav-link <?php echo ($current_page === 'cases') ? 'active' : ''; ?>">
                    <span class="sidebar-nav-icon">📥</span>
                    <span>Case Inbox</span>
                </a>
            </li>
            <li class="sidebar-nav-item" data-menu="orders">
                <a href="<?php echo public_url('orders.php'); ?>"
                    class="sidebar-nav-link <?php echo ($current_page === 'orders') ? 'active' : ''; ?>">
                    <span class="sidebar-nav-icon">📦</span>
                    <span>คำสั่งซื้อ</span>
                </a>
            </li>

            <li class="sidebar-nav-item" data-menu="products">
                <a href="<?php echo public_url('products.php'); ?>"
                    class="sidebar-nav-link <?php echo ($current_page === 'products') ? 'active' : ''; ?>">
                    <span class="sidebar-nav-icon">🏷️</span>
                    <span>จัดการสินค้า</span>
                </a>
            </li>

            <!-- ========== กลุ่ม 4: การเงิน ========== -->
            <li class="sidebar-nav-item" data-menu="payment_history">
                <a href="<?php echo public_url('payment-history.php'); ?>"
                    class="sidebar-nav-link <?php echo ($current_page === 'payment_history') ? 'active' : ''; ?>">
                    <span class="sidebar-nav-icon">💰</span>
                    <span>ประวัติการชำระ</span>
                </a>
            </li>
            <li class="sidebar-nav-item" data-menu="reports">
                <a href="<?php echo public_url('reports/income.php'); ?>"
                    class="sidebar-nav-link <?php echo ($current_page === 'reports') ? 'active' : ''; ?>">
                    <span class="sidebar-nav-icon">📊</span>
                    <span>รายงานรายรับ</span>
                </a>
            </li>

            <!-- ========== กลุ่ม 5: บริการพิเศษ ========== -->
            <li class="sidebar-nav-item" data-menu="installments">
                <a href="<?php echo public_url('installments.php'); ?>"
                    class="sidebar-nav-link <?php echo ($current_page === 'installments') ? 'active' : ''; ?>">
                    <span class="sidebar-nav-icon">📅</span>
                    <span>ผ่อนชำระ</span>
                </a>
            </li>
            <li class="sidebar-nav-item" data-menu="pawns">
                <a href="<?php echo public_url('pawns.php'); ?>" class="sidebar-nav-link <?php echo ($current_page === 'pawns') ? 'active' : ''; ?>">
                    <span class="sidebar-nav-icon">🏆</span>
                    <span>ฝากจำนำ</span>
                </a>
            </li>
            <li class="sidebar-nav-item" data-menu="repairs">
                <a href="<?php echo public_url('repairs.php'); ?>"
                    class="sidebar-nav-link <?php echo ($current_page === 'repairs') ? 'active' : ''; ?>">
                    <span class="sidebar-nav-icon">🔧</span>
                    <span>งานซ่อม</span>
                </a>
            </li>
            <li class="sidebar-nav-item" data-menu="addresses">
                <a href="<?php echo public_url('addresses.php'); ?>"
                    class="sidebar-nav-link <?php echo ($current_page === 'addresses') ? 'active' : ''; ?>">
                    <span class="sidebar-nav-icon">📍</span>
                    <span>ที่อยู่</span>
                </a>
            </li>

            <!-- ========== กลุ่ม 6: ตั้งค่าและโปรไฟล์ ========== -->
            <li class="sidebar-nav-item" data-menu="profile">
                <a href="<?php echo public_url('profile.php'); ?>"
                    class="sidebar-nav-link <?php echo ($current_page === 'profile') ? 'active' : ''; ?>">
                    <span class="sidebar-nav-icon">👤</span>
                    <span>โปรไฟล์</span>
                </a>
            </li>


            <!-- ========== กลุ่ม 7: ระบบ ========== -->
            <li class="sidebar-nav-item" data-menu="cronjobs">
                <a href="<?php echo public_url('cronjobs.php'); ?>"
                    class="sidebar-nav-link <?php echo ($current_page === 'cronjobs') ? 'active' : ''; ?>">
                    <span class="sidebar-nav-icon">⏰</span>
                    <span>Cronjob Monitor</span>
                </a>
            </li>

            <li class="sidebar-nav-item" style="margin-top: auto; padding-top: 2rem;" data-menu="logout">
                <a href="#" onclick="logout(); return false;" class="sidebar-nav-link"
                    style="color: var(--color-danger);">
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

    // ========================================
    // Load User Info
    // ========================================
    function loadSidebarUserInfo() {
        try {
            const userData = typeof getUserData === 'function' ? getUserData() : null;
            if (userData) {
                const userNameEl = document.getElementById('userName');
                const userEmailEl = document.getElementById('userEmail');
                if (userNameEl) userNameEl.textContent = userData.full_name || userData.email || '-';
                if (userEmailEl) userEmailEl.textContent = userData.email || '';
            }
        } catch (error) {
            console.error('[Sidebar] Failed to load user info:', error);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', loadSidebarUserInfo);
    } else {
        loadSidebarUserInfo();
    }

    // ========================================
    // Dynamic Menu Configuration Loading
    // ========================================

    // Helper to show all menus (fallback/no restriction)
    function showAllMenus() {
        const nav = document.getElementById('customerSidebarNav');
        if (nav) {
            nav.classList.remove('menu-loading');
            nav.classList.add('menu-ready');
        }
        console.log('📋 [MENU] Showing all menus (fallback)');
    }

    async function loadUserMenuConfig() {
        const nav = document.getElementById('customerSidebarNav');

        try {
            console.log('🔍 [MENU] Starting menu config load...');

            // Check if user has auth token first - skip if not logged in
            const authToken = localStorage.getItem('auth_token');
            const sessionToken = sessionStorage.getItem('auth_token');
            const adminToken = localStorage.getItem('admin_token');
            const adminSessionToken = sessionStorage.getItem('admin_token');

            console.log('🔑 [MENU] Token check:', {
                localStorage_auth: authToken ? '✅ EXISTS' : '❌ MISSING',
                sessionStorage_auth: sessionToken ? '✅ EXISTS' : '❌ MISSING',
                localStorage_admin: adminToken ? '✅ EXISTS' : '❌ MISSING',
                sessionStorage_admin: adminSessionToken ? '✅ EXISTS' : '❌ MISSING'
            });

            const token = authToken || sessionToken || adminToken || adminSessionToken;

            if (!token) {
                console.log('⚠️ [MENU] No auth token found, showing all menus');
                showAllMenus();
                return;
            }

            console.log('✅ [MENU] Token found, proceeding with API call');

            const apiUrl = (typeof PATH !== 'undefined' && PATH.api)
                ? PATH.api('api/user/menu-config.php')
                : '/api/user/menu-config.php';

            console.log('📡 [MENU] Calling API:', apiUrl);

            const response = await fetch(apiUrl, {
                method: 'GET',
                headers: {
                    'Content-Type': 'application/json',
                    'Authorization': `Bearer ${token}`
                },
                credentials: 'include'
            });

            console.log('📥 [MENU] Response status:', response.status);

            if (!response.ok) {
                console.warn('⚠️ [MENU] API returned non-OK status:', response.status);
                const errorText = await response.text();
                console.log('📄 [MENU] Error response:', errorText);
                showAllMenus(); // Fallback: show all
                return;
            }

            const result = await response.json();
            console.log('✅ [MENU] API response:', result);

            if (result.success && result.data && result.data.menus) {
                applyMenuVisibility(result.data.menus);
                console.log('Menu config loaded:', result.data.custom_config ? 'Custom' : 'Default');
            } else {
                console.warn('Invalid menu config response:', result);
                showAllMenus(); // Fallback: show all
            }
        } catch (error) {
            console.error('Failed to load menu config:', error);
            showAllMenus(); // Fallback: show all menus
        }
    }

    function applyMenuVisibility(menus) {
        const nav = document.getElementById('customerSidebarNav');

        // Get list of enabled menu IDs
        const enabledMenuIds = menus
            .filter(m => m.enabled === true)
            .map(m => m.id);

        console.log('Enabled menus:', enabledMenuIds);

        // Hide menu items that are not enabled
        const sidebarItems = document.querySelectorAll('.sidebar-nav-item');
        sidebarItems.forEach(item => {
            const menuId = item.getAttribute('data-menu');

            // Skip logout and items without data-menu
            if (!menuId || menuId === 'logout') {
                return;
            }

            if (!enabledMenuIds.includes(menuId)) {
                item.style.display = 'none';
                console.log('Hiding menu:', menuId);
            } else {
                item.style.display = '';
            }
        });

        // Switch from loading to ready state (triggers fade-in)
        if (nav) {
            nav.classList.remove('menu-loading');
            nav.classList.add('menu-ready');
            console.log('📋 [MENU] Menu ready with smooth transition!');
        }
    }

    // Load menu config when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', loadUserMenuConfig);
    } else {
        loadUserMenuConfig();
    }

    // Load Subscription Badge
    async function loadSubscriptionBadge() {
        console.log('🗓️ [SUB] Loading subscription badge...');

        const token = localStorage.getItem('auth_token');
        const skeleton = document.getElementById('subscriptionSkeleton');
        const content = document.getElementById('subscriptionContent');
        const badge = document.getElementById('subscriptionBadge');
        const text = document.getElementById('subscriptionText');
        const progress = document.getElementById('subscriptionProgress');

        // Helper to hide badge completely (no subscription)
        function hideBadge() {
            if (skeleton) skeleton.style.opacity = '0';
            if (badge) {
                badge.style.minHeight = '0';
                badge.style.height = '0';
                badge.style.padding = '0';
                badge.style.overflow = 'hidden';
                badge.style.transition = 'all 0.3s ease';
            }
        }

        // Helper to show content with fade-in
        function showContent() {
            if (skeleton) skeleton.style.opacity = '0';
            if (content) {
                content.classList.add('visible');
            }
        }

        if (!token) {
            console.log('🗓️ [SUB] No token found');
            hideBadge();
            return;
        }

        if (!badge || !text) {
            console.log('🗓️ [SUB] Badge elements not found');
            return;
        }

        try {
            console.log('🗓️ [SUB] Fetching subscription...');
            const response = await fetch('/api/user/subscription.php', {
                headers: { 'Authorization': 'Bearer ' + token }
            });

            console.log('🗓️ [SUB] Response status:', response.status);
            if (!response.ok) {
                console.log('🗓️ [SUB] Response not OK');
                hideBadge();
                return;
            }

            const result = await response.json();
            console.log('🗓️ [SUB] Result:', result);

            if (!result.success || !result.data?.has_subscription || !result.data?.subscription) {
                console.log('🗓️ [SUB] No subscription data');
                hideBadge();
                return;
            }

            const sub = result.data.subscription;
            console.log('🗓️ [SUB] Subscription:', sub);

            if (!sub.current_period_end) {
                console.log('🗓️ [SUB] No period end date');
                hideBadge();
                return;
            }

            const end = new Date(sub.current_period_end);
            const start = new Date(sub.current_period_start);
            const today = new Date();
            today.setHours(0, 0, 0, 0);

            const total = Math.ceil((end - start) / 86400000);
            const remaining = Math.ceil((end - today) / 86400000);

            console.log('🗓️ [SUB] Days:', { total, remaining });

            const percent = Math.max(0, Math.min(100, (remaining / total) * 100));

            // Set progress bar width
            if (progress) {
                progress.style.width = percent + '%';
            }

            // Determine color based on remaining days
            let barColor, textColor;
            if (remaining <= 0) {
                barColor = '#ef5350'; // Light Red
                textColor = '#ffcdd2';
                text.textContent = '❌ หมดอายุแล้ว';
            } else if (remaining <= 7) {
                barColor = '#ff9800'; // Orange  
                textColor = '#ffe0b2';
                text.textContent = '⚠️ เหลือ ' + remaining + ' วัน';
            } else if (remaining <= 30) {
                barColor = '#ffc107'; // Yellow
                textColor = '#fff8e1';
                text.textContent = 'เหลือ ' + remaining + ' วัน';
            } else {
                barColor = '#4caf50'; // Green
                textColor = '#c8e6c9';
                text.textContent = 'เหลือ ' + remaining + ' วัน';
            }

            if (progress) progress.style.background = barColor;
            text.style.color = textColor;

            // Show renew button when days remaining < 14
            const renewBtn = document.getElementById('renewButton');
            if (renewBtn) {
                if (remaining <= 14) {
                    renewBtn.style.display = 'block';
                } else {
                    renewBtn.style.display = 'none';
                }
            }

            // Smooth transition: hide skeleton, show content
            showContent();
            console.log('🗓️ [SUB] Badge updated with smooth transition!');
        } catch (e) {
            console.error('🗓️ [SUB] Error:', e);
            hideBadge();
        }
    }

    setTimeout(loadSubscriptionBadge, 300);
</script>