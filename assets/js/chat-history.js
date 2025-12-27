// Chat History JavaScript
let allConversations = [];

document.addEventListener('DOMContentLoaded', () => {
    loadConversations();
});

async function loadConversations() {
    try {
        // Load list
        const result = await apiCall(API_ENDPOINTS.CUSTOMER_CONVERSATIONS);

        if (result && result.success) {
            allConversations = (result.data && Array.isArray(result.data.conversations)) ? result.data.conversations : [];
            renderConversations(allConversations);

            // Load stats separately (API returns {success:true,data:{total_conversations,...}})
            const statsResult = await apiCall(API_ENDPOINTS.CUSTOMER_CONVERSATIONS + '/stats');
            if (statsResult && statsResult.success) {
                displayStatistics({
                    total: statsResult.data.total_conversations,
                    total_messages: statsResult.data.total_messages,
                    line_count: statsResult.data.line_count,
                    facebook_count: statsResult.data.facebook_count,
                });
            }
        } else {
            const msg = (result && result.message) ? result.message : 'ไม่สามารถโหลดข้อมูลได้';
            showError(msg);
        }
    } catch (error) {
        console.error('Error:', error);
        showError('เกิดข้อผิดพลาดในการโหลดข้อมูล');
    }
}

function displayStatistics(stats) {
    if (!stats) return;

    document.getElementById('totalConversations').textContent = stats.total || '0';
    document.getElementById('totalMessages').textContent = stats.total_messages || '0';
    document.getElementById('lineCount').textContent = stats.line_count || '0';
    document.getElementById('facebookCount').textContent = stats.facebook_count || '0';
}

function renderConversations(conversations) {
    const tbody = document.getElementById('conversationsTableBody');

    if (!conversations || conversations.length === 0) {
        tbody.innerHTML = '<tr><td colspan="7" style="text-align:center;padding:2rem;color:var(--color-gray);">ไม่พบรายการสนทนา</td></tr>';
        return;
    }

    tbody.innerHTML = conversations.map(conv => `
        <tr onclick="viewMessages('${conv.conversation_id}')" style="cursor:pointer;">
            <td>
                <span class="platform-badge platform-${conv.platform}">
                    ${conv.platform === 'line' ? '🟢 LINE' : '🔵 Facebook'}
                </span>
            </td>
            <td>${formatDateTime(conv.started_at)}</td>
            <td>${formatDateTime(conv.last_message_at)}</td>
            <td><strong>${conv.message_count}</strong></td>
            <td>
                <span class="status-badge status-${conv.status}">
                    ${conv.status === 'active' ? 'กำลังดำเนินการ' : 'สิ้นสุด'}
                </span>
            </td>
            <td>${conv.conversation_summary?.outcome || '-'}</td>
            <td>
                <button class="btn btn-sm btn-primary" onclick="event.stopPropagation(); viewMessages('${conv.conversation_id}');">
                    <i class="fas fa-eye"></i> ดู
                </button>
            </td>
        </tr>
    `).join('');
}

function applyFilters() {
    const platform = document.getElementById('platformFilter').value;
    const status = document.getElementById('statusFilter').value;

    let filtered = allConversations;

    if (platform) {
        filtered = filtered.filter(c => c.platform === platform);
    }

    if (status) {
        filtered = filtered.filter(c => c.status === status);
    }

    renderConversations(filtered);
}

async function viewMessages(conversationId) {
    const modal = document.getElementById('messagesModal');
    const container = document.getElementById('messagesContainer');
    const info = document.getElementById('conversationInfo');

    modal.style.display = 'block';
    container.innerHTML = '<p style="text-align:center;padding:2rem;">กำลังโหลด...</p>';

    try {
        const result = await apiCall(API_ENDPOINTS.CUSTOMER_CONVERSATION_MESSAGES(conversationId));

        if (result && result.success) {
            const messages = result.data.messages || [];
            const conversation = allConversations.find(c => c.conversation_id === conversationId);

            // Show conversation info
            if (conversation) {
                info.innerHTML = `
                    <div style="display:flex;justify-content:space-between;align-items:center;">
                        <div>
                            <strong>Conversation ID:</strong> ${conversationId}<br>
                            <strong>Platform:</strong> <span class="platform-badge platform-${conversation.platform}">${conversation.platform.toUpperCase()}</span>
                        </div>
                        <div style="text-align:right;">
                            <strong>ข้อความทั้งหมด:</strong> ${messages.length}<br>
                            <strong>สถานะ:</strong> <span class="status-badge status-${conversation.status}">${conversation.status}</span>
                        </div>
                    </div>
                `;
            }

            // Show messages
            if (messages.length === 0) {
                container.innerHTML = '<p style="text-align:center;color:var(--color-gray);">ไม่มีข้อความ</p>';
            } else {
                container.innerHTML = messages.map(msg => `
                    <div class="message-bubble message-${msg.direction}">
                        <div style="font-weight:600;margin-bottom:0.25rem;">
                            ${msg.sender_type === 'customer' ? '👤 ลูกค้า' : '🤖 Bot'}
                        </div>
                        <div>${escapeHtml(msg.message_text || '[ไม่มีข้อความ]')}</div>
                        <div class="message-meta">
                            ${formatDateTime(msg.sent_at)}
                            ${msg.intent ? ` • Intent: ${msg.intent}` : ''}
                        </div>
                    </div>
                `).join('');
            }
        } else {
            container.innerHTML = '<p style="color:var(--color-danger);text-align:center;">ไม่สามารถโหลดข้อมูลได้</p>';
        }
    } catch (error) {
        console.error('Error:', error);
        container.innerHTML = '<p style="color:var(--color-danger);text-align:center;">เกิดข้อผิดพลาด</p>';
    }
}

function closeMessagesModal() {
    document.getElementById('messagesModal').style.display = 'none';
}

function formatDateTime(dt) {
    if (!dt) return '-';
    return new Date(dt).toLocaleString('th-TH');
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function showError(message) {
    const tbody = document.getElementById('conversationsTableBody');
    tbody.innerHTML = `<tr><td colspan="7" style="text-align:center;padding:2rem;color:var(--color-danger);">${message}</td></tr>`;
}

// Close modal on click outside
window.addEventListener('click', (e) => {
    const modal = document.getElementById('messagesModal');
    if (e.target === modal) {
        closeMessagesModal();
    }
});
