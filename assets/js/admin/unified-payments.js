/**
 * Unified Payments Management - Admin JavaScript
 * @version 1.0
 * @date 2026-01-07
 */

let paymentsData = [];
let currentFilters = {
    status: '',
    payment_type: '',
    page: 1
};
let currentPaymentId = null;
let currentPaymentData = null;

// Initialize on DOM ready
document.addEventListener('DOMContentLoaded', function() {
    loadPayments();
});

/**
 * Load payments from API
 */
async function loadPayments() {
    const tbody = document.getElementById('paymentsTableBody');
    tbody.innerHTML = `
        <tr>
            <td colspan="9" style="text-align:center;padding:2rem;">
                <div class="spinner" style="margin:0 auto 1rem;"></div>
                กำลังโหลดข้อมูล...
            </td>
        </tr>
    `;
    
    try {
        let url = API_ENDPOINTS.ADMIN_UNIFIED_PAYMENTS;
        const params = new URLSearchParams();
        
        if (currentFilters.status) params.append('status', currentFilters.status);
        if (currentFilters.payment_type) params.append('payment_type', currentFilters.payment_type);
        if (currentFilters.page > 1) params.append('page', currentFilters.page);
        
        if (params.toString()) {
            url += '?' + params.toString();
        }
        
        const res = await fetch(url, {
            credentials: 'include'
        }).then(r => r.json());
        
        if (!res.success) {
            tbody.innerHTML = `<tr><td colspan="9" style="text-align:center;color:red;">เกิดข้อผิดพลาด: ${res.message || 'Unknown error'}</td></tr>`;
            return;
        }
        
        paymentsData = res.data || [];
        const summary = res.summary || {};
        
        // Update badges
        document.getElementById('pendingBadge').textContent = `รอตรวจสอบ: ${summary.pending_count || 0}`;
        document.getElementById('unclassifiedBadge').textContent = `ยังไม่จำแนก: ${summary.unclassified_count || 0}`;
        
        if (paymentsData.length === 0) {
            tbody.innerHTML = `<tr><td colspan="9" style="text-align:center;color:#6b7280;">ไม่พบรายการ</td></tr>`;
            return;
        }
        
        renderPaymentsTable(paymentsData);
        renderPagination(res.pagination);
        
    } catch (e) {
        console.error('loadPayments error:', e);
        tbody.innerHTML = `<tr><td colspan="9" style="text-align:center;color:red;">เกิดข้อผิดพลาดในการโหลดข้อมูล</td></tr>`;
    }
}

/**
 * Render payments table
 */
function renderPaymentsTable(payments) {
    const tbody = document.getElementById('paymentsTableBody');
    
    tbody.innerHTML = payments.map(p => {
        const typeIcon = getTypeIcon(p.payment_type);
        const statusBadge = getStatusBadge(p.status);
        const aiSuggestion = getAISuggestion(p);
        const reference = getReference(p);
        
        return `
            <tr class="${p.status === 'pending' ? 'row-pending' : ''}">
                <td>${formatDateTime(p.created_at)}</td>
                <td><code style="font-size: 0.8rem;">${escapeHtml(p.payment_no || '-')}</code></td>
                <td>
                    <div style="font-weight: 500;">${escapeHtml(p.customer_name || 'ไม่ระบุ')}</div>
                    <div style="font-size: 0.8rem; color: #6b7280;">${escapeHtml(p.customer_email || p.customer_phone || '')}</div>
                </td>
                <td style="text-align:right;">
                    <strong style="color: #047857;">฿${formatNumber(p.amount)}</strong>
                </td>
                <td>
                    <span class="type-badge ${p.payment_type}">${typeIcon}</span>
                </td>
                <td>
                    <span class="ref-badge">${reference}</span>
                </td>
                <td>${aiSuggestion}</td>
                <td>${statusBadge}</td>
                <td>
                    ${p.status === 'pending' ? `
                        <button class="btn btn-sm btn-success" onclick="openClassifyModal(${p.id})" title="จำแนกและอนุมัติ">
                            <i class="fas fa-check"></i>
                        </button>
                        <button class="btn btn-sm btn-danger" onclick="openRejectModal(${p.id})" title="ปฏิเสธ">
                            <i class="fas fa-times"></i>
                        </button>
                    ` : `
                        <button class="btn btn-sm btn-outline" onclick="viewPaymentDetail(${p.id})" title="ดูรายละเอียด">
                            <i class="fas fa-eye"></i>
                        </button>
                    `}
                </td>
            </tr>
        `;
    }).join('');
}

/**
 * Get type icon/label
 */
function getTypeIcon(type) {
    const types = {
        'unknown': '❓ ยังไม่จำแนก',
        'full': '💳 ชำระเต็ม',
        'installment': '📅 ผ่อนชำระ',
        'savings': '🐷 ออมเงิน'
    };
    return types[type] || types['unknown'];
}

/**
 * Get status badge HTML
 */
function getStatusBadge(status) {
    const statuses = {
        'pending': '<span class="status-badge pending"><i class="fas fa-clock"></i> รอตรวจสอบ</span>',
        'verified': '<span class="status-badge verified"><i class="fas fa-check"></i> อนุมัติแล้ว</span>',
        'rejected': '<span class="status-badge rejected"><i class="fas fa-times"></i> ปฏิเสธ</span>'
    };
    return statuses[status] || statuses['pending'];
}

/**
 * Get AI suggestion display
 */
function getAISuggestion(payment) {
    if (!payment.ai_suggested_type || payment.ai_suggested_type === 'unknown') {
        return '<span style="color: #9ca3af;">-</span>';
    }
    
    const confidence = parseFloat(payment.ai_confidence) || 0;
    let confidenceClass = 'low';
    if (confidence >= 0.8) confidenceClass = 'high';
    else if (confidence >= 0.5) confidenceClass = 'medium';
    
    const typeLabel = getTypeIcon(payment.ai_suggested_type).split(' ')[0]; // Just the icon
    
    return `
        <div class="ai-suggestion">
            <span>${typeLabel}</span>
            <span class="ai-confidence ${confidenceClass}">${Math.round(confidence * 100)}%</span>
        </div>
    `;
}

/**
 * Get reference display
 */
function getReference(payment) {
    if (payment.reference_type === 'order' && payment.order_no) {
        return `<code>${payment.order_no}</code>`;
    }
    if (payment.reference_type === 'installment_contract' && payment.contract_no) {
        return `<code>${payment.contract_no}</code>`;
    }
    if (payment.reference_type === 'savings_account' && payment.savings_account_no) {
        return `<code>${payment.savings_account_no}</code>`;
    }
    return '<span style="color: #9ca3af;">-</span>';
}

/**
 * Filter by status
 */
function filterPayments(status) {
    currentFilters.status = status;
    currentFilters.page = 1;
    
    // Update UI
    document.querySelectorAll('.filter-tab').forEach(tab => {
        tab.classList.toggle('active', tab.dataset.status === status);
    });
    
    loadPayments();
}

/**
 * Filter by type
 */
function filterByType(type) {
    currentFilters.payment_type = type;
    currentFilters.page = 1;
    
    // Update UI
    document.querySelectorAll('.type-btn').forEach(btn => {
        btn.classList.toggle('active', btn.dataset.type === type);
    });
    
    loadPayments();
}

/**
 * Open classification modal
 */
async function openClassifyModal(paymentId) {
    currentPaymentId = paymentId;
    document.getElementById('classifyModal').classList.remove('hidden');
    
    const modalBody = document.getElementById('classifyModalBody');
    modalBody.innerHTML = `
        <div class="loading-placeholder">
            <div class="spinner"></div>
            <p>กำลังโหลดข้อมูล...</p>
        </div>
    `;
    
    try {
        const res = await fetch(API_ENDPOINTS.ADMIN_UNIFIED_PAYMENT_DETAIL(paymentId), {
            credentials: 'include'
        }).then(r => r.json());
        
        if (!res.success) {
            modalBody.innerHTML = `<div class="alert alert-danger">${res.message || 'ไม่สามารถโหลดข้อมูลได้'}</div>`;
            return;
        }
        
        currentPaymentData = res.data;
        renderClassifyForm(res.data);
        
    } catch (e) {
        console.error('openClassifyModal error:', e);
        modalBody.innerHTML = `<div class="alert alert-danger">เกิดข้อผิดพลาดในการโหลดข้อมูล</div>`;
    }
}

/**
 * Render classification form
 */
function renderClassifyForm(data) {
    const payment = data.payment;
    const refs = data.customer_references;
    
    const aiType = payment.ai_suggested_type || 'unknown';
    const aiConfidence = parseFloat(payment.ai_confidence) || 0;
    
    const modalBody = document.getElementById('classifyModalBody');
    
    modalBody.innerHTML = `
        <!-- Payment Info -->
        <div class="classify-section">
            <div class="payment-info-card">
                <div class="amount-big">฿${formatNumber(payment.amount)}</div>
                <div class="meta">
                    <div><strong>เลขที่:</strong> ${escapeHtml(payment.payment_no || '-')}</div>
                    <div><strong>ลูกค้า:</strong> ${escapeHtml(payment.customer_name || 'ไม่ระบุ')} (${escapeHtml(payment.customer_email || payment.customer_phone || '-')})</div>
                    <div><strong>วันที่:</strong> ${formatDateTime(payment.created_at)}</div>
                    ${aiType !== 'unknown' ? `<div style="margin-top: 0.5rem;"><span style="background: rgba(255,255,255,0.2); padding: 0.25rem 0.5rem; border-radius: 4px;">🤖 AI แนะนำ: ${getTypeIcon(aiType)} (${Math.round(aiConfidence * 100)}%)</span></div>` : ''}
                </div>
            </div>
            
            ${payment.slip_image ? `
                <div style="margin-bottom: 1rem;">
                    <img src="${escapeHtml(payment.slip_image)}" class="slip-preview" onclick="window.open(this.src, '_blank')" alt="Payment Slip">
                </div>
            ` : ''}
        </div>
        
        <!-- Type Selection -->
        <div class="classify-section">
            <h4><i class="fas fa-tag"></i> เลือกประเภทการชำระ</h4>
            <div class="type-select-grid">
                <div class="type-select-card ${aiType === 'full' ? 'selected' : ''}" data-type="full" onclick="selectType('full')">
                    <div class="icon">💳</div>
                    <div class="label">ชำระเต็ม</div>
                    <div style="font-size: 0.8rem; color: #6b7280;">สำหรับคำสั่งซื้อ</div>
                </div>
                <div class="type-select-card ${aiType === 'installment' ? 'selected' : ''}" data-type="installment" onclick="selectType('installment')">
                    <div class="icon">📅</div>
                    <div class="label">ผ่อนชำระ</div>
                    <div style="font-size: 0.8rem; color: #6b7280;">สำหรับสัญญาผ่อน</div>
                </div>
                <div class="type-select-card ${aiType === 'savings' ? 'selected' : ''}" data-type="savings" onclick="selectType('savings')">
                    <div class="icon">🐷</div>
                    <div class="label">ฝากออมเงิน</div>
                    <div style="font-size: 0.8rem; color: #6b7280;">สำหรับบัญชีออม</div>
                </div>
            </div>
        </div>
        
        <!-- Reference Selection -->
        <div class="classify-section" id="referenceSection" style="display: none;">
            <h4><i class="fas fa-link"></i> เลือกรายการอ้างอิง</h4>
            <div id="referenceList" class="reference-list">
                <div style="padding: 1rem; text-align: center; color: #6b7280;">
                    กรุณาเลือกประเภทก่อน
                </div>
            </div>
        </div>
        
        <!-- Period Selection (for installments) -->
        <div class="classify-section" id="periodSection" style="display: none;">
            <h4><i class="fas fa-calendar"></i> งวดที่ชำระ</h4>
            <input type="number" id="periodNumber" class="form-control" min="1" placeholder="งวดที่...">
        </div>
        
        <!-- Notes -->
        <div class="classify-section">
            <h4><i class="fas fa-sticky-note"></i> หมายเหตุ (ถ้ามี)</h4>
            <textarea id="classifyNotes" class="form-control" rows="2" placeholder="หมายเหตุเพิ่มเติม..."></textarea>
        </div>
        
        <!-- Error -->
        <div id="classifyError" class="alert alert-danger" style="display: none;"></div>
        
        <!-- Actions -->
        <div style="display: flex; gap: 1rem; margin-top: 1.5rem;">
            <button class="btn btn-success" style="flex: 1;" onclick="submitClassify()">
                <i class="fas fa-check"></i> อนุมัติและบันทึก
            </button>
            <button class="btn btn-outline" style="flex: 1;" onclick="closeClassifyModal()">
                <i class="fas fa-times"></i> ยกเลิก
            </button>
        </div>
    `;
    
    // Store references for later use
    window.customerRefs = refs;
    
    // Auto-select AI suggested type
    if (aiType !== 'unknown') {
        selectType(aiType);
    }
}

/**
 * Select payment type
 */
function selectType(type) {
    // Update UI
    document.querySelectorAll('.type-select-card').forEach(card => {
        card.classList.toggle('selected', card.dataset.type === type);
    });
    
    // Show reference section
    document.getElementById('referenceSection').style.display = 'block';
    
    // Show/hide period section
    document.getElementById('periodSection').style.display = type === 'installment' ? 'block' : 'none';
    
    // Render references
    renderReferences(type);
}

/**
 * Render reference list based on type
 */
function renderReferences(type) {
    const container = document.getElementById('referenceList');
    const refs = window.customerRefs || {};
    
    let items = [];
    let refType = '';
    
    if (type === 'full') {
        refType = 'order';
        items = refs.orders || [];
    } else if (type === 'installment') {
        refType = 'installment_contract';
        items = refs.installments || [];
    } else if (type === 'savings') {
        refType = 'savings_account';
        items = refs.savings || [];
    }
    
    if (items.length === 0) {
        container.innerHTML = `
            <div style="padding: 1rem; text-align: center; color: #6b7280;">
                ไม่พบรายการ${type === 'full' ? 'คำสั่งซื้อ' : type === 'installment' ? 'สัญญาผ่อน' : 'บัญชีออม'}ที่ใช้งานอยู่
            </div>
        `;
        return;
    }
    
    container.innerHTML = items.map((item, idx) => {
        let title = '';
        let code = '';
        let meta = '';
        
        if (type === 'full') {
            code = item.order_no;
            title = item.product_name || 'คำสั่งซื้อ';
            meta = `ยอด: ฿${formatNumber(item.total_amount)} | ชำระแล้ว: ฿${formatNumber(item.paid_amount)}`;
        } else if (type === 'installment') {
            code = item.contract_no;
            title = item.product_name || 'สัญญาผ่อน';
            meta = `งวดละ: ฿${formatNumber(item.amount_per_period)} | ชำระแล้ว: ${item.paid_periods}/${item.total_periods} งวด`;
            
            // Auto-fill period number
            if (idx === 0) {
                setTimeout(() => {
                    const periodInput = document.getElementById('periodNumber');
                    if (periodInput) {
                        periodInput.value = (item.paid_periods || 0) + 1;
                    }
                }, 100);
            }
        } else if (type === 'savings') {
            code = item.account_no;
            title = item.product_name || 'บัญชีออม';
            meta = `ยอดปัจจุบัน: ฿${formatNumber(item.current_amount)} | เป้าหมาย: ฿${formatNumber(item.target_amount)}`;
        }
        
        return `
            <div class="reference-item ${idx === 0 ? 'selected' : ''}" data-ref-type="${refType}" data-ref-id="${item.id}" onclick="selectReference(this)">
                <input type="radio" name="reference" class="reference-radio" ${idx === 0 ? 'checked' : ''}>
                <div class="reference-info">
                    <div class="reference-title">
                        <code>${escapeHtml(code)}</code> - ${escapeHtml(title)}
                    </div>
                    <div class="reference-meta">${meta}</div>
                </div>
            </div>
        `;
    }).join('');
}

/**
 * Select reference item
 */
function selectReference(element) {
    // Update UI
    document.querySelectorAll('.reference-item').forEach(item => {
        item.classList.remove('selected');
        item.querySelector('input[type="radio"]').checked = false;
    });
    
    element.classList.add('selected');
    element.querySelector('input[type="radio"]').checked = true;
}

/**
 * Submit classification
 */
async function submitClassify() {
    const errorBox = document.getElementById('classifyError');
    errorBox.style.display = 'none';
    
    // Get selected type
    const selectedType = document.querySelector('.type-select-card.selected');
    if (!selectedType) {
        errorBox.textContent = 'กรุณาเลือกประเภทการชำระเงิน';
        errorBox.style.display = 'block';
        return;
    }
    const paymentType = selectedType.dataset.type;
    
    // Get selected reference
    const selectedRef = document.querySelector('.reference-item.selected');
    if (!selectedRef) {
        errorBox.textContent = 'กรุณาเลือกรายการอ้างอิง';
        errorBox.style.display = 'block';
        return;
    }
    const referenceType = selectedRef.dataset.refType;
    const referenceId = selectedRef.dataset.refId;
    
    // Get period number (for installments)
    let periodNumber = null;
    if (paymentType === 'installment') {
        periodNumber = parseInt(document.getElementById('periodNumber').value) || null;
    }
    
    // Get notes
    const notes = document.getElementById('classifyNotes').value.trim();
    
    try {
        const res = await fetch(API_ENDPOINTS.ADMIN_UNIFIED_PAYMENT_CLASSIFY(currentPaymentId), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'include',
            body: JSON.stringify({
                payment_type: paymentType,
                reference_type: referenceType,
                reference_id: referenceId,
                period_number: periodNumber,
                notes: notes
            })
        }).then(r => r.json());
        
        if (!res.success) {
            errorBox.textContent = res.message || 'เกิดข้อผิดพลาด';
            errorBox.style.display = 'block';
            return;
        }
        
        // Success
        closeClassifyModal();
        showToast('อนุมัติและบันทึกเรียบร้อยแล้ว', 'success');
        loadPayments();
        
    } catch (e) {
        console.error('submitClassify error:', e);
        errorBox.textContent = 'เกิดข้อผิดพลาดในการบันทึก';
        errorBox.style.display = 'block';
    }
}

/**
 * Close classification modal
 */
function closeClassifyModal() {
    document.getElementById('classifyModal').classList.add('hidden');
    currentPaymentId = null;
    currentPaymentData = null;
}

/**
 * Open reject modal
 */
function openRejectModal(paymentId) {
    currentPaymentId = paymentId;
    document.getElementById('rejectPaymentId').value = paymentId;
    document.getElementById('rejectReason').value = '';
    document.getElementById('rejectError').style.display = 'none';
    document.getElementById('rejectModal').classList.remove('hidden');
}

/**
 * Close reject modal
 */
function closeRejectModal() {
    document.getElementById('rejectModal').classList.add('hidden');
    currentPaymentId = null;
}

/**
 * Submit rejection
 */
async function submitReject() {
    const paymentId = document.getElementById('rejectPaymentId').value;
    const reason = document.getElementById('rejectReason').value.trim();
    const errorBox = document.getElementById('rejectError');
    
    if (!reason) {
        errorBox.textContent = 'กรุณาระบุเหตุผลในการปฏิเสธ';
        errorBox.style.display = 'block';
        return;
    }
    
    try {
        const res = await fetch(API_ENDPOINTS.ADMIN_UNIFIED_PAYMENT_REJECT(paymentId), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'include',
            body: JSON.stringify({ reason })
        }).then(r => r.json());
        
        if (!res.success) {
            errorBox.textContent = res.message || 'เกิดข้อผิดพลาด';
            errorBox.style.display = 'block';
            return;
        }
        
        closeRejectModal();
        showToast('ปฏิเสธการชำระเงินเรียบร้อยแล้ว', 'warning');
        loadPayments();
        
    } catch (e) {
        console.error('submitReject error:', e);
        errorBox.textContent = 'เกิดข้อผิดพลาด';
        errorBox.style.display = 'block';
    }
}

/**
 * View payment detail (for already processed payments)
 */
function viewPaymentDetail(paymentId) {
    openClassifyModal(paymentId);
}

/**
 * Render pagination
 */
function renderPagination(pagination) {
    if (!pagination || pagination.total_pages <= 1) {
        document.getElementById('pagination').innerHTML = '';
        return;
    }
    
    const container = document.getElementById('pagination');
    let html = '<div class="pagination">';
    
    // Previous
    if (pagination.page > 1) {
        html += `<button class="pagination-btn" onclick="goToPage(${pagination.page - 1})"><i class="fas fa-chevron-left"></i></button>`;
    }
    
    // Page numbers
    for (let i = 1; i <= pagination.total_pages; i++) {
        if (i === pagination.page) {
            html += `<button class="pagination-btn active">${i}</button>`;
        } else if (i === 1 || i === pagination.total_pages || Math.abs(i - pagination.page) <= 2) {
            html += `<button class="pagination-btn" onclick="goToPage(${i})">${i}</button>`;
        } else if (Math.abs(i - pagination.page) === 3) {
            html += `<span class="pagination-dots">...</span>`;
        }
    }
    
    // Next
    if (pagination.page < pagination.total_pages) {
        html += `<button class="pagination-btn" onclick="goToPage(${pagination.page + 1})"><i class="fas fa-chevron-right"></i></button>`;
    }
    
    html += '</div>';
    container.innerHTML = html;
}

/**
 * Go to page
 */
function goToPage(page) {
    currentFilters.page = page;
    loadPayments();
}

// Utility functions
function formatNumber(num) {
    return parseFloat(num || 0).toLocaleString('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function formatDateTime(dateStr) {
    if (!dateStr) return '-';
    const d = new Date(dateStr);
    return d.toLocaleDateString('th-TH') + ' ' + d.toLocaleTimeString('th-TH', { hour: '2-digit', minute: '2-digit' });
}

function escapeHtml(str) {
    if (!str) return '';
    return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}

function showToast(message, type = 'info') {
    const toast = document.createElement('div');
    toast.className = `toast toast-${type}`;
    toast.textContent = message;
    toast.style.cssText = `
        position: fixed;
        bottom: 20px;
        right: 20px;
        padding: 1rem 1.5rem;
        background: ${type === 'success' ? '#10b981' : type === 'warning' ? '#f59e0b' : '#3b82f6'};
        color: white;
        border-radius: 8px;
        box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        z-index: 10000;
        animation: slideIn 0.3s ease;
    `;
    document.body.appendChild(toast);
    
    setTimeout(() => {
        toast.style.animation = 'slideOut 0.3s ease';
        setTimeout(() => toast.remove(), 300);
    }, 3000);
}

// Add CSS animation
const style = document.createElement('style');
style.textContent = `
    @keyframes slideIn {
        from { transform: translateX(100%); opacity: 0; }
        to { transform: translateX(0); opacity: 1; }
    }
    @keyframes slideOut {
        from { transform: translateX(0); opacity: 1; }
        to { transform: translateX(100%); opacity: 0; }
    }
    .row-pending {
        background: #fffbeb;
    }
`;
document.head.appendChild(style);
