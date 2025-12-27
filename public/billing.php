<?php
/**
 * Customer Billing Page
 */
define('INCLUDE_CHECK', true);

$page_title = "ใบแจ้งหนี้ - AI Automation";
$current_page = "billing";

include('../includes/customer/header.php');
include('../includes/customer/sidebar.php');
?>

<main class="main-content">
    <div class="page-header">
        <h1 class="page-title">ใบแจ้งหนี้ & ประวัติการชำระเงิน</h1>
        <p class="page-subtitle">จัดการและดาวน์โหลดใบแจ้งหนี้ของคุณ</p>
    </div>

    <!-- Invoices Table -->
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">ใบแจ้งหนี้ทั้งหมด</h3>
            <p class="card-subtitle">รายการใบแจ้งหนี้และสถานะการชำระเงิน</p>
        </div>
        <div class="card-body">
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>เลขที่ใบแจ้งหนี้</th>
                            <th>วันที่</th>
                            <th>รอบบิล</th>
                            <th>จำนวนเงิน</th>
                            <th>สถานะ</th>
                            <th>วันครบกำหนด</th>
                            <th>การกระทำ</th>
                        </tr>
                    </thead>
                    <tbody id="invoicesBody">
                        <tr>
                            <td colspan="7" style="text-align: center; padding: 2rem;">
                                <div class="loading-spinner" style="margin: 0 auto;"></div>
                                <p style="margin-top: 1rem; color: var(--color-gray);">กำลังโหลด...</p>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Payment History -->
    <div class="card mt-4">
        <div class="card-header">
            <h3 class="card-title">ประวัติการชำระเงิน</h3>
            <p class="card-subtitle">รายการธุรกรรมทั้งหมด</p>
        </div>
        <div class="card-body">
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>วันที่</th>
                            <th>เลขที่ใบแจ้งหนี้</th>
                            <th>จำนวนเงิน</th>
                            <th>วิธีการชำระ</th>
                            <th>สถานะ</th>
                        </tr>
                    </thead>
                    <tbody id="transactionsBody">
                        <tr>
                            <td colspan="5" style="text-align: center; padding: 2rem;">
                                <div class="loading-spinner" style="margin: 0 auto;"></div>
                                <p style="margin-top: 1rem; color: var(--color-gray);">กำลังโหลด...</p>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Invoice Details Modal -->
    <div id="invoiceModal" class="modal hidden">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title">รายละเอียดใบแจ้งหนี้</h2>
                <button class="modal-close" onclick="closeInvoiceModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body" id="invoiceModalBody">
                <div class="loading-spinner" style="margin: 2rem auto;"></div>
            </div>
        </div>
    </div>
</main>

<?php
$inline_script = <<<'JAVASCRIPT'
document.addEventListener('DOMContentLoaded', async () => {
    await loadInvoices();
    await loadTransactions();
});

async function loadInvoices() {
    try {
        const response = await apiCall(API_ENDPOINTS.BILLING_INVOICE_LIST);
        if (response && response.success) {
            displayInvoices(response.data);
        }
    } catch (error) {
        console.error('Failed to load invoices:', error);
        displayInvoicesError();
    }
}

function displayInvoices(invoices) {
    const tbody = document.getElementById('invoicesBody');
    
    if (!invoices || invoices.length === 0) {
        tbody.innerHTML = `
            <tr>
                <td colspan="7" style="text-align: center; padding: 2rem;">
                    <div style="font-size: 3rem; margin-bottom: 1rem;">📄</div>
                    <p style="color: var(--color-gray);">ยังไม่มีใบแจ้งหนี้</p>
                </td>
            </tr>
        `;
        return;
    }
    
    tbody.innerHTML = '';
    
    invoices.forEach(invoice => {
        const row = document.createElement('tr');
        
        const statusBadge = invoice.status === 'paid'
            ? '<span class="badge badge-success">ชำระแล้ว</span>'
            : invoice.status === 'pending'
                ? '<span class="badge badge-warning">รอชำระ</span>'
                : invoice.status === 'failed'
                    ? '<span class="badge badge-danger">ล้มเหลว</span>'
                    : '<span class="badge badge-info">ยกเลิก</span>';
        
        const billingPeriod = invoice.billing_period_start && invoice.billing_period_end
            ? `${formatDate(invoice.billing_period_start).split(' ')[0]} - ${formatDate(invoice.billing_period_end).split(' ')[0]}`
            : '-';
        
        row.innerHTML = `
            <td><strong>${invoice.invoice_number}</strong></td>
            <td>${formatDate(invoice.created_at).split(' ')[0]}</td>
            <td>${billingPeriod}</td>
            <td><strong>${formatCurrency(invoice.total)}</strong></td>
            <td>${statusBadge}</td>
            <td>${invoice.due_date ? formatDate(invoice.due_date).split(' ')[0] : '-'}</td>
            <td>
                <button class="btn btn-sm btn-primary" onclick="viewInvoice(${invoice.id})" title="ดูรายละเอียดใบแจ้งหนี้">
                    <i class="fas fa-file-invoice"></i> ดูรายละเอียด
                </button>
            </td>
        `;
        
        tbody.appendChild(row);
    });
}

function displayInvoicesError() {
    const tbody = document.getElementById('invoicesBody');
    tbody.innerHTML = `
        <tr>
            <td colspan="7" style="text-align: center; padding: 2rem; color: var(--color-danger);">
                ไม่สามารถโหลดข้อมูลได้
            </td>
        </tr>
    `;
}

async function loadTransactions() {
    try {
        const response = await apiCall(API_ENDPOINTS.BILLING_TRANSACTIONS);
        if (response && response.success) {
            displayTransactions(response.data);
        }
    } catch (error) {
        console.error('Failed to load transactions:', error);
        displayTransactionsError();
    }
}

function displayTransactions(transactions) {
    const tbody = document.getElementById('transactionsBody');
    
    if (!transactions || transactions.length === 0) {
        tbody.innerHTML = `
            <tr>
                <td colspan="5" style="text-align: center; padding: 2rem;">
                    <p style="color: var(--color-gray);">ยังไม่มีประวัติการชำระเงิน</p>
                </td>
            </tr>
        `;
        return;
    }
    
    tbody.innerHTML = '';
    
    transactions.forEach(tx => {
        const row = document.createElement('tr');
        
        const statusBadge = tx.status === 'successful'
            ? '<span class="badge badge-success">สำเร็จ</span>'
            : tx.status === 'pending'
                ? '<span class="badge badge-warning">รอดำเนินการ</span>'
                : '<span class="badge badge-danger">ล้มเหลว</span>';
        
        const paymentMethod = tx.card_brand && tx.card_last4
            ? `${tx.card_brand.toUpperCase()} •••• ${tx.card_last4}`
            : '-';
        
        row.innerHTML = `
            <td>${formatDate(tx.created_at)}</td>
            <td>${tx.invoice_number}</td>
            <td><strong>${formatCurrency(tx.amount)}</strong></td>
            <td>${paymentMethod}</td>
            <td>${statusBadge}</td>
        `;
        
        tbody.appendChild(row);
    });
}

function displayTransactionsError() {
    const tbody = document.getElementById('transactionsBody');
    tbody.innerHTML = `
        <tr>
            <td colspan="5" style="text-align: center; padding: 2rem; color: var(--color-danger);">
                ไม่สามารถโหลดข้อมูลได้
            </td>
        </tr>
    `;
}

async function viewInvoice(invoiceId) {
    const modal = document.getElementById('invoiceModal');
    const modalBody = document.getElementById('invoiceModalBody');
    
    // Show modal with loading state
    modalBody.innerHTML = '<div class="loading-spinner" style="margin: 2rem auto;"></div>';
    modal.classList.remove('hidden');
    document.body.style.overflow = 'hidden';
    
    try {
        // Use API endpoint instead of web route to avoid rewrite loops
        const url = typeof API_ENDPOINTS !== 'undefined' && API_ENDPOINTS.BILLING_INVOICE_DETAIL
            ? API_ENDPOINTS.BILLING_INVOICE_DETAIL(invoiceId)
            : `/api/billing/invoice-detail.php?id=${invoiceId}`;

        const response = await apiCall(url);
        if (response && response.success) {
            // API returns {invoice: {...}, items: [...]}
            const invoiceData = response.data.invoice || response.data;
            invoiceData.items = response.data.items || [];
            showInvoiceDetails(invoiceData);
        } else {
            modalBody.innerHTML = '<p style="text-align: center; color: var(--color-danger); padding: 2rem;">ไม่สามารถโหลดรายละเอียดใบแจ้งหนี้ได้</p>';
        }
    } catch (error) {
        console.error('Failed to load invoice details:', error);
        modalBody.innerHTML = '<p style="text-align: center; color: var(--color-danger); padding: 2rem;">เกิดข้อผิดพลาดในการโหลดข้อมูล</p>';
    }
}

function showInvoiceDetails(invoice) {
    const modalBody = document.getElementById('invoiceModalBody');
    
    const statusBadge = invoice.status === 'paid'
        ? '<span class="badge badge-success">ชำระแล้ว</span>'
        : invoice.status === 'pending'
            ? '<span class="badge badge-warning">รอชำระ</span>'
            : invoice.status === 'failed'
                ? '<span class="badge badge-danger">ล้มเหลว</span>'
                : '<span class="badge badge-info">ยกเลิก</span>';
    
    const billingPeriod = invoice.billing_period_start && invoice.billing_period_end
        ? `${formatDate(invoice.billing_period_start).split(' ')[0]} - ${formatDate(invoice.billing_period_end).split(' ')[0]}`
        : '-';
    
    let lineItemsHTML = '';
    if (invoice.items && invoice.items.length > 0) {
        invoice.items.forEach(item => {
            lineItemsHTML += `
                <tr>
                    <td>
                        <strong>${item.description || 'รายการ'}</strong>
                        ${item.quantity ? `<div class="text-muted" style="font-size:0.85rem;">จำนวน ${item.quantity}</div>` : ''}
                    </td>
                    <td class="text-right">${formatCurrency(item.amount)}</td>
                </tr>
            `;
        });
    } else {
        lineItemsHTML = `
            <tr>
                <td colspan="2" class="text-center text-muted" style="padding:0.75rem;">
                    ไม่มีรายการในใบแจ้งหนี้นี้
                </td>
            </tr>
        `;
    }
    
    modalBody.innerHTML = `
        <div class="invoice-details-popup">
            <div class="invoice-details-header">
                <div>
                    <div class="text-muted" style="font-size:0.85rem;">เลขที่ใบแจ้งหนี้</div>
                    <div style="font-size:1.2rem;font-weight:600;">${invoice.invoice_number}</div>
                </div>
                <div style="text-align:right;">
                    <div class="text-muted" style="font-size:0.85rem;">สถานะ</div>
                    ${statusBadge}
                </div>
            </div>

            <div class="invoice-details-meta">
                <div>
                    <div class="text-muted" style="font-size:0.8rem;">วันที่ออกบิล</div>
                    <div>${formatDate(invoice.created_at)}</div>
                </div>
                <div>
                    <div class="text-muted" style="font-size:0.8rem;">วันครบกำหนด</div>
                    <div>${invoice.due_date ? formatDate(invoice.due_date) : '-'}</div>
                </div>
                <div>
                    <div class="text-muted" style="font-size:0.8rem;">รอบบิล</div>
                    <div>${billingPeriod}</div>
                </div>
            </div>

            <div class="invoice-details-section">
                <h4 class="invoice-section-title"><i class="fas fa-list-ul"></i> รายการค่าใช้จ่าย</h4>
                <div class="table-container">
                    <table class="table table-sm">
                        <tbody>
                            ${lineItemsHTML}
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="invoice-details-totals">
                <div class="totals-row">
                    <span>ยอดรวมย่อย</span>
                    <strong>${formatCurrency(invoice.amount || invoice.total)}</strong>
                </div>
                ${invoice.tax ? `
                <div class="totals-row">
                    <span>ภาษี</span>
                    <strong>${formatCurrency(invoice.tax)}</strong>
                </div>
                ` : ''}
                <div class="totals-row totals-row--grand">
                    <span>ยอดรวมทั้งสิ้น</span>
                    <strong>${formatCurrency(invoice.total)}</strong>
                </div>
            </div>

            ${invoice.status === 'pending' ? `
            <div class="invoice-details-actions">
                <button class="btn btn-primary btn-block" onclick="payInvoice(${invoice.id})">
                    <i class="fas fa-credit-card"></i> ชำระเงิน
                </button>
            </div>
            ` : ''}
        </div>
    `;
}

function closeInvoiceModal() {
    const modal = document.getElementById('invoiceModal');
    modal.classList.add('hidden');
    document.body.style.overflow = 'auto';
}

async function payInvoice(invoiceId) {
    closeInvoiceModal();
    // Redirect to payment page with invoice parameter
    window.location.href = PAGES.USER_PAYMENT + `?invoice=${invoiceId}`;
}

// Close modal when clicking outside
window.addEventListener('click', (e) => {
    const modal = document.getElementById('invoiceModal');
    if (e.target === modal) {
        closeInvoiceModal();
    }
});

// Close modal with ESC key
document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
        const modal = document.getElementById('invoiceModal');
        if (modal && !modal.classList.contains('hidden')) {
            closeInvoiceModal();
        }
    }
});
JAVASCRIPT;

include('../includes/customer/footer.php');
?>
