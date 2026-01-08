/**
 * LINE Applications Monitor - JavaScript
 * Handles data loading, filtering, and actions for line-applications.php
 */

let currentApplication = null;
let currentPage = 1;
let applicationsData = [];

// Initialize on page load
document.addEventListener('DOMContentLoaded', () => {
    // Require authentication
    requireAuth();

    loadStatistics();
    loadCampaigns();
    loadApplications();

    // Set event listeners
    document.getElementById('searchInput')?.addEventListener('keyup', debounce(applyFilters, 500));
});

// Debounce helper
function debounce(func, wait) {
    let timeout;
    return function executedFunction(...args) {
        const later = () => {
            clearTimeout(timeout);
            func(...args);
        };
        clearTimeout(timeout);
        timeout = setTimeout(later, wait);
    };
}

// Load Statistics
async function loadStatistics() {
    try {
        const apiUrl = PATH.api('api/admin/line-applications.php');
        const result = await apiCall(apiUrl);

        if (result && result.success && result.data) {
            const apps = result.data;
            const total = apps.length;
            const approved = apps.filter(a => a.status === 'APPROVED').length;
            const rejected = apps.filter(a => a.status === 'REJECTED').length;
            const pending = apps.filter(a => ['RECEIVED', 'DOC_PENDING', 'OCR_PROCESSING', 'NEED_REVIEW', 'INCOMPLETE'].includes(a.status)).length;

            document.getElementById('totalApplications').textContent = total;
            document.getElementById('approvedCount').textContent = approved;
            document.getElementById('rejectedCount').textContent = rejected;
            document.getElementById('pendingCount').textContent = pending;
        }
    } catch (error) {
        console.error('Error loading statistics:', error);
    }
}

// Load Campaigns for filter
async function loadCampaigns() {
    try {
        const apiUrl = PATH.api('api/lineapp/campaigns.php');
        const result = await apiCall(apiUrl);

        if (result && result.success && result.data) {
            const select = document.getElementById('campaignFilter');

            result.data.forEach(campaign => {
                const option = document.createElement('option');
                option.value = campaign.id;
                option.textContent = campaign.name;
                select.appendChild(option);
            });
        }
    } catch (error) {
        console.error('Error loading campaigns:', error);
    }
}

// Load Applications with filters
async function loadApplications(page = 1) {
    try {
        currentPage = page;

        // Build query params
        const params = new URLSearchParams();
        params.append('page', page);
        params.append('per_page', 20);

        // Filters
        const search = document.getElementById('searchInput')?.value;
        if (search) params.append('search', search);

        const campaign = document.getElementById('campaignFilter')?.value;
        if (campaign) params.append('campaign_id', campaign);

        const status = document.getElementById('statusFilter')?.value;
        if (status) params.append('status', status);

        const dateFrom = document.getElementById('dateFrom')?.value;
        if (dateFrom) params.append('date_from', dateFrom);

        const dateTo = document.getElementById('dateTo')?.value;
        if (dateTo) params.append('date_to', dateTo);

        const priority = document.getElementById('priorityFilter')?.value;
        if (priority) params.append('priority', priority);

        const apiUrl = PATH.api('api/admin/line-applications.php?' + params.toString());
        const result = await apiCall(apiUrl);

        if (result && result.success) {
            applicationsData = result.data;
            renderApplicationsTable(result.data);
            renderPagination(result.pagination);
        }
    } catch (error) {
        console.error('Error loading applications:', error);
        showError('ไม่สามารถโหลดข้อมูลได้ กรุณาลองใหม่อีกครั้ง');
    }
}

// Render Applications Table
function renderApplicationsTable(applications) {
    const tbody = document.getElementById('applicationsTableBody');

    if (applications.length === 0) {
        tbody.innerHTML = `
            <tr>
                <td colspan="8" style="text-align:center;padding:2rem;color:var(--color-gray);">
                    ไม่พบข้อมูลใบสมัคร
                </td>
            </tr>
        `;
        return;
    }

    tbody.innerHTML = applications.map(app => `
        <tr>
            <td>
                <strong>${escapeHtml(app.application_no)}</strong>
                ${app.priority !== 'normal' ? `<br><span class="priority-badge priority-${app.priority}">${getPriorityLabel(app.priority)}</span>` : ''}
            </td>
            <td>${escapeHtml(app.campaign_name)}</td>
            <td>
                <div style="display:flex;align-items:center;">
                    ${app.line_picture_url ? `<img src="${escapeHtml(app.line_picture_url)}" class="user-avatar" alt="">` : ''}
                    <span>${escapeHtml(app.line_display_name || 'N/A')}</span>
                </div>
            </td>
            <td>
                ${app.phone ? `📱 ${escapeHtml(app.phone)}<br>` : ''}
                ${app.email ? `📧 ${escapeHtml(app.email)}` : ''}
            </td>
            <td>
                <span class="status-badge status-${app.status}">${getStatusLabel(app.status)}</span>
                ${app.needs_manual_review ? '<br><span style="font-size:0.7rem;color:var(--color-warning);">⚠️ ต้องตรวจสอบ</span>' : ''}
            </td>
            <td>
                ${app.document_count}/${app.document_count || 0}
                ${app.ocr_completed_count ? `<br><span style="font-size:0.7rem;color:var(--color-success);">✅ OCR: ${app.ocr_completed_count}</span>` : ''}
            </td>
            <td>${formatDate(app.submitted_at)}</td>
            <td>
                <button class="btn btn-sm btn-primary" onclick="viewApplication(${app.id})">
                    <i class="fas fa-eye"></i> ดู
                </button>
            </td>
        </tr>
    `).join('');
}

// Render Pagination
function renderPagination(pagination) {
    if (!pagination) return;

    const container = document.getElementById('pagination');
    const { current_page, last_page } = pagination;

    let html = '';

    if (current_page > 1) {
        html += `<button class="btn btn-sm btn-secondary" onclick="loadApplications(${current_page - 1})">« ก่อนหน้า</button>`;
    }

    for (let i = Math.max(1, current_page - 2); i <= Math.min(last_page, current_page + 2); i++) {
        if (i === current_page) {
            html += `<button class="btn btn-sm btn-primary">${i}</button>`;
        } else {
            html += `<button class="btn btn-sm btn-secondary" onclick="loadApplications(${i})">${i}</button>`;
        }
    }

    if (current_page < last_page) {
        html += `<button class="btn btn-sm btn-secondary" onclick="loadApplications(${current_page + 1})">ถัดไป »</button>`;
    }

    container.innerHTML = html;
}

// View Application Detail
async function viewApplication(applicationId) {
    try {
        const apiUrl = PATH.api(`api/admin/line-applications.php?id=${applicationId}`);
        const result = await apiCall(apiUrl);

        if (result && result.success) {
            currentApplication = result.data;
            renderApplicationDetail(result.data);
            openDetailModal();
        }
    } catch (error) {
        console.error('Error loading application detail:', error);
        showError('ไม่สามารถโหลดรายละเอียดได้');
    }
}

// Render Application Detail in Modal
function renderApplicationDetail(app) {
    // Left side: Application Info
    const infoHtml = `
        <div class="card">
            <div class="card-header">
                <h3>${app.application_no}</h3>
                <span class="status-badge status-${app.status}">${getStatusLabel(app.status)}</span>
            </div>
            <div class="card-body">
                <h4>👤 ผู้สมัคร</h4>
                <div style="display:flex;align-items:center;margin-bottom:1rem;">
                    ${app.line_picture_url ? `<img src="${app.line_picture_url}" style="width:50px;height:50px;border-radius:50%;margin-right:1rem;">` : ''}
                    <div>
                        <strong>${app.line_display_name || 'N/A'}</strong><br>
                        <small>${app.line_user_id}</small>
                    </div>
                </div>
                
                <h4>📞 ติดต่อ</h4>
                <p>
                    ${app.phone ? `📱 ${app.phone}<br>` : ''}
                    ${app.email ? `📧 ${app.email}` : ''}
                </p>
                
                <h4>📝 คำตอบฟอร์ม</h4>
                ${renderFormData(app.form_data, app.form_config)}
                
                <h4>📅 Timeline</h4>
                ${renderStatusHistory(app.status_history)}
            </div>
        </div>
    `;

    // Right side: Documents
    const docsHtml = `
        <div class="card">
            <div class="card-header">
                <h3>📄 เอกสาร (${app.documents?.length || 0})</h3>
            </div>
            <div class="card-body">
                ${renderDocuments(app.documents)}
            </div>
        </div>
    `;

    document.getElementById('applicationInfo').innerHTML = infoHtml;
    document.getElementById('documentViewer').innerHTML = docsHtml;
}

// Render Form Data
function renderFormData(formData, formConfig) {
    if (!formData || Object.keys(formData).length === 0) {
        return '<p style="color:var(--color-gray);">ไม่มีข้อมูลฟอร์ม</p>';
    }

    let html = '<div style="background:var(--color-background);padding:1rem;border-radius:8px;">';

    for (const [key, value] of Object.entries(formData)) {
        html += `
            <div style="margin-bottom:0.5rem;">
                <strong>${key}:</strong> ${escapeHtml(value)}
            </div>
        `;
    }

    html += '</div>';
    return html;
}

// Render Status History
function renderStatusHistory(history) {
    if (!history || history.length === 0) {
        return '<p style="color:var(--color-gray);">ไม่มีประวัติ</p>';
    }

    return '<div style="max-height:200px;overflow-y:auto;">' +
        history.map(h => `
            <div style="padding:0.5rem;border-left:3px solid var(--color-primary);margin-bottom:0.5rem;background:var(--color-background);">
                <div style="font-size:0.8rem;color:var(--color-gray);">${h.changed_at}</div>
                <div><strong>${h.from} → ${h.to}</strong></div>
                <div style="font-size:0.85rem;">${h.reason || ''}</div>
                ${h.changed_by ? `<div style="font-size:0.75rem;color:var(--color-gray);">โดย: ${h.changed_by}</div>` : ''}
            </div>
        `).join('') +
        '</div>';
}

// Render Documents
function renderDocuments(documents) {
    if (!documents || documents.length === 0) {
        return '<p style="color:var(--color-gray);">ไม่มีเอกสาร</p>';
    }

    return documents.map(doc => `
        <div style="margin-bottom:1rem;padding:1rem;border:1px solid var(--color-border);border-radius:8px;">
            <h5>${doc.document_label || doc.document_type}</h5>
            <p style="font-size:0.85rem;color:var(--color-gray);">
                ${doc.original_filename} (${formatBytes(doc.file_size)})
            </p>
            ${doc.ocr_processed ? `
                <div style="background:var(--color-background);padding:0.5rem;border-radius:4px;margin-top:0.5rem;">
                    <strong>OCR Results:</strong><br>
                    Confidence: <span class="confidence-${getConfidenceClass(doc.ocr_confidence)}">${(doc.ocr_confidence * 100).toFixed(1)}%</span>
                </div>
            ` : '<p style="color:var(--color-warning);">⏳ รอ OCR</p>'}
        </div>
    `).join('');
}

// Actions
function approveApplication() {
    if (!currentApplication) return;
    document.getElementById('approveModal').style.display = 'flex';
}

async function confirmApprove() {
    if (!currentApplication) return;

    const notes = document.getElementById('approveNotes').value;

    try {
        const apiUrl = PATH.api('api/admin/line-applications.php');
        const result = await apiCall(apiUrl, {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                id: currentApplication.id,
                action: 'approve',
                admin_notes: notes
            })
        });

        if (result && result.success) {
            showSuccess('อนุมัติใบสมัครเรียบร้อยแล้ว');
            closeApproveModal();
            closeDetailModal();
            loadApplications(currentPage);
            loadStatistics();
        } else {
            showError(result?.message || 'เกิดข้อผิดพลาด');
        }
    } catch (error) {
        console.error('Error approving:', error);
        showError('ไม่สามารถอนุมัติได้');
    }
}

function rejectApplication() {
    if (!currentApplication) return;
    document.getElementById('rejectModal').style.display = 'flex';
}

async function confirmReject() {
    if (!currentApplication) return;

    const reason = document.getElementById('rejectReason').value;
    if (!reason) {
        alert('กรุณาระบุเหตุผลในการปฏิเสธ');
        return;
    }

    try {
        const apiUrl = PATH.api('api/admin/line-applications.php');
        const result = await apiCall(apiUrl, {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                id: currentApplication.id,
                action: 'reject',
                rejection_reason: reason
            })
        });

        if (result && result.success) {
            showSuccess('ปฏิเสธใบสมัครเรียบร้อยแล้ว');
            closeRejectModal();
            closeDetailModal();
            loadApplications(currentPage);
            loadStatistics();
        } else {
            showError(result?.message || 'เกิดข้อผิดพลาด');
        }
    } catch (error) {
        console.error('Error rejecting:', error);
        showError('ไม่สามารถปฏิเสธได้');
    }
}

function requestMoreDocs() {
    // TODO: Implement request more documents modal
    alert('Feature coming soon: Request More Documents');
}

function setAppointment() {
    // TODO: Implement set appointment modal
    alert('Feature coming soon: Set Appointment');
}

// Modal Controls
function openDetailModal() {
    document.getElementById('detailModal').style.display = 'flex';
}

function closeDetailModal() {
    document.getElementById('detailModal').style.display = 'none';
}

function closeApproveModal() {
    document.getElementById('approveModal').style.display = 'none';
    document.getElementById('approveNotes').value = '';
}

function closeRejectModal() {
    document.getElementById('rejectModal').style.display = 'none';
    document.getElementById('rejectReason').value = '';
}

// Filters
function applyFilters() {
    loadApplications(1);
}

function clearFilters() {
    document.getElementById('searchInput').value = '';
    document.getElementById('campaignFilter').value = '';
    document.getElementById('statusFilter').value = '';
    document.getElementById('dateFrom').value = '';
    document.getElementById('dateTo').value = '';
    document.getElementById('priorityFilter').value = '';
    loadApplications(1);
}

// Export to CSV
function exportToCSV() {
    if (applicationsData.length === 0) {
        alert('ไม่มีข้อมูลให้ส่งออก');
        return;
    }

    const headers = ['เลขที่', 'แคมเปญ', 'ชื่อ', 'เบอร์', 'อีเมล', 'สถานะ', 'วันที่สมัคร'];
    const rows = applicationsData.map(app => [
        app.application_no,
        app.campaign_name,
        app.line_display_name || '',
        app.phone || '',
        app.email || '',
        getStatusLabel(app.status),
        formatDate(app.submitted_at)
    ]);

    const csv = [headers, ...rows].map(row => row.map(cell => `"${cell}"`).join(',')).join('\n');
    const blob = new Blob(['\ufeff' + csv], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = `line_applications_${new Date().toISOString().slice(0, 10)}.csv`;
    link.click();
}

// Utility functions
function getStatusLabel(status) {
    const labels = {
        'RECEIVED': 'ได้รับแล้ว',
        'DOC_PENDING': 'รอเอกสาร',
        'OCR_PROCESSING': 'กำลัง OCR',
        'NEED_REVIEW': 'ต้องตรวจสอบ',
        'APPROVED': 'อนุมัติแล้ว',
        'REJECTED': 'ปฏิเสธ',
        'INCOMPLETE': 'ขอเอกสารเพิ่ม'
    };
    return labels[status] || status;
}

function getPriorityLabel(priority) {
    const labels = {
        'low': 'ต่ำ',
        'normal': 'ปกติ',
        'high': 'สูง',
        'urgent': 'ด่วน'
    };
    return labels[priority] || priority;
}

function formatDate(dateString) {
    if (!dateString) return '-';
    const date = new Date(dateString);
    return date.toLocaleDateString('th-TH', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit'
    });
}

function formatBytes(bytes) {
    if (bytes === 0) return '0 Bytes';
    const k = 1024;
    const sizes = ['Bytes', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return Math.round(bytes / Math.pow(k, i) * 100) / 100 + ' ' + sizes[i];
}

function getConfidenceClass(confidence) {
    if (confidence >= 0.8) return 'high';
    if (confidence >= 0.6) return 'medium';
    return 'low';
}

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function showSuccess(message) {
    alert('✅ ' + message);
}

function showError(message) {
    alert('❌ ' + message);
}
