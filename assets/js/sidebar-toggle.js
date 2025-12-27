/**
 * Sidebar Mobile Toggle
 * Handles hamburger menu and sidebar visibility on mobile/tablet
 */

(function () {
    console.log('🔧 [sidebar-toggle.js] Loading...');

    // Wait for DOM ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    function init() {
        console.log('🔧 [sidebar-toggle.js] Initializing mobile menu...');

        const menuToggle = document.getElementById('mobileMenuToggle');
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('mobileOverlay');

        if (!menuToggle || !sidebar || !overlay) {
            console.warn('⚠️ [sidebar-toggle.js] Required elements not found:', {
                menuToggle: !!menuToggle,
                sidebar: !!sidebar,
                overlay: !!overlay
            });
            return;
        }

        // Toggle sidebar when hamburger is clicked
        menuToggle.addEventListener('click', function () {
            console.log('🍔 [sidebar-toggle.js] Menu toggle clicked');
            sidebar.classList.toggle('mobile-open');
            overlay.classList.toggle('active');
        });

        // Close sidebar when overlay is clicked
        overlay.addEventListener('click', function () {
            console.log('🔵 [sidebar-toggle.js] Overlay clicked - closing sidebar');
            sidebar.classList.remove('mobile-open');
            overlay.classList.remove('active');
        });

        // Close sidebar when any nav link is clicked (UX improvement)
        const navLinks = sidebar.querySelectorAll('.sidebar-nav-link');
        navLinks.forEach(function (link) {
            link.addEventListener('click', function () {
                console.log('🔗 [sidebar-toggle.js] Nav link clicked - closing sidebar');
                sidebar.classList.remove('mobile-open');
                overlay.classList.remove('active');
            });
        });

        console.log('✅ [sidebar-toggle.js] Mobile menu initialized successfully');
    }
})();
