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
                <div class="card-header" style="background: linear-gradient(135deg, #1e40af 0%, #2563eb 100%); border-bottom: none;">
                    <h3 class="card-title" style="color: white; font-weight: 600;">
                        <i class="fas fa-credit-card"></i> วิธีการชำระเงิน
                    </h3>
                </div>
                <div class="card-body" style="padding: 2rem;">
                    <!-- Bank Transfer Section -->
                    <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; padding: 2rem; margin-bottom: 1.5rem;">
                        <div style="display: flex; align-items: center; gap: 0.75rem; margin-bottom: 1.5rem;">
                            <div style="width: 48px; height: 48px; background: linear-gradient(135deg, #2563eb, #3b82f6); border-radius: 12px; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                                <i class="fas fa-university" style="color: white; font-size: 1.25rem;"></i>
                            </div>
                            <div>
                                <h4 style="margin: 0; color: #1e293b; font-size: 1.125rem; font-weight: 600;">โอนเงินผ่านธนาคาร</h4>
                                <p style="margin: 0; color: #64748b; font-size: 0.875rem;">รองรับทุกธนาคาร ผ่านแอพธนาคารหรือ ATM</p>
                            </div>
                        </div>
                        
                        <!-- Bank Account Details -->
                        <div style="background: white; border-radius: 8px; padding: 1.5rem; box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);">
                            <div style="display: grid; gap: 1rem;">
                                <div style="display: grid; grid-template-columns: 120px 1fr; gap: 1rem; align-items: center; padding-bottom: 0.875rem; border-bottom: 1px solid #f1f5f9;">
                                    <span style="color: #64748b; font-size: 0.875rem; font-weight: 500;">ธนาคาร</span>
                                    <span style="color: #0f172a; font-weight: 600;">ธนาคารไทยพาณิชย์ (SCB)</span>
                                </div>
                                
                                <div style="display: grid; grid-template-columns: 120px 1fr; gap: 1rem; align-items: center; padding-bottom: 0.875rem; border-bottom: 1px solid #f1f5f9;">
                                    <span style="color: #64748b; font-size: 0.875rem; font-weight: 500;">ชื่อบัญชี</span>
                                    <span style="color: #0f172a; font-weight: 600;">บริษัท บ็อกซ์ ดีไซน์ จำกัด</span>
                                </div>
                                
                                <div style="display: grid; grid-template-columns: 120px 1fr; gap: 1rem; align-items: center;">
                                    <span style="color: #64748b; font-size: 0.875rem; font-weight: 500;">เลขที่บัญชี</span>
                                    <span style="font-family: 'Courier New', monospace; font-size: 1.25rem; font-weight: 700; color: #2563eb; letter-spacing: 0.05em;">123-456-7890</span>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Instructions -->
                    <div style="background: #fffbeb; border-left: 4px solid #f59e0b; border-radius: 8px; padding: 1.25rem; margin-bottom: 1.5rem;">
                        <div style="display: flex; gap: 0.75rem;">
                            <i class="fas fa-info-circle" style="color: #d97706; margin-top: 0.125rem; flex-shrink: 0;"></i>
                            <div style="color: #78350f;">
                                <p style="margin: 0 0 0.75rem 0; font-weight: 600; font-size: 0.9375rem;">หลังโอนเงินแล้ว กรุณาแจ้งสลิปการโอน</p>
                                <div style="font-size: 0.875rem; line-height: 1.6;">
                                    <div style="margin-bottom: 0.375rem;">📱 <strong>LINE Official:</strong> @boxdesign</div>
                                    <div style="margin-bottom: 0.375rem;">✉️ <strong>อีเมล:</strong> payment@boxdesign.in.th</div>
                                    <div style="margin-top: 0.75rem; padding-top: 0.75rem; border-top: 1px solid #fde68a; color: #92400e; font-style: italic;">
                                        ทีมงานจะดำเนินการเพิ่มวันใช้งานให้ภายใน 24 ชั่วโมง
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Pricing Tiers -->
                    <div style="background: white; border: 1px solid #e2e8f0; border-radius: 8px; padding: 1.25rem;">
                        <div style="display: flex; align-items: center; gap: 0.5rem; margin-bottom: 1rem;">
                            <i class="fas fa-tag" style="color: #2563eb;"></i>
                            <h5 style="margin: 0; color: #1e293b; font-weight: 600; font-size: 0.9375rem;">แพ็กเกจแนะนำ</h5>
                        </div>
                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 0.75rem;">
                            <div style="padding: 0.75rem; background: #f8fafc; border-radius: 6px; border: 1px solid #e2e8f0;">
                                <div style="font-weight: 600; color: #0f172a; margin-bottom: 0.25rem;">30 วัน</div>
                                <div style="color: #2563eb; font-size: 1.125rem; font-weight: 700;">฿2,500</div>
                            </div>
                            <div style="padding: 0.75rem; background: #f8fafc; border-radius: 6px; border: 1px solid #e2e8f0;">
                                <div style="font-weight: 600; color: #0f172a; margin-bottom: 0.25rem;">90 วัน</div>
                                <div style="color: #059669; font-size: 1.125rem; font-weight: 700;">฿6,900 <span style="font-size: 0.75rem; color: #10b981;">ประหยัด 5%</span></div>
                            </div>
                            <div style="padding: 0.75rem; background: #f8fafc; border-radius: 6px; border: 1px solid #e2e8f0;">
                                <div style="font-weight: 600; color: #0f172a; margin-bottom: 0.25rem;">365 วัน</div>
                                <div style="color: #059669; font-size: 1.125rem; font-weight: 700;">฿25,000 <span style="font-size: 0.75rem; color: #10b981;">ประหยัด 17%</span></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>
    <div class="row" style="margin-top: 1.5rem;">
        <div class="col-12">
            <div class="card">
                <div class="card-header" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white;">
                    <h3 class="card-title" style="color: white;"><i class="fas fa-university"></i> การชำระเงิน / ต่ออายุแพ็กเกจ</h3>
                </div>
                <div class="card-body">
                    <div style="background: #f0f9ff; border: 2px solid #3b82f6; border-radius: 12px; padding: 1.5rem;">
                        <h4 style="margin-top: 0; color: #1e40af;">🏦 โอนเงินผ่านบัญชีธนาคาร</h4>
                        
                        <div style="background: white; padding: 1.25rem; border-radius: 8px; margin: 1rem 0;">
                            <div style="display: grid; grid-template-columns: 140px 1fr; gap: 0.75rem; font-size: 0.95rem;">
                                <div style="font-weight: 600; color: #475569;">ธนาคาร:</div>
                                <div style="color: #1e293b;">ไทยพาณิชย์ (SCB)</div>
                                
                                <div style="font-weight: 600; color: #475569;">ชื่อบัญชี:</div>
                                <div style="color: #1e293b;">บริษัท บ็อกซ์ ดีไซน์ จำกัด</div>
                                
                                <div style="font-weight: 600; color: #475569;">เลขที่บัญชี:</div>
                                <div style="font-family: monospace; font-size: 1.1rem; font-weight: 700; color: #0369a1;">123-456-7890</div>
                            </div>
                        </div>
                        
                        <div style="background: #fef3c7; border-left: 4px solid #f59e0b; padding: 1rem; border-radius: 6px; margin-top: 1rem;">
                            <div style="color: #92400e;">
                                <strong style="display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.5rem;">
                                    <i class="fas fa-info-circle"></i> หลังโอนเงินแล้ว
                                </strong>
                                <div style="margin-left: 1.5rem;">
                                    แจ้งสลิปพร้อมระบุจำนวนวันที่ต้องการต่อมาที่:<br>
                                    📱 <strong>LINE:</strong> @boxdesign<br>
                                    ✉️ <strong>อีเมล:</strong> payment@boxdesign.in.th<br>
                                    <br>
                                    <em style="font-size: 0.9rem;">ทีมงานจะดำเนินการเพิ่มวันให้ภายใน 24 ชั่วโมง</em>
                                </div>
                            </div>
                        </div>

                        <div style="background: white; border: 1px solid #e5e7eb; border-radius: 8px; padding: 1rem; margin-top: 1rem;">
                            <div style="font-weight: 600; margin-bottom: 0.5rem; color: #374151;">💡 แพ็กเกจยอดนิยม</div>
                            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 0.75rem; font-size: 0.9rem; color: #6b7280;">
                                <div>• 30 วัน = 2,500 บาท</div>
                                <div>• 90 วัน = 6,900 บาท (ลด 5%)</div>
                                <div>• 365 วัน = 25,000 บาท (ลด 17%)</div>
                            </div>
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
        // Fetch subscription data from API
        const res = await fetch('/api/customer/subscription-info.php');
        const data = await res.json();
        
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
document.addEventListener('DOMContentLoaded', function() {
    loadSubscriptionInfo();
});
</script>

<?php
include('../includes/customer/footer.php');
?>
