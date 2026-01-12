// Addresses Page JavaScript
let allAddresses = [];
let filteredAddresses = [];
let addressSearchQuery = '';
let addressFilter = ''; // '' | 'default'
let targetAddressIdFromQuery = '';
let currentPage = 1;
let totalPages = 1;
const ITEMS_PER_PAGE = 20;

function getQueryParam(name) {
    try {
        return new URLSearchParams(window.location.search).get(name);
    } catch {
        return null;
    }
}

document.addEventListener('DOMContentLoaded', () => {
    // Deep-link support
    targetAddressIdFromQuery = getQueryParam('address_id') || '';
    const searchFromQuery = getQueryParam('search') || '';

    const searchInput = document.getElementById('addressSearch');
    if (searchInput && searchFromQuery) {
        searchInput.value = searchFromQuery;
        addressSearchQuery = String(searchFromQuery).trim().toLowerCase();
    }

    setupAddressSearchAndFilters();
    loadAddresses();
});

function setupAddressSearchAndFilters() {
    const searchInput = document.getElementById('addressSearch');
    if (searchInput) {
        searchInput.addEventListener('input', (e) => {
            addressSearchQuery = e.target.value.trim().toLowerCase();
            applyAddressFilters();
        });
    }
}

function filterAddresses(type, evt) {
    addressFilter = type || '';
    
    // Update active chip
    document.querySelectorAll('.filter-chip').forEach(chip => chip.classList.remove('active'));
    
    const target = (evt && (evt.currentTarget || evt.target)) ? (evt.currentTarget || evt.target) : null;
    const btn = target ? target.closest('.filter-chip') : document.querySelector(`.filter-chip[data-filter="${type}"]`);
    if (btn) btn.classList.add('active');
    
    applyAddressFilters();
}

function clearAddressFilters() {
    const searchInput = document.getElementById('addressSearch');
    if (searchInput) searchInput.value = '';
    addressSearchQuery = '';
    addressFilter = '';
    applyAddressFilters();
}

function applyAddressFilters() {
    let result = [...allAddresses];

    if (addressFilter === 'default') {
        result = result.filter(a => !!a.is_default);
    }

    if (addressSearchQuery) {
        const q = addressSearchQuery;
        result = result.filter(a => {
            const fields = [
                a.recipient_name,
                a.phone,
                a.address_line1,
                a.address_line2,
                a.subdistrict,
                a.district,
                a.province,
                a.postal_code
            ].filter(Boolean);
            return fields.some(f => String(f).toLowerCase().includes(q));
        });
    }

    filteredAddresses = result;
    renderAddresses(filteredAddresses);
    updateAddressFilterHint();

    // If deep-linked, try highlight after render
    if (targetAddressIdFromQuery) {
        highlightAddressCardById(targetAddressIdFromQuery);
    }
}

function updateAddressFilterHint() {
    const el = document.getElementById('addressFilterHint');
    if (!el) return;

    const total = allAddresses.length;
    const shown = filteredAddresses.length;

    const parts = [];
    if (addressSearchQuery) parts.push(`ค้นหา: "${addressSearchQuery}"`);
    if (addressFilter === 'default') parts.push('ตัวกรอง: ที่อยู่หลัก');

    el.textContent = parts.length
        ? `แสดง ${shown}/${total} รายการ (${parts.join(' • ')})`
        : `แสดงทั้งหมด ${shown} รายการ`;
}

function highlightAddressCardById(addressId) {
    const id = String(addressId);
    
    // Try table row first
    const row = document.querySelector(`tr[data-address-id="${CSS.escape(id)}"]`);
    if (row) {
        document.querySelectorAll('tr.is-highlighted').forEach(el => el.classList.remove('is-highlighted'));
        row.classList.add('is-highlighted');
        row.scrollIntoView({ behavior: 'smooth', block: 'center' });
        return;
    }
    
    // Try mobile card
    const card = document.querySelector(`.address-mobile-card[data-address-id="${CSS.escape(id)}"]`);
    if (card) {
        document.querySelectorAll('.address-mobile-card.is-highlighted').forEach(el => el.classList.remove('is-highlighted'));
        card.classList.add('is-highlighted');
        card.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
}

async function loadAddresses(page = 1) {
    currentPage = page;
    try {
        const result = await apiCall(API_ENDPOINTS.CUSTOMER_ADDRESSES + `?page=${currentPage}&limit=${ITEMS_PER_PAGE}`);

        if (result && result.success) {
            // API returns { data: { addresses: [...], pagination: {...} } }
            allAddresses = (result.data && Array.isArray(result.data.addresses)) ? result.data.addresses : (result.data || []);
            filteredAddresses = [...allAddresses];
            
            const pagination = result.data?.pagination || {};
            totalPages = pagination.total_pages || 1;
            renderPagination(pagination.total || allAddresses.length, totalPages);

            // Apply filters (also triggers deep-link highlight)
            applyAddressFilters();
        } else {
            showError('ไม่สามารถโหลดข้อมูลได้');
        }
    } catch (error) {
        console.error('Error:', error);
        showError('เกิดข้อผิดพลาดในการโหลดข้อมูล');
    }
}

function renderPagination(total, pages) {
    const container = document.getElementById('addressesPagination');
    if (!container || pages <= 1) {
        if (container) container.innerHTML = '';
        return;
    }
    
    const prevDisabled = currentPage === 1 ? 'disabled' : '';
    const nextDisabled = currentPage === pages ? 'disabled' : '';
    
    container.innerHTML = `
        <button class="btn-pagination" onclick="goToPage(${currentPage - 1})" ${prevDisabled}>
            <i class="fas fa-chevron-left"></i>
        </button>
        <span class="page-indicator">หน้า ${currentPage} / ${pages} (${total} รายการ)</span>
        <button class="btn-pagination" onclick="goToPage(${currentPage + 1})" ${nextDisabled}>
            <i class="fas fa-chevron-right"></i>
        </button>
    `;
}

function goToPage(page) {
    if (page < 1 || page > totalPages) return;
    loadAddresses(page);
}

function renderAddresses(addresses) {
    const tableBody = document.getElementById('addressesTableBody');
    const mobileContainer = document.getElementById('addressesMobileContainer');

    // Empty state
    if (!addresses || addresses.length === 0) {
        const emptyHtml = `
            <div style="text-align:center;padding:3rem;">
                <div style="font-size:3rem;margin-bottom:1rem;">📍</div>
                <p style="color:#6b7280;font-size:1.1rem;margin-bottom:1rem;">ไม่พบที่อยู่ตามตัวกรอง/คำค้น</p>
                <button class="btn btn-outline" onclick="clearAddressFilters()">ล้างตัวกรอง</button>
            </div>
        `;
        
        if (tableBody) {
            tableBody.innerHTML = `<tr><td colspan="6">${emptyHtml}</td></tr>`;
        }
        if (mobileContainer) {
            mobileContainer.innerHTML = emptyHtml;
        }
        return;
    }

    // Helper function
    const getFullAddress = (addr) => {
        let parts = [addr.address_line1];
        if (addr.address_line2) parts.push(addr.address_line2);
        if (addr.subdistrict) parts.push('ต.' + addr.subdistrict);
        parts.push('อ.' + addr.district);
        return parts.join(' ');
    };

    // Render Desktop Table
    if (tableBody) {
        tableBody.innerHTML = addresses.map(addr => `
            <tr data-address-id="${addr.id}">
                <td>
                    ${addr.is_default ? '<span class="default-badge-sm" title="ที่อยู่หลัก"><i class="fas fa-star" style="font-size:0.6rem;"></i></span>' : ''}
                </td>
                <td>
                    <div class="recipient-cell">
                        <span class="recipient-name">${addr.recipient_name}</span>
                        <span class="recipient-phone">${addr.phone}</span>
                    </div>
                </td>
                <td>
                    <div class="address-cell">${getFullAddress(addr)}</div>
                </td>
                <td>${addr.province}</td>
                <td>${addr.postal_code}</td>
                <td>
                    <div class="action-btns">
                        ${!addr.is_default ? `<button class="btn-action btn-primary-action" onclick="setDefaultAddress(${addr.id})" title="ตั้งเป็นหลัก"><i class="fas fa-star"></i></button>` : ''}
                        <button class="btn-action" onclick="editAddress(${addr.id})" title="แก้ไข"><i class="fas fa-edit"></i></button>
                        <button class="btn-action btn-danger-action" onclick="deleteAddress(${addr.id})" title="ลบ"><i class="fas fa-trash"></i></button>
                    </div>
                </td>
            </tr>
        `).join('');
    }

    // Render Mobile Cards
    if (mobileContainer) {
        mobileContainer.innerHTML = addresses.map(addr => `
            <div class="address-mobile-card ${addr.is_default ? 'is-default' : ''}" data-address-id="${addr.id}">
                <div class="address-mobile-header">
                    <div>
                        <div class="address-mobile-recipient">${addr.recipient_name}</div>
                        <div class="address-mobile-phone">${addr.phone}</div>
                    </div>
                </div>
                <div class="address-mobile-body">
                    ${getFullAddress(addr)}<br>
                    จ.${addr.province} ${addr.postal_code}
                </div>
                <div class="address-mobile-actions">
                    ${!addr.is_default ? `<button class="btn-action btn-primary-action" onclick="setDefaultAddress(${addr.id})"><i class="fas fa-star"></i> ตั้งเป็นหลัก</button>` : ''}
                    <button class="btn-action" onclick="editAddress(${addr.id})"><i class="fas fa-edit"></i> แก้ไข</button>
                    <button class="btn-action btn-danger-action" onclick="deleteAddress(${addr.id})"><i class="fas fa-trash"></i> ลบ</button>
                </div>
            </div>
        `).join('');
    }
}

function showAddressForm(addressId = null) {
    const modal = document.getElementById('addressModal');
    const form = document.getElementById('addressForm');
    const title = document.getElementById('modalTitle');

    form.reset();

    // Ensure modal UI is consistent
    modal.style.display = 'flex';
    document.body.style.overflow = 'hidden';

    if (addressId) {
        const address = allAddresses.find(a => a.id === addressId);
        if (address) {
            title.textContent = 'แก้ไขที่อยู่';
            document.getElementById('addressId').value = address.id;
            document.getElementById('recipientName').value = address.recipient_name;
            document.getElementById('phone').value = address.phone;
            document.getElementById('addressLine1').value = address.address_line1;
            document.getElementById('addressLine2').value = address.address_line2 || '';
            document.getElementById('subdistrict').value = address.subdistrict || '';
            document.getElementById('district').value = address.district;
            document.getElementById('province').value = address.province;
            document.getElementById('postalCode').value = address.postal_code;
        }
    } else {
        title.textContent = 'เพิ่มที่อยู่ใหม่';
        document.getElementById('addressId').value = '';
    }
}

function editAddress(id) {
    showAddressForm(id);
}

async function setDefaultAddress(addressId) {
    const confirmed = await showConfirmDialog({
        title: 'ตั้งเป็นที่อยู่หลัก',
        message: 'ต้องการตั้งที่อยู่นี้เป็นที่อยู่หลักใช่หรือไม่?',
        confirmText: 'ยืนยัน',
        cancelText: 'ยกเลิก',
        type: 'confirm'
    });
    
    if (!confirmed) return;
    
    try {
        // Router expects method POST with action=set_default
        const result = await apiCall(API_ENDPOINTS.CUSTOMER_ADDRESS_SET_DEFAULT(addressId), {
            method: 'POST'
        });

        if (result && result.success) {
            toast('ตั้งค่าที่อยู่หลักเรียบร้อย', 'success');
            await loadAddresses();
        } else {
            toast((result && result.message) || 'ไม่สามารถดำเนินการได้', 'error');
        }
    } catch (error) {
        toast('เกิดข้อผิดพลาด', 'error');
    }
}

async function deleteAddress(addressId) {
    const confirmed = await showConfirmDialog({
        title: 'ลบที่อยู่',
        message: 'ต้องการลบที่อยู่นี้ใช่หรือไม่? การดำเนินการนี้ไม่สามารถย้อนกลับได้',
        confirmText: 'ลบ',
        cancelText: 'ยกเลิก',
        type: 'danger'
    });
    
    if (!confirmed) return;

    try {
        const result = await apiCall(API_ENDPOINTS.CUSTOMER_ADDRESS_DETAIL(addressId), {
            method: 'DELETE'
        });

        if (result && result.success) {
            toast('ลบที่อยู่เรียบร้อย', 'success');
            await loadAddresses();
        } else {
            toast((result && result.message) || 'ไม่สามารถลบได้', 'error');
        }
    } catch (error) {
        toast('เกิดข้อผิดพลาด', 'error');
    }
}

// Custom Confirm Dialog
function showConfirmDialog(options = {}) {
    return new Promise((resolve) => {
        const {
            title = 'ยืนยัน',
            message = 'คุณแน่ใจหรือไม่?',
            confirmText = 'ยืนยัน',
            cancelText = 'ยกเลิก',
            type = 'confirm' // 'confirm' | 'danger'
        } = options;
        
        const icon = type === 'danger' ? '⚠️' : '❓';
        const btnClass = type === 'danger' ? 'btn-danger' : 'btn-confirm';
        
        const overlay = document.createElement('div');
        overlay.className = 'confirm-dialog-overlay';
        overlay.innerHTML = `
            <div class="confirm-dialog-box">
                <div class="confirm-dialog-icon">${icon}</div>
                <div class="confirm-dialog-title">${title}</div>
                <div class="confirm-dialog-message">${message}</div>
                <div class="confirm-dialog-buttons">
                    <button class="confirm-dialog-btn btn-cancel">${cancelText}</button>
                    <button class="confirm-dialog-btn ${btnClass}">${confirmText}</button>
                </div>
            </div>
        `;
        
        document.body.appendChild(overlay);
        document.body.style.overflow = 'hidden';
        
        const cleanup = (result) => {
            document.body.removeChild(overlay);
            document.body.style.overflow = '';
            resolve(result);
        };
        
        // Event listeners
        overlay.querySelector('.btn-cancel').addEventListener('click', () => cleanup(false));
        overlay.querySelector(`.${btnClass}`).addEventListener('click', () => cleanup(true));
        overlay.addEventListener('click', (e) => {
            if (e.target === overlay) cleanup(false);
        });
        
        // ESC to close
        const escHandler = (e) => {
            if (e.key === 'Escape') {
                document.removeEventListener('keydown', escHandler);
                cleanup(false);
            }
        };
        document.addEventListener('keydown', escHandler);
        
        // Focus confirm button
        setTimeout(() => overlay.querySelector(`.${btnClass}`).focus(), 50);
    });
}

function closeAddressModal() {
    const modal = document.getElementById('addressModal');
    if (modal) modal.style.display = 'none';
    document.body.style.overflow = '';
}

// Close on ESC for better UX
document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') closeAddressModal();
});

document.getElementById('addressForm')?.addEventListener('submit', async (e) => {
    e.preventDefault();

    const addressId = document.getElementById('addressId').value;
    const data = {
        recipient_name: document.getElementById('recipientName').value,
        phone: document.getElementById('phone').value,
        address_line1: document.getElementById('addressLine1').value,
        address_line2: document.getElementById('addressLine2').value,
        subdistrict: document.getElementById('subdistrict').value,
        district: document.getElementById('district').value,
        province: document.getElementById('province').value,
        postal_code: document.getElementById('postalCode').value
    };

    try {
        const url = addressId ?
            API_ENDPOINTS.CUSTOMER_ADDRESS_DETAIL(addressId) :
            API_ENDPOINTS.CUSTOMER_ADDRESSES;

        const result = await apiCall(url, {
            method: addressId ? 'PUT' : 'POST',
            body: data
        });

        if (result && result.success) {
            toast(addressId ? 'แก้ไขที่อยู่เรียบร้อย' : 'เพิ่มที่อยู่เรียบร้อย', 'success');
            closeAddressModal();
            await loadAddresses();
        } else {
            toast((result && result.message) || 'ไม่สามารถบันทึกได้', 'error');
        }
    } catch (error) {
        toast('เกิดข้อผิดพลาด', 'error');
    }
});

function showError(message) {
    const tableBody = document.getElementById('addressesTableBody');
    const mobileContainer = document.getElementById('addressesMobileContainer');
    
    const errorHtml = `
        <div style="text-align:center;padding:3rem;">
            <p style="color:#dc2626;">${message}</p>
        </div>
    `;
    
    if (tableBody) {
        tableBody.innerHTML = `<tr><td colspan="6">${errorHtml}</td></tr>`;
    }
    if (mobileContainer) {
        mobileContainer.innerHTML = errorHtml;
    }
}

// Use global showToast from auth.js if available; fallback to alert.
function toast(message, type = 'success') {
    if (typeof showToast === 'function') return showToast(message, type);
    alert(message);
}
