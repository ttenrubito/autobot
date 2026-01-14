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
            <!-- ========== กลุ่ม 1: ภาพรวม ========== -->
            <li class="sidebar-nav-item" data-menu="dashboard">
                <a href="dashboard.php" class="sidebar-nav-link <?php echo ($current_page === 'dashboard') ? 'active' : ''; ?>">
                    <span class="sidebar-nav-icon">📊</span>
                    <span>Dashboard</span>
                </a>
            </li>
            
            <!-- ========== กลุ่ม 2: การสื่อสารกับลูกค้า ========== -->
            <li class="sidebar-nav-item" data-menu="chat_history">
                <a href="chat-history.php" class="sidebar-nav-link <?php echo ($current_page === 'chat_history') ? 'active' : ''; ?>">
                    <span class="sidebar-nav-icon">💬</span>
                    <span>ประวัติการสนทนา</span>
                </a>
            </li>
            <li class="sidebar-nav-item" data-menu="addresses">
                <a href="addresses.php" class="sidebar-nav-link <?php echo ($current_page === 'addresses') ? 'active' : ''; ?>">
                    <span class="sidebar-nav-icon">📍</span>
                    <span>ที่อยู่จัดส่ง</span>
                </a>
            </li>
            
            <!-- ========== กลุ่ม 3: การขายและสั่งซื้อ ========== -->
            <li class="sidebar-nav-item" data-menu="orders">
                <a href="orders.php" class="sidebar-nav-link <?php echo ($current_page === 'orders') ? 'active' : ''; ?>">
                    <span class="sidebar-nav-icon">📦</span>
                    <span>คำสั่งซื้อ</span>
                </a>
            </li>
            
            <!-- ========== กลุ่ม 4: การเงิน ========== -->
            <li class="sidebar-nav-item" data-menu="payment_history">
                <a href="payment-history.php" class="sidebar-nav-link <?php echo ($current_page === 'payment_history') ? 'active' : ''; ?>">
                    <span class="sidebar-nav-icon">💰</span>
                    <span>ประวัติการชำระ(ตรวจ)</span>
                </a>
            </li>
            <li class="sidebar-nav-item" data-menu="cases">
                <a href="cases.php" class="sidebar-nav-link <?php echo ($current_page === 'cases') ? 'active' : ''; ?>">
                    <span class="sidebar-nav-icon">📥</span>
                    <span>Case Inbox</span>
                </a>
            </li>
            
            <!-- ========== กลุ่ม 5: บริการพิเศษ ========== -->
            <li class="sidebar-nav-item" data-menu="savings">
                <a href="savings.php" class="sidebar-nav-link <?php echo ($current_page === 'savings') ? 'active' : ''; ?>">
                    <span class="sidebar-nav-icon">🐷</span>
                    <span>ออมเงิน</span>
                </a>
            </li>
            <li class="sidebar-nav-item" data-menu="installments">
                <a href="installments.php" class="sidebar-nav-link <?php echo ($current_page === 'installments') ? 'active' : ''; ?>">
                    <span class="sidebar-nav-icon">📅</span>
                    <span>ผ่อนชำระ</span>
                </a>
            </li>
            <li class="sidebar-nav-item" data-menu="deposits">
                <a href="deposits.php" class="sidebar-nav-link <?php echo ($current_page === 'deposits') ? 'active' : ''; ?>">
                    <span class="sidebar-nav-icon">💎</span>
                    <span>มัดจำสินค้า</span>
                </a>
            </li>
            <li class="sidebar-nav-item" data-menu="pawns">
                <a href="pawns.php" class="sidebar-nav-link <?php echo ($current_page === 'pawns') ? 'active' : ''; ?>">
                    <span class="sidebar-nav-icon">🏆</span>
                    <span>ฝากจำนำ</span>
                </a>
            </li>
            <li class="sidebar-nav-item" data-menu="repairs">
                <a href="repairs.php" class="sidebar-nav-link <?php echo ($current_page === 'repairs') ? 'active' : ''; ?>">
                    <span class="sidebar-nav-icon">🔧</span>
                    <span>งานซ่อม</span>
                </a>
            </li>
            
            <!-- ========== กลุ่ม 6: ตั้งค่าและโปรไฟล์ ========== -->
            <li class="sidebar-nav-item" data-menu="profile">
                <a href="profile.php" class="sidebar-nav-link <?php echo ($current_page === 'profile') ? 'active' : ''; ?>">
                    <span class="sidebar-nav-icon">👤</span>
                    <span>โปรไฟล์</span>
                </a>
            </li>
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

    // ========================================
    // Dynamic Menu Configuration Loading
    // ========================================
    async function loadUserMenuConfig() {
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
                console.log('⚠️ [MENU] No auth token found, skipping menu config');
                return; // User not logged in, don't call API
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
                return;
            }
            
            const result = await response.json();
            console.log('✅ [MENU] API response:', result);
            
            if (result.success && result.data && result.data.menus) {
                applyMenuVisibility(result.data.menus);
                console.log('Menu config loaded:', result.data.custom_config ? 'Custom' : 'Default');
            } else {
                console.warn('Invalid menu config response:', result);
            }
        } catch (error) {
            console.error('Failed to load menu config:', error);
            // Fallback: show all menus (do nothing)
        }
    }

    function applyMenuVisibility(menus) {
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
    }

    // Load menu config when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', loadUserMenuConfig);
    } else {
        loadUserMenuConfig();
    }
</script>
