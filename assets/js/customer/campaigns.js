/**
 * Campaigns Manager - JavaScript
 */

console.log('🚀 [CAMPAIGNS] Script loaded');

let campaigns = [];
let currentCampaign = null;
let formQuestions = [];
let requiredDocuments = [];

// Initialize
document.addEventListener('DOMContentLoaded', () => {
    console.log('📄 [CAMPAIGNS] DOM loaded');

    // Check tokens before requireAuth
    const authToken = localStorage.getItem('auth_token');
    const sessionToken = sessionStorage.getItem('auth_token');
    console.log('🔑 [CAMPAIGNS] Token status:', {
        localStorage_auth: authToken ? '✅ EXISTS (' + authToken.substring(0, 20) + '...)' : '❌ MISSING',
        sessionStorage_auth: sessionToken ? '✅ EXISTS' : '❌ MISSING'
    });

    // Require authentication
    console.log('🔐 [CAMPAIGNS] Calling requireAuth()...');
    requireAuth();
    console.log('✅ [CAMPAIGNS] requireAuth() completed');

    loadCampaigns();

    // Form submit
    document.getElementById('campaignForm')?.addEventListener('submit', handleSubmit);
});

// Load Campaigns
async function loadCampaigns() {
    try {
        const apiUrl = PATH.api('api/admin/campaigns.php');
        const result = await apiCall(apiUrl);

        if (result && result.success) {
            campaigns = result.data;
            renderCampaignsTable(result.data);
        }
    } catch (error) {
        console.error('Error loading campaigns:', error);
        showError('ไม่สามารถโหลดข้อมูลได้');
    }
}

// Render Campaigns Table
function renderCampaignsTable(data) {
    const tbody = document.getElementById('campaignsTableBody');

    if (data.length === 0) {
        tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;padding:2rem;color:var(--color-gray);">ยังไม่มีแคมเปญ</td></tr>';
        return;
    }

    tbody.innerHTML = data.map(c => `
        <tr>
            <td><strong>${escapeHtml(c.code)}</strong></td>
            <td>${escapeHtml(c.name)}</td>
            <td>
                ${c.start_date ? formatDate(c.start_date) : '-'} 
                ถึง 
                ${c.end_date ? formatDate(c.end_date) : '-'}
            </td>
            <td>
                ${c.application_count || 0} / ${c.max_applications || '∞'}
                ${c.approved_count ? `<br><small style="color:var(--color-success);">อนุมัติ: ${c.approved_count}</small>` : ''}
            </td>
            <td>
                <span class="status-badge ${c.is_active ? 'status-APPROVED' : 'status-REJECTED'}">
                    ${c.is_active ? 'เปิดใช้งาน' : 'ปิดใช้งาน'}
                </span>
            </td>
            <td>
                <button class="btn btn-sm btn-primary" onclick="editCampaign(${c.id})">
                    <i class="fas fa-edit"></i> แก้ไข
                </button>
                <button class="btn btn-sm btn-danger" onclick="deleteCampaign(${c.id})">
                    <i class="fas fa-trash"></i>
                </button>
            </td>
        </tr>
    `).join('');
}

// Open Campaign Modal (Create)
function openCampaignModal() {
    currentCampaign = null;
    formQuestions = [];
    requiredDocuments = [];

    document.getElementById('modalTitle').textContent = 'สร้างแคมเปญใหม่';
    document.getElementById('campaignForm').reset();
    document.getElementById('questionsList').innerHTML = '';
    document.getElementById('documentsList').innerHTML = '';

    // Add default question
    addQuestion();
    // Add default document
    addDocument();

    document.getElementById('campaignModal').style.display = 'flex';
}

// Close Campaign Modal
function closeCampaignModal() {
    document.getElementById('campaignModal').style.display = 'none';
}

// Edit Campaign
async function editCampaign(id) {
    try {
        const apiUrl = PATH.api(`api/admin/campaigns.php?id=${id}`);
        const result = await apiCall(apiUrl);

        if (result && result.success) {
            currentCampaign = result.data;
            populateForm(result.data);
            document.getElementById('modalTitle').textContent = 'แก้ไขแคมเปญ';
            document.getElementById('campaignModal').style.display = 'flex';
        }
    } catch (error) {
        console.error('Error loading campaign:', error);
        showError('ไม่สามารถโหลดข้อมูลได้');
    }
}

// Populate Form for Edit
function populateForm(campaign) {
    document.getElementById('campaignCode').value = campaign.code;
    document.getElementById('campaignName').value = campaign.name;
    document.getElementById('campaignDescription').value = campaign.description || '';
    document.getElementById('campaignStartDate').value = campaign.start_date || '';
    document.getElementById('campaignEndDate').value = campaign.end_date || '';
    document.getElementById('campaignActive').value = campaign.is_active;
    document.getElementById('liffId').value = campaign.liff_id || '';
    document.getElementById('ocrEnabled').checked = campaign.ocr_enabled == 1;
    document.getElementById('autoApprove').checked = campaign.auto_approve == 1;

    // Populate form questions - handle both array and {questions: []} format
    if (campaign.form_config) {
        if (Array.isArray(campaign.form_config)) {
            formQuestions = campaign.form_config;
        } else if (campaign.form_config.questions) {
            formQuestions = campaign.form_config.questions;
        } else {
            formQuestions = [];
        }
    } else {
        formQuestions = [];
    }
    renderQuestions();

    // Populate required documents - ensure it's always an array
    if (campaign.required_documents) {
        requiredDocuments = Array.isArray(campaign.required_documents)
            ? campaign.required_documents
            : [];
    } else {
        requiredDocuments = [];
    }
    renderDocuments();
}

// Form Builder - Add Question
function addQuestion() {
    const question = {
        id: Date.now(),
        type: 'text',
        label: '',
        placeholder: '',
        required: true,
        options: []
    };

    formQuestions.push(question);
    renderQuestions();
}

// Render Questions
function renderQuestions() {
    const container = document.getElementById('questionsList');

    container.innerHTML = formQuestions.map((q, index) => `
        <div class="question-item" data-index="${index}">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:0.5rem;">
                <strong>คำถามที่ ${index + 1}</strong>
                <button type="button" class="remove-btn" onclick="removeQuestion(${index})">
                    <i class="fas fa-times"></i> ลบ
                </button>
            </div>
            
            <label>ประเภทคำถาม:</label>
            <select class="form-control" onchange="updateQuestionType(${index}, this.value)">
                <option value="text" ${q.type === 'text' ? 'selected' : ''}>ข้อความสั้น</option>
                <option value="textarea" ${q.type === 'textarea' ? 'selected' : ''}>ข้อความยาว</option>
                <option value="number" ${q.type === 'number' ? 'selected' : ''}>ตัวเลข</option>
                <option value="email" ${q.type === 'email' ? 'selected' : ''}>อีเมล</option>
                <option value="tel" ${q.type === 'tel' ? 'selected' : ''}>เบอร์โทร</option>
                <option value="date" ${q.type === 'date' ? 'selected' : ''}>วันที่</option>
                <option value="select" ${q.type === 'select' ? 'selected' : ''}>เลือก (Dropdown)</option>
            </select>
            
            <label>คำถาม:</label>
            <input type="text" class="form-control" placeholder="ชื่อ-นามสกุล" 
                   value="${escapeHtml(q.label || '')}" 
                   onchange="updateQuestionLabel(${index}, this.value)">
            
            <label>Placeholder (ตัวอย่าง):</label>
            <input type="text" class="form-control" placeholder="กรอกชื่อ-นามสกุล" 
                   value="${escapeHtml(q.placeholder || '')}" 
                   onchange="updateQuestionPlaceholder(${index}, this.value)">
            
            <label style="display:flex;align-items:center;gap:0.5rem;margin-top:0.5rem;">
                <input type="checkbox" ${q.required ? 'checked' : ''} 
                       onchange="updateQuestionRequired(${index}, this.checked)">
                ต้องกรอก (Required)
            </label>
        </div>
    `).join('');
}

// Update Question
function updateQuestionType(index, type) {
    formQuestions[index].type = type;
}

function updateQuestionLabel(index, label) {
    formQuestions[index].label = label;
}

function updateQuestionPlaceholder(index, placeholder) {
    formQuestions[index].placeholder = placeholder;
}

function updateQuestionRequired(index, required) {
    formQuestions[index].required = required;
}

function removeQuestion(index) {
    if (confirm('ต้องการลบคำถามนี้หรือไม่?')) {
        formQuestions.splice(index, 1);
        renderQuestions();
    }
}

// Documents Builder - Add Document
function addDocument() {
    const doc = {
        id: Date.now(),
        type: '',
        label: '',
        required: true
    };

    requiredDocuments.push(doc);
    renderDocuments();
}

// Render Documents
function renderDocuments() {
    const container = document.getElementById('documentsList');

    container.innerHTML = requiredDocuments.map((d, index) => `
        <div class="document-item" data-index="${index}">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:0.5rem;">
                <strong>เอกสารที่ ${index + 1}</strong>
                <button type="button" class="remove-btn" onclick="removeDocument(${index})">
                    <i class="fas fa-times"></i> ลบ
                </button>
            </div>
            
            <label>ประเภทเอกสาร:</label>
            <select class="form-control" onchange="updateDocumentType(${index}, this.value)">
                <option value="">-- เลือก --</option>
                <option value="id_card" ${d.type === 'id_card' ? 'selected' : ''}>บัตรประชาชน</option>
                <option value="house_registration" ${d.type === 'house_registration' ? 'selected' : ''}>ทะเบียนบ้าน</option>
                <option value="salary_slip" ${d.type === 'salary_slip' ? 'selected' : ''}>สลิปเงินเดือน</option>
                <option value="bank_statement" ${d.type === 'bank_statement' ? 'selected' : ''}>Statement ธนาคาร</option>
                <option value="other" ${d.type === 'other' ? 'selected' : ''}>อื่นๆ</option>
            </select>
            
            <label>ชื่อเอกสาร:</label>
            <input type="text" class="form-control" placeholder="บัตรประชาชน (หน้า-หลัง)" 
                   value="${escapeHtml(d.label || '')}" 
                   onchange="updateDocumentLabel(${index}, this.value)">
            
            <label style="display: flex; align-items: center; margin-top: 0.75rem; cursor: pointer;">
                <input type="checkbox" 
                       ${d.required ? 'checked' : ''} 
                       onchange="updateDocumentRequired(${index}, this.checked)"
                       style="margin-right: 0.5rem; cursor: pointer;">
                <span style="font-weight: normal;">
                    <i class="fas fa-exclamation-circle" style="color: #dc3545;"></i>
                    จำเป็นต้องแนบ (Required)
                </span>
            </label>
        </div>
    `).join('');
}

function updateDocumentType(index, type) {
    requiredDocuments[index].type = type;
}

function updateDocumentLabel(index, label) {
    requiredDocuments[index].label = label;
}

function updateDocumentRequired(index, required) {
    requiredDocuments[index].required = required;
}

function removeDocument(index) {
    if (confirm('ต้องการลบเอกสารนี้หรือไม่?')) {
        requiredDocuments.splice(index, 1);
        renderDocuments();
    }
}

// Handle Submit
async function handleSubmit(e) {
    e.preventDefault();

    const data = {
        code: document.getElementById('campaignCode').value.trim(),
        name: document.getElementById('campaignName').value.trim(),
        description: document.getElementById('campaignDescription').value.trim(),
        start_date: document.getElementById('campaignStartDate').value || null,
        end_date: document.getElementById('campaignEndDate').value || null,
        is_active: parseInt(document.getElementById('campaignActive').value),
        liff_id: document.getElementById('liffId').value.trim() || null,
        form_config: formQuestions,
        required_documents: requiredDocuments,
        ocr_enabled: document.getElementById('ocrEnabled').checked ? 1 : 0,
        auto_approve: document.getElementById('autoApprove').checked ? 1 : 0
    };

    try {
        const apiUrl = PATH.api('api/admin/campaigns.php');
        const method = currentCampaign ? 'PUT' : 'POST';

        if (currentCampaign) {
            data.id = currentCampaign.id;
        }

        const result = await apiCall(apiUrl, {
            method: method,
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(data)
        });

        if (result && result.success) {
            showSuccess(currentCampaign ? 'แก้ไขแคมเปญสำเร็จ' : 'สร้างแคมเปญสำเร็จ');
            closeCampaignModal();
            loadCampaigns();
        } else {
            showError(result?.message || 'เกิดข้อผิดพลาด');
        }
    } catch (error) {
        console.error('Error saving campaign:', error);
        showError('ไม่สามารถบันทึกได้');
    }
}

// Delete Campaign
async function deleteCampaign(id) {
    if (!confirm('ต้องการลบแคมเปญนี้หรือไม่?')) return;

    try {
        const apiUrl = PATH.api(`api/admin/campaigns.php?id=${id}`);
        const result = await apiCall(apiUrl, {
            method: 'DELETE'
        });

        if (result && result.success) {
            showSuccess('ลบแคมเปญสำเร็จ');
            loadCampaigns();
        } else {
            showError(result?.message || 'ไม่สามารถลบได้');
        }
    } catch (error) {
        console.error('Error deleting campaign:', error);
        showError('เกิดข้อผิดพลาด');
    }
}

// Utility
function formatDate(dateString) {
    if (!dateString) return '-';
    const date = new Date(dateString);
    return date.toLocaleDateString('th-TH', { year: 'numeric', month: 'short', day: 'numeric' });
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
