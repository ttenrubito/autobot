<?php
/**
 * Admin Panel - Footer Include
 * Common JavaScript includes and closing tags
 */

require_once __DIR__ . '/../config-paths.php';
?>
    <!-- Note: path-config.js + auth.js + admin.js are loaded in admin/header.php -->

    <?php if (isset($extra_scripts) && is_array($extra_scripts)): ?>
        <?php foreach ($extra_scripts as $script): ?>
            <?php
            if (strpos($script, 'http://') === 0 || strpos($script, 'https://') === 0) {
                echo '<script src="' . htmlspecialchars($script) . '"></script>' . "\n";
            } else {
                $normalized = ltrim($script, './');
                while (strpos($normalized, '../') === 0) {
                    $normalized = substr($normalized, 3);
                }
                if (strpos($normalized, 'assets/') === 0) {
                    $normalized = substr($normalized, strlen('assets/'));
                }
                echo '<script src="' . asset($normalized) . '"></script>' . "\n";
            }
            ?>
        <?php endforeach; ?>
    <?php endif; ?>

    <!-- Mobile Menu Toggle Script -->
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const menuToggle = document.getElementById('mobileMenuToggle');
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('mobileOverlay');

            if (menuToggle && sidebar && overlay) {
                menuToggle.addEventListener('click', function() {
                    sidebar.classList.toggle('mobile-open');
                    overlay.classList.toggle('active');
                });

                overlay.addEventListener('click', function() {
                    sidebar.classList.remove('mobile-open');
                    overlay.classList.remove('active');
                });
            }
        });
    </script>

    <?php if (isset($inline_script)): ?>
        <script>
            <?php echo $inline_script; ?>
        </script>
    <?php endif; ?>
</body>

</html>
