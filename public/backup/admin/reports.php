<?php
/**
 * Admin Reports
 */
define('INCLUDE_CHECK', true);

$page_title = "รายงาน - Admin Panel";
$current_page = "reports";

include('../../includes/admin/header.php');
include('../../includes/admin/sidebar.php');
?>

<main class="main-content">
    <div class="page-header">
        <h1 class="page-title"><i class="fas fa-chart-bar"></i> รายงาน</h1>
    </div>

    <div class="card">
        <div class="card-header">
            <h3 class="card-title">📊 รายงานสรุป</h3>
        </div>
        <div class="card-body">
            <p style="text-align:center; padding:3rem; color:var(--color-gray);">
                <i class="fas fa-chart-line" style="font-size:3rem; display:block; margin-bottom:1rem;"></i>
                ระบบรายงานกำลังพัฒนา<br />
                <small>Coming soon...</small>
            </p>
        </div>
    </div>
</main>

<?php include('../../includes/admin/footer.php'); ?>
