/**
 * Payment Test JavaScript
 * Handles payment testing functionality
 */

requireAuth();

document.addEventListener('DOMContentLoaded', async () => {
    await loadUserInfo();
    await loadPaymentMethods();
    await loadRecentTransactions();
});

async function loadUserInfo() {
    const userData = getUserData();
    if (userData) {
        document.getElementById('userName').textContent = userData.full_name || userData.email;
        document.getElementById('userEmail').textContent = userData.email;
        const initial = (userData.full_name || userData.email).charAt(0).toUpperCase();
        document.getElementById('userAvatar').textContent = initial;
    }
}

async function loadPaymentMethods() {
    try {
        const response = await apiCall('/payment/methods');

        if (response && response.success && response.data.length > 0) {
            const select = document.getElementById('paymentMethod');
            select.innerHTML = '';

            response.data.forEach(method => {
                const option = document.createElement('option');
                option.value = method.id;
                option.textContent = `${method.card_brand.toUpperCase()} •••• ${method.card_last4}${method.is_default ? ' (บัตรหลัก)' : ''}`;
                if (method.is_default) {
                    option.selected = true;
                }
                select.appendChild(option);
            });
        } else {
            const select = document.getElementById('paymentMethod');
            select.innerHTML = '<option value="">ไม่มีบัตร - กรุณาเพิ่มบัตรก่อน</option>';
            document.getElementById('chargeBtn').disabled = true;
        }
    } catch (error) {
        console.error('Failed to load payment methods:', error);
    }
}

async function loadRecentTransactions() {
    try {
        const response = await apiCall('/billing/transactions?limit=5');

        if (response && response.success && response.data.length > 0) {
            displayTransactions(response.data);
        } else {
            document.getElementById('transactionsList').innerHTML = `
                <div class="text-center" style="padding: 1rem; color: var(--color-gray);">
                    <i class="fas fa-inbox" style="font-size: 2rem; opacity: 0.3;"></i>
                    <p style="margin-top: 0.5rem;">ยังไม่มีรายการ</p>
                </div>
            `;
        }
    } catch (error) {
        console.error('Failed to load transactions:', error);
    }
}

function displayTransactions(transactions) {
    const container = document.getElementById('transactionsList');

    container.innerHTML = transactions.map(tx => {
        const statusColors = {
            'successful': 'var(--color-success)',
            'pending': 'var(--color-warning)',
            'failed': 'var(--color-danger)'
        };

        const statusIcons = {
            'successful': 'check-circle',
            'pending': 'clock',
            'failed': 'times-circle'
        };

        return `
            <div style="padding: 0.75rem; border-bottom: 1px solid var(--color-light-3); font-size: 0.875rem;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.25rem;">
                    <strong style="color: ${statusColors[tx.status] || 'var(--color-dark)'};">
                        ${formatCurrency(tx.amount)}
                    </strong>
                    <i class="fas fa-${statusIcons[tx.status] || 'question'}" style="color: ${statusColors[tx.status] || 'var(--color-gray)'};"></i>
                </div>
                <div style="color: var(--color-gray); font-size: 0.75rem;">
                    ${tx.description || 'Payment'}
                </div>
                <div style="color: var(--color-gray); font-size: 0.7rem;">
                    ${formatDate(tx.created_at)}
                </div>
            </div>
        `;
    }).join('');
}

async function createCharge(event) {
    event.preventDefault();

    const amount = parseFloat(document.getElementById('amount').value);
    const description = document.getElementById('description').value;
    const paymentMethodId = document.getElementById('paymentMethod').value;

    if (!paymentMethodId) {
        showError('กรุณาเลือกบัตรที่ต้องการใช้');
        return;
    }

    if (amount < 20) {
        showError('จำนวนเงินขั้นต่ำ 20 บาท');
        return;
    }

    // Confirm
    if (!confirm(`ต้องการตัดเงิน ${formatCurrency(amount)} ใช่หรือไม่?\n\n(Test Mode - จะไม่มีการตัดเงินจริง)`)) {
        return;
    }

    const btn = document.getElementById('chargeBtn');
    const originalText = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> กำลังประมวลผล...';

    hideError();
    document.getElementById('chargeResult').classList.add('hidden');

    try {
        const response = await apiCall('/payment/create-charge', {
            method: 'POST',
            body: JSON.stringify({
                amount: amount,
                description: description,
                payment_method_id: paymentMethodId
            })
        });

        if (response && response.success) {
            // Show success
            document.getElementById('resultChargeId').textContent = response.data.omise_charge_id;
            document.getElementById('resultTransactionId').textContent = response.data.transaction_id;
            document.getElementById('resultAmount').textContent = formatNumber(response.data.amount);
            document.getElementById('resultStatus').textContent = response.data.status;
            document.getElementById('chargeResult').classList.remove('hidden');

            // Reset form
            document.getElementById('chargeForm').reset();

            // Reload transactions
            await loadRecentTransactions();

            // Show success message
            showToast('ชำระเงินสำเร็จ! ตรวจสอบได้ใน Omise Dashboard', 'success');
        } else {
            showError(response.message || 'การชำระเงินล้มเหลว');
        }
    } catch (error) {
        console.error('Charge error:', error);
        showError('เกิดข้อผิดพลาด: ' + (error.message || 'Unknown error'));
    } finally {
        btn.disabled = false;
        btn.innerHTML = originalText;
    }
}

function showError(message) {
    const errorDiv = document.getElementById('chargeError');
    errorDiv.textContent = message;
    errorDiv.classList.remove('hidden');
}

function hideError() {
    document.getElementById('chargeError').classList.add('hidden');
}
