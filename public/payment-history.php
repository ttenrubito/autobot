<?php
/**
 * Payment History - Customer Portal
 */
define('INCLUDE_CHECK', true);

$page_title = "ประวัติการชำระเงิน - AI Automation";
$current_page = "payment_history";

include('../includes/customer/header.php');
include('../includes/customer/sidebar.php');
?>

<!-- Main Content -->
<main class="main-content">
    <!-- Page Header -->
    <div class="page-header">
        <div>
            <h1 class="page-title">💰 ประวัติการชำระเงิน</h1>
            <p class="page-subtitle">ดูรายการชำระเงินและสลิปการโอน</p>
        </div>
        <div class="keyboard-hint">
            <small>
                <kbd>Ctrl</kbd> + <kbd>K</kbd> ค้นหา | 
                <kbd>←</kbd> <kbd>→</kbd> เปลี่ยนหน้า | 
                <kbd>ESC</kbd> ปิด
            </small>
        </div>
    </div>

    <!-- Search Bar -->
    <div class="card">
        <div class="card-body" style="padding: 1rem;">
            <div class="search-box">
                <i class="fas fa-search search-icon"></i>
                <input 
                    type="search" 
                    id="paymentSearch" 
                    class="search-input" 
                    placeholder="ค้นหาเลขที่ชำระเงิน, เลขคำสั่งซื้อ, หรือจำนวนเงิน..."
                    aria-label="ค้นหาการชำระเงิน"
                >
            </div>
        </div>
    </div>

    <!-- Filter Tabs & Date Range -->
    <div class="card mt-3">
        <div class="card-body" style="padding: 1rem;">
            <div class="filter-tabs">
                <button class="filter-tab active" onclick="filterPayments('', event)" data-filter="">
                    <span class="tab-icon">📋</span>
                    <span class="tab-label">ทั้งหมด</span>
                </button>
                <button class="filter-tab" onclick="filterPayments('full', event)" data-filter="full">
                    <span class="tab-icon">💳</span>
                    <span class="tab-label">จ่ายเต็ม</span>
                </button>
                <button class="filter-tab" onclick="filterPayments('installment', event)" data-filter="installment">
                    <span class="tab-icon">📅</span>
                    <span class="tab-label">ผ่อนชำระ</span>
                </button>
                <button class="filter-tab" onclick="filterPayments('pending', event)" data-filter="pending">
                    <span class="tab-icon">⏳</span>
                    <span class="tab-label">รอตรวจสอบ</span>
                </button>
            </div>
        </div>
    </div>

    <!-- Date Range Filter -->
    <div class="card mt-3">
        <div class="card-body" style="padding: 1rem;">
            <div class="date-filter-container">
                <div class="date-filter-header">
                    <span class="date-filter-icon">📅</span>
                    <span class="date-filter-label">กรองตามวันที่</span>
                </div>
                <div class="date-filter-inputs">
                    <div class="date-input-group">
                        <label for="startDate" class="date-label">วันเริ่มต้น</label>
                        <input 
                            type="date" 
                            id="startDate" 
                            class="date-input"
                            aria-label="วันเริ่มต้น"
                        >
                    </div>
                    <div class="date-separator">ถึง</div>
                    <div class="date-input-group">
                        <label for="endDate" class="date-label">วันสิ้นสุด</label>
                        <input 
                            type="date" 
                            id="endDate" 
                            class="date-input"
                            aria-label="วันสิ้นสุด"
                        >
                    </div>
                    <button class="btn-filter-date" onclick="applyDateFilter()" title="กรองตามวันที่">
                        <i class="fas fa-filter"></i> กรอง
                    </button>
                    <button class="btn-clear-date" onclick="clearDateFilter()" title="ล้างการกรอง">
                        <i class="fas fa-times"></i> ล้าง
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Payments List -->
    <div class="card mt-4">
        <div class="card-header">
            <h3 class="card-title">รายการชำระเงิน</h3>
        </div>
        <div class="card-body">
            <div id="paymentsContainer" class="payments-grid">
                <!-- Loading -->
                <div class="loading-state">
                    <div class="spinner"></div>
                    <p>กำลังโหลดข้อมูล...</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Pagination -->
    <div id="paymentPagination" class="pagination-container" style="display: none;">
        <!-- Rendered by JavaScript -->
    </div>
</main>

<!-- Payment Details Modal (Outside of main-content) -->
<div id="paymentModal" class="payment-detail-modal" style="display: none;">
    <div class="payment-modal-overlay" onclick="closePaymentModal()"></div>
    <div class="payment-modal-dialog">
        <div class="payment-modal-header">
            <h2 class="payment-modal-title">🔍 ตรวจสอบการชำระเงิน</h2>
            <button class="payment-modal-close" onclick="closePaymentModal()" aria-label="Close">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                    <path d="M18 6L6 18M6 6l12 12" stroke-width="2" stroke-linecap="round"/>
                </svg>
            </button>
        </div>
        <div class="payment-modal-body" id="paymentDetailsContent">
            <!-- Content will be loaded here -->
        </div>
    </div>
</div>

<!-- Toast Notification -->
<div id="toast" class="toast"></div>

<style>
/* Filter Tabs */
.filter-tabs {
    display: flex;
    gap: 0.75rem;
    flex-wrap: wrap;
}

.filter-tab {
    flex: 1;
    min-width: 140px;
    padding: 0.875rem 1.25rem;
    border: 2px solid #e5e7eb; /* Subtle gray border */
    background: #ffffff; /* White background */
    border-radius: 12px;
    cursor: pointer;
    transition: all 0.2s ease;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
    font-weight: 500;
    color: #4b5563; /* Medium gray text */
}

.filter-tab:hover {
    border-color: #d1d5db; /* Slightly darker gray */
    background: #f9fafb; /* Very light gray */
    transform: translateY(-2px);
}

.filter-tab.active {
    border-color: #6b7280; /* Darker gray for active */
    background: #374151; /* Dark gray background */
    color: white;
    box-shadow: 0 2px 8px rgba(55, 65, 81, 0.2);
}

.tab-icon {
    font-size: 1.25rem;
}

.tab-label {
    font-size: 0.9rem;
}

/* Date Range Filter */
.date-filter-container {
    display: flex;
    flex-direction: column;
    gap: 1rem;
}

.date-filter-header {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-weight: 600;
    font-size: 1rem;
    color: #1f2937; /* Professional dark gray */
}

.date-filter-icon {
    font-size: 1.25rem;
}

.date-filter-inputs {
    display: flex;
    align-items: flex-end;
    gap: 1rem;
    flex-wrap: wrap;
}

.date-input-group {
    display: flex;
    flex-direction: column;
    gap: 0.375rem;
    flex: 1;
    min-width: 180px;
}

.date-label {
    font-size: 0.8rem;
    font-weight: 500;
    color: #6b7280; /* Consistent gray */
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.date-input {
    padding: 0.75rem 1rem;
    border: 2px solid #e5e7eb; /* Subtle border */
    border-radius: 10px;
    font-size: 1rem;
    font-family: inherit;
    background: #ffffff; /* White */
    color: #111827; /* Dark text */
    transition: all 0.2s ease;
    cursor: pointer;
}

.date-input:hover {
    border-color: #d1d5db; /* Slightly darker gray */
}

.date-input:focus {
    outline: none;
    border-color: #9ca3af; /* Medium gray focus */
    box-shadow: 0 0 0 3px rgba(156, 163, 175, 0.1);
}

.date-separator {
    font-weight: 500;
    color: #6b7280; /* Consistent gray */
    padding-bottom: 0.75rem;
    white-space: nowrap;
}

.btn-filter-date,
.btn-clear-date {
    padding: 0.75rem 1.5rem;
    border: 2px solid transparent;
    border-radius: 10px;
    font-size: 0.95rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s ease;
    display: flex;
    align-items: center;
    gap: 0.5rem;
    white-space: nowrap;
}

.btn-filter-date {
    background: #374151; /* Dark gray - professional */
    color: white;
    box-shadow: 0 2px 8px rgba(55, 65, 81, 0.2);
}

.btn-filter-date:hover {
    background: #1f2937; /* Darker gray on hover */
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(55, 65, 81, 0.25);
}

.btn-clear-date {
    background: #ffffff; /* White */
    color: #6b7280; /* Gray text */
    border-color: #e5e7eb; /* Subtle border */
}

.btn-clear-date:hover {
    background: #f9fafb; /* Light gray */
    border-color: #d1d5db; /* Darker border */
    color: #374151; /* Darker text */
    transform: translateY(-2px);
}

@media (max-width: 768px) {
    .date-filter-inputs {
        flex-direction: column;
        align-items: stretch;
    }
    
    .date-input-group {
        min-width: 100%;
    }
    
    .date-separator {
        text-align: center;
        padding-bottom: 0;
    }
    
    .btn-filter-date,
    .btn-clear-date {
        justify-content: center;
    }
}

/* Payments Grid */
.payments-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
    gap: 1.5rem;
}

.payment-card {
    background: var(--color-card);
    border: 1px solid var(--color-border);
    border-radius: 16px;
    padding: 1.5rem;
    transition: all 0.3s ease;
    cursor: pointer;
    position: relative;
    overflow: hidden;
}

.payment-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    width: 4px;
    height: 100%;
    background: var(--color-primary);
    opacity: 0;
    transition: opacity 0.3s ease;
}

.payment-card:hover::before {
    opacity: 1;
}

.payment-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 12px 28px rgba(0,0,0,0.12);
    border-color: var(--color-primary);
}

.payment-header {
    display: flex;
    justify-content: space-between;
    align-items: start;
    margin-bottom: 1rem;
}

.payment-no {
    font-weight: 700;
    font-size: 1.1rem;
    color: var(--color-text);
}

.payment-status {
    padding: 0.375rem 0.875rem;
    border-radius: 20px;
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.status-verified {
    background: #10b981;
    color: white;
}

.status-pending {
    background: #f59e0b;
    color: white;
}

.status-rejected {
    background: #ef4444;
    color: white;
}

.payment-amount {
    font-size: 1.75rem;
    font-weight: 700;
    color: var(--color-primary);
    margin: 0.75rem 0;
}

.payment-details {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
    font-size: 0.875rem;
    color: var(--color-gray);
}

.payment-detail-row {
    display: flex;
    justify-content: space-between;
}

.payment-type-badge {
    display: inline-flex;
    align-items: center;
    gap: 0.375rem;
    padding: 0.25rem 0.75rem;
    background: var(--color-background);
    border-radius: 12px;
    font-size: 0.8rem;
    font-weight: 500;
    margin-top: 0.75rem;
}

/* Loading State */
.loading-state {
    grid-column: 1 / -1;
    text-align: center;
    padding: 3rem;
}

.spinner {
    width: 48px;
    height: 48px;
    border: 4px solid var(--color-border);
    border-top-color: var(--color-primary);
    border-radius: 50%;
    animation: spin 0.8s linear infinite;
    margin: 0 auto 1rem;
}

@keyframes spin {
    to { transform: rotate(360deg); }
}

/* Professional Payment Modal - PERFECTLY CENTERED */
.payment-modal {
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    position: fixed !important;
    inset: 0 !important; /* top: 0, right: 0, bottom: 0, left: 0 */
    z-index: 9999 !important;
    margin: 0 !important;
    padding: 0 !important;
}

.payment-modal-overlay {
    position: fixed !important;
    inset: 0 !important;
    background: rgba(0, 0, 0, 0.8);
    backdrop-filter: blur(10px);
    -webkit-backdrop-filter: blur(10px);
    z-index: 9998;
}

.payment-modal-dialog {
    position: relative !important;
    background: var(--color-card, #ffffff);
    border-radius: 0; /* Full screen on mobile */
    box-shadow: 0 24px 48px rgba(0, 0, 0, 0.4);
    width: 100vw; /* Full width on mobile */
    height: 100vh; /* Full height on mobile */
    max-width: 100vw;
    max-height: 100vh;
    display: flex;
    flex-direction: column;
    overflow: hidden;
    z-index: 9999;
    animation: modalSlideUp 0.3s cubic-bezier(0.16, 1, 0.3, 1);
    margin: 0 !important; /* Remove any margin */
}

@media (min-width: 768px) {
    .payment-modal-dialog {
        border-radius: 20px;
        width: 90vw !important;
        max-width: 900px !important;
        height: auto !important;
        max-height: 90vh !important;
        min-width: 600px;
    }
}

@keyframes modalSlideUp {
    from {
        opacity: 0;
        transform: translateY(50px) scale(0.95);
    }
    to {
        opacity: 1;
        transform: translateY(0) scale(1);
    }
}

.payment-modal-header {
    padding: 1.5rem 2rem;
    border-bottom: 1px solid #e5e7eb;
    display: flex;
    align-items: center;
    justify-content: space-between;
    background: #ffffff; /* Clean white background */
    flex-shrink: 0; /* Prevent header from shrinking */
}

.payment-modal-title {
    font-size: 1.5rem;
    font-weight: 700;
    color: #1f2937; /* Professional dark gray */
    margin: 0;
}

.payment-modal-close {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    background: #f3f4f6; /* Subtle gray background */
    border: none;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.2s ease;
    color: #6b7280; /* Medium gray icon */
}

.payment-modal-close:hover {
    background: #e5e7eb; /* Darker gray on hover */
    color: #374151; /* Darker icon on hover */
    transform: rotate(90deg);
}

.payment-modal-body {
    padding: 1rem;
    overflow-y: auto;
    flex: 1;
    background: #f9fafb; /* Subtle light gray background */
    -webkit-overflow-scrolling: touch; /* Smooth scrolling on iOS */
}

@media (min-width: 768px) {
    .payment-modal-body {
        padding: 2rem;
    }
}

/* Scrollbar styling for modal body */
.payment-modal-body::-webkit-scrollbar {
    width: 8px;
}

.payment-modal-body::-webkit-scrollbar-track {
    background: rgba(0, 0, 0, 0.05);
    border-radius: 4px;
}

.payment-modal-body::-webkit-scrollbar-thumb {
    background: rgba(0, 0, 0, 0.15); /* Slightly darker for visibility */
    border-radius: 4px;
}

.payment-modal-body::-webkit-scrollbar-thumb:hover {
    background: rgba(0, 0, 0, 0.25);
}

/* Single-Column Mobile-First Layout - LIKE CHAT APP */
.slip-chat-layout {
    display: flex;
    flex-direction: column;
    gap: 1.5rem;
    width: 100%;
    max-width: 800px; /* Comfortable reading width like mobile chat */
    margin: 0 auto;
}

.slip-chat-left,
.slip-chat-right {
    display: flex;
    flex-direction: column;
    gap: 1.5rem;
    width: 100%;
}

/* Detail Section */
.detail-section {
    background: #ffffff; /* Clean white cards */
    border-radius: 16px;
    padding: 1.5rem;
    border: 1px solid #e5e7eb; /* Subtle border */
    width: 100%;
    max-width: 100%;
    box-sizing: border-box;
    overflow: hidden;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05); /* Very subtle shadow */
}

.detail-section h3 {
    font-size: 1.1rem;
    font-weight: 700;
    margin: 0 0 1rem 0;
    color: #1f2937; /* Professional dark gray */
    word-wrap: break-word;
}

.detail-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 1rem;
    width: 100%;
}

@media (max-width: 576px) {
    .detail-grid {
        grid-template-columns: 1fr;
    }
}

.detail-item {
    display: flex;
    flex-direction: column;
    gap: 0.25rem;
    min-width: 0;
}

.detail-label {
    font-size: 0.8rem;
    color: #6b7280; /* Medium gray for labels */
    font-weight: 500;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.detail-value {
    font-size: 1rem;
    color: #111827; /* Almost black for values - maximum readability */
    font-weight: 600;
    word-wrap: break-word;
    overflow-wrap: break-word;
}

/* Customer Profile Card - Keep LINE green ONLY for customer profile */
.customer-profile-card {
    background: linear-gradient(135deg, #06C755 0%, #00B900 100%); /* LINE green gradient */
    color: white;
    border: none !important;
    box-shadow: 0 4px 12px rgba(6, 199, 85, 0.2); /* Green shadow for depth */
}

.profile-header {
    display: flex;
    align-items: center;
    gap: 1.25rem;
}

.profile-avatar {
    flex-shrink: 0;
}

.profile-avatar img {
    width: 72px;
    height: 72px;
    border-radius: 50%;
    border: 3px solid rgba(255, 255, 255, 0.3);
    object-fit: cover;
    background: white;
}

.profile-avatar-placeholder {
    width: 72px;
    height: 72px;
    border-radius: 50%;
    background: rgba(255, 255, 255, 0.25);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 2rem;
    font-weight: 700;
    color: white;
    border: 3px solid rgba(255, 255, 255, 0.3);
}

.profile-info {
    flex: 1;
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
}

.profile-name {
    font-size: 1.5rem;
    font-weight: 700;
    margin: 0;
    color: white;
    text-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.profile-phone {
    font-size: 0.95rem;
    color: rgba(255, 255, 255, 0.95);
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.profile-platform {
    display: flex;
    gap: 0.5rem;
    margin-top: 0.25rem;
}

.platform-badge {
    display: inline-flex;
    align-items: center;
    gap: 0.375rem;
    padding: 0.375rem 0.875rem;
    background: rgba(255, 255, 255, 0.25);
    border-radius: 20px;
    font-size: 0.8rem;
    font-weight: 600;
    backdrop-filter: blur(10px);
}

.platform-badge svg {
    width: 16px;
    height: 16px;
}

.platform-line {
    color: white;
}

/* LINE-style Chat Bubbles - Keep LINE green for bot messages */
.slip-chat-box {
    background: #ffffff; /* White background instead of gray */
    border: 1px solid #e5e7eb; /* Subtle border */
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
}

.slip-chat-bubbles {
    display: flex;
    flex-direction: column;
    gap: 1rem;
}

.bubble {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
    max-width: 80%;
    animation: slideIn 0.3s ease-out;
}

@keyframes slideIn {
    from {
        opacity: 0;
        transform: translateY(10px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.bubble-bot {
    align-self: flex-start;
}

.bubble-user {
    align-self: flex-end;
}

.bubble-label {
    font-size: 0.75rem;
    color: #6b7280; /* Consistent gray */
    font-weight: 600;
    padding: 0 0.5rem;
}

.bubble-text {
    padding: 1rem 1.25rem;
    border-radius: 18px;
    font-size: 0.95rem;
    line-height: 1.6;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
    position: relative;
}

.bubble-bot .bubble-text {
    background: #06c755; /* LINE green for bot - THE ONLY GREEN ACCENT */
    color: white;
    border-bottom-left-radius: 4px;
}

.bubble-user .bubble-text {
    background: #f3f4f6; /* Light gray instead of white */
    color: #111827; /* Dark text */
    border: 1px solid #e5e7eb;
    border-bottom-right-radius: 4px;
}

/* Slip Image Container - Clean professional style */
.slip-image-container {
    position: relative;
    border-radius: 12px;
    overflow: hidden;
    background: #ffffff; /* Clean white */
    border: 2px solid #e5e7eb; /* Subtle border */
    padding: 1.5rem;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05); /* Subtle shadow */
    transition: all 0.3s ease;
}

.slip-image-container:hover {
    box-shadow: 0 4px 16px rgba(0, 0, 0, 0.08); /* Slightly stronger on hover */
    border-color: #d1d5db; /* Slightly darker gray */
}

.slip-image {
    width: 100%;
    height: auto;
    max-height: 600px;
    object-fit: contain;
    border-radius: 8px;
    cursor: zoom-in;
    transition: transform 0.3s ease;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
    background: white;
    display: block;
}

.slip-image:hover {
    transform: scale(1.02);
}

.slip-image.zoomed {
    position: fixed;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%) !important;
    z-index: 99999;
    max-width: 90vw;
    max-height: 90vh;
    width: auto;
    height: auto;
    cursor: zoom-out;
    box-shadow: 0 24px 48px rgba(0, 0, 0, 0.4);
    border-radius: 12px;
}

/* Zoom backdrop (pseudo-elements on <img> are unreliable) */
.slip-zoom-backdrop{
    position: fixed;
    inset: 0;
    background: rgba(0, 0, 0, 0.85);
    backdrop-filter: blur(8px);
    -webkit-backdrop-filter: blur(8px);
    z-index: 99998;
    animation: fadeIn 0.3s ease-out;
}

/* Remove old pseudo backdrop */
.slip-image.zoomed::after {
    content: none !important;
}

/* Backdrop overlay when image is zoomed */
.slip-caption {
    margin-top: 0.75rem;
    font-size: 0.875rem;
    color: #6b7280; /* Consistent gray */
    text-align: center;
    line-height: 1.5;
    font-style: italic;
}

/* Approve Panel - Clean minimal style */
.slip-approve-panel {
    background: #ffffff; /* Clean white */
    border: 2px solid #e5e7eb; /* Subtle gray border */
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
}

.slip-approve-panel h3 {
    color: #1f2937; /* Professional dark gray */
}

.slip-approve-panel .hint {
    font-size: 0.9rem;
    color: #4b5563; /* Medium gray for text */
    margin-bottom: 1rem;
    padding: 0.75rem;
    background: #f9fafb; /* Very light gray background */
    border-radius: 8px;
    border-left: 3px solid #9ca3af; /* Gray accent */
}

.action-row {
    display: flex;
    gap: 1rem;
    margin-top: 1rem;
}

.btn {
    flex: 1;
    padding: 0.875rem 1.5rem;
    border: none;
    border-radius: 12px;
    font-weight: 600;
    font-size: 1rem;
    cursor: pointer;
    transition: all 0.2s ease;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
}

.btn:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.btn-success {
    background: #10b981; /* Solid green - no gradient */
    color: white;
    box-shadow: 0 2px 8px rgba(16, 185, 129, 0.2); /* Subtle shadow */
}

.btn-success:hover:not(:disabled) {
    background: #059669; /* Darker green on hover */
    transform: translateY(-1px); /* Subtle lift */
    box-shadow: 0 4px 12px rgba(16, 185, 129, 0.25);
}

.btn-danger {
    background: #ef4444; /* Solid red - no gradient */
    color: white;
    box-shadow: 0 2px 8px rgba(239, 68, 68, 0.2);
}

.btn-danger:hover:not(:disabled) {
    background: #dc2626; /* Darker red on hover */
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(239, 68, 68, 0.25);
}

/* Toast Notification - Clean minimal style */
.toast {
    position: fixed;
    bottom: 2rem;
    right: 2rem;
    background: white;
    padding: 1rem 1.5rem;
    border-radius: 12px;
    box-shadow: 0 4px 16px rgba(0, 0, 0, 0.1); /* Cleaner shadow */
    display: none;
    align-items: center;
    gap: 0.75rem;
    z-index: 10001;
    min-width: 300px;
    animation: slideUp 0.3s ease-out;
    border: 1px solid #e5e7eb; /* Add subtle border */
}

@keyframes slideUp {
    from {
        opacity: 0;
        transform: translateY(20px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.toast.show {
    display: flex;
}

.toast.success {
    border-left: 4px solid #10b981;
}

.toast.error {
    border-left: 4px solid #ef4444;
}

.toast.info {
    border-left: 4px solid #3b82f6;
}

/* Responsive Design */
@media (max-width: 768px) {
    .payment-modal-dialog {
        max-width: 100%;
        max-height: 100vh;
        border-radius: 0;
        margin: 0;
    }

    .payment-modal {
        padding: 0;
    }

    .payment-modal-header {
        padding: 1rem 1.5rem;
    }

    .payment-modal-title {
        font-size: 1.25rem;
    }

    .payment-modal-body {
        padding: 1rem;
    }

    .slip-chat-layout {
        grid-template-columns: 1fr;
        gap: 1rem;
    }

    .detail-section {
        padding: 1rem;
    }

    .detail-grid {
        grid-template-columns: 1fr;
        gap: 0.75rem;
    }

    .action-row {
        flex-direction: column;
    }

    .btn {
        width: 100%;
    }

    .filter-tabs {
        gap: 0.5rem;
    }

    .filter-tab {
        min-width: auto;
        padding: 0.75rem 1rem;
        font-size: 0.875rem;
    }

    .tab-icon {
        font-size: 1.1rem;
    }

    .payments-grid {
        grid-template-columns: 1fr;
        gap: 1rem;
    }

    .payment-card {
        padding: 1rem;
    }

    .payment-amount {
        font-size: 1.5rem;
    }
}

/* Dark mode support */
@media (prefers-color-scheme: dark) {
    .payment-modal-dialog {
        box-shadow: 0 24px 48px rgba(0, 0, 0, 0.6);
    }

    .slip-image-container {
        background: linear-gradient(135deg, #1f2937 0%, #111827 100%);
    }

    .bubble-user .bubble-text {
        background: #374151;
        color: #f3f4f6;
        border-color: #4b5563;
    }
}

/* Search Box */
.search-box {
    position: relative;
    width: 100%;
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
    grid-column: 1 / -1;
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
    grid-column: 1 / -1;
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

/* Payment card keyboard focus */
.payment-card:focus {
    outline: 2px solid var(--color-primary);
    outline-offset: 2px;
}

/* Payment History Modal (isolated from global .payment-modal in assets/css/style.css) */
.payment-detail-modal{
    display:none; /* JS toggles to flex */
    position:fixed;
    inset:0;
    z-index:9999;
    margin:0;
    padding:0;
    align-items:center;
    justify-content:center;
}
.payment-detail-modal[style*="display: flex"],
.payment-detail-modal.is-open{
    display:flex;
}

/* Keep overlay/dialog styles working under the new root class */
.payment-detail-modal .payment-modal-overlay{
    position:fixed;
    inset:0;
    background:rgba(0,0,0,0.8);
    backdrop-filter:blur(10px);
    -webkit-backdrop-filter:blur(10px);
    z-index:9998;
}

.payment-detail-modal .payment-modal-dialog{
    position:relative;
    background:var(--color-card, #fff);
    border-radius:0;
    box-shadow:0 24px 48px rgba(0,0,0,0.4);
    width:100vw;
    height:100vh;
    max-width:100vw;
    max-height:100vh;
    display:flex;
    flex-direction:column;
    overflow:hidden;
    z-index:9999;
    margin:0;
}

@media (min-width: 768px){
    .payment-detail-modal .payment-modal-dialog{
        border-radius:20px;
        width:90vw;
        max-width:900px;
        height:auto;
        max-height:90vh;
        min-width:600px;
    }
}
</style>

<?php
$extra_scripts = [
    'assets/js/payment-history.js'
];

include('../includes/customer/footer.php');
?>
