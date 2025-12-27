// Orders Page JavaScript
let allOrders = [];

function getQueryParam(name) {
    try {
        return new URLSearchParams(window.location.search).get(name);
    } catch {
        return null;
    }
}

function buildPaymentHistoryLinkForOrderNo(orderNo) {
    const q = orderNo ? encodeURIComponent(String(orderNo)) : '';
    const base = (typeof PATH !== 'undefined' && typeof PATH.page === 'function')
        ? PATH.page('payment-history.php')
        : '/payment-history.php';
    return q ? `${base}?order_no=${q}` : base;
}

function buildAddressesLinkForOrder(order) {
    const addressId = order?.shipping_address_id || order?.address_id || order?.customer_address_id;
    const base = (typeof PATH !== 'undefined' && typeof PATH.page === 'function')
        ? PATH.page('addresses.php')
        : '/addresses.php';

    if (addressId) return `${base}?address_id=${encodeURIComponent(String(addressId))}`;
    return base;
}

document.addEventListener('DOMContentLoaded', () => {
    loadOrders();
});

async function loadOrders() {
    try {
        const result = await apiCall(API_ENDPOINTS.CUSTOMER_ORDERS);

        if (result && result.success) {
            // API returns { data: { orders: [...], pagination: {...} } }
            allOrders = (result.data && Array.isArray(result.data.orders)) ? result.data.orders : (result.data || []);

            // Support deep-links from payment-history
            const targetOrderNo = getQueryParam('order_no') || getQueryParam('payment_order_no');
            if (targetOrderNo) {
                const filtered = allOrders.filter(o => String(o.order_no) === String(targetOrderNo));
                renderOrders(filtered);

                // Auto open detail if exactly one match
                if (filtered.length === 1) {
                    viewOrderDetail(filtered[0].id);
                }
            } else {
                renderOrders(allOrders);
            }
        } else {
            showError('ไม่สามารถโหลดข้อมูลได้');
        }
    } catch (error) {
        console.error('Error:', error);
        showError('เกิดข้อผิดพลาดในการโหลดข้อมูล');
    }
}

function renderOrders(orders) {
    const tbody = document.getElementById('ordersTableBody');

    const targetOrderNo = getQueryParam('order_no');

    if (!orders || orders.length === 0) {
        tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;padding:2rem;color:var(--color-gray);">ไม่พบคำสั่งซื้อ</td></tr>';
        return;
    }

    tbody.innerHTML = orders.map(order => {
        const statusClass = {
            'pending': 'warning',
            'processing': 'info',
            'shipped': 'primary',
            'delivered': 'success',
            'cancelled': 'danger'
        }[order.status] || 'secondary';

        const statusText = {
            'pending': 'รอดำเนินการ',
            'processing': 'กำลังเตรียม',
            'shipped': 'จัดส่งแล้ว',
            'delivered': 'ส่งถึงแล้ว',
            'cancelled': 'ยกเลิก'
        }[order.status] || order.status;

        const isHighlighted = targetOrderNo && String(order.order_no) === String(targetOrderNo);
        const rowStyle = isHighlighted ? 'background: rgba(59, 130, 246, 0.08);' : '';

        return `
            <tr onclick="viewOrderDetail(${order.id})" style="cursor:pointer;${rowStyle}">
                <td><strong>${order.order_no}</strong></td>
                <td>
                    ${order.product_name}<br>
                    <small style="color:var(--color-gray);">${order.product_code || ''}</small>
                </td>
                <td style="text-align:right;"><strong>฿${formatNumber(order.total_amount)}</strong></td>
                <td>
                    <span class="badge badge-${order.payment_type === 'full' ? 'success' : 'info'}">
                        ${order.payment_type === 'full' ? '💳 จ่ายเต็ม' : '📅 ผ่อน ' + order.installment_months + ' งวด'}
                    </span>
                </td>
                <td><span class="badge badge-${statusClass}">${statusText}</span></td>
                <td>
                    <button class="btn btn-sm btn-primary" onclick="event.stopPropagation(); viewOrderDetail(${order.id});">
                        <i class="fas fa-eye"></i> ดู
                    </button>
                </td>
            </tr>
        `;
    }).join('');
}

async function viewOrderDetail(orderId) {
    const modal = document.getElementById('orderModal');
    const content = document.getElementById('orderDetailsContent');

    modal.style.display = 'flex';
    content.innerHTML = '<p style="text-align:center;padding:2rem;">กำลังโหลด...</p>';

    try {
        const result = await apiCall(API_ENDPOINTS.CUSTOMER_ORDER_DETAIL(orderId));

        if (result && result.success) {
            const order = result.data || {};

            // Normalize API field name for installments
            if (!order.installments && Array.isArray(order.installment_schedule)) {
                order.installments = order.installment_schedule;
            }

            content.innerHTML = renderOrderDetails(order);
        } else {
            content.innerHTML = '<p style="color:var(--color-danger);text-align:center;">ไม่สามารถโหลดข้อมูลได้</p>';
        }
    } catch (error) {
        console.error('Error:', error);
        content.innerHTML = '<p style="color:var(--color-danger);text-align:center;">เกิดข้อผิดพลาด</p>';
    }
}

function renderOrderDetails(order) {
    const statusClass = {
        'pending': 'warning',
        'processing': 'info',
        'shipped': 'primary',
        'delivered': 'success',
        'cancelled': 'danger'
    }[order.status] || 'secondary';

    const safeOrderNo = order.order_no || '-';
    const safeStatusText = order.status || '-';
    const safeProductName = order.product_name || '-';

    let html = `
        <div class="detail-section">
            <h3>📦 ข้อมูลคำสั่งซื้อ</h3>
            <div class="detail-grid">
                <div class="detail-item">
                    <div class="detail-label">เลขที่คำสั่งซื้อ</div>
                    <div class="detail-value">${safeOrderNo}</div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">สถานะ</div>
                    <div class="detail-value"><span class="badge badge-${statusClass}">${safeStatusText}</span></div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">สินค้า</div>
                    <div class="detail-value">${safeProductName}</div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">รหัสสินค้า</div>
                    <div class="detail-value">${order.product_code || '-'}</div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">จำนวนเงิน</div>
                    <div class="detail-value" style="color:var(--color-primary);font-size:1.25rem;">฿${formatNumber(order.total_amount)}</div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">วันที่สั่ง</div>
                    <div class="detail-value">${formatDateTime(order.created_at)}</div>
                </div>
            </div>

            <div style="margin-top:1rem; display:flex; gap:.75rem; flex-wrap:wrap; align-items:center;">
                <a class="btn btn-outline" href="${buildPaymentHistoryLinkForOrderNo(order.order_no)}" onclick="event.preventDefault(); window.location.href='${buildPaymentHistoryLinkForOrderNo(order.order_no)}';">
                    <i class="fas fa-receipt"></i> ดูการชำระเงินของออเดอร์นี้
                </a>
                <a class="btn btn-outline" href="${buildAddressesLinkForOrder(order)}" onclick="event.preventDefault(); window.location.href='${buildAddressesLinkForOrder(order)}';">
                    <i class="fas fa-map-marker-alt"></i> ดูที่อยู่จัดส่ง
                </a>
                <span style="color:var(--color-gray); font-size:.9rem;">เชื่อมกันด้วย <strong>order_no</strong> และ <strong>shipping_address_id</strong></span>
            </div>
        </div>
    `;

    // Installment schedule
    if (order.installments && order.installments.length > 0) {
        html += `
            <div class="detail-section">
                <h3>📅 ตารางผ่อนชำระ (${order.installment_months} งวด)</h3>
                <table style="width:100%;font-size:0.9rem;">
                    <thead>
                        <tr style="background:var(--color-background);">
                            <th style="padding:0.75rem;">งวดที่</th>
                            <th>วันครบกำหนด</th>
                            <th style="text-align:right;">จำนวนเงิน</th>
                            <th>สถานะ</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${order.installments.map(inst => `
                            <tr>
                                <td style="padding:0.75rem;"><strong>${inst.period_number}</strong></td>
                                <td>${formatDate(inst.due_date)}</td>
                                <td style="text-align:right;">฿${formatNumber(inst.amount)}</td>
                                <td>
                                    <span class="badge badge-${inst.status === 'paid' ? 'success' : inst.status === 'overdue' ? 'danger' : 'warning'}">
                                        ${inst.status === 'paid' ? '✓ ชำระแล้ว' : inst.status === 'overdue' ? '⚠️ เกินกำหนด' : '⏳ รอชำระ'}
                                    </span>
                                </td>
                            </tr>
                        `).join('')}
                    </tbody>
                </table>
            </div>
        `;
    }

    return html;
}

function closeOrderModal() {
    document.getElementById('orderModal').style.display = 'none';
}

function formatNumber(num) {
    const n = Number(num);
    if (!Number.isFinite(n)) return '0.00';
    return n.toLocaleString('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function formatDate(date) {
    if (!date) return '-';
    return new Date(date).toLocaleDateString('th-TH');
}

function formatDateTime(date) {
    if (!date) return '-';
    return new Date(date).toLocaleString('th-TH');
}

function showError(message) {
    const tbody = document.getElementById('ordersTableBody');
    tbody.innerHTML = `<tr><td colspan="6" style="text-align:center;padding:2rem;color:var(--color-danger);">${message}</td></tr>`;
}
