<?php
/**
 * Admin Chat Logs
 */
define('INCLUDE_CHECK', true);
$page_title = "Chat Logs - Admin Panel";
$current_page = "chat-logs";
include('../../includes/admin/header.php');
include('../../includes/admin/sidebar.php');
?>

<main class="main-content">
    <div class="page-header">
        <h1 class="page-title"><i class="fas fa-comments"></i> Chat Logs</h1>
        <p class="page-subtitle">ดูประวัติการสนทนาของลูกค้า</p>
    </div>

    <!-- Filters -->
    <div class="card">
        <div class="card-body">
            <div class="row">
                <div class="col-3">
                    <label>ลูกค้า:</label>
                    <select id="customerFilter" class="form-control">
                        <option value="">ทั้งหมด</option>
                    </select>
                </div>
                <div class="col-3">
                    <label>แพลตฟอร์ม:</label>
                    <select id="platformFilter" class="form-control">
                        <option value="">ทั้งหมด</option>
                        <option value="line">LINE</option>
                        <option value="facebook">Facebook</option>
                    </select>
                </div>
                <div class="col-3">
                    <label>ช่วงเวลา:</label>
                    <input type="date" id="dateFilter" class="form-control">
                </div>
                <div class="col-3">
                    <label>&nbsp;</label>
                    <button class="btn btn-primary" onclick="loadConversations()" style="width: 100%;">
                        <i class="fas fa-search"></i> ค้นหา
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Conversations List -->
    <div class="card mt-4">
        <div class="card-header">
            <h3 class="card-title">รายการสนทนา</h3>
        </div>
        <div class="card-body">
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>ลูกค้า</th>
                            <th>แพลตฟอร์ม</th>
                            <th>เริ่มเมื่อ</th>
                            <th>ข้อความ</th>
                            <th>สถานะ</th>
                            <th>สรุป</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody id="conversationsTableBody">
                        <tr><td colspan="7" style="text-align: center; padding: 2rem;">กำลังโหลด...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</main>

<div id="chatModal" class="modal">
    <div class="modal-content" style="max-width: 900px;">
        <span class="close" onclick="closeModal()">&times;</span>
        <div id="chatDetails"></div>
    </div>
</div>

<?php
$inline_script = <<<'JAVASCRIPT'
async function loadConversations() {
    const token = localStorage.getItem('admin_token');
    const tbody = document.getElementById('conversationsTableBody');
    
    const customer = document.getElementById('customerFilter').value;
    const platform = document.getElementById('platformFilter').value;
    const date = document.getElementById('dateFilter').value;
    
    let url = '/api/admin/conversations?limit=50';
    if (customer) url += `&customer_id=${customer}`;
    if (platform) url += `&platform=${platform}`;
    if (date) url += `&date=${date}`;

    try {
        const response = await fetch(url, {
            headers: { 'Authorization': 'Bearer ' + token }
        });

        const result = await response.json();
        if (result.success && result.data.conversations.length > 0) {
            tbody.innerHTML = result.data.conversations.map(conv => `
                <tr>
                    <td>${conv.customer_name}<br><small>${conv.customer_email}</small></td>
                    <td><span class="badge badge-${conv.platform === 'line' ? 'success' : 'primary'}">${conv.platform.toUpperCase()}</span></td>
                    <td>${formatDateTime(conv.started_at)}</td>
                    <td>${conv.message_count}</td>
                    <td><span class="badge badge-${conv.status === 'active' ? 'warning' : 'secondary'}">${conv.status === 'active' ? 'กำลังดำเนินการ' : 'สิ้นสุด'}</span></td>
                    <td>${conv.conversation_summary?.outcome || '-'}</td>
                    <td><button class="btn btn-sm btn-primary" onclick="viewChat('${conv.conversation_id}')"><i class="fas fa-eye"></i></button></td>
                </tr>
            `).join('');
            
            // Load customers for filter
            if (result.data.customers) {
                const customerFilter = document.getElementById('customerFilter');
                customerFilter.innerHTML = '<option value="">ทั้งหมด</option>' +
                    result.data.customers.map(c => `<option value="${c.id}">${c.full_name} (${c.email})</option>`).join('');
            }
        } else {
            tbody.innerHTML = '<tr><td colspan="7" style="text-align: center; padding: 2rem;">ไม่พบข้อมูล</td></tr>';
        }
    } catch (error) {
        console.error('Error:', error);
        tbody.innerHTML = '<tr><td colspan="7" style="text-align: center; color: var(--color-danger);">เกิดข้อผิดพลาด</td></tr>';
    }
}

async function viewChat(conversationId) {
    const token = localStorage.getItem('admin_token');
    const modal = document.getElementById('chatModal');
    const details = document.getElementById('chatDetails');
    
    modal.style.display = 'block';
    details.innerHTML = '<p style="text-align: center;">กำลังโหลด...</p>';

    try {
        const response = await fetch(`/api/admin/conversations/${conversationId}/messages`, {
            headers: { 'Authorization': 'Bearer ' + token }
        });

        const result = await response.json();
        if (result.success) {
            const messages = result.data.messages;
            details.innerHTML = `
                <h2>รายละเอียดการสนทนา</h2>
                <p><strong>Conversation ID:</strong> ${conversationId}</p>
                <div style="max-height: 500px; overflow-y: auto; margin-top: 1rem;">
                    ${messages.map(msg => `
                        <div style="padding: 0.75rem; margin-bottom: 0.5rem; background: ${msg.direction === 'incoming' ? 'var(--color-background)' : 'var(--color-light-1)'}; border-radius: 8px;">
                            <strong>${msg.sender_type === 'customer' ? 'ลูกค้า' : 'Bot'}:</strong> ${msg.message_text || '[Image]'}<br>
                            <small style="color: var(--color-gray);">${formatDateTime(msg.sent_at)}${msg.intent ? ` | Intent: ${msg.intent}` : ''}</small>
                        </div>
                    `).join('')}
                </div>
            `;
        }
    } catch (error) {
        console.error('Error:', error);
        details.innerHTML = '<p style="color: var(--color-danger);">เกิดข้อผิดพลาด</p>';
    }
}

function closeModal() {
    document.getElementById('chatModal').style.display = 'none';
}

function formatDateTime(dt) {
    return new Date(dt).toLocaleString('th-TH');
}

document.addEventListener('DOMContentLoaded', loadConversations);
JAVASCRIPT;

include('../../includes/admin/footer.php');
?>
