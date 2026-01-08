<?php
/**
 * Campaigns Manager - Customer Portal
 */
define('INCLUDE_CHECK', true);

$page_title = "จัดการแคมเปญ - AI Automation";
$current_page = "campaigns";

include('../includes/customer/header.php');
include('../includes/customer/sidebar.php');
?>

<main class="main-content">
    <div class="page-header">
        <div>
            <h1 class="page-title">🎯 จัดการแคมเปญ</h1>
            <p class="page-subtitle">สร้างและจัดการแคมเปญสำหรับรับใบสมัคร LINE</p>
        </div>
        <div>
            <button class="btn btn-primary" onclick="openCampaignModal()">
                <i class="fas fa-plus"></i> สร้างแคมเปญใหม่
            </button>
        </div>
    </div>

    <!-- Campaigns List -->
    <div class="card mt-4">
        <div class="card-header">
            <h3 class="card-title">รายการแคมเปญ</h3>
        </div>
        <div class="card-body">
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>รหัส</th>
                            <th>ชื่อแคมเปญ</th>
                            <th>ระยะเวลา</th>
                            <th>ใบสมัคร</th>
                            <th>สถานะ</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody id="campaignsTableBody">
                        <tr>
                            <td colspan="6" style="text-align:center;padding:2rem;">
                                <div class="spinner" style="margin:0 auto 1rem;"></div>
                                กำลังโหลดข้อมูล...
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</main>

<!-- Campaign Modal -->
<div id="campaignModal" class="modal modal-ui" data-ui="modal" style="display:none;">
    <div class="modal-overlay" onclick="closeCampaignModal()"></div>
    <div class="modal-dialog" style="max-width:1100px;">
        <div class="modal-content-wrapper campaign-modal">
            <div class="modal-header-gradient">
                <div>
                    <h2 class="modal-title-gradient" id="modalTitle">สร้างแคมเปญใหม่</h2>
                    <p class="modal-subtitle">กรอกข้อมูลแคมเปญและฟอร์มสำหรับผู้สมัคร</p>
                </div>
                <button class="modal-close-modern" onclick="closeCampaignModal()" type="button">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                        <path d="M18 6L6 18M6 6l12 12" stroke-width="2" stroke-linecap="round"/>
                    </svg>
                </button>
            </div>
            <div class="modal-body-modern">
                <form id="campaignForm">
                    <!-- Basic Info Section -->
                    <div class="form-section">
                        <div class="section-header">
                            <span class="section-icon">📋</span>
                            <h4>ข้อมูลพื้นฐาน</h4>
                        </div>
                        <div class="section-content">
                            <div class="row">
                                <div class="col-6">
                                    <div class="form-group">
                                        <label>รหัสแคมเปญ <span class="required">*</span></label>
                                        <input type="text" id="campaignCode" class="form-control" required placeholder="เช่น CAMP2026">
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="form-group">
                                        <label>สถานะ</label>
                                        <select id="campaignActive" class="form-control">
                                            <option value="1">🟢 เปิดใช้งาน</option>
                                            <option value="0">🔴 ปิดใช้งาน</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label>ชื่อแคมเปญ <span class="required">*</span></label>
                                <input type="text" id="campaignName" class="form-control" required placeholder="แคม เพเปญสม้ครสินเชื่อ 2026">
                            </div>
                            
                            <div class="form-group">
                                <label>คำอธิบาย</label>
                                <textarea id="campaignDescription" class="form-control" rows="2" placeholder="รายละเอียดเกี่ยวกับแคมเปญนี้"></textarea>
                            </div>
                            
                            <div class="row">
                                <div class="col-6">
                                    <div class="form-group">
                                        <label>วันที่เริ่มต้น</label>
                                        <input type="date" id="campaignStartDate" class="form-control">
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="form-group">
                                        <label>วันที่สิ้นสุด</label>
                                        <input type="date" id="campaignEndDate" class="form-control">
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Form Builder Section -->
                    <div class="form-section">
                        <div class="section-header">
                            <span class="section-icon">🎨</span>
                            <h4>สร้างฟอร์ม</h4>
                        </div>
                        <div class="section-content">
                            <div id="questionsList"></div>
                            <button type="button" class="btn btn-add" onclick="addQuestion()">
                                <i class="fas fa-plus"></i> เพิ่มคำถาม
                            </button>
                        </div>
                    </div>

                    <!-- Required Documents Section -->
                    <div class="form-section">
                        <div class="section-header">
                            <span class="section-icon">📄</span>
                            <h4>เอกสารที่ต้องการ</h4>
                        </div>
                        <div class="section-content">
                            <div id="documentsList"></div>
                            <button type="button" class="btn btn-add" onclick="addDocument()">
                                <i class="fas fa-plus"></i> เพิ่มเอกสาร
                            </button>
                        </div>
                    </div>

                    <!-- LINE Integration Section -->
                    <div class="form-section">
                        <div class="section-header">
                            <span class="section-icon">💚</span>
                            <h4>LINE Integration (LIFF)</h4>
                        </div>
                        <div class="section-content">
                            <div class="form-group">
                                <label>LIFF ID <span class="text-muted">(ถ้ามี)</span></label>
                                <input type="text" id="liffId" class="form-control" placeholder="เช่น 1234567890-AbCdEfGh">
                                <small class="form-text text-muted">
                                    🔗 LIFF ID จาก <a href="https://developers.line.biz/console/" target="_blank">LINE Developers Console</a><br>
                                    💡 ถ้าใส่ LIFF ID → ผู้ใช้จะได้ลิงก์ฟอร์มในแชท<br>
                                    ⚠️ ถ้าไม่ใส่ → ระบบจะใช้การถาม-ตอบแบบแชท
                                </small>
                            </div>
                        </div>
                    </div>

                    <!-- Settings Section -->
                    <div class="form-section">
                        <div class="section-header">
                            <span class="section-icon">⚙️</span>
                            <h4>ตั้งค่า</h4>
                        </div>
                        <div class="section-content">
                            <div class="settings-grid">
                                <label class="checkbox-card">
                                    <input type="checkbox" id="ocrEnabled">
                                    <div class="checkbox-content">
                                        <span class="checkbox-icon">🔍</span>
                                        <span class="checkbox-label">เปิดใช้ OCR</span>
                                        <span class="checkbox-desc">อ่านข้อมูลจากเอกสารอัตโนมัติ</span>
                                    </div>
                                </label>
                                <label class="checkbox-card">
                                    <input type="checkbox" id="autoApprove">
                                    <div class="checkbox-content">
                                        <span class="checkbox-icon">✅</span>
                                        <span class="checkbox-label">อนุมัติ อัตโนมัติ</span>
                                        <span class="checkbox-desc">อนุมัติอัตโนมัติเมื่อตรวจสอบผ่าน</span>
                                    </div>
                                </label>
                            </div>
                        </div>
                    </div>

                    <!-- Action Buttons -->
                    <div class="modal-footer-modern">
                        <button type="button" class="btn btn-cancel" onclick="closeCampaignModal()">
                            <i class="fas fa-times"></i> ยกเลิก
                        </button>
                        <button type="submit" class="btn btn-save">
                            <i class="fas fa-save"></i> บันทึก
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<style>
/* Clean Professional Modal Styles */
.campaign-modal {
    animation: modalFadeIn 0.2s ease-out;
}

@keyframes modalFadeIn {
    from { opacity: 0; }
    to { opacity: 1; }
}

.modal-header-gradient {
    background: #f8f9fa;
    color: #2c3e50;
    padding: 1.5rem 2rem;
    border-bottom: 1px solid #e0e0e0;
    border-radius: 8px 8px 0 0;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.modal-title-gradient {
    font-size: 1.5rem;
    font-weight: 600;
    margin: 0;
    color: #2c3e50;
}

.modal-subtitle {
    font-size: 0.875rem;
    color: #6c757d;
    margin: 0.25rem 0 0 0;
}

.modal-close-modern {
    background: transparent;
    border: none;
    width: 32px;
    height: 32px;
    border-radius: 4px;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: background 0.2s;
    color: #6c757d;
}

.modal-close-modern:hover {
    background: #e9ecef;
}

.modal-body-modern {
    padding: 0;
    max-height: calc(90vh - 200px);
    overflow-y: auto;
}

/* Form Sections */
.form-section {
    border-bottom: 1px solid #e9ecef;
}

.form-section:last-of-type {
    border-bottom: none;
}

.section-header {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 1.25rem 2rem 1rem;
    background: #fafbfc;
}

.section-icon {
    font-size: 1.25rem;
}

.section-header h4 {
    margin: 0;
    font-size: 1rem;
    font-weight: 600;
    color: #495057;
}

.section-content {
    padding: 1.5rem 2rem;
    background: white;
}

/* Form Groups */
.form-group {
    margin-bottom: 1.25rem;
}

.form-group:last-child {
    margin-bottom: 0;
}

.form-group label {
    display: block;
    margin-bottom: 0.5rem;
    font-weight: 500;
    color: #495057;
    font-size: 0.875rem;
}

.required {
    color: #dc3545;
    margin-left: 0.25rem;
}

.form-control {
    width: 100%;
    padding: 0.625rem 0.875rem;
    border: 1px solid #ced4da;
    border-radius: 4px;
    font-size: 0.875rem;
    transition: border-color 0.2s, box-shadow 0.2s;
    background: white;
}

.form-control:focus {
    outline: none;
    border-color: #4a90e2;
    box-shadow: 0 0 0 3px rgba(74, 144, 226, 0.1);
}

/* Settings Grid */
.settings-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
    gap: 1rem;
}

.checkbox-card {
    display: block;
    padding: 1rem;
    border: 1px solid #dee2e6;
    border-radius: 6px;
    cursor: pointer;
    transition: all 0.2s;
    background: #fafbfc;
}

.checkbox-card:hover {
    border-color: #4a90e2;
    background: #f8f9fa;
}

.checkbox-card input[type="checkbox"] {
    display: none;
}

.checkbox-card input[type="checkbox"]:checked + .checkbox-content {
    background: #e3f2fd;
    border-left: 3px solid #4a90e2;
}

.checkbox-content {
    display: flex;
    flex-direction: column;
    gap: 0.375rem;
    padding: 0.5rem;
    border-radius: 4px;
    transition: all 0.2s;
    border-left: 3px solid transparent;
}

.checkbox-icon {
    font-size: 1.25rem;
}

.checkbox-label {
    font-weight: 600;
    font-size: 0.875rem;
    color: #2c3e50;
}

.checkbox-desc {
    font-size: 0.75rem;
    color: #6c757d;
}

/* Question/Document Items */
.question-item, .document-item {
    background: #f8f9fa;
    padding: 1rem;
    border-radius: 6px;
    margin-bottom: 0.875rem;
    border: 1px solid #e9ecef;
}

.question-item label, .document-item label {
    display: block;
    margin-bottom: 0.5rem;
    font-weight: 500;
    font-size: 0.875rem;
    color: #495057;
}

.question-item input,
.question-item select,
.document-item input,
.document-item select {
    width: 100%;
    margin-bottom: 0.75rem;
}

.remove-btn {
    background: #dc3545;
    color: white;
    border: none;
    padding: 0.375rem 0.75rem;
    border-radius: 4px;
    cursor: pointer;
    font-size: 0.875rem;
    font-weight: 500;
    transition: background 0.2s;
}

.remove-btn:hover {
    background: #c82333;
}

/* Buttons */
.btn-add {
    background: white;
    border: 1px dashed #adb5bd;
    color: #495057;
    padding: 0.625rem 1.25rem;
    border-radius: 4px;
    cursor: pointer;
    font-weight: 500;
    font-size: 0.875rem;
    transition: all 0.2s;
    width: 100%;
}

.btn-add:hover {
    border-color: #4a90e2;
    color: #4a90e2;
    background: #f8f9fa;
}

.modal-footer-modern {
    padding: 1rem 2rem;
    border-top: 1px solid #e9ecef;
    display: flex;
    gap: 0.75rem;
    justify-content: flex-end;
    background: #fafbfc;
}

.btn-cancel {
    background: white;
    border: 1px solid #ced4da;
    color: #495057;
    padding: 0.625rem 1.5rem;
    border-radius: 4px;
    cursor: pointer;
    font-weight: 500;
    font-size: 0.875rem;
    transition: all 0.2s;
}

.btn-cancel:hover {
    background: #e9ecef;
    border-color: #adb5bd;
}

.btn-save {
    background: #4a90e2;
    border: none;
    color: white;
    padding: 0.625rem 2rem;
    border-radius: 4px;
    cursor: pointer;
    font-weight: 500;
    font-size: 0.875rem;
    transition: all 0.2s;
}

.btn-save:hover {
    background: #357abd;
}

/* Spinner */
.spinner {
    width: 40px;
    height: 40px;
    border: 3px solid #e9ecef;
    border-top-color: #4a90e2;
    border-radius: 50%;
    animation: spin 0.8s linear infinite;
}

@keyframes spin {
    to { transform: rotate(360deg); }
}

/* HR Divider */
hr {
    border: none;
    border-top: 1px solid #e9ecef;
    margin: 0;
}

/* Responsive */
@media (max-width: 768px) {
    .modal-dialog {
        max-width: 95% !important;
        margin: 1rem auto;
    }
    
    .modal-header-gradient {
        padding: 1.25rem 1.5rem;
    }
    
    .modal-title-gradient {
        font-size: 1.25rem;
    }
    
    .section-header,
    .section-content,
    .modal-footer-modern {
        padding: 1rem 1.5rem;
    }
    
    .settings-grid {
        grid-template-columns: 1fr;
    }
}
</style>

<?php
$extra_scripts = [
    '../assets/js/customer/campaigns.js'
];

include('../includes/customer/footer.php');
?>
