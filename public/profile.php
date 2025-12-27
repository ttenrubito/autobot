<?php
/**
 * Customer Profile Page - Redesigned
 */
define('INCLUDE_CHECK', true);

$page_title = "โปรไฟล์ - AI Automation";
$current_page = "profile";

include('../includes/customer/header.php');
include('../includes/customer/sidebar.php');
?>

<style>
.profile-security-notice {
    background: #f8fafc;
    border: 2px solid #e2e8f0;
    border-left: 4px solid #3b82f6;
    border-radius: var(--radius-lg);
    padding: 1rem 1.25rem;
    display: flex;
    align-items: center;
    gap: 1rem;
    margin-bottom: 1.5rem;
}

.profile-security-notice i {
    font-size: 1.75rem;
    color: #3b82f6;
}

.profile-card-enhanced {
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    border: 1px solid #e5e7eb;
    transition: all 0.3s ease;
}

.profile-card-enhanced:hover {
    box-shadow: 0 4px 12px rgba(0,0,0,0.08);
}

.profile-header-gradient {
    background: #ffffff;
    border-bottom: 2px solid #e5e7eb;
    color: #111827;
    padding: 1.5rem 2rem;
}

.profile-header-gradient h3 {
    color: #111827 !important;
}

.profile-header-gradient p {
    color: #6b7280 !important;
}

.profile-form-label {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-weight: 600;
    color: #374151;
    margin-bottom: 0.5rem;
}

.profile-form-label i {
    color: #3b82f6;
}

.profile-input-enhanced {
    border: 1px solid #d1d5db;
    transition: all 0.3s ease;
    padding: 0.75rem 1rem;
}

.profile-input-enhanced:focus {
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
    outline: none;
}

.profile-avatar-wrapper {
    position: relative;
    display: inline-block;
}

.profile-avatar-main {
    width: 100px;
    height: 100px;
    margin: 0 auto 1rem;
    border-radius: 50%;
    background: #3b82f6;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 2.5rem;
    color: white;
    font-weight: 700;
    box-shadow: 0 4px 12px rgba(59, 130, 246, 0.2);
}

.profile-avatar-badge {
    position: absolute;
    bottom: 1rem;
    right: -5px;
    background: #10b981;
    width: 28px;
    height: 28px;
    border-radius: 50%;
    border: 3px solid white;
    display: flex;
    align-items: center;
    justify-content: center;
    box-shadow: 0 2px 8px rgba(16, 185, 129, 0.3);
}

.profile-info-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0.875rem;
    background: #f9fafb;
    border: 1px solid #f3f4f6;
    border-radius: var(--radius-md);
    margin-bottom: 0.75rem;
    transition: all 0.2s ease;
}

.profile-info-row:hover {
    background: #f3f4f6;
    border-color: #e5e7eb;
}

.profile-security-card {
    background: #ffffff;
    border: 2px solid #e5e7eb;
}

.profile-support-card {
    background: #f9fafb;
    border: 2px solid #e5e7eb;
}

.profile-password-hint {
    margin-bottom: 1.5rem;
    display: flex;
    align-items: start;
    gap: 0.75rem;
    padding: 1rem;
    background: #fffbeb;
    border: 1px solid #fcd34d;
    border-left: 4px solid #f59e0b;
    border-radius: var(--radius-md);
}

.profile-security-item {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    margin-bottom: 0.5rem;
    font-size: 0.875rem;
    color: #374151;
}
</style>

<main class="main-content">
    <!-- Page Header -->
    <div class="page-header">
        <h1 class="page-title">ตั้งค่าโปรไฟล์</h1>
        <p class="page-subtitle">จัดการข้อมูลบัญชีและความปลอดภัยของคุณ</p>
    </div>

    <!-- Security Notice -->
    <div class="profile-security-notice">
        <i class="fas fa-shield-alt"></i>
        <div>
            <strong style="display: block; margin-bottom: 0.25rem;">ข้อมูลของคุณปลอดภัย</strong>
            <div style="font-size: 0.875rem; color: #1e40af;">
                เราใช้การเข้ารหัส SSL 256-bit และปกป้องข้อมูลตามมาตรฐานสากล
            </div>
        </div>
    </div>

    <div class="row">
        <!-- Left Column: Forms -->
        <div class="col-8">
            <!-- Account Information Card -->
            <div class="card profile-card-enhanced">
                <div class="profile-header-gradient">
                    <div style="display: flex; align-items: center; gap: 0.75rem;">
                        <i class="fas fa-user-circle" style="font-size: 1.75rem; color: #3b82f6;"></i>
                        <div>
                            <h3 style="margin: 0; font-size: 1.25rem; color: #111827;">ข้อมูลบัญชี</h3>
                            <p style="margin: 0.25rem 0 0; font-size: 0.875rem; color: #6b7280;">อัพเดทข้อมูลส่วนตัวของคุณ</p>
                        </div>
                    </div>
                </div>
                <div class="card-body" style="padding: 2rem;">
                    <form id="profileForm">
                        <div class="form-group">
                            <label class="profile-form-label">
                                <i class="fas fa-user"></i> ชื่อ-นามสกุล
                            </label>
                            <input type="text" id="fullName" class="form-control profile-input-enhanced" placeholder="John Doe">
                        </div>

                        <div class="form-group">
                            <label class="profile-form-label">
                                <i class="fas fa-envelope"></i> อีเมล
                            </label>
                            <input type="email" id="email" class="form-control profile-input-enhanced" placeholder="your@email.com" disabled style="background: #f9fafb; cursor: not-allowed;">
                            <small style="color: #6b7280; font-size: 0.875rem; display: flex; align-items: center; gap: 0.25rem; margin-top: 0.5rem;">
                                <i class="fas fa-lock" style="font-size: 0.75rem;"></i>
                                ไม่สามารถเปลี่ยนอีเมลได้เพื่อความปลอดภัย
                            </small>
                        </div>

                        <div class="row">
                            <div class="col-6">
                                <div class="form-group">
                                    <label class="profile-form-label">
                                        <i class="fas fa-phone"></i> เบอร์โทรศัพท์
                                    </label>
                                    <input type="tel" id="phone" class="form-control profile-input-enhanced" placeholder="0812345678">
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="form-group">
                                    <label class="profile-form-label">
                                        <i class="fas fa-building"></i> ชื่อบริษัท
                                    </label>
                                    <input type="text" id="companyName" class="form-control profile-input-enhanced" placeholder="บริษัท ABC จำกัด">
                                </div>
                            </div>
                        </div>

                        <div id="profileError" class="form-error hidden"></div>

                        <button type="submit" class="btn btn-primary" style="display: inline-flex; align-items: center; gap: 0.5rem; padding: 0.75rem 2rem; font-weight: 600;">
                            <i class="fas fa-save"></i> บันทึกการเปลี่ยนแปลง
                        </button>
                    </form>
                </div>
            </div>

            <!-- Change Password Card -->
            <div class="card profile-card-enhanced mt-4">
                <div class="profile-header-gradient">
                    <div style="display: flex; align-items: center; gap: 0.75rem;">
                        <i class="fas fa-key" style="font-size: 1.75rem; color: #3b82f6;"></i>
                        <div>
                            <h3 style="margin: 0; font-size: 1.25rem; color: #111827;">เปลี่ยนรหัสผ่าน</h3>
                            <p style="margin: 0.25rem 0 0; font-size: 0.875rem; color: #6b7280;">อัพเดทรหัสผ่านเพื่อความปลอดภัย</p>
                        </div>
                    </div>
                </div>
                <div class="card-body" style="padding: 2rem;">
                    <!-- Password Hint -->
                    <div class="profile-password-hint">
                        <i class="fas fa-exclamation-triangle" style="font-size: 1.25rem; color: #d97706;"></i>
                        <div style="flex: 1;">
                            <strong style="display: block; margin-bottom: 0.5rem; color: #92400e;">คำแนะนำรหัสผ่านที่ปลอดภัย:</strong>
                            <ul style="margin: 0; padding: 0 0 0 1.25rem; font-size: 0.875rem; color: #78350f;">
                                <li>ใช้อย่างน้อย 8 ตัวอักษร</li>
                                <li>ผสม A-Z, a-z, 0-9 และอักขระพิเศษ</li>
                                <li>ไม่ใช้รหัสผ่านเดียวกันกับเว็บไซต์อื่น</li>
                            </ul>
                        </div>
                    </div>

                    <form id="passwordForm">
                        <div class="form-group">
                            <label class="profile-form-label">
                                <i class="fas fa-lock"></i> รหัสผ่านปัจจุบัน
                            </label>
                            <input type="password" id="currentPassword" class="form-control profile-input-enhanced" placeholder="••••••••">
                        </div>

                        <div class="row">
                            <div class="col-6">
                                <div class="form-group">
                                    <label class="profile-form-label">
                                        <i class="fas fa-lock"></i> รหัสผ่านใหม่
                                    </label>
                                    <input type="password" id="newPassword" class="form-control profile-input-enhanced" placeholder="••••••••">
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="form-group">
                                    <label class="profile-form-label">
                                        <i class="fas fa-check-circle"></i> ยืนยันรหัสผ่านใหม่
                                    </label>
                                    <input type="password" id="confirmPassword" class="form-control profile-input-enhanced" placeholder="••••••••">
                                </div>
                            </div>
                        </div>

                        <div id="passwordError" class="form-error hidden"></div>

                        <button type="submit" class="btn btn-secondary" style="display: inline-flex; align-items: center; gap: 0.5rem; padding: 0.75rem 2rem; font-weight: 600;">
                            <i class="fas fa-shield-alt"></i> เปลี่ยนรหัสผ่าน
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <!-- Right Column: Account Info -->
        <div class="col-4">
            <!-- Profile Summary Card -->
            <div class="card profile-card-enhanced">
                <div class="card-body" style="padding: 2rem;">
                    <div style="text-align: center; padding-bottom: 1.5rem; border-bottom: 2px solid #e5e7eb;">
                        <div class="profile-avatar-wrapper">
                            <div class="profile-avatar-main" id="profileAvatar">U</div>
                            <div class="profile-avatar-badge">
                                <i class="fas fa-check" style="font-size: 0.75rem; color: white;"></i>
                            </div>
                        </div>
                        <div style="font-weight: 700; font-size: 1.25rem; margin-bottom: 0.25rem; color: #111827;" id="profileName">Loading...</div>
                        <div style="color: #6b7280; font-size: 0.875rem;" id="profileEmail"></div>
                        <div style="margin-top: 0.75rem;">
                            <span class="badge badge-success" id="accountStatus" style="padding: 0.5rem 1rem; font-size: 0.875rem; font-weight: 600;">Active</span>
                        </div>
                    </div>

                    <div style="margin-top: 1.5rem;">
                        <div class="profile-info-row">
                            <div style="display: flex; align-items: center; gap: 0.5rem;">
                                <i class="fas fa-calendar-alt" style="color: #667eea;"></i>
                                <span style="color: #6b7280; font-size: 0.875rem;">สมัครสมาชิกเมื่อ</span>
                            </div>
                            <strong id="memberSince" style="font-size: 0.875rem; color: #111827;">-</strong>
                        </div>

                        <div class="profile-info-row">
                            <div style="display: flex; align-items: center; gap: 0.5rem;">
                                <i class="fas fa-sign-in-alt" style="color: #10b981;"></i>
                                <span style="color: #6b7280; font-size: 0.875rem;">เข้าสู่ระบบล่าสุด</span>
                            </div>
                            <strong id="lastLogin" style="font-size: 0.875rem; color: #111827;">-</strong>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Security Status Card -->
            <div class="card profile-security-card mt-3">
                <div class="card-body" style="padding: 1.5rem;">
                    <div style="display: flex; align-items: center; gap: 0.75rem; margin-bottom: 1rem;">
                        <div style="width: 48px; height: 48px; background: #f3f4f6; border-radius: 50%; display: flex; align-items: center; justify-content: center; border: 2px solid #e5e7eb;">
                            <i class="fas fa-shield-alt" style="color: #3b82f6; font-size: 1.5rem;"></i>
                        </div>
                        <div>
                            <strong style="color: #111827; display: block; font-size: 1.125rem;">ความปลอดภัยบัญชี</strong>
                            <div style="font-size: 0.75rem; color: #3b82f6;">ระดับ: ปลอดภัย</div>
                        </div>
                    </div>
                    <div>
                        <div class="profile-security-item">
                            <i class="fas fa-check-circle" style="color: #10b981;"></i>
                            การเข้ารหัส SSL 256-bit
                        </div>
                        <div class="profile-security-item">
                            <i class="fas fa-check-circle" style="color: #10b981;"></i>
                            ตรวจสอบอีเมลแล้ว
                        </div>
                        <div class="profile-security-item">
                            <i class="fas fa-check-circle" style="color: #10b981;"></i>
                            รหัสผ่านเข้ารหัส
                        </div>
                    </div>
                </div>
            </div>

            <!-- Support Card -->
            <div class="card profile-support-card mt-3">
                <div class="card-body" style="padding: 1.5rem;">
                    <div style="display: flex; align-items: center; gap: 0.75rem; margin-bottom: 0.75rem;">
                        <i class="fas fa-headset" style="font-size: 1.75rem; color: #6b7280;"></i>
                        <strong style="color: #111827; font-size: 1.125rem;">ต้องการความช่วยเหลือ?</strong>
                    </div>
                    <p style="font-size: 0.875rem; color: #6b7280; margin-bottom: 1rem; line-height: 1.6;">
                        ทีมสนับสนุนของเราพร้อมช่วยเหลือคุณตลอด 24/7
                    </p>
                    <a href="mailto:support@aiautomation.com" class="btn btn-outline" style="width: 100%; display: inline-flex; align-items: center; justify-content: center; gap: 0.5rem; border-color: #3b82f6; color: #3b82f6; font-weight: 600; padding: 0.75rem;">
                        <i class="fas fa-envelope"></i> ติดต่อฝ่ายสนับสนุน
                    </a>
                </div>
            </div>
        </div>
    </div>
</main>

<?php
$inline_script = <<<'JAVASCRIPT'
document.addEventListener('DOMContentLoaded', async () => {
    await loadUserProfile();
});

async function loadUserProfile() {
    try {
        const response = await apiCall(API_ENDPOINTS.AUTH_ME);
        if (response && response.success) {
            displayUserProfile(response.data);
        }
    } catch (error) {
        console.error('Failed to load profile:', error);
    }
}

function displayUserProfile(user) {
    const initial = (user.full_name || user.email).charAt(0).toUpperCase();
    
    // Sidebar
    const sidebarAvatarEl = document.getElementById('userAvatar');
    const sidebarNameEl = document.getElementById('userName');
    const sidebarEmailEl = document.getElementById('userEmail');
    
    if (sidebarAvatarEl) sidebarAvatarEl.textContent = initial;
    if (sidebarNameEl) sidebarNameEl.textContent = user.full_name || user.email;
    if (sidebarEmailEl) sidebarEmailEl.textContent = user.email;
    
    // Profile card
    document.getElementById('profileAvatar').textContent = initial;
    document.getElementById('profileName').textContent = user.full_name || user.email;
    document.getElementById('profileEmail').textContent = user.email;
    document.getElementById('accountStatus').textContent = user.status === 'active' ? 'Active' : 'Inactive';
    document.getElementById('memberSince').textContent = formatDate(user.created_at).split(' ')[0];
    document.getElementById('lastLogin').textContent = user.last_login ? formatDate(user.last_login).split(' ')[0] : 'ไม่ทราบ';
    
    // Form fields
    document.getElementById('fullName').value = user.full_name || '';
    document.getElementById('email').value = user.email;
    document.getElementById('phone').value = user.phone || '';
    document.getElementById('companyName').value = user.company_name || '';
}

// Real-time phone validation
document.getElementById('phone')?.addEventListener('input', (e) => {
    const phone = e.target.value;
    const phonePattern = /^0[0-9]{9}$/;  // 10 digits starting with 0
    
    if (phone && !phonePattern.test(phone)) {
        e.target.style.borderColor = '#ef4444';
        e.target.setAttribute('aria-invalid', 'true');
    } else {
        e.target.style.borderColor = '';
        e.target.removeAttribute('aria-invalid');
    }
});

// Password strength checker
function checkPasswordStrength(password) {
    let strength = 0;
    const feedback = [];
    
    if (password.length >= 8) strength += 1;
    else feedback.push('ใช้อย่างน้อย 8 ตัวอักษร');
    
    if (/[a-z]/.test(password)) strength += 1;
    else feedback.push('ใช้ตัวพิมพ์เล็ก (a-z)');
    
    if (/[A-Z]/.test(password)) strength += 1;
    else feedback.push('ใช้ตัวพิมพ์ใหญ่ (A-Z)');
    
    if (/[0-9]/.test(password)) strength += 1;
    else feedback.push('ใช้ตัวเลข (0-9)');
    
    if (/[^A-Za-z0-9]/.test(password)) strength += 1;
    else feedback.push('ใช้อักขระพิเศษ (!@#$...)');
    
    return { strength, feedback };
}

// Show password strength indicator
document.getElementById('newPassword')?.addEventListener('input', (e) => {
    const password = e.target.value;
    const { strength, feedback } = checkPasswordStrength(password);
    
    // Remove existing indicator
    let indicator = document.getElementById('passwordStrengthIndicator');
    if (!indicator && password) {
        indicator = document.createElement('div');
        indicator.id = 'passwordStrengthIndicator';
        indicator.style.marginTop = '0.5rem';
        e.target.parentElement.appendChild(indicator);
    }
    
    if (!password) {
        if (indicator) indicator.remove();
        return;
    }
    
    const strengthLabels = ['อ่อนมาก', 'อ่อน', 'ปานกลาง', 'ดี', 'แข็งแรง'];
    const strengthColors = ['#ef4444', '#f59e0b', '#eab308', '#84cc16', '#22c55e'];
    const strengthLevel = Math.min(strength, 4);
    
    indicator.innerHTML = `
        <div style="display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.25rem;">
            <div style="flex: 1; height: 4px; background: #e5e7eb; border-radius: 2px; overflow: hidden;">
                <div style="width: ${(strength / 5) * 100}%; height: 100%; background: ${strengthColors[strengthLevel]}; transition: all 0.3s;"></div>
            </div>
            <span style="font-size: 0.75rem; font-weight: 600; color: ${strengthColors[strengthLevel]};">
                ${strengthLabels[strengthLevel]}
            </span>
        </div>
        ${feedback.length > 0 ? `
            <div style="font-size: 0.75rem; color: #6b7280;">
                ${feedback.join(' • ')}
            </div>
        ` : ''}
    `;
});

// Confirm password validation
document.getElementById('confirmPassword')?.addEventListener('input', (e) => {
    const newPassword = document.getElementById('newPassword').value;
    const confirmPassword = e.target.value;
    
    if (confirmPassword && newPassword !== confirmPassword) {
        e.target.style.borderColor = '#ef4444';
        e.target.setAttribute('aria-invalid', 'true');
    } else {
        e.target.style.borderColor = '';
        e.target.removeAttribute('aria-invalid');
    }
});

// Profile form submission
document.getElementById('profileForm')?.addEventListener('submit', async (e) => {
    e.preventDefault();
    showLoading();
    
    const fullName = document.getElementById('fullName').value;
    const phone = document.getElementById('phone').value;
    const companyName = document.getElementById('companyName').value;
    
    try {
        const res = await apiCall(API_ENDPOINTS.USER_PROFILE, {
            method: 'PUT',
            body: {
                full_name: fullName,
                phone: phone,
                company_name: companyName
            }
        });
        
        hideLoading();
        
        if (!res || !res.success) {
            showToast(res?.message || 'ไม่สามารถบันทึกข้อมูลได้', 'error');
            return;
        }
        
        showToast('บันทึกข้อมูลสำเร็จ', 'success');
        await loadUserProfile(); // Reload profile to show updated data
    } catch (err) {
        hideLoading();
        showToast('เกิดข้อผิดพลาด: ' + (err.message || 'ไม่ทราบสาเหตุ'), 'error');
    }
});

// Password form submission
document.getElementById('passwordForm')?.addEventListener('submit', async (e) => {
    e.preventDefault();
    
    const currentPassword = document.getElementById('currentPassword').value;
    const newPassword = document.getElementById('newPassword').value;
    const confirmPassword = document.getElementById('confirmPassword').value;
    
    const errorEl = document.getElementById('passwordError');
    
    if (!currentPassword || !newPassword || !confirmPassword) {
        errorEl.textContent = 'กรุณากรอกข้อมูลให้ครบถ้วน';
        errorEl.classList.remove('hidden');
        return;
    }
    
    if (newPassword !== confirmPassword) {
        errorEl.textContent = 'รหัสผ่านไม่ตรงกัน';
        errorEl.classList.remove('hidden');
        return;
    }
    
    if (newPassword.length < 8) {
        errorEl.textContent = 'รหัสผ่านต้องมีอย่างน้อย 8 ตัวอักษร';
        errorEl.classList.remove('hidden');
        return;
    }
    
    errorEl.classList.add('hidden');
    showLoading();

    try {
        const res = await apiCall(API_ENDPOINTS.PROFILE_PASSWORD, {
            method: 'POST',
            body: {
                current_password: currentPassword,
                new_password: newPassword
            }
        });

        hideLoading();

        if (!res || !res.success) {
            errorEl.textContent = res?.message || 'ไม่สามารถเปลี่ยนรหัสผ่านได้';
            errorEl.classList.remove('hidden');
            return;
        }

        showToast('เปลี่ยนรหัสผ่านสำเร็จ', 'success');
        document.getElementById('passwordForm').reset();
    } catch (err) {
        hideLoading();
        errorEl.textContent = 'เกิดข้อผิดพลาด: ' + (err.message || 'ไม่ทราบสาเหตุ');
        errorEl.classList.remove('hidden');
    }
});
JAVASCRIPT;

include('../includes/customer/footer.php');
?>
