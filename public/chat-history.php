<?php
/**
 * Chat History - Customer Portal
 */
define('INCLUDE_CHECK', true);

$page_title = "ประวัติการสนทนา - AI Automation";
$current_page = "chat_history";

include('../includes/customer/header.php');
include('../includes/customer/sidebar.php');
?>

<main class="main-content">
    <div class="page-header">
        <div>
            <h1 class="page-title">💬 ประวัติการสนทนา</h1>
            <p class="page-subtitle">ดูประวัติการสนทนากับ Chatbot ของคุณ</p>
        </div>
    </div>

    <!-- Statistics Cards -->
    <div class="row">
        <div class="col-3">
            <div class="stat-card">
                <div class="stat-icon primary">💬</div>
                <div class="stat-content">
                    <div class="stat-label">การสนทนาทั้งหมด</div>
                    <div class="stat-value" id="totalConversations">-</div>
                </div>
            </div>
        </div>
        <div class="col-3">
            <div class="stat-card">
                <div class="stat-icon secondary">📨</div>
                <div class="stat-content">
                    <div class="stat-label">ข้อความทั้งหมด</div>
                    <div class="stat-value" id="totalMessages">-</div>
                </div>
            </div>
        </div>
        <div class="col-3">
            <div class="stat-card">
                <div class="stat-icon info">🟢</div>
                <div class="stat-content">
                    <div class="stat-label">LINE</div>
                    <div class="stat-value" id="lineCount">-</div>
                </div>
            </div>
        </div>
        <div class="col-3">
            <div class="stat-card">
                <div class="stat-icon success">🔵</div>
                <div class="stat-content">
                    <div class="stat-label">Facebook</div>
                    <div class="stat-value" id="facebookCount">-</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="card mt-4">
        <div class="card-body">
            <div class="row">
                <div class="col-4">
                    <label>แพลตฟอร์ม:</label>
                    <select id="platformFilter" class="form-control">
                        <option value="">ทั้งหมด</option>
                        <option value="line">LINE</option>
                        <option value="facebook">Facebook</option>
                    </select>
                </div>
                <div class="col-4">
                    <label>สถานะ:</label>
                    <select id="statusFilter" class="form-control">
                        <option value="">ทั้งหมด</option>
                        <option value="active">กำลังดำเนินการ</option>
                        <option value="ended">สิ้นสุด</option>
                    </select>
                </div>
                <div class="col-4">
                    <label>&nbsp;</label>
                    <button class="btn btn-primary" onclick="applyFilters()" style="width:100%;">
                        🔍 ค้นหา
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Conversations List -->
    <div class="card mt-4">
        <div class="card-header">
            <h3 class="card-title">รายการการสนทนา</h3>
        </div>
        <div class="card-body">
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>แพลตฟอร์ม</th>
                            <th>เริ่มเมื่อ</th>
                            <th>ข้อความล่าสุด</th>
                            <th>จำนวนข้อความ</th>
                            <th>สถานะ</th>
                            <th>สรุป</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody id="conversationsTableBody">
                        <tr>
                            <td colspan="7" style="text-align:center;padding:2rem;">
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

<!-- Messages Modal -->
<div id="messagesModal" class="modal modal-ui" data-ui="modal" style="display:none;">
    <div class="modal-overlay" onclick="closeMessagesModal()"></div>
    <div class="modal-dialog" style="max-width:900px;">
        <div class="modal-content-wrapper">
            <div class="modal-header-custom">
                <h2 class="modal-title-custom">รายละเอียดการสนทนา</h2>
                <button class="modal-close-custom" onclick="closeMessagesModal()">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                        <path d="M18 6L6 18M6 6l12 12" stroke-width="2" stroke-linecap="round"/>
                    </svg>
                </button>
            </div>
            <div class="modal-body-custom">
                <div id="conversationInfo" style="background:var(--color-background);padding:1rem;border-radius:8px;margin-bottom:1rem;"></div>
                <div id="messagesContainer" style="max-height:500px;overflow-y:auto;border:1px solid var(--color-border);border-radius:8px;padding:1rem;">
                    <p style="text-align:center;color:var(--color-gray);">กำลังโหลด...</p>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
/* Keep only local non-modal styles; modal styling is handled by assets/css/modal-fixes.css */
.spinner {
    width: 48px;
    height: 48px;
    border: 4px solid var(--color-border);
    border-top-color: var(--color-primary);
    border-radius: 50%;
    animation: spin 0.8s linear infinite;
}

@keyframes spin {
    to { transform: rotate(360deg); }
}

.message-bubble {
    margin-bottom: 1rem;
    padding: 0.75rem 1rem;
    border-radius: 12px;
    max-width: 70%;
}

.message-incoming {
    background: var(--color-background);
    margin-right: auto;
}

.message-outgoing {
    background: linear-gradient(135deg, var(--color-primary), var(--color-secondary));
    color: white;
    margin-left: auto;
}

.message-meta {
    font-size: 0.75rem;
    opacity: 0.8;
    margin-top: 0.5rem;
}

.platform-badge {
    display: inline-flex;
    align-items: center;
    padding: 0.25rem 0.75rem;
    border-radius: 12px;
    font-size: 0.75rem;
    font-weight: 600;
}

.platform-line {
    background: #10b981;
    color: white;
}

.platform-facebook {
    background: #3b82f6;
    color: white;
}

.status-badge {
    padding: 0.25rem 0.75rem;
    border-radius: 12px;
    font-size: 0.75rem;
    font-weight: 600;
}

.status-active {
    background: #10b981;
    color: white;
}

.status-ended {
    background: #6b7280;
    color: white;
}
</style>

<?php
$extra_scripts = [
    'assets/js/chat-history.js'
];

include('../includes/customer/footer.php');
?>
