<?php
/**
 * Customer Portal - Footer Include
 * Common JavaScript includes and closing tags
 */

require_once __DIR__ . '/../config-paths.php';
?>
    <!-- Note: path-config.js is already loaded in header.php -->

    <!-- Load auth.js synchronously to ensure it's available before page scripts -->
    <script src="<?php echo asset('js/auth.js'); ?>?v=<?php echo time(); ?>"></script>

    <?php if (isset($extra_scripts) && is_array($extra_scripts)): ?>
        <?php foreach ($extra_scripts as $script): ?>
            <?php
            // External scripts
            if (strpos($script, 'http://') === 0 || strpos($script, 'https://') === 0) {
                echo '<script src="' . htmlspecialchars($script) . '"></script>' . "\n";
            } else {
                // Local scripts: normalize to be relative (assets/js/.. or ../assets/js/.. etc)
                $normalized = ltrim($script, './');

                // If caller passes '../assets/...', strip leading '../'
                while (strpos($normalized, '../') === 0) {
                    $normalized = substr($normalized, 3);
                }

                // Ensure we always serve from /assets
                if (strpos($normalized, 'assets/') === 0) {
                    $normalized = substr($normalized, strlen('assets/'));
                }

                echo '<script src="' . asset($normalized) . '"></script>' . "\n";
            }
            ?>
        <?php endforeach; ?>
    <?php endif; ?>

    <!-- Mobile Sidebar Toggle -->
    <script src="<?php echo asset('js/sidebar-toggle.js'); ?>?v=<?php echo time(); ?>"></script>

    <?php if (isset($inline_script)): ?>
        <script>
            <?php echo $inline_script; ?>
        </script>
    <?php endif; ?>
</body>

</html>
