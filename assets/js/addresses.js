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

function filterAddresses(type) {
    addressFilter = type || '';
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
    const card = document.querySelector(`.address-card[data-address-id="${CSS.escape(id)}"]`);
    if (!card) return;

    // Remove previous highlights
    document.querySelectorAll('.address-card.is-highlighted').forEach(el => el.classList.remove('is-highlighted'));

    card.classList.add('is-highlighted');
    card.scrollIntoView({ behavior: 'smooth', block: 'center' });
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
    const container = document.getElementById('addressesContainer');

    if (!addresses || addresses.length === 0) {
        container.innerHTML = `
            <div style="grid-column:1/-1;text-align:center;padding:3rem;">
                <p style="color:var(--color-gray);font-size:1.1rem;">ไม่พบที่อยู่ตามตัวกรอง/คำค้น</p>
                <div style="margin-top:1rem;">
                    <button class="btn btn-outline" onclick="clearAddressFilters()">ล้างตัวกรอง</button>
                </div>
            </div>
        `;
        return;
    }

    const getLandmark = (additionalInfo) => {
        if (!additionalInfo) return '';
        // API returns already-decoded object; some older data might be JSON string.
        if (typeof additionalInfo === 'string') {
            try {
                const obj = JSON.parse(additionalInfo);
                return (obj && obj.landmark) ? String(obj.landmark) : '';
            } catch {
                return '';
            }
        }
        if (typeof additionalInfo === 'object') {
            return additionalInfo.landmark ? String(additionalInfo.landmark) : '';
        }
        return '';
    };

    container.innerHTML = addresses.map(addr => {
        const landmark = getLandmark(addr.additional_info);
        return `
        <div class="address-card ${addr.is_default ? 'address-default' : ''}" data-address-id="${addr.id}">
            ${addr.is_default ? '<div class="default-badge">✓ ที่อยู่หลัก</div>' : ''}
            <div class="address-header">
                <div class="address-recipient">${addr.recipient_name}</div>
                <div class="address-phone">📞 ${addr.phone}</div>
            </div>
            <div class="address-details">
                ${addr.address_line1}<br>
                ${addr.address_line2 ? addr.address_line2 + '<br>' : ''}
                ${addr.subdistrict ? 'ต.' + addr.subdistrict + ' ' : ''}อ.${addr.district} 
                จ.${addr.province} ${addr.postal_code}
            </div>
            ${landmark ? `
                <div class="address-note">
                    📍 ${landmark}
                </div>
            ` : ''}
            <div class="address-actions">
                ${!addr.is_default ? `<button class="btn btn-sm btn-outline" onclick="setDefaultAddress(${addr.id})">ตั้งเป็นหลัก</button>` : ''}
                <button class="btn btn-sm btn-secondary" onclick="editAddress(${addr.id})">แก้ไข</button>
                <button class="btn btn-sm btn-danger" onclick="deleteAddress(${addr.id})">ลบ</button>
            </div>
        </div>
    `;
    }).join('');
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
    if (!confirm('ต้องการลบที่อยู่นี้ใช่หรือไม่?')) return;

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
    const container = document.getElementById('addressesContainer');
    container.innerHTML = `
        <div style="grid-column:1/-1;text-align:center;padding:3rem;">
            <p style="color:var(--color-danger);">${message}</p>
        </div>
    `;
}

// Use global showToast from auth.js if available; fallback to alert.
function toast(message, type = 'success') {
    if (typeof showToast === 'function') return showToast(message, type);
    alert(message);
}
