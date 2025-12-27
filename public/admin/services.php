<?php
/**
 * Admin Services Management
 */
define('INCLUDE_CHECK', true);

$page_title = "จัดการบริการ - Admin Panel";
$current_page = "services";

include('../../includes/admin/header.php');
include('../../includes/admin/sidebar.php');
?>

<main class="main-content">
    <div class="page-header">
        <h1 class="page-title"><i class="fas fa-robot"></i> จัดการบริการ</h1>
    </div>

    <div class="card">
        <div class="card-body">
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>ชื่อบริการ</th>
                            <th>ลูกค้า</th>
                            <th>ประเภท</th>
                            <th>แพลตฟอร์ม</th>
                            <th>สถานะ</th>
                            <th>การใช้งาน</th>
                            <th>วันที่สร้าง</th>
                        </tr>
                    </thead>
                    <tbody id="servicesTable">
                        <tr>
                            <td colspan="7" style="text-align:center;">กำลังโหลด...</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</main>

<?php
$inline_script = <<<'JAVASCRIPT'
async function loadServices() {
    const token = localStorage.getItem('admin_token');
    try {
        const response = await fetch(API_ENDPOINTS.ADMIN_SERVICES, {
            headers: { 'Authorization': 'Bearer ' + token }
        });
        const result = await response.json();

        const tbody = document.getElementById('servicesTable');
        if (result.success && result.data.services) {
            tbody.innerHTML = result.data.services.map(s => `
                <tr>
                    <td><strong>${s.service_name}</strong></td>
                    <td>${s.customer_name}<br/><small>${s.customer_email}</small></td>
                    <td><span class="badge badge-secondary">${s.service_type_name}</span></td>
                    <td>${s.platform || '-'}</td>
                    <td><span class="badge badge-${s.status === 'active' ? 'success' : 'warning'}">${s.status}</span></td>
                    <td>${s.usage_count || 0} requests</td>
                    <td>${new Date(s.created_at).toLocaleDateString('th-TH')}</td>
                </tr>
            `).join('');
        } else {
            tbody.innerHTML = '<tr><td colspan="7" style="text-align:center;">ไม่พบข้อมูล</td></tr>';
        }
    } catch (error) {
        console.error('Error:', error);
        document.getElementById('servicesTable').innerHTML = '<tr><td colspan="7" style="text-align:center;">เกิดข้อผิดพลาด</td></tr>';
    }
}

loadServices();
JAVASCRIPT;

include('../../includes/admin/footer.php');
?>
