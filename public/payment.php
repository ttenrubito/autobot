<?php
/**
 * Customer Payment Page - Manual Subscription Management
 * Simplified version without Omise integration
 */
define('INCLUDE_CHECK', true);

$page_title = "แพ็กเกจและการชำระเงิน - AI Automation";
$current_page = "payment";

$extra_css = [];

include('../includes/customer/header.php');
include('../includes/customer/sidebar.php');
?>

<style>
    /* Professional Color Palette - Muted & Trustworthy */
    :root {
        --primary-blue: #2563eb;
        --primary-blue-light: #3b82f6;
        --primary-blue-dark: #1e40af;
        --accent-blue: #0ea5e9;
        --success-green: #059669;
        --warning-orange: #ea580c;
        --danger-red: #dc2626;
        --neutral-50: #f8fafc;
        --neutral-100: #f1f5f9;
        --neutral-200: #e2e8f0;
        --neutral-300: #cbd5e1;
        --neutral-700: #334155;
        --neutral-800: #1e293b;
        --neutral-900: #0f172a;
    }

    /* Custom Progress Bar - Professional Style */
    .subscription-progress {
        background: var(--neutral-200);
        height: 32px;
        border-radius: 16px;
        overflow: hidden;
        position: relative;
        box-shadow: inset 0 2px 4px rgba(0, 0, 0, 0.06);
    }

    .subscription-progress-bar {
        height: 100%;
        transition: width 0.6s cubic-bezier(0.4, 0, 0.2, 1);
        display: flex;
        align-items: center;
        padding: 0 1rem;
        color: white;
        font-weight: 600;
        font-size: 0.875rem;
        box-shadow: inset 0 1px 2px rgba(255, 255, 255, 0.2);
    }

    /* Professional Card Styling */
    .payment-card {
        background: white;
        border: 1px solid var(--neutral-200);
        border-radius: 12px;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
        transition: box-shadow 0.2s ease;
    }

    .payment-card:hover {
        box-shadow: 0 4px 6px rgba(0, 0, 0, 0.07);
    }
</style>

<main class="main-content">
    <div class="page-header">
        <div>
            <h1 class="page-title">แพ็กเกจและการชำระเงิน</h1>
            <p class="page-subtitle">ตรวจสอบแพ็กเกจปัจจุบัน วันหมดอายุ และข้อมูลการชำระเงิน</p>
        </div>
    </div>

    <!-- Subscription Status Card -->
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-body" style="padding: 2rem;">
                    <div id="subscriptionDisplay">
                        <div class="text-center card-loading">
                            <div class="loading-spinner"></div>
                            <p class="card-loading-text">กำลังโหลดข้อมูล...</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>


    <!-- Payment Instructions Card -->
    <div class="row" style="margin-top: 1.5rem;">
        <div class="col-12">
            <div class="card">
                <div class="card-header"
                    style="background: linear-gradient(135deg, #1e40af 0%, #2563eb 100%); border-bottom: none; padding: 1rem 1.5rem;">
                    <h3 class="card-title"
                        style="color: white; font-weight: 600; margin: 0; display: flex; align-items: center; gap: 0.5rem;">
                        <i class="fas fa-credit-card"></i>
                        <span>วิธีการชำระเงิน</span>
                    </h3>
                </div>
                <div class="card-body" style="padding: 2rem;">
                    <!-- Bank Transfer Section -->
                    <div
                        style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; padding: 2rem; margin-bottom: 1.5rem;">
                        <div style="display: flex; align-items: center; gap: 0.75rem; margin-bottom: 1.5rem;">
                            <div
                                style="width: 48px; height: 48px; background: linear-gradient(135deg, #2563eb, #3b82f6); border-radius: 12px; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                                <i class="fas fa-university" style="color: white; font-size: 1.25rem;"></i>
                            </div>
                            <div>
                                <h4 style="margin: 0; color: #1e293b; font-size: 1.125rem; font-weight: 600;">
                                    โอนเงินผ่านธนาคาร</h4>
                                <p style="margin: 0; color: #64748b; font-size: 0.875rem;">รองรับทุกธนาคาร
                                    ผ่านแอพธนาคารหรือ ATM</p>
                            </div>
                        </div>

                        <!-- Bank Account Details -->
                        <div
                            style="background: white; border-radius: 8px; padding: 1.5rem; box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);">
                            <div style="display: grid; gap: 1rem;">
                                <div
                                    style="display: grid; grid-template-columns: 120px 1fr; gap: 1rem; align-items: center; padding-bottom: 0.875rem; border-bottom: 1px solid #f1f5f9;">
                                    <span style="color: #64748b; font-size: 0.875rem; font-weight: 500;">ธนาคาร</span>
                                    <span style="color: #0f172a; font-weight: 600;">ธนาคารไทยพาณิชย์ (SCB)</span>
                                </div>

                                <div
                                    style="display: grid; grid-template-columns: 120px 1fr; gap: 1rem; align-items: center; padding-bottom: 0.875rem; border-bottom: 1px solid #f1f5f9;">
                                    <span
                                        style="color: #64748b; font-size: 0.875rem; font-weight: 500;">ชื่อบัญชี</span>
                                    <span style="color: #0f172a; font-weight: 600;">บริษัท บ็อกซ์ ดีไซน์ จำกัด</span>
                                </div>

                                <div
                                    style="display: grid; grid-template-columns: 120px 1fr; gap: 1rem; align-items: center;">
                                    <span
                                        style="color: #64748b; font-size: 0.875rem; font-weight: 500;">เลขที่บัญชี</span>
                                    <span
                                        style="font-family: 'Courier New', monospace; font-size: 1.25rem; font-weight: 700; color: #2563eb; letter-spacing: 0.05em;">123-456-7890</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Instructions -->
                    <div
                        style="background: #fffbeb; border-left: 4px solid #f59e0b; border-radius: 8px; padding: 1.25rem; margin-bottom: 1.5rem;">
                        <div style="display: flex; gap: 0.75rem;">
                            <i class="fas fa-info-circle"
                                style="color: #d97706; margin-top: 0.125rem; flex-shrink: 0;"></i>
                            <div style="color: #78350f;">
                                <p style="margin: 0 0 0.75rem 0; font-weight: 600; font-size: 0.9375rem;">
                                    หลังโอนเงินแล้ว กรุณาแจ้งสลิปการโอน</p>
                                <div style="font-size: 0.875rem; line-height: 1.6;">
                                    <div style="margin-bottom: 0.375rem;">📱 <strong>LINE Official:</strong> @boxdesign
                                    </div>
                                    <div style="margin-bottom: 0.375rem;">✉️ <strong>อีเมล:</strong>
                                        payment@boxdesign.in.th</div>
                                    <div
                                        style="margin-top: 0.75rem; padding-top: 0.75rem; border-top: 1px solid #fde68a; color: #92400e; font-style: italic;">
                                        ทีมงานจะดำเนินการเพิ่มวันใช้งานให้ภายใน 24 ชั่วโมง
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Pricing Tiers -->
                    <div
                        style="display:none;background: white; border: 1px solid #e2e8f0; border-radius: 8px; padding: 1.25rem;">
                        <div style="display: flex; align-items: center; gap: 0.5rem; margin-bottom: 1rem;">
                            <i class="fas fa-tag" style="color: #2563eb;"></i>
                            <h5 style="margin: 0; color: #1e293b; font-weight: 600; font-size: 0.9375rem;">แพ็กเกจแนะนำ
                            </h5>
                        </div>
                        <div
                            style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 0.75rem;">
                            <div
                                style="padding: 0.75rem; background: #f8fafc; border-radius: 6px; border: 1px solid #e2e8f0;">
                                <div style="font-weight: 600; color: #0f172a; margin-bottom: 0.25rem;">30 วัน</div>
                                <div style="color: #2563eb; font-size: 1.125rem; font-weight: 700;">฿2,500</div>
                            </div>
                            <div
                                style="padding: 0.75rem; background: #f8fafc; border-radius: 6px; border: 1px solid #e2e8f0;">
                                <div style="font-weight: 600; color: #0f172a; margin-bottom: 0.25rem;">90 วัน</div>
                                <div style="color: #059669; font-size: 1.125rem; font-weight: 700;">฿6,900 <span
                                        style="font-size: 0.75rem; color: #10b981;">ประหยัด 5%</span></div>
                            </div>
                            <div
                                style="padding: 0.75rem; background: #f8fafc; border-radius: 6px; border: 1px solid #e2e8f0;">
                                <div style="font-weight: 600; color: #0f172a; margin-bottom: 0.25rem;">365 วัน</div>
                                <div style="color: #059669; font-size: 1.125rem; font-weight: 700;">฿25,000 <span
                                        style="font-size: 0.75rem; color: #10b981;">ประหยัด 17%</span></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Slip Upload Card -->
    <div class="row" style="margin-top: 1.5rem;">
        <div class="col-12">
            <div class="card">
                <div class="card-header"
                    style="background: linear-gradient(135deg, #059669 0%, #10b981 100%); border-bottom: none; padding: 1rem 1.5rem;">
                    <h3 class="card-title"
                        style="color: white; font-weight: 600; margin: 0; display: flex; align-items: center; gap: 0.5rem;">
                        <i class="fas fa-upload"></i>
                        <span>แจ้งชำระเงิน</span>
                    </h3>
                </div>
                <div class="card-body" style="padding: 2rem;">
                    <form id="slipUploadForm" enctype="multipart/form-data">
                        <div style="display: grid; gap: 1.5rem;">
                            <!-- Amount Input (Optional with auto-detection) -->
                            <div>
                                <label style="display: block; font-weight: 600; color: #1e293b; margin-bottom: 0.5rem;">
                                    <i class="fas fa-coins" style="color: #059669; margin-right: 0.375rem;"></i>
                                    จำนวนเงินที่โอน (บาท)
                                </label>
                                <input type="number" name="amount" id="slipAmount" min="1" step="0.01"
                                    placeholder="เว้นว่างได้ถ้าระบบอ่านจากสลิป"
                                    style="width: 100%; padding: 0.75rem 1rem; border: 1px solid #e2e8f0; border-radius: 8px; font-size: 1rem; transition: border-color 0.2s;"
                                    onfocus="this.style.borderColor='#059669'"
                                    onblur="this.style.borderColor='#e2e8f0'">
                                <p style="margin: 0.5rem 0 0; font-size: 0.8125rem; color: #64748b;">
                                    <i class="fas fa-info-circle" style="color: #2563eb;"></i>
                                    หากไม่ระบุ ระบบจะพยายามอ่านจำนวนเงินจากสลิปอัตโนมัติ
                                </p>
                            </div>

                            <!-- Slip Upload -->
                            <div>
                                <label style="display: block; font-weight: 600; color: #1e293b; margin-bottom: 0.5rem;">
                                    <i class="fas fa-image" style="color: #059669; margin-right: 0.375rem;"></i>
                                    ไฟล์สลิปการโอน <span style="color: #dc2626;">*</span>
                                </label>
                                <div id="slipDropzone"
                                    style="border: 2px dashed #e2e8f0; border-radius: 12px; padding: 2rem; text-align: center; cursor: pointer; transition: all 0.2s;"
                                    onclick="document.getElementById('slipFile').click()"
                                    ondragover="event.preventDefault(); this.style.borderColor='#059669'; this.style.background='#f0fdf4';"
                                    ondragleave="this.style.borderColor='#e2e8f0'; this.style.background='transparent';"
                                    ondrop="handleFileDrop(event)">
                                    <input type="file" name="slip" id="slipFile" accept="image/*" required
                                        style="display: none;" onchange="handleFileSelect(this)">
                                    <div id="slipPreview">
                                        <i class="fas fa-cloud-upload-alt"
                                            style="font-size: 2.5rem; color: #94a3b8; margin-bottom: 0.75rem;"></i>
                                        <p style="color: #64748b; margin: 0 0 0.25rem 0; font-weight: 500;">
                                            คลิกหรือลากไฟล์มาวางที่นี่</p>
                                        <p style="color: #94a3b8; margin: 0; font-size: 0.8125rem;">รองรับ JPG, PNG,
                                            WebP (สูงสุด 10MB)</p>
                                    </div>
                                </div>
                            </div>

                            <!-- Submit Button -->
                            <button type="submit" id="submitSlipBtn"
                                style="width: 100%; padding: 1rem; background: linear-gradient(135deg, #059669, #10b981); color: white; border: none; border-radius: 10px; font-size: 1rem; font-weight: 600; cursor: pointer; transition: all 0.2s; display: flex; align-items: center; justify-content: center; gap: 0.5rem;">
                                <i class="fas fa-paper-plane"></i>
                                <span>แจ้งชำระเงิน</span>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Payment History Card -->
    <div class="row" style="margin-top: 1.5rem;">
        <div class="col-12">
            <div class="card">
                <div class="card-header"
                    style="background: #f8fafc; border-bottom: 1px solid #e2e8f0; padding: 1rem 1.5rem;">
                    <h3 class="card-title"
                        style="color: #1e293b; font-weight: 600; margin: 0; display: flex; align-items: center; gap: 0.5rem;">
                        <i class="fas fa-history" style="color: #64748b;"></i>
                        <span>ประวัติการชำระเงิน</span>
                    </h3>
                </div>
                <div class="card-body" style="padding: 1.5rem;">
                    <div id="paymentHistoryContainer">
                        <div style="text-align: center; padding: 2rem; color: #94a3b8;">
                            <div class="loading-spinner" style="margin-bottom: 1rem;"></div>
                            <p>กำลังโหลด...</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>

<script>
    // ========================================
    // Subscription Display with Progress Bar
    // ========================================

    async function loadSubscriptionInfo() {
        const container = document.getElementById('subscriptionDisplay');

        try {
            // Fetch subscription data from API (same endpoint as sidebar)
            const token = localStorage.getItem('auth_token');
            const res = await fetch('/api/user/subscription.php', {
                headers: token ? { 'Authorization': 'Bearer ' + token } : {}
            });
            const result = await res.json();

            // Map to expected format (API returns { success, data: { subscription, plan } })
            const data = {
                success: result.success && result.data?.has_subscription,
                subscription: result.data?.subscription,
                plan: result.data?.plan
            };

            if (!data.success || !data.subscription) {
                container.innerHTML = `
                <div style="text-align: center; padding: 2rem; color: var(--color-gray);">
                    <i class="fas fa-inbox" style="font-size: 3rem; margin-bottom: 1rem; opacity: 0.3;"></i>
                    <p>ยังไม่มีแพ็กเกจที่ใช้งานอยู่</p>
                </div>
            `;
                return;
            }

            const sub = data.subscription;
            const plan = data.plan || {};

            // Calculate days remaining
            const endDate = new Date(sub.current_period_end);
            const today = new Date();
            const daysRemaining = Math.ceil((endDate - today) / (1000 * 60 * 60 * 24));
            const totalDays = Math.ceil((endDate - new Date(sub.current_period_start)) / (1000 * 60 * 60 * 24));
            const progressPercent = Math.max(0, Math.min(100, (daysRemaining / totalDays) * 100));

            // Progress bar color based on days remaining (professional palette)
            let progressColor = '#059669'; // success green
            let statusColor = '#059669';
            let statusBg = '#d1fae5';
            let statusText = 'ปกติ';

            if (daysRemaining < 7) {
                progressColor = '#dc2626'; // danger red
                statusColor = '#dc2626';
                statusBg = '#fee2e2';
                statusText = 'ใกล้หมดอายุ';
            } else if (daysRemaining < 14) {
                progressColor = '#ea580c'; // warning orange
                statusColor = '#ea580c';
                statusBg = '#ffedd5';
                statusText = 'ควรต่ออายุ';
            }

            const startDateThai = new Date(sub.current_period_start).toLocaleDateString('th-TH', {
                year: 'numeric',
                month: 'short',
                day: 'numeric'
            });

            const endDateThai = endDate.toLocaleDateString('th-TH', {
                year: 'numeric',
                month: 'short',
                day: 'numeric'
            });

            container.innerHTML = `
            <!-- Subscription Header -->
            <div style="text-align: center; margin-bottom: 2rem;">
                <div style="display: inline-flex; align-items: center; gap: 0.75rem; background: #f1f5f9; padding: 0.5rem 1.25rem; border-radius: 24px; margin-bottom: 0.75rem;">
                    <i class="fas fa-box" style="color: #2563eb; font-size: 0.875rem;"></i>
                    <span style="font-size: 0.875rem; color: #64748b; font-weight: 500;">แพ็กเกจปัจจุบัน</span>
                </div>
                <h2 style="margin: 0; color: #0f172a; font-size: 1.875rem; font-weight: 700;">${plan.name || 'Standard'}</h2>
            </div>
            
            <!-- Stats Grid -->
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 1rem; margin-bottom: 2rem;">
                <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; padding: 1rem; text-align: center;">
                    <div style="color: #64748b; font-size: 0.8125rem; margin-bottom: 0.375rem; font-weight: 500;">วันคงเหลือ</div>
                    <div style="color: ${statusColor}; font-size: 1.875rem; font-weight: 700; line-height: 1;">${daysRemaining}</div>
                    <div style="color: #94a3b8; font-size: 0.75rem; margin-top: 0.25rem;">วัน</div>
                </div>
                
                <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; padding: 1rem; text-align: center;">
                    <div style="color: #64748b; font-size: 0.8125rem; margin-bottom: 0.375rem; font-weight: 500;">ระยะเวลารวม</div>
                    <div style="color: #2563eb; font-size: 1.875rem; font-weight: 700; line-height: 1;">${totalDays}</div>
                    <div style="color: #94a3b8; font-size: 0.75rem; margin-top: 0.25rem;">วัน</div>
                </div>
                
                <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; padding: 1rem; text-align: center;">
                    <div style="color: #64748b; font-size: 0.8125rem; margin-bottom: 0.375rem; font-weight: 500;">สถานะ</div>
                    <div style="margin-top: 0.375rem;">
                        <span style="display: inline-block; background: ${statusBg}; color: ${statusColor}; padding: 0.375rem 0.875rem; border-radius: 16px; font-size: 0.8125rem; font-weight: 600;">${statusText}</span>
                    </div>
                </div>
            </div>
            
            <!-- Progress Section -->
            <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; padding: 1.5rem;">
                <div style="display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 1rem;">
                    <span style="font-size: 0.875rem; color: #64748b; font-weight: 500;">ความคืบหน้า</span>
                    <span style="font-size: 1.125rem; color: #0f172a; font-weight: 600;">${Math.round(progressPercent)}%</span>
                </div>
                
                <!-- Progress Bar -->
                <div class="subscription-progress" style="margin-bottom: 1rem;">
                    <div class="subscription-progress-bar" style="background: linear-gradient(90deg, ${progressColor}, ${progressColor}dd); width: ${progressPercent}%;">
                        ${progressPercent > 15 ? Math.round(progressPercent) + '%' : ''}
                    </div>
                </div>
                
                <div style="display: flex; justify-content: space-between; font-size: 0.8125rem; color: #64748b;">
                    <span><i class="fas fa-calendar-alt" style="margin-right: 0.375rem; color: #94a3b8;"></i>${startDateThai}</span>
                    <span><i class="fas fa-calendar-check" style="margin-right: 0.375rem; color: #94a3b8;"></i>${endDateThai}</span>
                </div>
            </div>
            
            ${daysRemaining < 14 ? `
                <div style="background: #fffbeb; border: 1px solid #fde68a; border-radius: 12px; padding: 1.25rem; margin-top: 1.5rem; display: flex; gap: 0.875rem;">
                    <div style="flex-shrink: 0;">
                        <div style="width: 40px; height: 40px; background: #fef3c7; border-radius: 10px; display: flex; align-items: center; justify-content: center;">
                            <i class="fas fa-exclamation-triangle" style="color: #d97706; font-size: 1.125rem;"></i>
                        </div>
                    </div>
                    <div style="flex: 1;">
                        <div style="color: #78350f; font-weight: 600; margin-bottom: 0.375rem; font-size: 0.9375rem;">แพ็กเกจกำลังจะหมดอายุ</div>
                        <div style="color: #92400e; font-size: 0.875rem; line-height: 1.5;">
                            กรุณาโอนเงินเพื่อต่ออายุการใช้งาน โดยอ้างอิงจากแพ็กเกจด้านล่าง และแจ้งสลิปการโอนตามช่องทางที่ระบุ
                        </div>
                    </div>
                </div>
            ` : ''}
        `;

        } catch (err) {
            console.error('Error loading subscription:', err);
            container.innerHTML = `
            <div style="text-align: center; padding: 2rem; color: var(--color-danger);">
                <i class="fas fa-exclamation-circle" style="font-size: 2rem; margin-bottom: 0.5rem;"></i>
                <p>เกิดข้อผิดพลาดในการโหลดข้อมูล</p>
            </div>
        `;
        }
    }

    // Load on page ready
    document.addEventListener('DOMContentLoaded', function () {
        loadSubscriptionInfo();
        loadPaymentHistory();
        setupSlipUpload();
    });

    // ========================================
    // Slip Upload Functions
    // ========================================

    let selectedFile = null;

    function setupSlipUpload() {
        const form = document.getElementById('slipUploadForm');
        if (form) {
            form.addEventListener('submit', handleSlipSubmit);
        }
    }

    function handleFileSelect(input) {
        const file = input.files[0];
        if (file) {
            previewFile(file);
        }
    }

    function handleFileDrop(event) {
        event.preventDefault();
        const dropzone = document.getElementById('slipDropzone');
        dropzone.style.borderColor = '#e2e8f0';
        dropzone.style.background = 'transparent';

        const file = event.dataTransfer.files[0];
        if (file && file.type.startsWith('image/')) {
            document.getElementById('slipFile').files = event.dataTransfer.files;
            previewFile(file);
        }
    }

    function previewFile(file) {
        selectedFile = file;
        const preview = document.getElementById('slipPreview');
        const dropzone = document.getElementById('slipDropzone');

        const reader = new FileReader();
        reader.onload = function (e) {
            preview.innerHTML = `
            <div style="position: relative; display: inline-block;">
                <img src="${e.target.result}" style="max-width: 200px; max-height: 150px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">
                <div style="margin-top: 0.75rem;">
                    <p style="color: #059669; font-weight: 600; margin: 0;">
                        <i class="fas fa-check-circle"></i> ${file.name}
                    </p>
                    <p style="color: #94a3b8; font-size: 0.8125rem; margin: 0.25rem 0 0;">
                        ${(file.size / 1024 / 1024).toFixed(2)} MB
                    </p>
                </div>
            </div>
        `;
            dropzone.style.borderColor = '#059669';
            dropzone.style.background = '#f0fdf4';
        };
        reader.readAsDataURL(file);
    }

    async function handleSlipSubmit(event) {
        event.preventDefault();

        const form = event.target;
        const submitBtn = document.getElementById('submitSlipBtn');
        const amountInput = document.getElementById('slipAmount');
        const fileInput = document.getElementById('slipFile');

        const amount = amountInput.value;
        const file = fileInput.files[0];

        // Amount is now optional - validation happens on server
        // Server will try to extract from slip if not provided

        if (!file) {
            showAlert('กรุณาเลือกไฟล์สลิป', 'warning');
            return;
        }

        // Disable button and show loading
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> กำลังอัพโหลด...';

        try {
            const token = localStorage.getItem('auth_token');
            const formData = new FormData();
            formData.append('slip', file);
            formData.append('amount', amount);

            const response = await fetch('/api/user/upload-subscription-slip.php', {
                method: 'POST',
                headers: token ? { 'Authorization': 'Bearer ' + token } : {},
                body: formData
            });

            const result = await response.json();

            if (result.success) {
                // Build detailed success message
                let alertType = 'success';
                let message = result.message || 'อัพโหลดสำเร็จ!';

                // If verified, show additional info
                if (result.data?.is_verified) {
                    const verifiedAmount = result.data.verified_amount;
                    if (verifiedAmount) {
                        message += ` (ยอด ฿${verifiedAmount.toLocaleString()})`;
                    }
                }

                showAlert(message, alertType);
                form.reset();
                resetSlipPreview();
                loadPaymentHistory(); // Refresh history
            } else {
                showAlert(result.message || 'เกิดข้อผิดพลาด', 'danger');
            }
        } catch (err) {
            console.error('Upload error:', err);
            showAlert('เกิดข้อผิดพลาดในการอัพโหลด', 'danger');
        } finally {
            submitBtn.disabled = false;
            submitBtn.innerHTML = '<i class="fas fa-paper-plane"></i> <span>แจ้งชำระเงิน</span>';
        }
    }

    function resetSlipPreview() {
        const preview = document.getElementById('slipPreview');
        const dropzone = document.getElementById('slipDropzone');
        selectedFile = null;

        preview.innerHTML = `
        <i class="fas fa-cloud-upload-alt" style="font-size: 2.5rem; color: #94a3b8; margin-bottom: 0.75rem;"></i>
        <p style="color: #64748b; margin: 0 0 0.25rem 0; font-weight: 500;">คลิกหรือลากไฟล์มาวางที่นี่</p>
        <p style="color: #94a3b8; margin: 0; font-size: 0.8125rem;">รองรับ JPG, PNG, WebP (สูงสุด 10MB)</p>
    `;
        dropzone.style.borderColor = '#e2e8f0';
        dropzone.style.background = 'transparent';
    }

    function showAlert(message, type = 'info') {
        // Create alert element
        const alertDiv = document.createElement('div');
        alertDiv.style.cssText = `
        position: fixed;
        top: 20px;
        right: 20px;
        z-index: 9999;
        padding: 1rem 1.5rem;
        border-radius: 10px;
        box-shadow: 0 4px 20px rgba(0,0,0,0.15);
        display: flex;
        align-items: center;
        gap: 0.75rem;
        animation: slideIn 0.3s ease;
        max-width: 400px;
    `;

        const colors = {
            success: { bg: '#d1fae5', border: '#059669', text: '#065f46', icon: 'fa-check-circle' },
            warning: { bg: '#fef3c7', border: '#d97706', text: '#92400e', icon: 'fa-exclamation-triangle' },
            danger: { bg: '#fee2e2', border: '#dc2626', text: '#991b1b', icon: 'fa-times-circle' },
            info: { bg: '#dbeafe', border: '#2563eb', text: '#1e40af', icon: 'fa-info-circle' }
        };

        const c = colors[type] || colors.info;
        alertDiv.style.background = c.bg;
        alertDiv.style.borderLeft = `4px solid ${c.border}`;
        alertDiv.style.color = c.text;

        alertDiv.innerHTML = `
        <i class="fas ${c.icon}"></i>
        <span style="font-weight: 500;">${message}</span>
    `;

        document.body.appendChild(alertDiv);

        setTimeout(() => {
            alertDiv.style.animation = 'slideOut 0.3s ease forwards';
            setTimeout(() => alertDiv.remove(), 300);
        }, 4000);
    }

    // ========================================
    // Payment History Functions
    // ========================================

    async function loadPaymentHistory() {
        const container = document.getElementById('paymentHistoryContainer');
        if (!container) return;

        try {
            const token = localStorage.getItem('auth_token');
            const response = await fetch('/api/user/subscription-payments.php', {
                headers: token ? { 'Authorization': 'Bearer ' + token } : {}
            });

            const result = await response.json();

            if (!result.success || !result.data?.payments?.length) {
                container.innerHTML = `
                <div style="text-align: center; padding: 2rem; color: #94a3b8;">
                    <i class="fas fa-inbox" style="font-size: 2.5rem; margin-bottom: 0.75rem; opacity: 0.5;"></i>
                    <p style="margin: 0;">ยังไม่มีประวัติการชำระเงิน</p>
                </div>
            `;
                return;
            }

            const payments = result.data.payments;

            container.innerHTML = `
            <div style="display: flex; flex-direction: column; gap: 1rem;">
                ${payments.map(p => `
                    <div style="display: flex; gap: 1rem; padding: 1rem; background: #f8fafc; border-radius: 10px; border: 1px solid #e2e8f0;">
                        ${p.slip_url ? `
                            <div style="flex-shrink: 0;">
                                <img src="${p.slip_url}" 
                                    style="width: 60px; height: 60px; object-fit: cover; border-radius: 8px; cursor: pointer; border: 1px solid #e2e8f0;"
                                    onclick="window.open('${p.slip_url}', '_blank')"
                                    onerror="this.src='data:image/svg+xml,<svg xmlns=\\'http://www.w3.org/2000/svg\\' viewBox=\\'0 0 100 100\\'><rect fill=\\'%23e2e8f0\\' width=\\'100\\' height=\\'100\\'/><text x=\\'50\\' y=\\'55\\' text-anchor=\\'middle\\' fill=\\'%2394a3b8\\' font-size=\\'14\\'>สลิป</text></svg>'">
                            </div>
                        ` : `
                            <div style="flex-shrink: 0; width: 60px; height: 60px; background: #e2e8f0; border-radius: 8px; display: flex; align-items: center; justify-content: center;">
                                <i class="fas fa-receipt" style="color: #94a3b8;"></i>
                            </div>
                        `}
                        <div style="flex: 1; min-width: 0;">
                            <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 0.375rem;">
                                <div>
                                    <span style="font-weight: 600; color: #0f172a;">${p.amount_formatted}</span>
                                    ${p.days_added > 0 ? `<span style="color: #059669; font-size: 0.8125rem; margin-left: 0.5rem;">+${p.days_added} วัน</span>` : ''}
                                </div>
                                <span style="
                                    padding: 0.25rem 0.625rem; 
                                    border-radius: 12px; 
                                    font-size: 0.75rem; 
                                    font-weight: 600;
                                    background: ${p.status === 'verified' ? '#d1fae5' : p.status === 'rejected' ? '#fee2e2' : '#fef3c7'};
                                    color: ${p.status === 'verified' ? '#065f46' : p.status === 'rejected' ? '#991b1b' : '#92400e'};
                                ">${p.status_icon} ${p.status_label}</span>
                            </div>
                            <div style="color: #64748b; font-size: 0.8125rem;">
                                <i class="fas fa-clock" style="margin-right: 0.25rem;"></i>
                                ${p.created_at_formatted}
                            </div>
                            ${p.rejection_reason ? `
                                <div style="margin-top: 0.5rem; padding: 0.5rem; background: #fee2e2; border-radius: 6px; font-size: 0.8125rem; color: #991b1b;">
                                    <i class="fas fa-info-circle" style="margin-right: 0.25rem;"></i>
                                    ${p.rejection_reason}
                                </div>
                            ` : ''}
                        </div>
                    </div>
                `).join('')}
            </div>
        `;

        } catch (err) {
            console.error('Error loading payment history:', err);
            container.innerHTML = `
            <div style="text-align: center; padding: 2rem; color: #dc2626;">
                <i class="fas fa-exclamation-circle" style="font-size: 1.5rem; margin-bottom: 0.5rem;"></i>
                <p style="margin: 0;">เกิดข้อผิดพลาดในการโหลดข้อมูล</p>
            </div>
        `;
        }
    }
</script>

<!-- Alert animation styles -->
<style>
    @keyframes slideIn {
        from {
            transform: translateX(100%);
            opacity: 0;
        }

        to {
            transform: translateX(0);
            opacity: 1;
        }
    }

    @keyframes slideOut {
        from {
            transform: translateX(0);
            opacity: 1;
        }

        to {
            transform: translateX(100%);
            opacity: 0;
        }
    }
</style>

<?php
include('../includes/customer/footer.php');
?>