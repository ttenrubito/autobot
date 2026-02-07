<?php
/**
 * Admin Payment Classification Page (Hybrid A+)
 * 
 * ใช้สำหรับ classify payments ที่ระบบไม่สามารถ auto-match ได้
 * Admin สามารถ:
 * 1. ดูสลิปที่รอการจัดประเภท
 * 2. เลือกว่าสลิปนี้เป็นของ order หรือ pawn
 * 3. เลือก order/pawn ที่ต้องการจับคู่
 * 
 * @version 1.0
 * @date 2026-01-31
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../includes/admin_auth.php';

// Require admin login
requireAdminLogin();

$db = Database::getInstance();
$adminUser = $_SESSION['admin_user'] ?? [];

$page_title = 'จัดประเภทการชำระเงิน';
include __DIR__ . '/../includes/admin/header.php';
?>

<div class="container-fluid py-4">
    <div class="row mb-4">
        <div class="col">
            <h1 class="h3 mb-0">
                <i class="fas fa-tasks text-primary me-2"></i>
                จัดประเภทการชำระเงิน (Hybrid A+)
            </h1>
            <p class="text-muted">สลิปที่รอการจัดประเภทจากแอดมิน</p>
        </div>
    </div>

    <!-- Summary Cards -->
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="card bg-warning text-dark">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="card-subtitle mb-1">รอจัดประเภท</h6>
                            <h2 class="mb-0" id="pendingCount">-</h2>
                        </div>
                        <i class="fas fa-clock fa-2x opacity-50"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-success text-white">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="card-subtitle mb-1">Auto-matched</h6>
                            <h2 class="mb-0" id="autoMatchedCount">-</h2>
                        </div>
                        <i class="fas fa-robot fa-2x opacity-50"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-info text-white">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="card-subtitle mb-1">Manual-matched</h6>
                            <h2 class="mb-0" id="manualMatchedCount">-</h2>
                        </div>
                        <i class="fas fa-user-check fa-2x opacity-50"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-danger text-white">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="card-subtitle mb-1">ไม่เจอ Match</h6>
                            <h2 class="mb-0" id="noMatchCount">-</h2>
                        </div>
                        <i class="fas fa-question fa-2x opacity-50"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Filter Tabs -->
    <ul class="nav nav-tabs mb-4" id="classifyTabs">
        <li class="nav-item">
            <a class="nav-link active" href="#" data-filter="pending">
                <i class="fas fa-clock me-1"></i> รอจัดประเภท
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link" href="#" data-filter="no_match">
                <i class="fas fa-question me-1"></i> ไม่เจอ Match
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link" href="#" data-filter="auto_matched">
                <i class="fas fa-robot me-1"></i> Auto-matched
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link" href="#" data-filter="all">
                <i class="fas fa-list me-1"></i> ทั้งหมด
            </a>
        </li>
    </ul>

    <!-- Payments List -->
    <div class="card">
        <div class="card-body">
            <div id="paymentsList">
                <div class="text-center py-5">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <p class="mt-2 text-muted">กำลังโหลดข้อมูล...</p>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Classification Modal -->
<div class="modal fade" id="classifyModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-tasks me-2"></i>
                    จัดประเภทการชำระเงิน
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row">
                    <!-- Slip Preview -->
                    <div class="col-md-4">
                        <h6 class="mb-3">🧾 สลิปการโอน</h6>
                        <img id="slipImage" src="" class="img-fluid rounded border" alt="Payment Slip">
                        <div class="mt-3" id="ocrDetails">
                            <!-- OCR details will be populated here -->
                        </div>
                    </div>
                    
                    <!-- Classification Options -->
                    <div class="col-md-8">
                        <h6 class="mb-3">📋 เลือกประเภท</h6>
                        
                        <input type="hidden" id="classifyPaymentId">
                        
                        <div class="btn-group w-100 mb-4" role="group">
                            <input type="radio" class="btn-check" name="classifyType" id="typeOrder" value="order">
                            <label class="btn btn-outline-primary" for="typeOrder">
                                <i class="fas fa-shopping-cart me-2"></i> ค่าสินค้า/ผ่อนชำระ
                            </label>
                            
                            <input type="radio" class="btn-check" name="classifyType" id="typePawn" value="pawn">
                            <label class="btn btn-outline-success" for="typePawn">
                                <i class="fas fa-gem me-2"></i> ค่าดอกเบี้ย/ไถ่ถอน
                            </label>
                            
                            <input type="radio" class="btn-check" name="classifyType" id="typeReject" value="reject">
                            <label class="btn btn-outline-danger" for="typeReject">
                                <i class="fas fa-times me-2"></i> ปฏิเสธ
                            </label>
                        </div>
                        
                        <!-- Order Selection (shown when typeOrder selected) -->
                        <div id="orderSelection" class="d-none">
                            <h6 class="mb-3">🛒 เลือก Order</h6>
                            <div id="orderCandidates" class="candidate-list">
                                <!-- Order candidates will be populated here -->
                            </div>
                        </div>
                        
                        <!-- Pawn Selection (shown when typePawn selected) -->
                        <div id="pawnSelection" class="d-none">
                            <h6 class="mb-3">💎 เลือกรายการจำนำ</h6>
                            <div class="mb-3">
                                <select id="pawnPaymentType" class="form-select">
                                    <option value="interest">ชำระดอกเบี้ย (ต่ออายุ)</option>
                                    <option value="redemption">ไถ่ถอน</option>
                                    <option value="partial">ชำระบางส่วน</option>
                                </select>
                            </div>
                            <div id="pawnCandidates" class="candidate-list">
                                <!-- Pawn candidates will be populated here -->
                            </div>
                        </div>
                        
                        <!-- Rejection Reason (shown when typeReject selected) -->
                        <div id="rejectSection" class="d-none">
                            <h6 class="mb-3">❌ เหตุผลที่ปฏิเสธ</h6>
                            <textarea id="rejectReason" class="form-control" rows="3" placeholder="ระบุเหตุผล..."></textarea>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ยกเลิก</button>
                <button type="button" class="btn btn-primary" onclick="submitClassification()">
                    <i class="fas fa-check me-1"></i> บันทึก
                </button>
            </div>
        </div>
    </div>
</div>

<style>
.candidate-list {
    max-height: 400px;
    overflow-y: auto;
}

.candidate-card {
    border: 2px solid #e9ecef;
    border-radius: 8px;
    padding: 1rem;
    margin-bottom: 0.75rem;
    cursor: pointer;
    transition: all 0.2s;
}

.candidate-card:hover {
    border-color: #0d6efd;
    background-color: #f8f9fa;
}

.candidate-card.selected {
    border-color: #0d6efd;
    background-color: #e7f1ff;
}

.candidate-card .confidence-badge {
    font-size: 0.75rem;
    padding: 0.25rem 0.5rem;
    border-radius: 20px;
}

.payment-card {
    border: 1px solid #e9ecef;
    border-radius: 12px;
    margin-bottom: 1rem;
    overflow: hidden;
}

.payment-card-header {
    background: #f8f9fa;
    padding: 1rem;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.payment-card-body {
    padding: 1rem;
    display: flex;
    gap: 1rem;
}

.payment-slip-thumb {
    width: 100px;
    height: 100px;
    object-fit: cover;
    border-radius: 8px;
    cursor: pointer;
}

.payment-details {
    flex: 1;
}

.match-status-badge {
    font-size: 0.8rem;
    padding: 0.25rem 0.75rem;
    border-radius: 20px;
}
</style>

<script>
let currentFilter = 'pending';
let selectedOrderId = null;
let selectedPawnId = null;

document.addEventListener('DOMContentLoaded', function() {
    loadPayments();
    loadSummary();
    
    // Tab click handlers
    document.querySelectorAll('#classifyTabs .nav-link').forEach(tab => {
        tab.addEventListener('click', function(e) {
            e.preventDefault();
            document.querySelectorAll('#classifyTabs .nav-link').forEach(t => t.classList.remove('active'));
            this.classList.add('active');
            currentFilter = this.dataset.filter;
            loadPayments();
        });
    });
    
    // Classification type change handlers
    document.querySelectorAll('input[name="classifyType"]').forEach(radio => {
        radio.addEventListener('change', function() {
            document.getElementById('orderSelection').classList.add('d-none');
            document.getElementById('pawnSelection').classList.add('d-none');
            document.getElementById('rejectSection').classList.add('d-none');
            
            if (this.value === 'order') {
                document.getElementById('orderSelection').classList.remove('d-none');
            } else if (this.value === 'pawn') {
                document.getElementById('pawnSelection').classList.remove('d-none');
            } else if (this.value === 'reject') {
                document.getElementById('rejectSection').classList.remove('d-none');
            }
        });
    });
});

async function loadSummary() {
    try {
        const res = await fetch('/api/admin/payments/classify-summary.php');
        const data = await res.json();
        if (data.success) {
            document.getElementById('pendingCount').textContent = data.summary.pending || 0;
            document.getElementById('autoMatchedCount').textContent = data.summary.auto_matched || 0;
            document.getElementById('manualMatchedCount').textContent = data.summary.manual_matched || 0;
            document.getElementById('noMatchCount').textContent = data.summary.no_match || 0;
        }
    } catch (e) {
        console.error('Failed to load summary:', e);
    }
}

async function loadPayments() {
    const container = document.getElementById('paymentsList');
    container.innerHTML = `
        <div class="text-center py-5">
            <div class="spinner-border text-primary" role="status"></div>
            <p class="mt-2 text-muted">กำลังโหลดข้อมูล...</p>
        </div>
    `;
    
    try {
        const res = await fetch(`/api/admin/payments/pending-classify.php?filter=${currentFilter}`);
        const data = await res.json();
        
        if (data.success && data.payments.length > 0) {
            let html = '';
            data.payments.forEach(p => {
                const amount = parseFloat(p.amount) || 0;
                const ocrData = p.payment_details ? JSON.parse(p.payment_details) : {};
                const ocrResult = ocrData.ocr_result || {};
                
                html += `
                    <div class="payment-card">
                        <div class="payment-card-header">
                            <div>
                                <strong>${p.payment_no}</strong>
                                <span class="text-muted ms-2">${formatDate(p.created_at)}</span>
                            </div>
                            <div>
                                ${getMatchStatusBadge(p.match_status)}
                                ${getClassifiedBadge(p.classified_as)}
                            </div>
                        </div>
                        <div class="payment-card-body">
                            <img src="${p.slip_image || '/assets/img/no-image.png'}" 
                                 class="payment-slip-thumb" 
                                 onclick="openClassifyModal(${p.id})"
                                 alt="Slip">
                            <div class="payment-details">
                                <div class="row">
                                    <div class="col-md-6">
                                        <p class="mb-1"><strong>💰 จำนวนเงิน:</strong> ฿${formatNumber(amount)}</p>
                                        <p class="mb-1"><strong>🏦 ธนาคาร:</strong> ${ocrResult.bank || '-'}</p>
                                        <p class="mb-1"><strong>📅 วันที่:</strong> ${ocrResult.date || '-'}</p>
                                    </div>
                                    <div class="col-md-6">
                                        <p class="mb-1"><strong>👤 ผู้โอน:</strong> ${ocrResult.sender_name || '-'}</p>
                                        <p class="mb-1"><strong>🔢 Ref:</strong> ${ocrResult.ref || '-'}</p>
                                        <p class="mb-1"><strong>📱 Platform:</strong> ${p.platform || '-'}</p>
                                    </div>
                                </div>
                            </div>
                            <div class="d-flex flex-column gap-2">
                                <button class="btn btn-primary btn-sm" onclick="openClassifyModal(${p.id})">
                                    <i class="fas fa-tasks me-1"></i> จัดประเภท
                                </button>
                            </div>
                        </div>
                    </div>
                `;
            });
            container.innerHTML = html;
        } else {
            container.innerHTML = `
                <div class="text-center py-5">
                    <i class="fas fa-check-circle fa-3x text-success mb-3"></i>
                    <p class="text-muted">ไม่มีรายการที่รอจัดประเภท</p>
                </div>
            `;
        }
    } catch (e) {
        console.error('Failed to load payments:', e);
        container.innerHTML = `
            <div class="text-center py-5 text-danger">
                <i class="fas fa-exclamation-triangle fa-3x mb-3"></i>
                <p>เกิดข้อผิดพลาดในการโหลดข้อมูล</p>
            </div>
        `;
    }
}

async function openClassifyModal(paymentId) {
    document.getElementById('classifyPaymentId').value = paymentId;
    
    // Reset selections
    document.querySelectorAll('input[name="classifyType"]').forEach(r => r.checked = false);
    document.getElementById('orderSelection').classList.add('d-none');
    document.getElementById('pawnSelection').classList.add('d-none');
    document.getElementById('rejectSection').classList.add('d-none');
    selectedOrderId = null;
    selectedPawnId = null;
    
    // Load payment details
    try {
        const res = await fetch(`/api/admin/payments/classify-detail.php?id=${paymentId}`);
        const data = await res.json();
        
        if (data.success) {
            const p = data.payment;
            const ocrData = p.payment_details ? JSON.parse(p.payment_details) : {};
            const ocrResult = ocrData.ocr_result || {};
            
            document.getElementById('slipImage').src = p.slip_image || '/assets/img/no-image.png';
            document.getElementById('ocrDetails').innerHTML = `
                <div class="card">
                    <div class="card-body small">
                        <p class="mb-1"><strong>💰 จำนวน:</strong> ฿${formatNumber(p.amount)}</p>
                        <p class="mb-1"><strong>🏦 ธนาคาร:</strong> ${ocrResult.bank || '-'}</p>
                        <p class="mb-1"><strong>📅 วันที่:</strong> ${ocrResult.date || '-'}</p>
                        <p class="mb-1"><strong>👤 ผู้โอน:</strong> ${ocrResult.sender_name || '-'}</p>
                        <p class="mb-0"><strong>🔢 Ref:</strong> ${ocrResult.ref || '-'}</p>
                    </div>
                </div>
            `;
            
            // Load candidates
            loadOrderCandidates(data.order_candidates || []);
            loadPawnCandidates(data.pawn_candidates || []);
        }
    } catch (e) {
        console.error('Failed to load payment details:', e);
    }
    
    new bootstrap.Modal(document.getElementById('classifyModal')).show();
}

function loadOrderCandidates(candidates) {
    const container = document.getElementById('orderCandidates');
    
    if (candidates.length === 0) {
        container.innerHTML = '<p class="text-muted">ไม่พบ order ที่ตรงกัน</p>';
        return;
    }
    
    let html = '';
    candidates.forEach(c => {
        const confidence = c.confidence || 0;
        let badgeClass = confidence >= 90 ? 'bg-success' : confidence >= 70 ? 'bg-warning' : 'bg-secondary';
        
        html += `
            <div class="candidate-card" onclick="selectOrder(${c.id}, this)">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <strong>#${c.order_no}</strong>
                        <span class="badge ${badgeClass} confidence-badge ms-2">${confidence}% match</span>
                    </div>
                    <span class="text-success fw-bold">฿${formatNumber(c.remaining_amount || c.total_amount)}</span>
                </div>
                <p class="mb-0 text-muted small mt-1">${c.product_code || ''} • ${c.match_reason || ''}</p>
            </div>
        `;
    });
    container.innerHTML = html;
}

function loadPawnCandidates(candidates) {
    const container = document.getElementById('pawnCandidates');
    
    if (candidates.length === 0) {
        container.innerHTML = '<p class="text-muted">ไม่พบรายการจำนำที่ตรงกัน</p>';
        return;
    }
    
    let html = '';
    candidates.forEach(c => {
        const confidence = c.confidence || 0;
        let badgeClass = confidence >= 90 ? 'bg-success' : confidence >= 70 ? 'bg-warning' : 'bg-secondary';
        
        html += `
            <div class="candidate-card" onclick="selectPawn(${c.id}, this)">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <strong>#${c.pawn_no}</strong>
                        <span class="badge ${badgeClass} confidence-badge ms-2">${confidence}% match</span>
                    </div>
                    <div class="text-end">
                        <div class="text-success fw-bold">฿${formatNumber(c.expected_interest || 0)}</div>
                        <small class="text-muted">ดอกเบี้ย</small>
                    </div>
                </div>
                <p class="mb-0 text-muted small mt-1">${c.item_name || c.product_code || ''} • ${c.match_reason || ''}</p>
            </div>
        `;
    });
    container.innerHTML = html;
}

function selectOrder(orderId, element) {
    document.querySelectorAll('#orderCandidates .candidate-card').forEach(c => c.classList.remove('selected'));
    element.classList.add('selected');
    selectedOrderId = orderId;
}

function selectPawn(pawnId, element) {
    document.querySelectorAll('#pawnCandidates .candidate-card').forEach(c => c.classList.remove('selected'));
    element.classList.add('selected');
    selectedPawnId = pawnId;
}

async function submitClassification() {
    const paymentId = document.getElementById('classifyPaymentId').value;
    const classifyType = document.querySelector('input[name="classifyType"]:checked')?.value;
    
    if (!classifyType) {
        alert('กรุณาเลือกประเภท');
        return;
    }
    
    let payload = {
        payment_id: paymentId,
        classify_type: classifyType
    };
    
    if (classifyType === 'order') {
        if (!selectedOrderId) {
            alert('กรุณาเลือก Order');
            return;
        }
        payload.order_id = selectedOrderId;
    } else if (classifyType === 'pawn') {
        if (!selectedPawnId) {
            alert('กรุณาเลือกรายการจำนำ');
            return;
        }
        payload.pawn_id = selectedPawnId;
        payload.payment_type = document.getElementById('pawnPaymentType').value;
    } else if (classifyType === 'reject') {
        payload.reason = document.getElementById('rejectReason').value;
    }
    
    try {
        const res = await fetch('/api/admin/payments/classify.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        
        const data = await res.json();
        
        if (data.success) {
            bootstrap.Modal.getInstance(document.getElementById('classifyModal')).hide();
            loadPayments();
            loadSummary();
            alert('บันทึกสำเร็จ');
        } else {
            alert(data.error || 'เกิดข้อผิดพลาด');
        }
    } catch (e) {
        console.error('Failed to submit:', e);
        alert('เกิดข้อผิดพลาดในการบันทึก');
    }
}

function getMatchStatusBadge(status) {
    const badges = {
        'pending': '<span class="badge bg-warning match-status-badge">รอจัดประเภท</span>',
        'auto_matched': '<span class="badge bg-success match-status-badge">Auto-matched</span>',
        'manual_matched': '<span class="badge bg-info match-status-badge">Manual</span>',
        'no_match': '<span class="badge bg-danger match-status-badge">ไม่เจอ Match</span>'
    };
    return badges[status] || '';
}

function getClassifiedBadge(classified) {
    const badges = {
        'order': '<span class="badge bg-primary ms-1">Order</span>',
        'pawn': '<span class="badge bg-success ms-1">Pawn</span>',
        'rejected': '<span class="badge bg-danger ms-1">Rejected</span>',
        'unknown': ''
    };
    return badges[classified] || '';
}

function formatNumber(num) {
    return new Intl.NumberFormat('th-TH').format(num);
}

function formatDate(dateStr) {
    if (!dateStr) return '-';
    const d = new Date(dateStr);
    return d.toLocaleDateString('th-TH', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
}
</script>

<?php include __DIR__ . '/../includes/admin/footer.php'; ?>
