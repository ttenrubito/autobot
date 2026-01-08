<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>สมัคร - LINE Application</title>
    
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
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
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
            margin-bottom: 0.5rem;
        }
        
        .header p {
            color: #666;
            font-size: 0.9rem;
        }
        
        .user-info {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 1rem;
            background: #f7f7f7;
            border-radius: 12px;
            margin-bottom: 1.5rem;
        }
        
        .user-info img {
            width: 50px;
            height: 50px;
            border-radius: 50%;
        }
        
        .user-info .name {
            font-weight: 600;
            color: #333;
        }
        
        .form-group {
            margin-bottom: 1.5rem;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: #333;
        }
        
        .form-group label .required {
            color: #e74c3c;
        }
        
        .form-control {
            width: 100%;
            padding: 0.75rem;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 1rem;
            transition: border-color 0.3s;
        }
        
        .form-control:focus {
            outline: none;
            border-color: #667eea;
        }
        
        textarea.form-control {
            min-height: 100px;
            resize: vertical;
        }
        
        .file-upload {
            border: 2px dashed #667eea;
            border-radius: 8px;
            padding: 1.5rem;
            text-align: center;
            cursor: pointer;
            transition: background 0.3s;
        }
        
        .file-upload:hover {
            background: #f0f0ff;
        }
        
        .file-upload input {
            display: none;
        }
        
        .file-preview {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            margin-top: 0.5rem;
        }
        
        .file-preview img {
            width: 80px;
            height: 80px;
            object-fit: cover;
            border-radius: 8px;
            border: 2px solid #e0e0e0;
        }
        
        .btn {
            width: 100%;
            padding: 1rem;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 12px;
            font-size: 1.1rem;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.2s;
        }
        
        .btn:hover {
            transform: translateY(-2px);
        }
        
        .btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        
        .loading {
            text-align: center;
            padding: 3rem;
        }
        
        .spinner {
            width: 50px;
            height: 50px;
            border: 4px solid #f3f3f3;
            border-top: 4px solid #667eea;
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
        }
        
        .success {
            background: #efe;
            color: #060;
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1rem;
            text-align: center;
        }
        
        .section-title {
            font-size: 1.2rem;
            font-weight: 600;
            color: #333;
            margin: 1.5rem 0 1rem 0;
            padding-bottom: 0.5rem;
            border-bottom: 2px solid #667eea;
        }
    </style>
</head>
<body>
    <div class="container">
        <div id="loadingScreen" class="loading">
            <div class="spinner"></div>
            <p>กำลังโหลด...</p>
        </div>
        
        <div id="errorScreen" style="display:none;">
            <div class="error" id="errorMessage"></div>
            <button class="btn" onclick="location.reload()">ลองอีกครั้ง</button>
        </div>
        
        <div id="successScreen" style="display:none;">
            <div class="success">
                <h2>✅ ส่งใบสมัครสำเร็จ!</h2>
                <p id="applicationNo" style="margin:1rem 0;font-size:1.2rem;font-weight:600;"></p>
                <p>เราจะแจ้งผลการพิจารณาทาง LINE ให้ทราบค่ะ</p>
            </div>
            <button class="btn" onclick="liff.closeWindow()">ปิดหน้าต่าง</button>
        </div>
        
        <div id="formScreen" style="display:none;">
            <div class="header">
                <h1 id="campaignName">📋 สมัครออนไลน์</h1>
                <p id="campaignDescription"></p>
            </div>
            
            <div class="user-info" id="userInfo" style="display:none;">
                <img id="userPicture" src="" alt="User">
                <div>
                    <div class="name" id="userName"></div>
                    <div style="font-size:0.85rem;color:#666;" id="userEmail"></div>
                </div>
            </div>
            
            <form id="applicationForm">
                <!-- Section 1: Personal Info -->
                <div class="section-title">📝 ข้อมูลส่วนตัว</div>
                <div id="formFields"></div>
                
                <!-- Section 2: Contact -->
                <div class="section-title">📞 ข้อมูลติดต่อ</div>
                <div class="form-group">
                    <label>เบอร์โทรศัพท์ <span class="required">*</span></label>
                    <input type="tel" id="phone" class="form-control" placeholder="098-765-4321" required>
                </div>
                <div class="form-group">
                    <label>อีเมล</label>
                    <input type="email" id="email" class="form-control" placeholder="example@email.com">
                </div>
                
                <!-- Section 3: Documents -->
                <div class="section-title">📄 เอกสารประกอบ</div>
                <div id="documentFields"></div>
                
                <button type="submit" class="btn" id="submitBtn">
                    ส่งใบสมัคร
                </button>
            </form>
        </div>
    </div>
    
    <script>
        let liffId = 'YOUR_LIFF_ID'; // TODO: Replace with actual LIFF ID
        let userProfile = null;
        let campaignData = null;
        let uploadedFiles = {};
        
        // Initialize LIFF
        window.addEventListener('load', async () => {
            try {
                await liff.init({ liffId: liffId });
                
                if (!liff.isLoggedIn()) {
                    liff.login();
                    return;
                }
                
                // Get user profile
                userProfile = await liff.getProfile();
                console.log('User profile:', userProfile);
                
                // Load campaign
                await loadCampaign();
                
            } catch (error) {
                console.error('LIFF init error:', error);
                showError('ไม่สามารถเชื่อมต่อ LINE ได้ กรุณาลองใหม่อีกครั้ง');
            }
        });
        
        // Load Campaign
        async function loadCampaign() {
            try {
                // Get campaign ID from URL parameter
                const urlParams = new URLSearchParams(window.location.search);
                const campaignId = urlParams.get('campaign_id') || 1;
                
                const response = await fetch(`../api/lineapp/campaigns.php?id=${campaignId}`);
                const result = await response.json();
                
                if (!result.success) {
                    throw new Error(result.message || 'ไม่พบแคมเปญนี้');
                }
                
                campaignData = result.data;
                renderForm();
                
            } catch (error) {
                console.error('Load campaign error:', error);
                showError(error.message || 'ไม่สามารถโหลดข้อมูลแคมเปญได้');
            }
        }
        
        // Render Form
        function renderForm() {
            // Hide loading
            document.getElementById('loadingScreen').style.display = 'none';
            document.getElementById('formScreen').style.display = 'block';
            
            // Set campaign info
            document.getElementById('campaignName').textContent = campaignData.name;
            document.getElementById('campaignDescription').textContent = campaignData.description || '';
            
            // Show user info
            document.getElementById('userInfo').style.display = 'flex';
            document.getElementById('userPicture').src = userProfile.pictureUrl || '';
            document.getElementById('userName').textContent = userProfile.displayName;
            
            // Render dynamic form fields
            const formFields = document.getElementById('formFields');
            const questions = campaignData.form_config || [];
            
            formFields.innerHTML = questions.map((q, index) => {
                return `
                    <div class="form-group">
                        <label>
                            ${q.label} 
                            ${q.required ? '<span class="required">*</span>' : ''}
                        </label>
                        ${renderFormField(q, index)}
                    </div>
                `;
            }).join('');
            
            // Render document fields
            const docFields = document.getElementById('documentFields');
            const documents = campaignData.required_documents || [];
            
            docFields.innerHTML = documents.map((doc, index) => {
                return `
                    <div class="form-group">
                        <label>
                            ${doc.label || doc.type}
                            ${doc.required ? '<span class="required">*</span>' : ''}
                        </label>
                        <div class="file-upload" onclick="document.getElementById('file_${index}').click()">
                            <input type="file" id="file_${index}" accept="image/*,application/pdf" 
                                   onchange="handleFileUpload(event, '${doc.type}', ${index})" 
                                   ${doc.required ? 'required' : ''}>
                            <p>📷 คลิกเพื่ออัปโหลด</p>
                            <p style="font-size:0.85rem;color:#666;">รองรับ JPG, PNG, PDF (สูงสุด 5MB)</p>
                        </div>
                        <div id="preview_${index}" class="file-preview"></div>
                    </div>
                `;
            }).join('');
        }
        
        // Render Form Field
        function renderFormField(question, index) {
            const fieldName = `field_${index}`;
            
            switch (question.type) {
                case 'textarea':
                    return `<textarea class="form-control" name="${fieldName}" placeholder="${question.placeholder || ''}" ${question.required ? 'required' : ''}></textarea>`;
                    
                case 'select':
                    const options = question.options || [];
                    return `
                        <select class="form-control" name="${fieldName}" ${question.required ? 'required' : ''}>
                            <option value="">-- เลือก --</option>
                            ${options.map(opt => `<option value="${opt}">${opt}</option>`).join('')}
                        </select>
                    `;
                    
                case 'date':
                case 'email':
                case 'tel':
                case 'number':
                    return `<input type="${question.type}" class="form-control" name="${fieldName}" placeholder="${question.placeholder || ''}" ${question.required ? 'required' : ''}>`;
                    
                default: // text
                    return `<input type="text" class="form-control" name="${fieldName}" placeholder="${question.placeholder || ''}" ${question.required ? 'required' : ''}>`;
            }
        }
        
        // Handle File Upload
        function handleFileUpload(event, documentType, index) {
            const file = event.target.files[0];
            if (!file) return;
            
            // Validate file size (5MB)
            if (file.size > 5 * 1024 * 1024) {
                alert('ไฟล์ใหญ่เกิน 5MB กรุณาเลือกไฟล์ใหม่');
                event.target.value = '';
                return;
            }
            
            // Store file
            uploadedFiles[documentType] = file;
            
            // Show preview
            const preview = document.getElementById(`preview_${index}`);
            if (file.type.startsWith('image/')) {
                const reader = new FileReader();
                reader.onload = (e) => {
                    preview.innerHTML = `<img src="${e.target.result}" alt="Preview">`;
                };
                reader.readAsDataURL(file);
            } else {
                preview.innerHTML = `<p>✅ ${file.name}</p>`;
            }
        }
        
        // Handle Form Submit
        document.getElementById('applicationForm')?.addEventListener('submit', async (e) => {
            e.preventDefault();
            
            const submitBtn = document.getElementById('submitBtn');
            submitBtn.disabled = true;
            submitBtn.textContent = 'กำลังส่ง...';
            
            try {
                // Collect form data
                const formData = {};
                const questions = campaignData.form_config || [];
                questions.forEach((q, index) => {
                    const field = document.querySelector(`[name="field_${index}"]`);
                    if (field) {
                        formData[q.label] = field.value;
                    }
                });
                
                // Create application
                const appResponse = await fetch('../api/lineapp/applications.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({
                        campaign_id: campaignData.id,
                        line_user_id: userProfile.userId,
                        line_display_name: userProfile.displayName,
                        line_picture_url: userProfile.pictureUrl,
                        phone: document.getElementById('phone').value,
                        email: document.getElementById('email').value || null,
                        form_data: formData,
                        source: 'line_liff'
                    })
                });
                
                const appResult = await appResponse.json();
                
                if (!appResult.success) {
                    throw new Error(appResult.message || 'ไม่สามารถสร้างใบสมัครได้');
                }
                
                const applicationId = appResult.data.application_id;
                const applicationNo = appResult.data.application_no;
                
                // Upload documents
                for (const [docType, file] of Object.entries(uploadedFiles)) {
                    const docFormData = new FormData();
                    docFormData.append('application_id', applicationId);
                    docFormData.append('document_type', docType);
                    docFormData.append('document_label', docType);
                    docFormData.append('file', file);
                    docFormData.append('source', 'line_liff');
                    
                    await fetch('../api/lineapp/documents.php', {
                        method: 'POST',
                        body: docFormData
                    });
                }
                
                // Show success
                document.getElementById('formScreen').style.display = 'none';
                document.getElementById('successScreen').style.display = 'block';
                document.getElementById('applicationNo').textContent = `เลขที่: ${applicationNo}`;
                
            } catch (error) {
                console.error('Submit error:', error);
                alert('เกิดข้อผิดพลาด: ' + error.message);
                submitBtn.disabled = false;
                submitBtn.textContent = 'ส่งใบสมัคร';
            }
        });
        
        // Show Error
        function showError(message) {
            document.getElementById('loadingScreen').style.display = 'none';
            document.getElementById('errorScreen').style.display = 'block';
            document.getElementById('errorMessage').textContent = message;
        }
    </script>
</body>
</html>
