// Conversations Page JavaScript - Enhanced with Pagination, Search, Error Handling
let allConversations = [];
let filteredConversations = [];
let currentPage = 1;
const ITEMS_PER_PAGE = 25;
let searchQuery = '';
let statusFilter = 'all'; // all, active, ended

function getLoginUrlSafe() {
    try {
        if (typeof PAGES !== 'undefined' && PAGES.USER_LOGIN) return PAGES.USER_LOGIN;
        if (typeof PATH !== 'undefined' && typeof PATH.page === 'function') return PATH.page('login.php');
        return '/login.php';
    } catch {
        try {
            if (typeof PATH !== 'undefined' && typeof PATH.page === 'function') return PATH.page('login.php');
        } catch { /* ignore */ }
        return '/login.php';
    }
}

// Load conversations on page load
document.addEventListener('DOMContentLoaded', () => {
    loadConversations();
    setupSearchAndFilters();
    setupKeyboardShortcuts();
});

// Load conversations from API
async function loadConversations() {
    const container = document.getElementById('conversationsContainer');
    
    // Show loading state
    container.innerHTML = `
        <div class="loading-state">
            <div class="spinner"></div>
            <p>กำลังโหลดข้อมูลการสนทนา...</p>
        </div>
    `;
    
    try {
        // Always prefer API_ENDPOINTS (already includes BASE_PATH via path-config.js)
        // Avoid wrapping with PATH.api() again (would produce /autobot/autobot/...) and avoid hardcoded /api/... (would break subpath deploys)
        const endpoint = (typeof API_ENDPOINTS !== 'undefined' && API_ENDPOINTS.CUSTOMER_CONVERSATIONS)
            ? API_ENDPOINTS.CUSTOMER_CONVERSATIONS
            : (typeof PATH !== 'undefined' && typeof PATH.api === 'function')
                ? PATH.api('api/customer/conversations.php')
                : '/api/customer/conversations.php';

        const result = await apiCall(endpoint);

        // apiCall() may return null on 401 (it triggers logout)
        if (result === null) {
            showError('เซสชันหมดอายุหรือยังไม่ได้เข้าสู่ระบบ', 'กรุณาเข้าสู่ระบบใหม่ แล้วลองอีกครั้ง', false);
            setTimeout(() => {
                window.location.href = getLoginUrlSafe();
            }, 800);
            return;
        }

        if (result && result.success) {
            allConversations = (result.data && Array.isArray(result.data.conversations))
                ? result.data.conversations
                : (Array.isArray(result.data) ? result.data : []);

            // Sort by last message date (newest first)
            allConversations.sort((a, b) => {
                const dateA = new Date(a.last_message_at || a.created_at);
                const dateB = new Date(b.last_message_at || b.created_at);
                return dateB - dateA;
            });

            filteredConversations = allConversations;
            currentPage = 1;
            renderConversations();
        } else {
            const status = result && (result.status || result.code);
            const msg = (result && result.message) ? String(result.message) : 'Unknown error';

            if (status === 401 || /unauthorized/i.test(msg)) {
                showError('ยังไม่ได้เข้าสู่ระบบ', 'กรุณาเข้าสู่ระบบก่อนใช้งานหน้านี้', false);
                setTimeout(() => {
                    window.location.href = getLoginUrlSafe();
                }, 800);
                return;
            }

            showError('ไม่สามารถโหลดข้อมูลได้', msg, true);
        }
    } catch (error) {
        console.error('Error loading conversations:', error);
        showError('เกิดข้อผิดพลาดในการโหลดข้อมูล', error.message, true);
    }
}

// Render conversations list with pagination
function renderConversations() {
    const container = document.getElementById('conversationsContainer');

    // Empty state
    if (!filteredConversations || filteredConversations.length === 0) {
        const emptyMessage = searchQuery 
            ? `ไม่พบการสนทนาที่ตรงกับ "${searchQuery}"`
            : 'ยังไม่มีประวัติการสนทนา';
        
        container.innerHTML = `
            <div class="empty-state">
                <div class="empty-icon">💬</div>
                <p class="empty-title">${emptyMessage}</p>
                ${searchQuery ? '<button class="btn btn-outline" onclick="clearSearch()">ล้างการค้นหา</button>' : ''}
            </div>
        `;
        
        // Hide pagination
        const paginationEl = document.getElementById('conversationPagination');
        if (paginationEl) paginationEl.style.display = 'none';
        
        return;
    }

    // Calculate pagination
    const totalItems = filteredConversations.length;
    const totalPages = Math.ceil(totalItems / ITEMS_PER_PAGE);
    const startIndex = (currentPage - 1) * ITEMS_PER_PAGE;
    const endIndex = Math.min(startIndex + ITEMS_PER_PAGE, totalItems);
    const currentItems = filteredConversations.slice(startIndex, endIndex);

    // Render conversation cards
    container.innerHTML = currentItems.map(conv => {
        const metadata = conv.metadata ? (typeof conv.metadata === 'string' ? JSON.parse(conv.metadata) : conv.metadata) : {};
        const profileUrl = metadata.line_profile_url || '';
        const customerName = conv.platform_user_name || 'ลูกค้า';
        const userPhone = metadata.user_phone || '';
        
        const statusClass = conv.status === 'active' ? 'active' : 'ended';
        const statusText = conv.status === 'active' ? 'กำลังสนทนา' : 'สิ้นสุด';
        
        const platformIcon = conv.platform === 'line' ? `
            <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor">
                <path d="M19.365 9.863c.349 0 .63.285.63.631 0 .345-.281.63-.63.63H17.61v1.125h1.755c.349 0 .63.283.63.63 0 .344-.281.629-.63.629h-2.386c-.345 0-.627-.285-.627-.629V8.108c0-.345.282-.63.63-.63h2.386c.346 0 .627.285.627.63 0 .349-.281.63-.63.63H17.61v1.125h1.755zm-3.855 3.016c0 .27-.174.51-.432.596-.064.021-.133.031-.199.031-.211 0-.391-.09-.51-.25l-2.443-3.317v2.94c0 .344-.279.629-.631.629-.346 0-.626-.285-.626-.629V8.108c0-.27.173-.51.43-.595.06-.023.136-.033.194-.033.195 0 .375.104.495.254l2.462 3.33V8.108c0-.345.282-.63.63-.63.345 0 .63.285.63.63v4.771zm-5.741 0c0 .344-.282.629-.631.629-.345 0-.627-.285-.627-.629V8.108c0-.345.282-.63.63-.63.346 0 .628.285.628.63v4.771zm-2.466.629H4.917c-.345 0-.63-.285-.63-.629V8.108c0-.345.285-.63.63-.63.348 0 .63.285.63.63v4.141h1.756c.348 0 .629.283.629.63 0 .344-.282.629-.629.629M24 10.314C24 4.943 18.615.572 12 .572S0 4.943 0 10.314c0 4.811 4.27 8.842 10.035 9.608.391.082.923.258 1.058.59.12.301.079.766.038 1.08l-.164 1.02c-.045.301-.24 1.186 1.049.645 1.291-.539 6.916-4.078 9.436-6.975C23.176 14.393 24 12.458 24 10.314"/>
            </svg>
        ` : '📱';

        const lastMessage = conv.last_message || 'ยังไม่มีข้อความ';
        const timeAgo = formatTimeAgo(conv.last_message_at || conv.created_at);

        return `
            <div class="conversation-card" onclick="viewConversationDetail('${conv.conversation_id}')" tabindex="0" role="button" aria-label="ดูการสนทนากับ ${customerName}">
                <div class="conversation-avatar">
                    ${profileUrl ? 
                        `<img src="${profileUrl}" alt="${customerName}" onerror="this.parentElement.innerHTML='<div class=\\'conversation-avatar-placeholder\\'>${customerName.charAt(0)}</div>'">` :
                        `<div class="conversation-avatar-placeholder">${customerName.charAt(0)}</div>`
                    }
                </div>
                <div class="conversation-content">
                    <div class="conversation-header">
                        <div class="conversation-name">
                            ${customerName}
                            <span class="conversation-platform">
                                ${platformIcon} ${conv.platform.toUpperCase()}
                            </span>
                        </div>
                        <span class="conversation-time">${timeAgo}</span>
                    </div>
                    ${userPhone ? `<div style="font-size: 0.85rem; color: var(--color-gray);">📱 ${userPhone}</div>` : ''}
                    <div class="conversation-last-message">${lastMessage}</div>
                    <div class="conversation-meta">
                        <span class="conversation-status status-${statusClass}">${statusText}</span>
                        <span style="font-size: 0.85rem; color: var(--color-gray);">
                            💬 ${conv.message_count || 0} ข้อความ
                        </span>
                    </div>
                </div>
            </div>
        `;
    }).join('');

    // Render pagination
    renderPagination(totalItems, totalPages, startIndex, endIndex);
}

// Render pagination controls
function renderPagination(totalItems, totalPages, startIndex, endIndex) {
    const paginationEl = document.getElementById('conversationPagination');
    if (!paginationEl) return;

    if (totalPages <= 1) {
        paginationEl.style.display = 'none';
        return;
    }

    paginationEl.style.display = 'flex';
    
    const prevDisabled = currentPage === 1 ? 'disabled' : '';
    const nextDisabled = currentPage === totalPages ? 'disabled' : '';
    
    paginationEl.innerHTML = `
        <div class="pagination-info">
            แสดง ${startIndex + 1}-${endIndex} จาก ${totalItems} รายการ
        </div>
        <div class="pagination-controls">
            <button class="btn-pagination" onclick="goToPage(1)" ${prevDisabled} aria-label="หน้าแรก">
                <i class="fas fa-angle-double-left"></i>
            </button>
            <button class="btn-pagination" onclick="goToPage(${currentPage - 1})" ${prevDisabled} aria-label="หน้าก่อน">
                <i class="fas fa-angle-left"></i>
            </button>
            <span class="page-indicator">หน้า ${currentPage} / ${totalPages}</span>
            <button class="btn-pagination" onclick="goToPage(${currentPage + 1})" ${nextDisabled} aria-label="หน้าถัดไป">
                <i class="fas fa-angle-right"></i>
            </button>
            <button class="btn-pagination" onclick="goToPage(${totalPages})" ${nextDisabled} aria-label="หน้าสุดท้าย">
                <i class="fas fa-angle-double-right"></i>
            </button>
        </div>
    `;
}

// Go to specific page
function goToPage(page) {
    const totalPages = Math.ceil(filteredConversations.length / ITEMS_PER_PAGE);
    if (page < 1 || page > totalPages) return;
    
    currentPage = page;
    renderConversations();
    
    // Scroll to top
    document.getElementById('conversationsContainer').scrollIntoView({ behavior: 'smooth' });
}

// Setup search and filters
function setupSearchAndFilters() {
    const searchInput = document.getElementById('conversationSearch');
    const statusButtons = document.querySelectorAll('[data-status-filter]');
    
    if (searchInput) {
        searchInput.addEventListener('input', (e) => {
            searchQuery = e.target.value.trim().toLowerCase();
            applyFilters();
        });
    }
    
    if (statusButtons) {
        statusButtons.forEach(btn => {
            btn.addEventListener('click', (e) => {
                statusButtons.forEach(b => b.classList.remove('active'));
                e.target.classList.add('active');
                statusFilter = e.target.dataset.statusFilter;
                applyFilters();
            });
        });
    }
}

// Apply filters and search
function applyFilters() {
    filteredConversations = allConversations.filter(conv => {
        // Status filter
        if (statusFilter !== 'all' && conv.status !== statusFilter) {
            return false;
        }
        
        // Search filter
        if (searchQuery) {
            const customerName = (conv.platform_user_name || '').toLowerCase();
            const lastMessage = (conv.last_message || '').toLowerCase();
            const metadata = conv.metadata ? (typeof conv.metadata === 'string' ? JSON.parse(conv.metadata) : conv.metadata) : {};
            const phone = (metadata.user_phone || '').toLowerCase();
            
            return customerName.includes(searchQuery) || 
                   lastMessage.includes(searchQuery) ||
                   phone.includes(searchQuery);
        }
        
        return true;
    });
    
    currentPage = 1;
    renderConversations();
}

// Clear search
function clearSearch() {
    const searchInput = document.getElementById('conversationSearch');
    if (searchInput) searchInput.value = '';
    searchQuery = '';
    applyFilters();
}

// Setup keyboard shortcuts
function setupKeyboardShortcuts() {
    document.addEventListener('keydown', (e) => {
        // ESC - Close modal
        if (e.key === 'Escape') {
            const modal = document.getElementById('conversationModal');
            if (modal && modal.style.display === 'flex') {
                closeConversationModal();
            }
        }
        
        // Ctrl/Cmd + K - Focus search
        if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
            e.preventDefault();
            const searchInput = document.getElementById('conversationSearch');
            if (searchInput) searchInput.focus();
        }
        
        // Arrow keys for pagination (when not in input)
        if (!['INPUT', 'TEXTAREA'].includes(e.target.tagName)) {
            if (e.key === 'ArrowLeft') {
                goToPage(currentPage - 1);
            } else if (e.key === 'ArrowRight') {
                goToPage(currentPage + 1);
            }
        }
    });
}

// View conversation detail
async function viewConversationDetail(conversationId) {
    const modal = document.getElementById('conversationModal');
    const content = document.getElementById('conversationDetailsContent');

    if (!modal || !content) return;

    modal.style.display = 'flex';
    content.innerHTML = `
        <div class="loading-state">
            <div class="spinner"></div>
            <p>กำลังโหลดรายละเอียด...</p>
        </div>
    `;

    try {
        // Use API_ENDPOINTS for proper path resolution
        const endpoint = (typeof API_ENDPOINTS !== 'undefined' && API_ENDPOINTS.CUSTOMER_CONVERSATION_DETAIL)
            ? API_ENDPOINTS.CUSTOMER_CONVERSATION_DETAIL(conversationId)
            : (typeof PATH !== 'undefined' && typeof PATH.api === 'function')
                ? PATH.api(`api/customer/conversations.php?id=${encodeURIComponent(String(conversationId))}`)
                : `/api/customer/conversations.php?id=${encodeURIComponent(String(conversationId))}`;

        const result = await apiCall(endpoint);

        if (result && result.success) {
            const conversation = result.data;
            content.innerHTML = renderConversationDetails(conversation);
        } else {
            content.innerHTML = `
                <div class="error-state">
                    <div class="error-icon">⚠️</div>
                    <h3>ไม่สามารถโหลดข้อมูลได้</h3>
                    <p>${result?.message || 'เกิดข้อผิดพลาดที่ไม่ทราบสาเหตุ'}</p>
                    <button class="btn btn-primary" onclick="viewConversationDetail('${conversationId}')">
                        <i class="fas fa-redo"></i> ลองใหม่
                    </button>
                </div>
            `;
        }
    } catch (error) {
        console.error('Error:', error);
        content.innerHTML = `
            <div class="error-state">
                <div class="error-icon">❌</div>
                <h3>เกิดข้อผิดพลาด</h3>
                <p>${error.message || 'ไม่สามารถเชื่อมต่อกับเซิร์ฟเวอร์'}</p>
                <button class="btn btn-primary" onclick="viewConversationDetail('${conversationId}')">
                    <i class="fas fa-redo"></i> ลองใหม่
                </button>
            </div>
        `;
    }
}

// Render conversation details
function renderConversationDetails(conversation) {
    const metadata = conversation.metadata ? (typeof conversation.metadata === 'string' ? JSON.parse(conversation.metadata) : conversation.metadata) : {};
    const profileUrl = metadata.line_profile_url || '';
    const customerName = conversation.platform_user_name || 'ลูกค้า';
    const userPhone = metadata.user_phone || '';
    const tags = metadata.tags || [];

    let html = `
        <!-- Customer Profile -->
        <div class="detail-section customer-profile-card">
            <div class="profile-header">
                <div class="profile-avatar">
                    ${profileUrl ? 
                        `<img src="${profileUrl}" alt="${customerName}" onerror="this.src='data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><circle cx=%2250%22 cy=%2250%22 r=%2250%22 fill=%22%2306C755%22/><text x=%2250%25%22 y=%2250%25%22 text-anchor=%22middle%22 dy=%22.3em%22 fill=%22white%22 font-size=%2240%22 font-weight=%22bold%22>${customerName.charAt(0)}</text></svg>'">` :
                        `<div class="profile-avatar-placeholder">${customerName.charAt(0)}</div>`
                    }
                </div>
                <div class="profile-info">
                    <h3 class="profile-name">${customerName}</h3>
                    ${userPhone ? `<div class="profile-phone">📱 ${userPhone}</div>` : ''}
                    <div class="profile-platform">
                        <span class="platform-badge platform-line">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor">
                                <path d="M19.365 9.863c.349 0 .63.285.63.631 0 .345-.281.63-.63.63H17.61v1.125h1.755c.349 0 .63.283.63.63 0 .344-.281.629-.63.629h-2.386c-.345 0-.627-.285-.627-.629V8.108c0-.345.282-.63.63-.63h2.386c.346 0 .627.285.627.63 0 .349-.281.63-.63.63H17.61v1.125h1.755zm-3.855 3.016c0 .27-.174.51-.432.596-.064.021-.133.031-.199.031-.211 0-.391-.09-.51-.25l-2.443-3.317v2.94c0 .344-.279.629-.631.629-.346 0-.626-.285-.626-.629V8.108c0-.27.173-.51.43-.595.06-.023.136-.033.194-.033.195 0 .375.104.495.254l2.462 3.33V8.108c0-.345.282-.63.63-.63.345 0 .63.285.63.63v4.771zm-5.741 0c0 .344-.282.629-.631.629-.345 0-.627-.285-.627-.629V8.108c0-.345.282-.63.63-.63.346 0 .628.285.628.63v4.771zm-2.466.629H4.917c-.345 0-.63-.285-.63-.629V8.108c0-.345.285-.63.63-.63.348 0 .63.285.63.63v4.141h1.756c.348 0 .629.283.629.63 0 .344-.282.629-.629.629M24 10.314C24 4.943 18.615.572 12 .572S0 4.943 0 10.314c0 4.811 4.27 8.842 10.035 9.608.391.082.923.258 1.058.59.12.301.079.766.038 1.08l-.164 1.02c-.045.301-.24 1.186 1.049.645 1.291-.539 6.916-4.078 9.436-6.975C23.176 14.393 24 12.458 24 10.314"/>
                            </svg>
                            ${(conversation.platform || 'LINE').toUpperCase()}
                        </span>
                        ${tags.map(tag => `<span class="platform-badge" style="background: rgba(255,255,255,0.25);">${tag}</span>`).join('')}
                    </div>
                </div>
            </div>
        </div>

        <!-- Conversation Info -->
        <div class="detail-section">
            <h3>📊 ข้อมูลการสนทนา</h3>
            <div class="detail-grid">
                <div class="detail-item">
                    <div class="detail-label">รหัสการสนทนา</div>
                    <div class="detail-value">${conversation.conversation_id}</div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">สถานะ</div>
                    <div class="detail-value">
                        <span class="conversation-status status-${conversation.status}">
                            ${conversation.status === 'active' ? 'กำลังสนทนา' : 'สิ้นสุด'}
                        </span>
                    </div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">เริ่มสนทนา</div>
                    <div class="detail-value">${formatDateTime(conversation.started_at)}</div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">ข้อความล่าสุด</div>
                    <div class="detail-value">${formatDateTime(conversation.last_message_at)}</div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">จำนวนข้อความ</div>
                    <div class="detail-value">${conversation.message_count || 0} ข้อความ</div>
                </div>
            </div>
        </div>

        <!-- Chat Messages -->
        <div class="detail-section slip-chat-box">
            <h3>💬 ประวัติการสนทนา</h3>
            <div class="slip-chat-bubbles" id="chatMessages">
                <p style="text-align:center;color:var(--color-gray);">กำลังโหลดข้อความ...</p>
            </div>
        </div>
    `;

    // Load messages after rendering
    setTimeout(() => loadConversationMessages(conversation.conversation_id), 100);

    return html;
}

// Load conversation messages
async function loadConversationMessages(conversationId) {
    const messagesContainer = document.getElementById('chatMessages');
    
    try {
        // Use API_ENDPOINTS for proper path resolution
        const endpoint = (typeof API_ENDPOINTS !== 'undefined' && API_ENDPOINTS.CUSTOMER_CONVERSATION_MESSAGES)
            ? API_ENDPOINTS.CUSTOMER_CONVERSATION_MESSAGES(conversationId)
            : PATH.api(`api/customer/conversations/${conversationId}/messages`);
            
        const result = await apiCall(endpoint);
        
        if (result && result.success && result.data && result.data.messages) {
            const messages = result.data.messages;
            
            if (messages.length === 0) {
                messagesContainer.innerHTML = '<p style="text-align:center;color:var(--color-gray);">ไม่มีข้อความในการสนทนานี้</p>';
                return;
            }
            
            messagesContainer.innerHTML = messages.map(msg => {
                const isBot = msg.sender_type === 'bot' || msg.sender_type === 'agent';
                const bubbleClass = isBot ? 'bubble-bot' : 'bubble-user';
                const label = isBot ? 'Bot' : (msg.sender_name || 'ลูกค้า');
                
                return `
                    <div class="bubble ${bubbleClass}">
                        <div class="bubble-label">${label}</div>
                        <div class="bubble-text">
                            ${msg.message_text || ''}
                            ${msg.message_type === 'image' ? '<br>📷 รูปภาพ' : ''}
                        </div>
                        <div class="bubble-time">${formatTime(msg.created_at)}</div>
                    </div>
                `;
            }).join('');
            
            // Scroll to latest message
            messagesContainer.scrollTop = messagesContainer.scrollHeight;
        } else {
            messagesContainer.innerHTML = `
                <div class="error-state-small">
                    <p style="color:var(--color-danger);text-align:center;">
                        <i class="fas fa-exclamation-triangle"></i> ไม่สามารถโหลดข้อความได้
                    </p>
                    <button class="btn btn-sm btn-outline" onclick="loadConversationMessages('${conversationId}')">
                        <i class="fas fa-redo"></i> ลองใหม่
                    </button>
                </div>
            `;
        }
    } catch (error) {
        console.error('Error loading messages:', error);
        messagesContainer.innerHTML = `
            <div class="error-state-small">
                <p style="color:var(--color-danger);text-align:center;">
                    <i class="fas fa-times-circle"></i> เกิดข้อผิดพลาด: ${error.message}
                </p>
                <button class="btn btn-sm btn-outline" onclick="loadConversationMessages('${conversationId}')">
                    <i class="fas fa-redo"></i> ลองใหม่
                </button>
            </div>
        `;
    }
}

// Close modal
function closeConversationModal() {
    const modal = document.getElementById('conversationModal');
    if (modal) modal.style.display = 'none';
}

// Format time ago
function formatTimeAgo(dateStr) {
    const date = new Date(dateStr);
    const now = new Date();
    const diffMs = now - date;
    const diffMins = Math.floor(diffMs / 60000);
    const diffHours = Math.floor(diffMs / 3600000);
    const diffDays = Math.floor(diffMs / 86400000);

    if (diffMins < 1) return 'เมื่อสักครู่';
    if (diffMins < 60) return `${diffMins} นาทีที่แล้ว`;
    if (diffHours < 24) return `${diffHours} ชั่วโมงที่แล้ว`;
    if (diffDays < 7) return `${diffDays} วันที่แล้ว`;
    return formatDate(dateStr);
}

// Format time only
function formatTime(dateStr) {
    if (!dateStr) return '';
    const date = new Date(dateStr);
    if (isNaN(date.getTime())) return '';
    return date.toLocaleTimeString('th-TH', { hour: '2-digit', minute: '2-digit' });
}

// Show error with retry option
function showError(message, details, canRetry = false) {
    const container = document.getElementById('conversationsContainer');
    container.innerHTML = `
        <div class="error-state">
            <div class="error-icon">⚠️</div>
            <h3 class="error-title">${message}</h3>
            ${details ? `<p class="error-details">${details}</p>` : ''}
            ${canRetry ? `
                <button class="btn btn-primary" onclick="loadConversations()">
                    <i class="fas fa-redo"></i> ลองใหม่อีกครั้ง
                </button>
            ` : ''}
        </div>
    `;
    
    // Hide pagination
    const paginationEl = document.getElementById('conversationPagination');
    if (paginationEl) paginationEl.style.display = 'none';
}

// Helpers
function formatDateTime(date) {
    if (!date) return '-';
    const d = new Date(date);
    if (isNaN(d.getTime())) return '-';
    return d.toLocaleString('th-TH');
}
