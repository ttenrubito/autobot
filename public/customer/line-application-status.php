<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ตรวจสอบสถานะ - LINE Application</title>
    
    <!-- LIFF SDK -->
    <script charset="utf-8" src="https://static.line-scdn.net/liff/edge/2/sdk.js"></script>
    
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Helvetica Neue', Arial, sans-serif;
            background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
            min-height: 100vh;
            padding: 1rem;
        }
        
        .container {
            max-width: 600px;
            margin: 0 auto;
            background: white;
            border-radius: 16px;
            padding: 1.5rem;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
        }
        
        .header {
            text-align: center;
            margin-bottom: 2rem;
        }
        
        .header h1 {
            font-size: 1.5rem;
            color: #333;
        }
        
        .status-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 1.5rem;
            border-radius: 12px;
            margin-bottom: 1.5rem;
            text-align: center;
        }
        
        .status-card .app-no {
            font-size: 1.2rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
        }
        
        .status-card .status {
            font-size: 1.5rem;
            font-weight: 700;
            margin: 1rem 0;
        }
        
        .timeline {
            position: relative;
            padding-left: 2rem;
        }
        
        .timeline::before {
            content: '';
            position: absolute;
            left: 8px;
            top: 0;
            bottom: 0;
            width: 2px;
            background: #e0e0e0;
        }
        
        .timeline-item {
            position: relative;
            margin-bottom: 1.5rem;
        }
        
        .timeline-item::before {
            content: '';
            position: absolute;
            left: -1.5rem;
            top: 4px;
            width: 16px;
            height: 16px;
            border-radius: 50%;
            background: white;
            border: 3px solid #667eea;
        }
        
        .timeline-item.active::before {
            background: #667eea;
        }
        
        .timeline-item .date {
            font-size: 0.85rem;
            color: #999;
        }
        
        .timeline-item .title {
            font-weight: 600;
            color: #333;
            margin: 0.25rem 0;
        }
        
        .timeline-item .description {
            font-size: 0.9rem;
            color: #666;
        }
        
        .info-section {
            margin-top: 1.5rem;
            padding: 1rem;
            background: #f7f7f7;
            border-radius: 12px;
        }
        
        .info-section h3 {
            font-size: 1rem;
            color: #333;
            margin-bottom: 0.5rem;
        }
        
        .info-row {
            display: flex;
            justify-content: space-between;
            padding: 0.5rem 0;
            border-bottom: 1px solid #e0e0e0;
        }
        
        .info-row:last-child {
            border-bottom: none;
        }
        
        .info-row .label {
            color: #666;
        }
        
        .info-row .value {
            font-weight: 600;
            color: #333;
        }
        
        .btn {
            width: 100%;
            padding: 1rem;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 12px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            margin-top: 1rem;
        }
        
        .btn-secondary {
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
        }
        
        .loading {
            text-align: center;
            padding: 3rem;
        }
        
        .spinner {
            width: 50px;
            height: 50px;
            border: 4px solid #f3f3f3;
            border-top: 4px solid #11998e;
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin: 0 auto 1rem;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        .error {
            background: #fee;
            color: #c00;
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1rem;
            text-align: center;
        }
        
        .badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 12px;
            font-size: 0.85rem;
            font-weight: 600;
        }
        
        .badge-success { background: #10b981; color: white; }
        .badge-danger { background: #ef4444; color: white; }
        .badge-warning { background: #f59e0b; color: white; }
        .badge-info { background: #3b82f6; color: white; }
    </style>
</head>
<body>
    <div class="container">
        <div id="loadingScreen" class="loading">
            <div class="spinner"></div>
            <p>กำลังตรวจสอบสถานะ...</p>
        </div>
        
        <div id="errorScreen" style="display:none;">
            <div class="error" id="errorMessage"></div>
            <button class="btn" onclick="location.reload()">ลองอีกครั้ง</button>
        </div>
        
        <div id="statusScreen" style="display:none;">
            <div class="header">
                <h1>🔍 สถานะใบสมัคร</h1>
            </div>
            
            <div class="status-card">
                <div class="app-no" id="applicationNo"></div>
                <div class="status" id="statusText"></div>
                <div id="substatus" style="font-size:0.9rem;"></div>
            </div>
            
            <div class="info-section">
                <h3>📋 ข้อมูลใบสมัคร</h3>
                <div class="info-row">
                    <span class="label">แคมเปญ:</span>
                    <span class="value" id="campaignName"></span>
                </div>
                <div class="info-row">
                    <span class="label">วันที่สมัคร:</span>
                    <span class="value" id="submittedDate"></span>
                </div>
                <div class="info-row">
                    <span class="label">อัปเดตล่าสุด:</span>
                    <span class="value" id="updatedDate"></span>
                </div>
            </div>
            
            <div class="info-section" id="appointmentSection" style="display:none;">
                <h3>📅 นัดหมาย</h3>
                <div class="info-row">
                    <span class="label">วันเวลา:</span>
                    <span class="value" id="appointmentDate"></span>
                </div>
                <div class="info-row">
                    <span class="label">สถานที่:</span>
                    <span class="value" id="appointmentLocation"></span>
                </div>
                <div id="appointmentNote" style="padding:0.5rem 0;color:#666;font-size:0.9rem;"></div>
            </div>
            
            <div class="info-section" id="rejectionSection" style="display:none;background:#fee;">
                <h3 style="color:#c00;">❌ เหตุผลที่ปฏิเสธ</h3>
                <p id="rejectionReason" style="color:#c00;margin-top:0.5rem;"></p>
            </div>
            
            <div style="margin-top:1.5rem;">
                <h3 style="margin-bottom:1rem;">📊 ติดตามสถานะ</h3>
                <div class="timeline" id="timeline"></div>
            </div>
            
            <div id="actionButtons"></div>
            
            <button class="btn" onclick="liff.closeWindow()">ปิดหน้าต่าง</button>
        </div>
    </div>
    
    <script>
        let liffId = 'YOUR_LIFF_ID'; // TODO: Replace with actual LIFF ID
        let userProfile = null;
        let applicationData = null;
        
        // Initialize LIFF
        window.addEventListener('load', async () => {
            try {
                await liff.init({ liffId: liffId });
                
                if (!liff.isLoggedIn()) {
                    liff.login();
                    return;
                }
                
                userProfile = await liff.getProfile();
                await loadApplication();
                
            } catch (error) {
                console.error('LIFF init error:', error);
                showError('ไม่สามารถเชื่อมต่อ LINE ได้');
            }
        });
        
        // Load Application
        async function loadApplication() {
            try {
                const response = await fetch(`../api/lineapp/applications.php?line_user_id=${userProfile.userId}`);
                const result = await response.json();
                
                if (!result.success || !result.data || result.data.length === 0) {
                    throw new Error('ไม่พบข้อมูลใบสมัคร');
                }
                
                // Get latest application
                applicationData = result.data[0];
                renderStatus();
                
            } catch (error) {
                console.error('Load error:', error);
                showError(error.message || 'ไม่สามารถโหลดข้อมูลได้');
            }
        }
        
        // Render Status
        function renderStatus() {
            document.getElementById('loadingScreen').style.display = 'none';
            document.getElementById('statusScreen').style.display = 'block';
            
            // Application info
            document.getElementById('applicationNo').textContent = applicationData.application_no;
            document.getElementById('statusText').textContent = getStatusLabel(applicationData.status);
            
            if (applicationData.substatus) {
                document.getElementById('substatus').textContent = applicationData.substatus;
            }
            
            document.getElementById('campaignName').textContent = applicationData.campaign_name;
            document.getElementById('submittedDate').textContent = formatDate(applicationData.submitted_at);
            document.getElementById('updatedDate').textContent = formatDate(applicationData.updated_at);
            
            // Appointment
            if (applicationData.appointment_datetime) {
                document.getElementById('appointmentSection').style.display = 'block';
                document.getElementById('appointmentDate').textContent = formatDate(applicationData.appointment_datetime);
                document.getElementById('appointmentLocation').textContent = applicationData.appointment_location || '-';
                if (applicationData.appointment_note) {
                    document.getElementById('appointmentNote').textContent = applicationData.appointment_note;
                }
            }
            
            // Rejection reason
            if (applicationData.status === 'REJECTED' && applicationData.rejection_reason) {
                document.getElementById('rejectionSection').style.display = 'block';
                document.getElementById('rejectionReason').textContent = applicationData.rejection_reason;
            }
            
            // Timeline
            renderTimeline();
            
            // Action buttons
            renderActionButtons();
        }
        
        // Render Timeline
        function renderTimeline() {
            const timeline = document.getElementById('timeline');
            const history = applicationData.status_history || [];
            
            if (history.length === 0) {
                timeline.innerHTML = '<p style="color:#999;">ยังไม่มีประวัติ</p>';
                return;
            }
            
            timeline.innerHTML = history.map((item, index) => {
                const isActive = index === history.length - 1;
                return `
                    <div class="timeline-item ${isActive ? 'active' : ''}">
                        <div class="date">${formatDate(item.changed_at)}</div>
                        <div class="title">${getStatusLabel(item.to)}</div>
                        <div class="description">${item.reason || ''}</div>
                    </div>
                `;
            }).join('');
        }
        
        // Render Action Buttons
        function renderActionButtons() {
            const container = document.getElementById('actionButtons');
            
            if (applicationData.status === 'INCOMPLETE') {
                container.innerHTML = `
                    <button class="btn btn-secondary" onclick="reuploadDocuments()">
                        📤 อัปโหลดเอกสารเพิ่ม
                    </button>
                `;
            }
        }
        
        // Re-upload Documents
        function reuploadDocuments() {
            // TODO: Implement re-upload flow
            alert('กำลังพัฒนา: ฟีเจอร์อัปโหลดเอกสารเพิ่ม');
        }
        
        // Utility Functions
        function getStatusLabel(status) {
            const labels = {
                'RECEIVED': '✅ ได้รับใบสมัครแล้ว',
                'DOC_PENDING': '⏳ รอเอกสาร',
                'OCR_PROCESSING': '🔄 กำลังประมวลผล',
                'NEED_REVIEW': '👀 รอตรวจสอบ',
                'APPROVED': '🎉 อนุมัติแล้ว',
                'REJECTED': '❌ ปฏิเสธ',
                'INCOMPLETE': '📄 ขอเอกสารเพิ่ม'
            };
            return labels[status] || status;
        }
        
        function formatDate(dateString) {
            if (!dateString) return '-';
            const date = new Date(dateString);
            return date.toLocaleString('th-TH', {
                year: 'numeric',
                month: 'long',
                day: 'numeric',
                hour: '2-digit',
                minute: '2-digit'
            });
        }
        
        function showError(message) {
            document.getElementById('loadingScreen').style.display = 'none';
            document.getElementById('errorScreen').style.display = 'block';
            document.getElementById('errorMessage').textContent = message;
        }
    </script>
</body>
</html>
