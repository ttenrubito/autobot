<?php
/**
 * Customer Services Page
 */
define('INCLUDE_CHECK', true);

$page_title = "บริการของฉัน - AI Automation";
$current_page = "services";

include('../includes/customer/header.php');
include('../includes/customer/sidebar.php');
?>

<main class="main-content">
    <div class="page-header">
        <h1 class="page-title">บริการของฉัน</h1>
        <p class="page-subtitle">จัดการและติดตาม AI Bot และ API ทั้งหมด</p>
    </div>

    <!-- Services Grid -->
    <div id="servicesContainer" class="row">
        <!-- Services will be loaded here -->
        <div class="col-12 text-center" style="padding: 3rem;">
            <div class="loading-spinner" style="margin: 0 auto;"></div>
            <p style="margin-top: 1rem; color: var(--color-gray);">กำลังโหลดข้อมูล...</p>
        </div>
    </div>
</main>

<?php
$inline_script = <<<'JAVASCRIPT'
async function loadServices() {
    try {
        const response = await apiCall(API_ENDPOINTS.SERVICES_LIST);

        if (response && response.success) {
            displayServices(response.data);
        }
    } catch (error) {
        console.error('Failed to load services:', error);
        displayError('ไม่สามารถโหลดข้อมูลได้');
    }
}

function displayServices(services) {
    const container = document.getElementById('servicesContainer');

    if (!services || services.length === 0) {
        container.innerHTML = `
            <div class="col-12 text-center" style="padding: 3rem;">
                <div style="font-size: 4rem; margin-bottom: 1rem;">🤖</div>
                <h3>ยังไม่มีบริการ</h3>
                <p style="color: var(--color-gray); margin-top: 0.5rem;">ติดต่อทีมงานเพื่อเปิดใช้บริการของคุณ</p>
            </div>
        `;
        return;
    }

    container.innerHTML = '';

    services.forEach(service => {
        const col = document.createElement('div');
        col.className = 'col-4';

        const statusClass = service.status === 'active' ? 'success' :
            service.status === 'paused' ? 'warning' : 'danger';
        const statusText = service.status === 'active' ? 'ใช้งานอยู่' :
            service.status === 'paused' ? 'หยุดชั่วคราว' : 'มีปัญหา';

        const icon = service.platform === 'facebook' ? '📘' :
            service.platform === 'line' ? '💚' :
                service.service_code === 'google_vision' ? '👁️' :
                    service.service_code === 'google_nl' ? '🧠' : '🤖';

        col.innerHTML = `
            <div class="card">
                <div class="card-header" style="background: var(--color-light); border-radius: var(--radius-lg) var(--radius-lg) 0 0;">
                    <div style="display: flex; justify-content: space-between; align-items: start;">
                        <div style="display: flex; align-items: center; gap: 0.75rem;">
                            <div style="font-size: 2rem;">${icon}</div>
                            <div>
                                <h3 class="card-title" style="margin: 0;">${service.service_name}</h3>
                                <p class="card-subtitle" style="margin: 0.25rem 0 0 0;">${service.service_type}</p>
                            </div>
                        </div>
                        <span class="badge badge-${statusClass}">${statusText}</span>
                    </div>
                </div>
                <div class="card-body">
                    <div style="display: flex; justify-content: space-between; margin-bottom: 1rem;">
                        <div>
                            <div style="font-size: 0.75rem; color: var(--color-gray); text-transform: uppercase;">ข้อความ 24h</div>
                            <div style="font-size: 1.5rem; font-weight: 700; color: var(--color-dark);">${formatNumber(service.messages_24h || 0)}</div>
                        </div>
                        <div>
                            <div style="font-size: 0.75rem; color: var(--color-gray); text-transform: uppercase;">API Calls 24h</div>
                            <div style="font-size: 1.5rem; font-weight: 700; color: var(--color-dark);">${formatNumber(service.api_calls_24h || 0)}</div>
                        </div>
                    </div>
                    ${service.platform ? `
                        <div style="margin-bottom: 1rem; padding: 0.75rem; background: var(--color-light); border-radius: var(--radius-md);">
                            <div style="font-size: 0.75rem; color: var(--color-gray); margin-bottom: 0.25rem;">API Key</div>
                            <code style="font-size: 0.75rem; color: var(--color-dark-2);">${service.api_key}</code>
                        </div>
                    ` : ''}
                    <a href="service-details.html?id=${service.id}" class="btn btn-primary btn-block">
                        ดูรายละเอียด
                    </a>
                </div>
            </div>
        `;

        container.appendChild(col);
    });
}

function displayError(message) {
    const container = document.getElementById('servicesContainer');
    container.innerHTML = `
        <div class="col-12 text-center" style="padding: 3rem;">
            <div style="font-size: 4rem; margin-bottom: 1rem;">⚠️</div>
            <h3>${message}</h3>
        </div>
    `;
}

document.addEventListener('DOMContentLoaded', loadServices);
JAVASCRIPT;

include('../includes/customer/footer.php');
?>
