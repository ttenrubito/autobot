<?php
/**
 * Admin Invoices Management
 */
define('INCLUDE_CHECK', true);

$page_title = "จัดการใบแจ้งหนี้ - Admin Panel";
$current_page = "invoices";

include('../../includes/admin/header.php');
include('../../includes/admin/sidebar.php');
?>

<main class="main-content">
    <div class="page-header">
        <h1 class="page-title"><i class="fas fa-file-invoice"></i> จัดการใบแจ้งหนี้</h1>
    </div>

    <div class="card">
        <div class="card-body">
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>เลขที่ใบแจ้งหนี้</th>
                            <th>ลูกค้า</th>
                            <th>จำนวนเงิน</th>
                            <th>สถานะ</th>
                            <th>กำหนดชำระ</th>
                            <th>ชำระแล้ว</th>
                            <th>วันที่สร้าง</th>
                        </tr>
                    </thead>
                    <tbody id="invoicesTable">
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
async function loadInvoices() {
    const token = localStorage.getItem('admin_token');
    try {
        const response = await fetch(API_ENDPOINTS.ADMIN_INVOICES, {
            headers: { 'Authorization': 'Bearer ' + token }
        });
        const result = await response.json();

        const tbody = document.getElementById('invoicesTable');
        if (result.success && result.data.invoices) {
            tbody.innerHTML = result.data.invoices.map(inv => `
                <tr>
                    <td><strong>${inv.invoice_number}</strong></td>
                    <td>${inv.customer_name}<br/><small>${inv.customer_email}</small></td>
                    <td>฿${parseFloat(inv.total).toLocaleString()}</td>
                    <td><span class="badge badge-${inv.status === 'paid' ? 'success' : inv.status === 'pending' ? 'warning' : 'danger'}">${inv.status}</span></td>
                    <td>${inv.due_date ? new Date(inv.due_date).toLocaleDateString('th-TH') : '-'}</td>
                    <td>${inv.paid_at ? new Date(inv.paid_at).toLocaleDateString('th-TH') : '-'}</td>
                    <td>${new Date(inv.created_at).toLocaleDateString('th-TH')}</td>
                </tr>
            `).join('');
        } else {
            tbody.innerHTML = '<tr><td colspan="7" style="text-align:center;">ไม่พบข้อมูล</td></tr>';
        }
    } catch (error) {
        console.error('Error:', error);
        document.getElementById('invoicesTable').innerHTML = '<tr><td colspan="7" style="text-align:center;">เกิดข้อผิดพลาด</td></tr>';
    }
}

loadInvoices();
JAVASCRIPT;

include('../../includes/admin/footer.php');
?>
