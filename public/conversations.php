<?php
/**
 * Conversations - Customer Chat History
 * แสดงประวัติการสนทนาทั้งหมดพร้อมข้อมูลลูกค้า
 */
define('INCLUDE_CHECK', true);

$page_title = "ประวัติการสนทนา - AI Automation";
$current_page = "conversations";

include('../includes/customer/header.php');
include('../includes/customer/sidebar.php');
?>

<!-- Main Content -->
<main class="main-content">
    <!-- Page Header -->
    <div class="page-header">
        <div>
            <h1 class="page-title">💬 ประวัติการสนทนา</h1>
            <p class="page-subtitle">ดูประวัติการแชทกับลูกค้าทั้งหมด</p>
        </div>
        <div class="keyboard-hint">
            <small>
                <kbd>Ctrl</kbd> + <kbd>K</kbd> ค้นหา | 
                <kbd>←</kbd> <kbd>→</kbd> เปลี่ยนหน้า | 
                <kbd>ESC</kbd> ปิด
            </small>
        </div>
    </div>

    <!-- Search and Filter -->
    <div class="card">
        <div class="card-body" style="padding: 1rem;">
            <div class="search-filter-row">
                <div class="search-box">
                    <i class="fas fa-search search-icon"></i>
                    <input 
                        type="search" 
                        id="conversationSearch" 
                        class="search-input" 
                        placeholder="ค้นหาชื่อลูกค้า, เบอร์โทร, หรือข้อความ..."
                        aria-label="ค้นหาการสนทนา"
                    >
                </div>
                <div class="filter-buttons">
                    <button class="filter-btn active" data-status-filter="all">
                        📋 ทั้งหมด
                    </button>
                    <button class="filter-btn" data-status-filter="active">
                        💬 กำลังสนทนา
                    </button>
                    <button class="filter-btn" data-status-filter="ended">
                        ✓ สิ้นสุดแล้ว
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Conversations List -->
    <div class="card mt-4">
        <div class="card-header">
            <h3 class="card-title">รายการสนทนา</h3>
        </div>
        <div class="card-body">
            <div id="conversationsContainer" class="conversations-list">
                <!-- Loading -->
                <div class="loading-state">
                    <div class="spinner"></div>
                    <p>กำลังโหลดข้อมูล...</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Pagination -->
    <div id="conversationPagination" class="pagination-container" style="display: none;">
        <!-- Rendered by JavaScript -->
    </div>
</main>

<!-- Conversation Details Modal -->
<div id="conversationModal" class="conversation-detail-modal" style="display: none;">
    <div class="conversation-modal-overlay" onclick="closeConversationModal()"></div>
    <div class="conversation-modal-dialog">
        <div class="conversation-modal-header">
            <h2 class="conversation-modal-title">💬 รายละเอียดการสนทนา</h2>
            <button class="conversation-modal-close" onclick="closeConversationModal()" aria-label="Close">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                    <path d="M18 6L6 18M6 6l12 12" stroke-width="2" stroke-linecap="round"/>
                </svg>
            </button>
        </div>
        <div class="conversation-modal-body" id="conversationDetailsContent">
            <!-- Content will be loaded here -->
        </div>
    </div>
</div>

<style>
/* Conversations List */
.conversations-list {
    display: flex;
    flex-direction: column;
    gap: 1rem;
}

.conversation-card {
    background: var(--color-card);
    border: 1px solid var(--color-border);
    border-radius: 12px;
    padding: 1.25rem;
    cursor: pointer;
    transition: all 0.2s ease;
    display: flex;
    gap: 1rem;
}

.conversation-card:hover {
    border-color: var(--color-primary);
    transform: translateX(4px);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
}

.conversation-avatar {
    flex-shrink: 0;
}

.conversation-avatar img {
    width: 56px;
    height: 56px;
    border-radius: 50%;
    border: 2px solid var(--color-border);
    object-fit: cover;
    background: white;
}

.conversation-avatar-placeholder {
    width: 56px;
    height: 56px;
    border-radius: 50%;
    background: linear-gradient(135deg, var(--color-primary), var(--color-secondary));
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.5rem;
    font-weight: 700;
    color: white;
}

.conversation-content {
    flex: 1;
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
}

.conversation-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.conversation-name {
    font-size: 1.1rem;
    font-weight: 700;
    color: var(--color-text);
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.conversation-platform {
    display: inline-flex;
    align-items: center;
    gap: 0.25rem;
    padding: 0.25rem 0.625rem;
    background: #06C755;
    color: white;
    border-radius: 12px;
    font-size: 0.75rem;
    font-weight: 600;
}

.conversation-time {
    font-size: 0.85rem;
    color: var(--color-gray);
}

.conversation-last-message {
    font-size: 0.9rem;
    color: var(--color-gray);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.conversation-meta {
    display: flex;
    gap: 1rem;
    align-items: center;
}

.conversation-status {
    padding: 0.25rem 0.75rem;
    border-radius: 12px;
    font-size: 0.75rem;
    font-weight: 600;
}

.status-active {
    background: #e8f5e9;
    color: #2e7d32;
}

.status-ended {
    background: #e0e0e0;
    color: #616161;
}

/* Loading State */
.loading-state {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 3rem;
    gap: 1rem;
}

.spinner {
    width: 40px;
    height: 40px;
    border: 4px solid var(--color-border);
    border-top-color: var(--color-primary);
    border-radius: 50%;
    animation: spin 0.8s linear infinite;
}

@keyframes spin {
    to { transform: rotate(360deg); }
}

.bubble-time {
    font-size: 0.7rem;
    color: var(--color-gray);
    padding: 0 0.5rem;
    text-align: right;
}

/* Search and Filter Row */
.search-filter-row {
    display: flex;
    gap: 1rem;
    flex-wrap: wrap;
    align-items: center;
}

.search-box {
    position: relative;
    flex: 1;
    min-width: 300px;
}

.search-icon {
    position: absolute;
    left: 1rem;
    top: 50%;
    transform: translateY(-50%);
    color: var(--color-gray);
    pointer-events: none;
}

.search-input {
    width: 100%;
    padding: 0.75rem 1rem 0.75rem 2.75rem;
    border: 2px solid var(--color-border);
    border-radius: 12px;
    font-size: 0.95rem;
    transition: all 0.2s ease;
}

.search-input:focus {
    outline: none;
    border-color: var(--color-primary);
    box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1);
}

.filter-buttons {
    display: flex;
    gap: 0.5rem;
}

.filter-btn {
    padding: 0.75rem 1.25rem;
    border: 2px solid var(--color-border);
    background: var(--color-card);
    border-radius: 12px;
    font-size: 0.9rem;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.2s ease;
    white-space: nowrap;
}

.filter-btn:hover {
    border-color: var(--color-primary);
    background: var(--color-background);
}

.filter-btn.active {
    border-color: var(--color-primary);
    background: linear-gradient(135deg, var(--color-primary), var(--color-secondary));
    color: white;
    box-shadow: 0 4px 12px rgba(99, 102, 241, 0.3);
}

.keyboard-hint {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    color: var(--color-gray);
    font-size: 0.85rem;
}

.keyboard-hint kbd {
    padding: 0.25rem 0.5rem;
    background: var(--color-background);
    border: 1px solid var(--color-border);
    border-radius: 4px;
    font-family: monospace;
    font-size: 0.8rem;
    box-shadow: 0 2px 4px rgba(0,0,0,0.05);
}

/* Pagination Container */
.pagination-container {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 1.5rem;
    background: var(--color-card);
    border-radius: 12px;
    margin-top: 1rem;
    flex-wrap: wrap;
    gap: 1rem;
}

.pagination-info {
    color: var(--color-gray);
    font-size: 0.9rem;
}

.pagination-controls {
    display: flex;
    gap: 0.5rem;
    align-items: center;
}

.btn-pagination {
    width: 36px;
    height: 36px;
    border: 1px solid var(--color-border);
    background: var(--color-card);
    border-radius: 8px;
    cursor: pointer;
    transition: all 0.2s ease;
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--color-text);
}

.btn-pagination:hover:not(:disabled) {
    border-color: var(--color-primary);
    background: var(--color-primary);
    color: white;
    transform: translateY(-2px);
}

.btn-pagination:disabled {
    opacity: 0.3;
    cursor: not-allowed;
}

.page-indicator {
    padding: 0 1rem;
    font-weight: 600;
    color: var(--color-text);
}

/* Empty State */
.empty-state {
    text-align: center;
    padding: 4rem 2rem;
}

.empty-icon {
    font-size: 4rem;
    margin-bottom: 1rem;
    opacity: 0.5;
}

.empty-title {
    font-size: 1.25rem;
    font-weight: 600;
    color: var(--color-gray);
    margin-bottom: 1rem;
}

/* Error State */
.error-state {
    text-align: center;
    padding: 4rem 2rem;
}

.error-icon {
    font-size: 4rem;
    margin-bottom: 1rem;
}

.error-title {
    font-size: 1.5rem;
    font-weight: 700;
    color: var(--color-danger);
    margin-bottom: 0.5rem;
}

.error-details {
    color: var(--color-gray);
    margin-bottom: 1.5rem;
}

.error-state-small {
    text-align: center;
    padding: 2rem 1rem;
}

/* Responsive */
@media (max-width: 768px) {
    .search-filter-row {
        flex-direction: column;
    }
    
    .search-box {
        min-width: 100%;
    }
    
    .filter-buttons {
        width: 100%;
        overflow-x: auto;
    }
    
    .keyboard-hint {
        display: none;
    }
    
    .pagination-container {
        flex-direction: column;
        text-align: center;
    }
}

/* Conversation card keyboard focus */
.conversation-card:focus {
    outline: 2px solid var(--color-primary);
    outline-offset: 2px;
}

/* Conversations: dedicated modal styles (avoid clashing with global .payment-modal) */
.conversation-detail-modal {
    position: fixed;
    inset: 0;
    z-index: 9999;
    display: none; /* toggled by JS */
    align-items: center;
    justify-content: center;
    padding: 1rem;
}

.conversation-modal-overlay {
    position: absolute;
    inset: 0;
    background: rgba(0, 0, 0, 0.55);
}

.conversation-modal-dialog {
    position: relative;
    width: min(980px, 96vw);
    max-height: 90vh;
    background: #fff;
    border-radius: 12px;
    box-shadow: 0 24px 64px rgba(0, 0, 0, 0.25);
    overflow: hidden;
    display: flex;
    flex-direction: column;
}

.conversation-modal-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    padding: 1rem 1.25rem;
    border-bottom: 1px solid var(--color-border);
    background: #fff;
}

.conversation-modal-title {
    margin: 0;
    font-size: 1.1rem;
    font-weight: 700;
    color: var(--color-text);
}

.conversation-modal-close {
    border: none;
    background: transparent;
    cursor: pointer;
    color: var(--color-gray);
    padding: .25rem;
    line-height: 0;
}

.conversation-modal-body {
    padding: 1rem 1.25rem;
    overflow: auto;
}

@media (max-width: 768px) {
    .conversation-modal-dialog {
        width: 100%;
        max-height: 92vh;
    }
    .conversation-modal-body {
        padding: 1rem;
    }
}
</style>

<?php
$extra_scripts = [
    'assets/js/conversations.js'
];

include('../includes/customer/footer.php');
?>
