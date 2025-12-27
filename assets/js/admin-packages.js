/**
 * Admin Packages Management JavaScript  
 * Full API Integration
 */

requireAuth();

// API Base URL - apiCall in auth.js already prefixes /autobot/api
const ADMIN_PACKAGES_API_BASE = '/api/admin/packages';

// Get modal elements
const packageModal = document.getElementById('packageModal');
const packageForm = document.getElementById('packageForm');
const packagesTable = document.getElementById('packagesTable');

// Track editing mode
let editingPackageId = null;

/**
 * Load all packages on page load
 */
document.addEventListener('DOMContentLoaded', () => {
    loadPackages();
});

/**
 * Load packages from API
 */
async function loadPackages() {
    try {
        const response = await apiCall(`${ADMIN_PACKAGES_API_BASE}/list.php`);

        if (response.success) {
            renderPackagesTable(response.data || []);
        } else {
            alert('ไม่สามารถโหลดข้อมูลแพ็คเกจได้: ' + response.message);
        }
    } catch (error) {
        console.error('Load packages error:', error);
        alert('เกิดข้อผิดพลาดในการโหลดข้อมูล');
    }
}

/**
 * Render packages table
 */
function renderPackagesTable(packages) {
    if (!packagesTable) return;

    if (packages.length === 0) {
        packagesTable.innerHTML = `
            <tr>
                <td colspan="9" style="text-align: center; padding: 2rem; color: var(--color-gray);">
                    ยังไม่มีแพ็คเกจ
                </td>
            </tr>
        `;
        return;
    }

    packagesTable.innerHTML = packages.map(pkg => `
        <tr>
            <td><strong>${escapeHtml(pkg.name || '')}</strong></td>
            <td>฿${formatNumber(pkg.monthly_price || 0)}</td>
            <td>${pkg.billing_period_days || 30} วัน</td>
            <td>${pkg.included_requests == null ? 'ไม่จำกัด' : formatNumber(pkg.included_requests)}</td>
            <td>${pkg.included_requests == null ? 'ไม่จำกัด' : formatNumber(pkg.included_requests)}</td>
            <td>${pkg.overage_rate ? '฿' + formatNumber(pkg.overage_rate) : '-'}</td>
            <td>${pkg.overage_rate ? '฿' + formatNumber(pkg.overage_rate) : '-'}</td>
            <td>
                <span class="badge badge-${pkg.is_active ? 'success' : 'danger'}">
                    ${pkg.is_active ? 'เปิดใช้งาน' : 'ปิดใช้งาน'}
                </span>
            </td>
            <td>
                <button class="btn btn-sm btn-outline" onclick="editPackage(${pkg.id})">
                    <i class="fas fa-edit"></i>
                </button>
                <button class="btn btn-sm btn-danger" onclick="deletePackage(${pkg.id})">
                    <i class="fas fa-trash"></i>
                </button>
            </td>
        </tr>
    `).join('');
}

/**
 * Show Create Package Modal
 */
function showCreatePackageModal() {
    editingPackageId = null;
    document.getElementById('modalTitle').textContent = 'สร้างแพ็คเกจใหม่';
    packageForm.reset();
    openModal();
}

/**
 * Edit Package - Load data and show modal
 */
async function editPackage(id) {
    try {
        const response = await apiCall(`${ADMIN_PACKAGES_API_BASE}/get.php?id=${id}`);

        if (response.success) {
            const pkg = response.data;
            editingPackageId = id;

            // Populate form
            document.getElementById('packageName').value = pkg.name || '';
            document.getElementById('monthlyPrice').value = pkg.monthly_price ?? '';
            document.getElementById('billingPeriod').value = pkg.billing_period_days ?? 30;
            document.getElementById('messageLimit').value = pkg.included_requests ?? '';
            document.getElementById('apiLimit').value = pkg.included_requests ?? '';
            document.getElementById('overageMessage').value = pkg.overage_rate ?? '';
            document.getElementById('overageApi').value = pkg.overage_rate ?? '';
            document.getElementById('description').value = pkg.description || '';
            document.getElementById('isActive').checked = !!pkg.is_active;

            // Update modal title
            document.getElementById('modalTitle').textContent = 'แก้ไขแพ็คเกจ';
            openModal();
        } else {
            alert('ไม่สามารถโหลดข้อมูลแพ็คเกจได้');
        }
    } catch (error) {
        console.error('Edit package error:', error);
        alert('เกิดข้อผิดพลาด');
    }
}

/**
 * Delete Package
 */
async function deletePackage(id) {
    if (!confirm('คุณแน่ใจหรือไม่ที่จะลบแพ็คเกจนี้?')) {
        return;
    }

    try {
        const response = await apiCall(`${ADMIN_PACKAGES_API_BASE}/delete.php?id=${id}`, {
            method: 'DELETE'
        });

        if (response.success) {
            alert(response.data?.message || 'ลบเรียบร้อยแล้ว');
            loadPackages(); // Reload table
        } else {
            alert('ไม่สามารถลบแพ็คเกจได้: ' + response.message);
        }
    } catch (error) {
        console.error('Delete package error:', error);
        alert('เกิดข้อผิดพลาด');
    }
}

/**
 * Handle form submission (Create or Update)
 */
packageForm?.addEventListener('submit', async (e) => {
    e.preventDefault();

    const formData = {
        name: document.getElementById('packageName').value.trim(),
        description: document.getElementById('description').value.trim(),
        monthly_price: parseFloat(document.getElementById('monthlyPrice').value),
        billing_period_days: parseInt(document.getElementById('billingPeriod').value, 10) || 30,
        included_requests: document.getElementById('messageLimit').value === '' ? null : parseInt(document.getElementById('messageLimit').value, 10),
        overage_rate: document.getElementById('overageMessage').value === '' ? null : parseFloat(document.getElementById('overageMessage').value),
        is_active: document.getElementById('isActive').checked
    };

    try {
        let response;

        if (editingPackageId) {
            // Update existing package
            response = await apiCall(`${ADMIN_PACKAGES_API_BASE}/update.php?id=${editingPackageId}`, {
                method: 'PUT',
                body: formData
            });
        } else {
            // Create new package
            response = await apiCall(`${ADMIN_PACKAGES_API_BASE}/create.php`, {
                method: 'POST',
                body: formData
            });
        }

        if (response.success) {
            alert(editingPackageId ? 'บันทึกการแก้ไขสำเร็จ!' : 'สร้างแพ็คเกจสำเร็จ!');
            closeModal();
            loadPackages(); // Reload table
        } else {
            alert('เกิดข้อผิดพลาด: ' + response.message);
        }
    } catch (error) {
        console.error('Save package error:', error);
        alert('เกิดข้อผิดพลาดในการบันทึก');
    }
});

/**
 * Modal Functions
 */
function hidePackageModal() {
    closeModal();
}

function openModal() {
    packageModal.classList.remove('hidden');
    setTimeout(() => {
        packageModal.style.opacity = '1';
    }, 10);
}

function closeModal() {
    packageModal.style.opacity = '0';
    setTimeout(() => {
        packageModal.classList.add('hidden');
        editingPackageId = null;
    }, 300);
}

// Close modal on backdrop click
packageModal?.addEventListener('click', (e) => {
    if (e.target === packageModal) {
        closeModal();
    }
});

// Close modal on ESC key
document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && !packageModal.classList.contains('hidden')) {
        closeModal();
    }
});

// Prevent modal content from triggering backdrop click
document.querySelector('.modal-content')?.addEventListener('click', (e) => {
    e.stopPropagation();
});

/**
 * Helper Functions
 */
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}
