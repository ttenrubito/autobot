<?php
/**
 * Cronjob Monitor - Customer Portal
 * หน้าดู/จัดการ Cronjobs ทั้งหมดในระบบ
 */
define('INCLUDE_CHECK', true);

$page_title = "Cronjob Monitor - AI Automation";
$current_page = "cronjobs";

include('../includes/customer/header.php');
include('../includes/customer/sidebar.php');
?>

<main class="main-content">
    <div class="page-header">
        <div class="page-header-content">
            <div>
                <h1 class="page-title">⏰ Cronjob Monitor</h1>
                <p class="page-subtitle">ดูและจัดการงานที่รันอัตโนมัติ</p>
            </div>
            <div class="page-header-actions">
                <button class="btn btn-outline" onclick="loadCronjobs()">
                    <i class="fas fa-sync-alt"></i> <span class="btn-text">รีเฟรช</span>
                </button>
            </div>
        </div>
    </div>

    <!-- Summary Cards -->
    <div class="summary-grid">
        <div class="summary-card summary-card-success">
            <div class="summary-icon">✅</div>
            <div class="summary-value" id="enabledCount">0</div>
            <div class="summary-label">เปิดใช้งาน</div>
        </div>
        <div class="summary-card summary-card-warning">
            <div class="summary-icon">📋</div>
            <div class="summary-value" id="plannedCount">0</div>
            <div class="summary-label">รอพัฒนา</div>
        </div>
        <div class="summary-card">
            <div class="summary-icon">📊</div>
            <div class="summary-value" id="todayRuns">0</div>
            <div class="summary-label">รันวันนี้</div>
        </div>
        <div class="summary-card summary-card-danger">
            <div class="summary-icon">❌</div>
            <div class="summary-value" id="errorCount">0</div>
            <div class="summary-label">ผิดพลาด</div>
        </div>
    </div>

    <!-- Cronjobs List -->
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">รายการ Cronjobs</h3>
        </div>
        <div class="card-body">
            <div id="cronjobsList" class="cronjobs-list">
                <div class="loading-placeholder">
                    <div class="spinner"></div>
                    <p>กำลังโหลดข้อมูล...</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Execution Logs -->
    <div class="card" style="margin-top: 1.5rem;">
        <div class="card-header">
            <h3 class="card-title">ประวัติการรัน</h3>
        </div>
        <div class="card-body">
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>Cronjob</th>
                            <th>สถานะ</th>
                            <th>ผลลัพธ์</th>
                            <th>เวลาที่ใช้</th>
                            <th>รันเมื่อ</th>
                        </tr>
                    </thead>
                    <tbody id="logsTableBody">
                        <tr>
                            <td colspan="5" style="text-align:center;color:#9ca3af;">
                                ยังไม่มีประวัติการรัน
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</main>

<style>
    .page-header-content {
        display: flex;
        justify-content: space-between;
        align-items: center;
        width: 100%;
        flex-wrap: wrap;
        gap: 1rem;
    }
    
    .page-header-actions {
        display: flex;
        gap: 0.5rem;
    }

    .summary-grid {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 1rem;
        margin-bottom: 1.5rem;
    }

    .summary-card {
        background: var(--color-white);
        border-radius: 16px;
        padding: 1.25rem;
        text-align: center;
        box-shadow: 0 1px 3px rgba(0,0,0,0.08);
    }

    .summary-icon {
        font-size: 2rem;
        margin-bottom: 0.5rem;
    }

    .summary-value {
        font-size: 1.75rem;
        font-weight: 700;
        color: var(--color-text);
    }

    .summary-label {
        font-size: 0.85rem;
        color: #6b7280;
    }

    .cronjobs-list {
        display: flex;
        flex-direction: column;
        gap: 1rem;
    }

    .cronjob-card {
        background: #f9fafb;
        border: 1px solid #e5e7eb;
        border-radius: 12px;
        padding: 1.25rem;
        transition: all 0.2s;
    }

    .cronjob-card:hover {
        border-color: var(--color-primary);
        box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    }

    .cronjob-card.enabled {
        border-left: 4px solid #22c55e;
    }

    .cronjob-card.planned {
        border-left: 4px solid #f59e0b;
    }

    .cronjob-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        margin-bottom: 0.75rem;
    }

    .cronjob-title {
        font-size: 1.1rem;
        font-weight: 600;
        color: var(--color-text);
        margin: 0;
    }

    .cronjob-schedule {
        font-size: 0.85rem;
        color: #6b7280;
        background: #e5e7eb;
        padding: 0.25rem 0.75rem;
        border-radius: 20px;
    }

    .cronjob-description {
        color: #6b7280;
        font-size: 0.9rem;
        margin-bottom: 0.75rem;
    }

    .cronjob-features {
        display: flex;
        flex-wrap: wrap;
        gap: 0.5rem;
        margin-bottom: 1rem;
    }

    .feature-tag {
        font-size: 0.75rem;
        background: #dbeafe;
        color: #1d4ed8;
        padding: 0.25rem 0.5rem;
        border-radius: 4px;
    }

    .cronjob-footer {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding-top: 0.75rem;
        border-top: 1px solid #e5e7eb;
    }

    .cronjob-status {
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }

    .status-dot {
        width: 8px;
        height: 8px;
        border-radius: 50%;
    }

    .status-dot.enabled { background: #22c55e; }
    .status-dot.planned { background: #f59e0b; }
    .status-dot.error { background: #ef4444; }

    .last-run {
        font-size: 0.8rem;
        color: #9ca3af;
    }

    .cronjob-actions {
        display: flex;
        gap: 0.5rem;
    }

    .badge-success { background: #dcfce7; color: #166534; }
    .badge-error { background: #fee2e2; color: #b91c1c; }
    .badge-skipped { background: #fef3c7; color: #92400e; }
    .badge-running { background: #dbeafe; color: #1d4ed8; }

    @media (max-width: 768px) {
        .summary-grid {
            grid-template-columns: repeat(2, 1fr);
        }
        
        .cronjob-header {
            flex-direction: column;
            gap: 0.5rem;
        }
        
        .cronjob-footer {
            flex-direction: column;
            gap: 0.75rem;
        }
        
        .cronjob-actions {
            width: 100%;
        }
        
        .cronjob-actions .btn {
            flex: 1;
        }
    }
</style>

<script>
    let cronjobsData = [];
    let logsData = [];

    document.addEventListener('DOMContentLoaded', () => {
        loadCronjobs();
        loadLogs();
    });

    async function loadCronjobs() {
        try {
            const res = await apiCall('/api/admin/cronjobs.php');
            
            if (res.success && res.data) {
                cronjobsData = res.data;
                renderCronjobs();
                updateSummary();
            }
        } catch (e) {
            console.error('Error loading cronjobs:', e);
            document.getElementById('cronjobsList').innerHTML = 
                '<div class="empty-state"><p>❌ ไม่สามารถโหลดข้อมูลได้</p></div>';
        }
    }

    async function loadLogs() {
        try {
            const res = await apiCall('/api/admin/cronjobs.php?logs=1&limit=20');
            
            if (res.success && res.data) {
                logsData = res.data;
                renderLogs();
            }
        } catch (e) {
            console.error('Error loading logs:', e);
        }
    }

    function renderCronjobs() {
        const container = document.getElementById('cronjobsList');
        
        if (!cronjobsData.length) {
            container.innerHTML = '<div class="empty-state"><p>ไม่พบ Cronjobs</p></div>';
            return;
        }

        container.innerHTML = cronjobsData.map(job => {
            const statusClass = job.status === 'enabled' ? 'enabled' : 'planned';
            const lastRun = job.last_executed ? formatDateTime(job.last_executed) : 'ยังไม่เคยรัน';
            const lastStatus = job.last_status ? getStatusBadge(job.last_status) : '';
            
            const features = (job.features || []).map(f => 
                `<span class="feature-tag">${escapeHtml(f)}</span>`
            ).join('');

            return `
                <div class="cronjob-card ${statusClass}">
                    <div class="cronjob-header">
                        <h4 class="cronjob-title">${escapeHtml(job.name)}</h4>
                        <span class="cronjob-schedule">🕐 ${escapeHtml(job.schedule_text)}</span>
                    </div>
                    <p class="cronjob-description">${escapeHtml(job.description)}</p>
                    <div class="cronjob-features">${features}</div>
                    <div class="cronjob-footer">
                        <div class="cronjob-status">
                            <span class="status-dot ${statusClass}"></span>
                            <span>${job.status === 'enabled' ? 'เปิดใช้งาน' : 'รอพัฒนา'}</span>
                            ${job.cloud_scheduler ? '<span class="badge badge-success" style="margin-left: 0.5rem;">☁️ Cloud Scheduler</span>' : ''}
                            ${lastStatus}
                        </div>
                        <div style="display: flex; align-items: center; gap: 1rem;">
                            <span class="last-run">รันล่าสุด: ${lastRun}</span>
                            <div class="cronjob-actions">
                                ${job.status === 'enabled' ? `
                                    <button class="btn btn-sm btn-primary" onclick="runCronjob('${job.id}')">
                                        <i class="fas fa-play"></i> รันเดี๋ยวนี้
                                    </button>
                                ` : `
                                    <button class="btn btn-sm btn-outline" disabled>
                                        <i class="fas fa-clock"></i> รอพัฒนา
                                    </button>
                                `}
                            </div>
                        </div>
                    </div>
                </div>
            `;
        }).join('');
    }

    function renderLogs() {
        const tbody = document.getElementById('logsTableBody');
        
        if (!logsData.length) {
            tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;color:#9ca3af;">ยังไม่มีประวัติการรัน</td></tr>';
            return;
        }

        tbody.innerHTML = logsData.map(log => {
            const result = log.result || {};
            let resultText = '-';
            
            if (result.processed !== undefined) {
                resultText = `ประมวลผล ${result.processed} รายการ, ส่ง ${result.reminders_sent || 0} แจ้งเตือน`;
            } else if (log.error_message) {
                resultText = `<span style="color:#ef4444;">${escapeHtml(log.error_message)}</span>`;
            }

            return `
                <tr>
                    <td><strong>${escapeHtml(getJobName(log.job_id))}</strong><br><small style="color:#9ca3af;">${escapeHtml(log.job_id)}</small></td>
                    <td>${getStatusBadge(log.status)}</td>
                    <td>${resultText}</td>
                    <td>${log.duration_ms ? log.duration_ms + ' ms' : '-'}</td>
                    <td>${formatDateTime(log.executed_at)}</td>
                </tr>
            `;
        }).join('');
    }

    function updateSummary() {
        const enabled = cronjobsData.filter(j => j.status === 'enabled').length;
        const planned = cronjobsData.filter(j => j.status === 'planned').length;
        const errors = cronjobsData.filter(j => j.last_status === 'error').length;
        
        document.getElementById('enabledCount').textContent = enabled;
        document.getElementById('plannedCount').textContent = planned;
        document.getElementById('errorCount').textContent = errors;
        
        // Today runs
        const today = new Date().toISOString().split('T')[0];
        const todayRuns = logsData.filter(l => l.executed_at && l.executed_at.startsWith(today)).length;
        document.getElementById('todayRuns').textContent = todayRuns;
    }

    async function runCronjob(jobId) {
        if (!confirm(`รัน "${getJobName(jobId)}" เดี๋ยวนี้?`)) return;

        showToast('กำลังรัน...', 'info');

        try {
            const res = await apiCall('/api/admin/cronjobs.php?action=run', {
                method: 'POST',
                body: { job_id: jobId }
            });

            if (res.success) {
                const result = res.result || {};
                showToast(`✅ รันสำเร็จ! ส่ง ${result.data?.reminders_sent || 0} แจ้งเตือน`, 'success');
                loadCronjobs();
                loadLogs();
            } else {
                showToast('❌ ' + (res.error || res.message || 'เกิดข้อผิดพลาด'), 'error');
            }
        } catch (e) {
            console.error('Error running cronjob:', e);
            showToast('❌ เกิดข้อผิดพลาด', 'error');
        }
    }

    function getJobName(jobId) {
        const job = cronjobsData.find(j => j.id === jobId);
        return job ? job.name : jobId;
    }

    function getStatusBadge(status) {
        const badges = {
            'success': '<span class="badge badge-success">✅ สำเร็จ</span>',
            'error': '<span class="badge badge-error">❌ ผิดพลาด</span>',
            'skipped': '<span class="badge badge-skipped">⏭️ ข้าม</span>',
            'running': '<span class="badge badge-running">🔄 กำลังรัน</span>'
        };
        return badges[status] || '';
    }

    function formatDateTime(dateStr) {
        if (!dateStr) return '-';
        const d = new Date(dateStr);
        return d.toLocaleDateString('th-TH') + ' ' + d.toLocaleTimeString('th-TH', { hour: '2-digit', minute: '2-digit' });
    }

    function escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    function showToast(message, type = 'info') {
        const toast = document.getElementById('toast');
        if (toast) {
            toast.textContent = message;
            toast.className = `toast ${type} show`;
            setTimeout(() => toast.classList.remove('show'), 3000);
        } else {
            alert(message);
        }
    }
</script>

<div id="toast" class="toast"></div>

<?php include('../includes/customer/footer.php'); ?>
