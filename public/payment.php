<?php
/**
 * Customer Payment Page (Improved UX)
 */
define('INCLUDE_CHECK', true);

$page_title = "ชำระเงิน - AI Automation";
$current_page = "payment";

// NOTE: CSS is loaded globally in includes/customer/header.php via asset() helper
// Keep $extra_css empty unless you truly need a page-only stylesheet.
$extra_css = [];

include('../includes/customer/header.php');
include('../includes/customer/sidebar.php');
?>

<style>
/* Tabs */
.payment-method-tabs {
    display: flex;
    gap: 0.75rem;
    border-bottom: 1px solid #e5e7eb;
    flex-wrap: wrap;
}

.payment-tab {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.55rem 1rem;
    border: none;
    border-radius: 999px;
    background-color: #f3f4f6;
    color: #4b5563;
    font-size: 0.95rem;
    font-weight: 600;
    cursor: pointer;
    transition: background-color 0.15s ease, color 0.15s ease, box-shadow 0.15s ease;
}

.payment-tab:hover { background-color: #e5e7eb; color: #111827; }
.payment-tab.active { background-color: #2563eb; color: #ffffff; box-shadow: 0 2px 6px rgba(37, 99, 235, 0.35); }
.payment-tab i { font-size: 0.95rem; }

/* Helper text under tabs */
.payment-helper {
    margin-top: 0.75rem;
    padding: 0.75rem 1rem;
    border-radius: 10px;
    background: linear-gradient(135deg, #f8fafc 0%, #ffffff 100%);
    border: 1px solid #e5e7eb;
    color: #374151;
    font-size: 0.95rem;
}
.payment-helper strong { color: #111827; }

/* Small chip style */
.payment-chip {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    padding: 0.2rem 0.6rem;
    border-radius: 999px;
    background: #eef2ff;
    color: #3730a3;
    font-size: 0.85rem;
    font-weight: 600;
}
</style>

<main class="main-content">
    <div class="page-header">
        <div>
            <h1 class="page-title">ชำระบิล & วิธีการชำระเงิน</h1>
            <p class="page-subtitle">เลือกบิลที่ค้างชำระ แล้วเลือกชำระผ่านบัตรหรือ PromptPay QR</p>
        </div>
    </div>

    <!-- Pending Invoices Summary (shown/hidden by JS) -->
    <div id="pendingInvoicesAlert" class="card hidden" style="margin-bottom: 1.25rem; border-left: 4px solid var(--color-warning); background: linear-gradient(135deg, #fff9e6 0%, #ffffff 100%);">
        <div class="card-body">
            <div style="display:flex; justify-content:space-between; align-items:center; gap:1rem;">
                <div style="flex:1;">
                    <h4 style="color: var(--color-warning); margin: 0 0 0.25rem 0; font-size: 1.05rem;">
                        <i class="fas fa-exclamation-circle"></i>
                        คุณมีบิลค้างชำระ <span id="pendingCount" style="font-weight: 800;">0</span> บิล
                    </h4>
                    <div style="display:flex; align-items:baseline; gap:0.75rem; flex-wrap:wrap;">
                        <span style="color: var(--color-dark-3); font-size: 0.95rem;">ยอดรวมค้างชำระ</span>
                        <strong id="pendingTotal" style="font-size: 1.6rem; color: var(--color-danger);">฿0.00</strong>
                        <span class="payment-chip"><i class="fas fa-bolt"></i> ชำระได้ทันที</span>
                    </div>
                </div>
                <button class="btn btn-primary btn-lg" onclick="showPendingInvoices()" style="white-space: nowrap;">
                    <i class="fas fa-credit-card"></i> ไปที่บิลค้างชำระ
                </button>
            </div>
        </div>
    </div>

    <!-- Top Tabs (keep existing IDs to reduce JS breakage) -->
    <div class="card" style="margin-bottom: 1.25rem;">
        <div class="card-body">
            <div class="payment-method-tabs">
                <!-- ✅ Default should be invoices -->
                <button class="payment-tab active" data-method="invoices" onclick="switchPaymentMethod('invoices')" id="invoicesTab">
                    <i class="fas fa-file-invoice-dollar"></i>
                    <span>1) ชำระบิลค้างชำระ</span>
                </button>

                <button class="payment-tab" data-method="promptpay" onclick="switchPaymentMethod('promptpay')" id="promptpayTab">
                    <i class="fas fa-qrcode"></i>
                    <span>2) จ่ายด้วย PromptPay QR</span>
                </button>

                <button class="payment-tab" data-method="card" onclick="switchPaymentMethod('card')" id="cardTab">
                    <i class="fas fa-id-card"></i>
                    <span>จัดการบัตรของฉัน</span>
                </button>
            </div>

            <div class="payment-helper" id="paymentHelperText">
                <strong>แนะนำ:</strong> เริ่มจากแท็บ <span class="payment-chip"><i class="fas fa-file-invoice-dollar"></i> ชำระบิลค้างชำระ</span>
                แล้วเลือกชำระด้วย <span class="payment-chip"><i class="fas fa-credit-card"></i> บัตร</span>
                หรือ <span class="payment-chip"><i class="fas fa-qrcode"></i> PromptPay QR</span>
            </div>
        </div>
    </div>

    <div class="row">
        <!-- Invoices & Payment Section (DEFAULT VISIBLE) -->
        <div class="col-8" id="invoicesSection">
            <div class="card">
                <div class="card-header">
                    <div>
                        <h3 class="card-title">บิลค้างชำระ</h3>
                        <p class="card-subtitle">
                            เลือกบิลที่ต้องการชำระ (แนะนำ: ชำระผ่านบัตรถ้าตั้งบัตรไว้แล้ว / ใช้ PromptPay หากต้องการจ่ายแบบสแกน QR)
                        </p>
                    </div>
                </div>
                <div class="card-body">
                    <!-- payment.js should render list + pay actions here -->
                    <div id="pendingInvoicesContainer">
                        <div class="text-center card-loading">
                            <div class="loading-spinner"></div>
                            <p class="card-loading-text">กำลังโหลดบิลค้างชำระ...</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- PromptPay Section (kept for compatibility; shown when user chooses promptpay tab) -->
        <div class="col-8 hidden" id="promptpaySection">
            <div class="card">
                <div class="card-header">
                    <div>
                        <h3 class="card-title">ชำระด้วย PromptPay QR</h3>
                        <p class="card-subtitle">เลือกบิลค้างชำระเพื่อสร้าง QR แล้วสแกนจ่าย (ระบบจะอัปเดตสถานะอัตโนมัติ)</p>
                    </div>
                </div>
                <div class="card-body">
                    <div id="promptpayInvoicesContainer">
                        <div class="text-center card-loading">
                            <div class="loading-spinner"></div>
                            <p class="card-loading-text">กำลังโหลดบิลค้างชำระ...</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Card Management Section (NOT default) -->
        <div class="col-8 hidden" id="cardSection">
            <div class="card">
                <div class="card-header card-header--between">
                    <div>
                        <h3 class="card-title">บัตรของฉัน</h3>
                        <p class="card-subtitle">บันทึก/ตั้งค่า “บัตรเริ่มต้น” เพื่อชำระเงินได้เร็วขึ้น</p>
                    </div>
                    <button id="addCardButton" class="btn btn-primary" onclick="showAddCardForm()">
                        เพิ่มบัตรใหม่
                    </button>
                </div>
                <div class="card-body">
                    <div id="paymentMethodsContainer">
                        <div class="text-center card-loading">
                            <div class="loading-spinner"></div>
                            <p class="card-loading-text">กำลังโหลด...</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Quick tip under card section -->
            <div class="card mt-3">
                <div class="card-body">
                    <div style="display:flex; gap:0.75rem; align-items:flex-start;">
                        <i class="fas fa-lightbulb" style="margin-top:0.2rem;"></i>
                        <div>
                            <div style="font-weight:700;">ทิป</div>
                            <div style="color: var(--color-gray);">
                                ถ้าต้องการ “จ่ายบิล” ให้กลับไปที่แท็บ <strong>ชำระบิลค้างชำระ</strong> แล้วกดชำระด้วยบัตร/PromptPay ได้เลย
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Right Column: Subscription + Trust -->
        <div class="col-4">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">แพ็คเกจปัจจุบัน</h3>
                </div>
                <div class="card-body" id="subscriptionInfo">
                    <div class="text-center card-loading card-loading--compact">
                        <p class="card-loading-text">กำลังโหลด...</p>
                    </div>
                </div>
            </div>

            <div class="card mt-3 card-trust">
                <div class="card-header card-trust-header">
                    <h3 class="card-title card-trust-title">
                        <i class="fas fa-shield-alt"></i> ระบบชำระเงินปลอดภัย
                    </h3>
                </div>
                <div class="card-body">
                    <div class="card-trust-list">
                        <div class="card-trust-item">
                            <div class="card-trust-icon"><i class="fas fa-lock"></i></div>
                            <div class="card-trust-text">
                                <strong class="card-trust-text-title">256-bit SSL Encryption</strong>
                                <span class="card-trust-text-subtitle">เข้ารหัสระดับธนาคาร</span>
                            </div>
                        </div>
                        <div class="card-trust-item">
                            <div class="card-trust-icon"><i class="fas fa-check-circle"></i></div>
                            <div class="card-trust-text">
                                <strong class="card-trust-text-title">PCI DSS Compliant</strong>
                                <span class="card-trust-text-subtitle">มาตรฐานสากล</span>
                            </div>
                        </div>
                        <div class="card-trust-item">
                            <div class="card-trust-icon"><i class="fas fa-user-shield"></i></div>
                            <div class="card-trust-text">
                                <strong class="card-trust-text-title">Omise Payment Gateway</strong>
                                <span class="card-trust-text-subtitle">ผู้ให้บริการชั้นนำ</span>
                            </div>
                        </div>
                    </div>

                    <div class="supported-cards supported-cards--compact">
                        <p class="supported-cards-label">บัตรที่รองรับ</p>
                        <div class="supported-cards-row">
                            <span class="card-brand card-brand__visa">
                                <img id="visaImg1" src="" alt="VISA">
                            </span>
                            <span class="card-brand card-brand--mc" aria-label="Mastercard">
                                <img id="masterImg1" src="" alt="Mastercard">
                            </span>
                            <span class="card-brand card-brand__jcb">
                                <img id="jcbImg1" src="" alt="JCB">
                            </span>
                            <span class="card-brand card-brand__amex">
                                <img id="amexImg1" src="" alt="AMEX">
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Add Card Modal -->
    <div id="addCardModal" class="hidden payment-modal-backdrop">
        <div class="card payment-modal">
            <div class="card-header payment-modal-header">
                <div class="payment-modal-header-content">
                    <div class="payment-modal-header-text">
                        <h3 class="payment-modal-title">
                            <i class="fas fa-credit-card"></i> เพิ่มบัตรเครดิต/เดบิต
                        </h3>
                        <p class="payment-modal-subtitle">
                            <i class="fas fa-lock"></i> ปลอดภัยด้วย SSL + PCI DSS
                        </p>
                    </div>
                    <button type="button" class="payment-modal-close" onclick="hideAddCardForm()">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            </div>

            <div class="card-body payment-modal-body">
                <div class="payment-modal-accepted">
                    <p class="payment-modal-accepted-label">บัตรที่รองรับ</p>
                    <div class="supported-cards-row">
                        <span class="card-brand card-brand__visa"><img id="visaImg2" src="" alt="VISA"></span>
                        <span class="card-brand card-brand--mc" aria-label="Mastercard"><img id="masterImg2" src="" alt="Mastercard"></span>
                        <span class="card-brand card-brand__jcb"><img id="jcbImg2" src="" alt="JCB"></span>
                        <span class="card-brand card-brand__amex"><img id="amexImg2" src="" alt="AMEX"></span>
                    </div>
                </div>

                <form id="cardForm" class="card-form">
                    <div class="form-group">
                        <label class="form-label"><i class="fas fa-credit-card"></i> หมายเลขบัตร</label>
                        <div class="card-form-number-wrapper">
                            <input type="text" id="cardNumber" class="form-control card-form-number-input"
                                placeholder="1234 5678 9012 3456" maxlength="19" required>
                            <div id="cardBrandIcon" class="card-form-brand-icon"><i class="fas fa-credit-card"></i></div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label"><i class="fas fa-user"></i> ชื่อบนบัตร</label>
                        <input type="text" id="cardName" class="form-control card-form-name-input" placeholder="JOHN DOE" required>
                    </div>

                    <div class="row">
                        <div class="col-6">
                            <div class="form-group">
                                <label class="form-label"><i class="far fa-calendar-alt"></i> วันหมดอายุ</label>
                                <input type="text" id="cardExpiry" class="form-control card-form-expiry-input" placeholder="MM/YY" maxlength="5" required>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="form-group">
                                <label class="form-label"><i class="fas fa-key"></i> CVV</label>
                                <input type="password" id="cardCVV" class="form-control card-form-cvv-input" placeholder="***" maxlength="4" required>
                            </div>
                        </div>
                    </div>

                    <div id="cardError" class="form-error hidden"></div>

                    <div class="payment-modal-actions">
                        <button type="submit" class="btn btn-primary payment-modal-submit">
                            <i class="fas fa-save"></i> บันทึกบัตร
                        </button>
                        <button type="button" class="btn btn-outline payment-modal-cancel" onclick="hideAddCardForm()">
                            <i class="fas fa-times"></i> ยกเลิก
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- PromptPay QR Modal -->
    <div id="promptpayQRModal" class="hidden payment-modal-backdrop">
        <div class="card payment-modal" style="max-width: 500px;">
            <div class="card-header payment-modal-header">
                <div class="payment-modal-header-content">
                    <div class="payment-modal-header-text">
                        <h3 class="payment-modal-title"><i class="fas fa-qrcode"></i> สแกน QR Code เพื่อชำระเงิน</h3>
                        <p class="payment-modal-subtitle" id="qrExpiryText">
                            <i class="fas fa-clock"></i> <span id="qrCountdown">กำลังโหลด...</span>
                        </p>
                    </div>
                    <button type="button" class="payment-modal-close" onclick="hidePromptPayQR()">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            </div>

            <div class="card-body payment-modal-body" style="text-align: center;">
                <div id="qrCodeContainer" style="margin: 1.5rem 0;">
                    <img id="qrCodeImage" src="" alt="PromptPay QR Code"
                         style="max-width: 100%; height: auto; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.1);">
                </div>

                <div style="background: var(--color-light-2); padding: 1rem; border-radius: 8px; margin-bottom: 1rem;">
                    <div style="font-size: 0.875rem; color: var(--color-gray); margin-bottom: 0.5rem;">จำนวนเงิน</div>
                    <div style="font-size: 2rem; font-weight: 800; color: var(--color-primary);" id="qrAmount">0.00</div>
                    <div style="font-size: 0.875rem; color: var(--color-gray);">บาท</div>
                </div>

                <div id="qrStatusMessage" class="hidden" style="margin-top: 1rem;"></div>

                <div style="background: #fff3cd; border: 1px solid #ffc107; border-radius: 8px; padding: 1rem; margin-top: 1rem; text-align: left;">
                    <div style="display: flex; align-items: start; gap: 0.75rem;">
                        <i class="fas fa-info-circle" style="color: #856404; margin-top: 0.25rem;"></i>
                        <div style="font-size: 0.875rem; color: #856404;">
                            <strong>วิธีชำระเงิน:</strong><br>
                            1) เปิดแอปธนาคารในมือถือ<br>
                            2) สแกน QR Code นี้<br>
                            3) ยืนยันการชำระเงิน<br>
                            4) รอสักครู่ ระบบจะอัปเดตสถานะอัตโนมัติ
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>

<script>
    // Make brand icons base-path aware (avoid /images/* 404 on localhost)
    (function () {
        function apply() {
            if (typeof PATH === 'undefined') return;
            document.querySelectorAll('img[src^="/images/"]').forEach(function (img) {
                var src = img.getAttribute('src') || '';
                // convert "/images/visa.svg" -> "images/visa.svg"
                var rel = src.replace(/^\//, '');
                img.src = PATH.image(rel);
            });
        }

        // Load card brand images
        const cardImages = ['visaImg1', 'masterImg1', 'jcbImg1', 'amexImg1', 'visaImg2', 'masterImg2', 'jcbImg2', 'amexImg2'];
        const cardFiles = ['visa.svg', 'master.svg', 'jcb.svg', 'amex.svg', 'visa.svg', 'master.svg', 'jcb.svg', 'amex.svg'];
        cardImages.forEach((id, idx) => {
            const el = document.getElementById(id);
            if (el) el.src = PATH.image(cardFiles[idx]);
        });

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', apply);
        } else {
            apply();
        }
    })();
</script>

<?php
$extra_scripts = [
    'https://cdn.omise.co/omise.js',
    'assets/js/payment.js'
];

include('../includes/customer/footer.php');
?>
