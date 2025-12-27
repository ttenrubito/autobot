<?php
/**
 * Admin Settings
 */
define('INCLUDE_CHECK', true);

$page_title = "ตั้งค่า - Admin Panel";
$current_page = "settings";

include('../../includes/admin/header.php');
include('../../includes/admin/sidebar.php');
?>

<main class="main-content">
    <div class="page-header">
        <h1 class="page-title"><i class="fas fa-cog"></i> ตั้งค่าระบบ</h1>
    </div>

    <div class="card">
        <div class="card-header">
            <h3 class="card-title">⚙️ การตั้งค่า</h3>
        </div>
        <div class="card-body">
            <p style="text-align:center; padding:3rem; color:var(--color-gray);">
                <i class="fas fa-wrench" style="font-size:3rem; display:block; margin-bottom:1rem;"></i>
                หน้าตั้งค่ากำลังพัฒนา<br />
                <small>Coming soon...</small>
            </p>
        </div>
    </div>
</main>

<?php include('../../includes/admin/footer.php'); ?>
