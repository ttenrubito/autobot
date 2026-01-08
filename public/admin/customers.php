<?php
/**
 * Admin Customers Management
 */
define('INCLUDE_CHECK', true);

$page_title = "จัดการลูกค้า - Admin Panel";
$current_page = "customers";

include('../../includes/admin/header.php');
include('../../includes/admin/sidebar.php');
?>

<main class="main-content">
    <div class="page-header">
        <div style="display: flex; justify-content: space-between; align-items: center;">
            <div>
                <h1 class="page-title"><i class="fas fa-users"></i> จัดการลูกค้า</h1>
                <p class="page-subtitle">จัดการข้อมูลลูกค้า แพ็กเกจ ช่องทางเชื่อมต่อ และบอทตอบกลับ</p>
            </div>
            <button class="btn btn-primary" onclick="showCreateCustomerModal()">
                <i class="fas fa-plus"></i> เพิ่มลูกค้าใหม่
            </button>
        </div>
    </div>

    <div class="card">
        <div class="card-body">
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>อีเมล</th>
                            <th>ชื่อ</th>
                            <th>บริษัท</th>
                            <th>แพ็กเกจปัจจุบัน</th>
                            <th>สถานะ</th>
                            <th>วันที่สมัคร</th>
                            <th>จัดการ</th>
                        </tr>
                    </thead>
                    <tbody id="customersTable">
                        <tr>
                            <td colspan="7" style="text-align:center;">กำลังโหลด...</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Customer Detail Drawer / Tabs -->
    <div id="customerDetailPanel" class="card hidden" style="margin-top: 1.5rem;">
        <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
            <div>
                <h3 class="card-title"><i class="fas fa-user-circle"></i> รายละเอียดลูกค้า</h3>
                <div id="customerDetailSummary" class="page-subtitle"></div>
            </div>
            <button class="btn btn-sm btn-outline" onclick="hideCustomerDetailPanel()">
                <i class="fas fa-times"></i> ปิด
            </button>
        </div>
        <div class="card-body">
            <div class="tabs">
                <button class="tab-button active" data-tab="profile" onclick="switchCustomerTab('profile')">
                    <i class="fas fa-id-card"></i> โปรไฟล์
                </button>
                <button class="tab-button" data-tab="channels" onclick="switchCustomerTab('channels')">
                    <i class="fas fa-plug"></i> ช่องทาง (Channels)
                </button>
                <button class="tab-button" data-tab="integrations" onclick="switchCustomerTab('integrations')">
                    <i class="fas fa-key"></i> Integrations / API Keys
                </button>
                <button class="tab-button" data-tab="bot-profiles" onclick="switchCustomerTab('bot-profiles')">
                    <i class="fas fa-robot"></i> Bot Profiles
                </button>
            </div>

            <!-- Tab: Profile (placeholder) -->
            <div id="tab-profile" class="tab-content active">
                <p style="color:var(--color-gray);">ข้อมูลโปรไฟล์ลูกค้า และสรุปบริการจะมาอยู่ตรงนี้ (ยังไม่เชื่อม API เต็ม)</p>
            </div>

            <!-- Tab: Channels -->
            <div id="tab-channels" class="tab-content hidden">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem;">
                    <h4><i class="fas fa-plug"></i> ช่องทางเชื่อมต่อ (Facebook / LINE / Webhook)</h4>
                    <button class="btn btn-sm btn-primary" onclick="openChannelModal()">
                        <i class="fas fa-plus"></i> เพิ่ม Channel
                    </button>
                </div>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>ชื่อ Channel</th>
                                <th>ประเภท</th>
                                <th>Inbound API Key</th>
                                <th>Bot Profile</th>
                                <th>สถานะ</th>
                                <th>จัดการ</th>
                            </tr>
                        </thead>
                        <tbody id="channelsTable">
                            <tr>
                                <td colspan="6" style="text-align:center;color:var(--color-gray);">
                                    ยังไม่มีข้อมูลช่องทาง (รอเชื่อม API ภายหลัง)
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Tab: Integrations -->
            <div id="tab-integrations" class="tab-content hidden">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem;">
                    <h4><i class="fas fa-key"></i> External Integrations (Google / LINE / OpenAI ฯลฯ)</h4>
                    <button class="btn btn-sm btn-primary" onclick="openIntegrationModal()">
                        <i class="fas fa-plus"></i> เพิ่ม Integration
                    </button>
                </div>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>Provider</th>
                                <th>API Key / Credential</th>
                                <th>Config</th>
                                <th>สถานะ</th>
                                <th>จัดการ</th>
                            </tr>
                        </thead>
                        <tbody id="integrationsTable">
                            <tr>
                                <td colspan="5" style="text-align:center;color:var(--color-gray);">
                                    ยังไม่มี Integration (รอเชื่อม API ภายหลัง)
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Tab: Bot Profiles -->
            <div id="tab-bot-profiles" class="tab-content hidden">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem;">
                    <h4><i class="fas fa-robot"></i> โปรไฟล์บอท / Logic การตอบแชท</h4>
                    <button class="btn btn-sm btn-primary" onclick="openBotProfileModal()">
                        <i class="fas fa-plus"></i> สร้าง Bot Profile
                    </button>
                </div>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>ชื่อโปรไฟล์</th>
                                <th>Handler Key</th>
                                <th>ใช้กับกี่ Channel</th>
                                <th>ตั้งเป็นค่าเริ่มต้น</th>
                                <th>จัดการ</th>
                            </tr>
                        </thead>
                        <tbody id="botProfilesTable">
                            <tr>
                                <td colspan="5" style="text-align:center;color:var(--color-gray);">
                                    ยังไม่มี Bot Profile (รอเชื่อม API ภายหลัง)
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Modals: Channel / Integration / Bot Profile (UI only, no API yet) -->
    <div id="channelModal" class="modal-backdrop hidden">
        <div class="modal-content">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title"><i class="fas fa-plug"></i> ตั้งค่า Channel</h3>
                    <button class="modal-close-btn" onclick="closeChannelModal()"><i class="fas fa-times"></i></button>
                </div>
                <div class="card-body">
                    <form id="channelForm">
                        <div class="form-group">
                            <label class="form-label">ชื่อ Channel</label>
                            <input type="text" class="form-control" id="channelName" placeholder="เช่น Facebook Page A" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">ประเภท</label>
                            <select id="channelType" class="form-control" onchange="toggleChannelFields()">
                                <option value="facebook">Facebook Messenger</option>
                                <option value="line">LINE Official Account</option>
                                <option value="webhook">Webhook ทั่วไป</option>
                                <option value="other">อื่น ๆ</option>
                            </select>
                        </div>
                        
                        <!-- Facebook Fields -->
                        <div id="facebookFields" style="display:none;border:1px solid #e5e7eb;border-radius:8px;padding:1rem;margin:1rem 0;background:#f9fafb;">
                            <h4 style="margin:0 0 1rem 0;font-size:1rem;color:var(--color-primary);">
                                <i class="fab fa-facebook"></i> Facebook Configuration
                            </h4>
                            <div class="form-group">
                                <label class="form-label">Page Access Token <span style="color:red;">*</span></label>
                                <input type="text" id="fbPageAccessToken" class="form-control" placeholder="EAA...">
                                <small style="color:var(--color-gray);">Get from Facebook App → Messenger → Settings</small>
                            </div>
                            <div class="form-group">
                                <label class="form-label">App Secret <span style="color:red;">*</span></label>
                                <input type="password" id="fbAppSecret" class="form-control" placeholder="abc123...">
                                <small style="color:var(--color-gray);">Get from Facebook App → Settings → Basic</small>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Verify Token</label>
                                <input type="text" id="fbVerifyToken" class="form-control" value="autobot_verify_2024">
                                <small style="color:var(--color-gray);">Use this when setting up webhook in Facebook</small>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Page ID</label>
                                <input type="text" id="fbPageId" class="form-control" placeholder="123456789">
                                <small style="color:var(--color-gray);">Optional: Your Facebook Page ID</small>
                            </div>
                        </div>
                        
                        <!-- LINE Fields -->
                        <div id="lineFields" style="display:none;border:1px solid #e5e7eb;border-radius:8px;padding:1rem;margin:1rem 0;background:#f9fafb;">
                            <h4 style="margin:0 0 1rem 0;font-size:1rem;color:#06c755;">
                                <i class="fab fa-line"></i> LINE Configuration
                            </h4>
                            <div class="form-group">
                                <label class="form-label">Channel Secret <span style="color:red;">*</span></label>
                                <input type="password" id="lineChannelSecret" class="form-control" placeholder="abc...">
                                <small style="color:var(--color-gray);">Get from LINE Developers → Basic Settings</small>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Channel Access Token <span style="color:red;">*</span></label>
                                <textarea id="lineChannelAccessToken" class="form-control" rows="2" placeholder="xyz..."></textarea>
                                <small style="color:var(--color-gray);">Get from LINE Developers → Messaging API</small>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Inbound API Key</label>
                            <div style="display:flex;gap:0.5rem;">
                                <input type="text" id="channelApiKey" class="form-control" readonly>
                                <button type="button" class="btn btn-outline" onclick="generateChannelKey()">สุ่ม</button>
                            </div>
                            <small style="color:var(--color-gray);">Auto-generated unique key for this channel</small>
                        </div>
                        
                        <!-- Webhook URL Display -->
                        <div id="webhookUrlDisplay" style="display:none;background:#f0f9ff;border:1px solid #bfdbfe;border-radius:8px;padding:1rem;margin:1rem 0;">
                            <label class="form-label" style="margin-bottom:0.5rem;">Webhook URL (ใช้ตั้งค่าใน Facebook/LINE)</label>
                            <div style="display:flex;gap:0.5rem;align-items:center;">
                                <input type="text" id="webhookUrl" class="form-control" readonly style="font-family:monospace;font-size:0.9rem;background:white;">
                                <button type="button" class="btn btn-sm btn-outline" onclick="copyWebhookUrl()" title="Copy">
                                    <i class="fas fa-copy"></i>
                                </button>
                            </div>
                            <small style="color:var(--color-gray);display:block;margin-top:0.5rem;">
                                ⚠️ ต้องใช้ HTTPS ใน production (use ngrok for testing)
                            </small>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Bot Profile ที่ใช้</label>
                            <select id="channelBotProfile" class="form-control">
                                <option value="">(จะโหลดจาก API ภายหลัง)</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label style="display:flex;align-items:center;gap:0.5rem;">
                                <input type="checkbox" id="channelActive" checked>
                                <span>เปิดใช้งาน Channel นี้</span>
                            </label>
                        </div>
                        <div style="display:flex;gap:1rem;margin-top:1.5rem;">
                            <button type="button" class="btn btn-primary" style="flex:1;" onclick="saveChannel()">บันทึก</button>
                            <button type="button" class="btn btn-outline" style="flex:1;" onclick="closeChannelModal()">ยกเลิก</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <div id="integrationModal" class="modal-backdrop hidden">
        <div class="modal-content">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title"><i class="fas fa-key"></i> ตั้งค่า Integration / API Key</h3>
                    <button class="modal-close-btn" onclick="closeIntegrationModal()"><i class="fas fa-times"></i></button>
                </div>
                <div class="card-body">
                    <form id="integrationForm">
                        <div class="form-group">
                            <label class="form-label">Provider</label>
                            <select id="integrationProvider" class="form-control">
                                <option value="google_nlp">Google Natural Language</option>
                                <option value="google_vision">Google Vision</option>
                                <option value="line">LINE Messaging API</option>
                                <option value="openai">OpenAI / ChatGPT (LLM)</option>
                                <option value="gemini">Google Gemini (LLM)</option>
                                <option value="llm">Generic LLM (เช่น OpenAI, Azure, ฯลฯ)</option>
                                <option value="custom">Custom</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">API Key / Credential</label>
                            <textarea id="integrationKey" class="form-control" rows="3" placeholder="วาง API Key หรือ JSON credential ที่นี่"></textarea>
                        </div>
                        <div class="form-group">
                            <label class="form-label" style="display:flex;justify-content:space-between;align-items:center;gap:0.5rem;">
                                <span>Config เพิ่มเติม (JSON)</span>
                                <span style="display:flex;gap:0.25rem;">
                                    <button type="button" id="integrationPresetLlM" class="btn btn-xs btn-outline-secondary">ใช้ LLM</button>
                                    <button type="button" id="integrationPresetVision" class="btn btn-xs btn-outline-secondary">ใช้ Vision</button>
                                    <button type="button" id="integrationPresetNlp" class="btn btn-xs btn-outline-secondary">ใช้ NLP</button>
                                    <button type="button" id="integrationFillExampleBtn" class="btn btn-xs btn-outline-secondary" style="display:none;">เติมจาก Hint</button>
                                </span>
                            </label>
                            <textarea id="integrationConfig" class="form-control" rows="3" placeholder='{"endpoint":"https://api.example.com","model":"gpt-4.1-mini"}'></textarea>
                            <small id="integrationConfigHelp" style="color:var(--color-gray);font-size:0.85rem;display:block;margin-top:0.25rem;"></small>
                        </div>
                        <div class="form-group">
                            <label style="display:flex;align-items:center;gap:0.5rem;">
                                <input type="checkbox" id="integrationActive" checked>
                                <span>เปิดใช้งาน Integration นี้</span>
                            </label>
                        </div>
                        <div style="display:flex;gap:1rem;margin-top:1.5rem;">
                            <button type="button" class="btn btn-primary" style="flex:1;" onclick="saveIntegration()">บันทึก</button>
                            <button type="button" class="btn btn-outline" style="flex:1;" onclick="closeIntegrationModal()">ยกเลิก</button>
                        </div>
                        <p style="margin-top:0.75rem;color:var(--color-gray);font-size:0.85rem;">
                            * ตอนนี้เป็น placeholder UI จะเชื่อม API จริงภายหลัง
                        </p>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <div id="botProfileModal" class="modal-backdrop hidden">
        <div class="modal-content">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title"><i class="fas fa-robot"></i> ตั้งค่า Bot Profile / Logic</h3>
                    <button class="modal-close-btn" onclick="closeBotProfileModal()"><i class="fas fa-times"></i></button>
                </div>
                <div class="card-body">
                    <form id="botProfileForm" onsubmit="return submitNewCustomer(event);">
                        <div class="form-group">
                            <label class="form-label">ชื่อโปรไฟล์บอท</label>
                            <input type="text" class="form-control" id="botProfileName" placeholder="เช่น Ecommerce หลัก, คลินิก เวชกรรม" />
                        </div>

                        <div class="form-group">
                            <label class="form-label">
                                Handler Key
                                <span title="กำหนดตัวจัดการหลักของบอท ปัจจุบันทุกค่า map ไปที่ router_v1 (Rule-based Router)">
                                    <i class="fas fa-info-circle" style="color: var(--color-gray);"></i>
                                </span>
                            </label>
                            <input type="text" class="form-control" id="botProfileHandler" placeholder="ใส่ router_v1 หรือคีย์อื่นสำหรับ handler เฉพาะ" />
                            <small style="color:var(--color-gray);">
                                ตอนนี้ระบบจะใช้ <code>router_v1</code> เป็นตัวจัดการหลักของทุกโปรไฟล์ (Rule-based routing + template)
                            </small>
                        </div>


                        <!-- NEW: Template Selection System -->
                        <div class="form-group" style="border: 2px dashed var(--color-primary); padding: 1.5rem; border-radius: 8px; background: linear-gradient(135deg, rgba(99, 102, 241, 0.05), rgba(168, 85, 247, 0.05));">
                            <label class="form-label" style="font-size: 1.1rem; font-weight: 600;">
                                🎯 เริ่มจาก Template (แนะนำ)
                                <span title="เลือกรูปแบบธุรกิจที่ใกล้เคียงเพื่อให้ระบบเติมค่าตั้งต้นให้อัตโนมัติ คุณสามารถปรับแต่งต่อได้">
                                    <i class="fas fa-info-circle" style="color: var(--color-gray);"></i>
                                </span>
                            </label>
                            
                            <!-- Step 1: Choose Category -->
                            <div id="templateCategoryGrid" class="template-category-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 1rem; margin-top: 0.75rem;">
                                <div class="template-category-card" data-category="shop" onclick="selectTemplateCategory('shop')" style="cursor: pointer; padding: 1rem; border: 2px solid #e5e7eb; border-radius: 8px; text-align: center; transition: all 0.2s;">
                                    <div style="font-size: 2rem; margin-bottom: 0.5rem;">🛒</div>
                                    <div style="font-weight: 600; font-size: 0.9rem;">ร้านค้า</div>
                                    <div style="font-size: 0.75rem; color: var(--color-gray);">E-commerce</div>
                                </div>
                                <div class="template-category-card" data-category="clinic" onclick="selectTemplateCategory('clinic')" style="cursor: pointer; padding: 1rem; border: 2px solid #e5e7eb; border-radius: 8px; text-align: center; transition: all 0.2s;">
                                    <div style="font-size: 2rem; margin-bottom: 0.5rem;">🏥</div>
                                    <div style="font-weight: 600; font-size: 0.9rem;">คลินิก</div>
                                    <div style="font-size: 0.75rem; color: var(--color-gray);">Healthcare</div>
                                </div>
                                <div class="template-category-card" data-category="hotel" onclick="selectTemplateCategory('hotel')" style="cursor: pointer; padding: 1rem; border: 2px solid #e5e7eb; border-radius: 8px; text-align: center; transition: all 0.2s;">
                                    <div style="font-size: 2rem; margin-bottom: 0.5rem;">🏨</div>
                                    <div style="font-weight: 600; font-size: 0.9rem;">โรงแรม</div>
                                    <div style="font-size: 0.75rem; color: var(--color-gray);">Hospitality</div>
                                </div>
                                <div class="template-category-card" data-category="other" onclick="selectTemplateCategory('other')" style="cursor: pointer; padding: 1rem; border: 2px solid #e5e7eb; border-radius: 8px; text-align: center; transition: all 0.2s;">
                                    <div style="font-size: 2rem; margin-bottom: 0.5rem;">📋</div>
                                    <div style="font-weight: 600; font-size: 0.9rem;">อื่น ๆ</div>
                                    <div style="font-size: 0.75rem; color: var(--color-gray);">Generic</div>
                                </div>
                            </div>

                            <!-- Step 2: Select Specific Template -->
                            <div id="templateSelectContainer" class="hidden" style="margin-top: 1rem;">
                                <label class="form-label" style="font-size: 0.9rem;">เลือก Template เฉพาะ</label>
                                <select id="botProfileTemplateSelect" class="form-control" onchange="applySelectedTemplate()">
                                    <option value="">-- เลือก Template --</option>
                                </select>
                                <div id="templateDescription" style="margin-top: 0.5rem; padding: 0.75rem; background: #f9fafb; border-radius: 6px; font-size: 0.85rem; color: var(--color-gray); display: none;"></div>
                            </div>

                            <!-- Step 3: Template Applied Badge -->
                            <div id="templateAppliedBadge" class="hidden" style="margin-top: 1rem; padding: 0.75rem; background: #ecfdf5; border: 1px solid #10b981; border-radius: 6px; display: flex; justify-content: space-between; align-items: center;">
                                <div>
                                    <span style="color: #10b981; font-weight: 600;">✓ ใช้ Template:</span>
                                    <strong id="appliedTemplateName" style="color: #059669;"></strong>
                                </div>
                                <button type="button" class="btn btn-xs btn-outline" onclick="clearTemplateSelection()" style="font-size: 0.8rem;">
                                    เปลี่ยน Template
                                </button>
                            </div>
                        </div>


                        <!-- Guided config: identity -->
                        <div class="form-group">
                            <label class="form-label">ข้อความต้อนรับ (Greeting)</label>
                            <textarea id="botIdentityGreeting" class="form-control" rows="2" placeholder="สวัสดีค่ะ ยินดีต้อนรับสู่ร้าน ..."></textarea>
                        </div>
                        <div class="form-group">
                            <label class="form-label">ข้อความตอบกลับเมื่อไม่เข้าใจ (Fallback)</label>
                            <textarea id="botIdentityFallback" class="form-control" rows="2" placeholder="ขออภัยค่ะ ตอนนี้ยังไม่เข้าใจคำถามนี้ ลองพิมพ์ใหม่อีกครั้งได้เลยนะคะ"></textarea>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Persona / ลักษณะการพูดของบอท</label>
                            <div class="grid" style="grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap:0.5rem;">
                                <div>
                                    <label style="font-size:0.85rem;color:var(--color-gray);">ภาษา</label>
                                    <select id="botPersonaLanguage" class="form-control">
                                        <option value="">(auto)</option>
                                        <option value="th">ไทย (th)</option>
                                        <option value="en">อังกฤษ (en)</option>
                                    </select>
                                </div>
                                <div>
                                    <label style="font-size:0.85rem;color:var(--color-gray);">โทนเสียง</label>
                                    <select id="botPersonaTone" class="form-control">
                                        <option value="">(ไม่ระบุ)</option>
                                        <option value="friendly">เป็นกันเอง</option>
                                        <option value="formal">สุภาพทางการ</option>
                                        <option value="playful">สนุกสนาน</option>
                                    </select>
                                </div>
                                <div>
                                    <label style="font-size:0.85rem;color:var(--color-gray);">ความยาวสูงสุด (ตัวอักษร)</label>
                                    <input type="number" min="0" id="botPersonaMaxChars" class="form-control" placeholder="เช่น 220">
                                </div>
                            </div>
                            <small style="color:var(--color-gray);font-size:0.8rem;">
                                ใช้กำหนด persona ใน config เช่น <code>{ "persona": { "language":"th", "tone":"friendly", "max_chars":220 } }</code>
                            </small>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Template ตอบกลับสำหรับรูปภาพ (Image Templates)</label>
                            <div class="grid" style="grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap:0.5rem;">
                                <div>
                                    <label style="font-size:0.85rem;color:var(--color-gray);">รูปสินค้าสอบถามของ (product_image)</label>
                                    <textarea id="botTemplateProductImage" class="form-control" rows="2" placeholder="ได้รับรูปสินค้ามาแล้วค่ะ รบกวนแจ้งชื่อรุ่นหรือรหัสสินค้าเพิ่มหน่อยนะคะ จะได้ช่วยเช็คของให้ถูกตัวค่ะ"></textarea>
                                </div>
                                <div>
                                    <label style="font-size:0.85rem;color:var(--color-gray);">สลิป/หลักฐานการชำระ (payment_proof)</label>
                                    <textarea id="botTemplatePaymentProof" class="form-control" rows="2" placeholder="ได้รับสลิปเรียบร้อยแล้วค่ะ เดี๋ยวขอเวลาเช็คยอดสักครู่ ถ้ามีอะไรผิดปกติจะแจ้งให้ทราบนะคะ"></textarea>
                                </div>
                                <div>
                                    <label style="font-size:0.85rem;color:var(--color-gray);">รูปทั่วไป (image_generic)</label>
                                    <textarea id="botTemplateImageGeneric" class="form-control" rows="2" placeholder="ได้รับรูปภาพแล้วค่ะ รบกวนช่วยบอกเพิ่มได้นิดนึงนะคะ ว่าอยากให้ช่วยดูหรือสอบถามเรื่องอะไรเกี่ยวกับรูปนี้"></textarea>
                                </div>
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="form-label">การใช้ LLM / Handoff / Buffering</label>
                            <div class="grid" style="grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap:0.5rem;">
                                <div>
                                    <label style="display:flex;align-items:center;gap:0.4rem;font-size:0.9rem;">
                                        <input type="checkbox" id="botLlmEnabled"> ใช้ LLM ช่วยตอบ (fallback / intent)
                                    </label>
                                    <label style="font-size:0.8rem;color:var(--color-gray);margin-top:0.25rem;">ดีเลย์ก่อนตอบ (ms)</label>
                                    <input type="number" min="0" max="3000" id="botLlmReplyDelay" class="form-control" placeholder="เช่น 800">
                                </div>
                                <div>
                                    <label style="display:flex;align-items:center;gap:0.4rem;font-size:0.9rem;">
                                        <input type="checkbox" id="botHandoffEnabled"> เปิดใช้ Handoff หาแอดมินเมื่อไม่มั่นใจ
                                    </label>
                                    <label style="font-size:0.8rem;color:var(--color-gray);margin-top:0.25rem;">Threshold ความมั่นใจ (&lt; ค่านี้จะ handoff)</label>
                                    <input type="number" step="0.05" min="0" max="1" id="botHandoffThreshold" class="form-control" placeholder="เช่น 0.55">
                                </div>
                                <div>
                                    <label style="display:flex;align-items:center;gap:0.4rem;font-size:0.9rem;">
                                        <input type="checkbox" id="botBufferingEnabled"> เปิดใช้ Buffering (รวมข้อความก่อนตอบ)
                                    </label>
                                    <label style="font-size:0.8rem;color:var(--color-gray);margin-top:0.25rem;">ดีเลย์ Buffer (ms)</label>
                                    <input type="number" min="0" id="botBufferingDebounce" class="form-control" placeholder="เช่น 1800">
                                </div>
                            </div>
                            <small style="color:var(--color-gray);font-size:0.8rem;">
                                ค่าพวกนี้จะ map ไปที่ <code>config.llm.reply_delay_ms</code>, <code>config.handoff</code>, <code>config.buffering</code> ของ RouterV1Handler
                            </small>
                        </div>

                        <div class="form-group">
                            <label class="form-label">
                                Intent &amp; Slots (ขั้นสูง)
                                <span title="กำหนด intent มาตรฐาน เช่น product_availability, installment_calc, booking และระบุ slot ที่ต้องการ เช่น price, months, down_payment เพื่อให้ LLM ช่วยถามเก็บข้อมูลให้ครบก่อนตอบ">
                                    <i class="fas fa-info-circle" style="color: var(--color-gray);"></i>
                                </span>
                            </label>
                            <textarea id="botIntentsJson" class="form-control" rows="3" placeholder='{
  "installment_calc": { "slots": ["price", "months", "down_payment"] }
}'></textarea>
                            <small style="color:var(--color-gray);font-size:0.8rem;">
                                ใส่เฉพาะส่วน <code>intents</code> เป็น JSON object (ไม่ต้องใส่ key intents ชั้นนอก) ตัวอย่าง:
                                <code>{ "installment_calc": { "slots": ["price","months","down_payment"] } }</code>
                            </small>
                        </div>

                        <!-- Advanced JSON block -->
                        <div class="form-group">
                            <label class="form-label" style="display:flex;align-items:center;justify-content:space-between;">
                                <span>
                                    Config (JSON ขั้นสูง)
                                    <span title="config ที่ส่งให้ handler router_v1 โดยตรง แนะนำให้ใช้ปุ่ม 'Sync จากฟอร์มด้านบน' เพื่อสร้างโครงให้ถูกต้อง">
                                        <i class="fas fa-info-circle" style="color: var(--color-gray);"></i>
                                    </span>
                                </span>
                                <button type="button" class="btn btn-xs btn-outline" onclick="syncBotConfigFromForm()">
                                    Sync จากฟอร์มด้านบน
                                </button>
                            </label>
                            <textarea id="botProfileConfig" class="form-control" rows="8" placeholder='{
  "routing_policy": {
    "rules": [
      { "when_any": ["มีของไหม"], "route_to": "product_availability" }
    ]
  },
  "response_templates": {
    "greeting": "สวัสดีค่ะ",
    "fallback": "ขออภัยค่ะ ยังไม่เข้าใจคำถามนี้"
  }
}'></textarea>
                            <small style="color:var(--color-gray);">
                                โครงสร้างที่ใช้โดย <code>router_v1</code>:
                                <pre style="white-space:pre-wrap;font-size:0.8rem;margin-top:0.25rem;">{
  "routing_policy": {
    "rules": [
      { "when_any": ["มีของไหม", "สต็อก"], "route_to": "product_availability" },
      { "when_any": ["ผ่อน", "0%"], "route_to": "installment_calc" },
      { "when_any": ["จองคิว", "นัดหมาย"], "route_to": "booking" }
    ]
  },
  "response_templates": {
    "greeting": "...ข้อความต้อนรับ...",
    "fallback": "...ข้อความ fallback..."
  },
  "intents": {
    "installment_calc": { "slots": ["price", "months", "down_payment"] }
  },
  "persona": {
    "language": "th",
    "tone": "friendly"
  }
}</pre>
                            </small>
                        </div>

                        <div class="form-group">
                            <label style="display:flex;align-items:center;gap:0.5rem;">
                                <input type="checkbox" id="botProfileDefault" checked />
                                <span>ตั้งเป็น Bot Profile เริ่มต้นสำหรับลูกค้ารายนี้</span>
                            </label>
                        </div>

                        <div style="display:flex;gap:1rem;margin-top:1.5rem;">
                            <button type="button" class="btn btn-primary" style="flex:1;" onclick="saveBotProfile()">บันทึก</button>
                            <button type="button" class="btn btn-outline" style="flex:1;" onclick="closeBotProfileModal()">ยกเลิก</button>
                        </div>

                        <p style="margin-top:0.75rem;color:var(--color-gray);font-size:0.85rem;">
                            * ระบบจะส่งค่า <code>handler_key</code> และ <code>config</code> นี้ไปที่ API gateway เพื่อกำหนด logic ของบอทต่อช่องทาง (Channel) แต่ละอัน
                        </p>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Assign Plan Modal -->
    <div id="assignPlanModal" class="modal-backdrop hidden">
        <div class="modal-content">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title"><i class="fas fa-box"></i> กำหนดแพ็กเกจให้ลูกค้า</h3>
                    <button class="modal-close-btn" onclick="hideAssignPlanModal()"><i class="fas fa-times"></i></button>
                </div>
                <div class="card-body">
                    <div style="margin-bottom: 1rem;">
                        <strong>ลูกค้า:</strong> <span id="assignPlanCustomerInfo"></span>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">เลือกแพ็กเกจ</label>
                        <select id="assignPlanSelect" class="form-control">
                            <option value="">-- เลือกแพ็กเกจ --</option>
                        </select>
                    </div>

                    <div id="assignPlanError" class="alert alert-danger" style="display: none; margin-top: 1rem;"></div>
                    <div id="assignPlanSuccess" class="alert alert-success" style="display: none; margin-top: 1rem;"></div>

                    <div style="display: flex; gap: 1rem; margin-top: 1.5rem;">
                        <button id="assignPlanSaveBtn" class="btn btn-primary" style="flex: 1;">
                            <i class="fas fa-save"></i> บันทึกแพ็กเกจ
                        </button>
                        <button type="button" class="btn btn-outline" style="flex: 1;" onclick="hideAssignPlanModal()">
                            <i class="fas fa-times"></i> ยกเลิก
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Extend Subscription Modal -->
    <div id="extendSubscriptionModal" class="modal-backdrop hidden">
        <div class="modal-content">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title"><i class="fas fa-calendar-plus"></i> เพิ่มวันใช้งาน</h3>
                    <button class="modal-close-btn" onclick="hideExtendSubscriptionModal()"><i class="fas fa-times"></i></button>
                </div>
                <div class="card-body">
                    <div style="margin-bottom: 1rem;">
                        <strong>ลูกค้า:</strong> <span id="extendSubCustomerInfo"></span>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">จำนวนวันที่ต้องการเพิ่ม</label>
                        <input type="number" id="extendSubDays" class="form-control" min="1" max="3650" value="30" placeholder="ใส่จำนวนวัน (1-3650)">
                        <small style="color: var(--color-gray);">ระบบจะเพิ่มวันต่อจากวันหมดอายุปัจจุบัน หรือเริ่มจากวันนี้หากไม่มีแพ็กเกจ</small>
                    </div>

                    <div id="extendSubError" class="alert alert-danger" style="display: none; margin-top: 1rem;"></div>
                    <div id="extendSubSuccess" class="alert alert-success" style="display: none; margin-top: 1rem;"></div>

                    <div style="display: flex; gap: 1rem; margin-top: 1.5rem;">
                        <button id="extendSubSaveBtn" class="btn btn-success" style="flex: 1;" onclick="saveExtendSubscription()">
                            <i class="fas fa-calendar-plus"></i> เพิ่มวันใช้งาน
                        </button>
                        <button type="button" class="btn btn-outline" style="flex: 1;" onclick="hideExtendSubscriptionModal()">
                            <i class="fas fa-times"></i> ยกเลิก
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Edit Customer Modal -->
    <div id="editCustomerModal" class="modal-backdrop hidden">
        <div class="modal-content">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title"><i class="fas fa-user-edit"></i> แก้ไขข้อมูลลูกค้า</h3>
                    <button class="modal-close-btn" onclick="hideEditCustomerModal()"><i class="fas fa-times"></i></button>
                </div>
                <div class="card-body">
                    <form id="editCustomerForm">
                        <input type="hidden" id="editCustomerId">
                        
                        <div class="form-group">
                            <label class="form-label">อีเมล</label>
                            <input type="email" id="editCustomerEmail" class="form-control" readonly style="background: #f5f5f5;">
                            <small style="color: var(--color-gray);">ไม่สามารถแก้ไขอีเมลได้</small>
                        </div>

                        <div class="form-group">
                            <label class="form-label">ชื่อ-นามสกุล <span style="color: red;">*</span></label>
                            <input type="text" id="editCustomerFullName" class="form-control" required>
                        </div>

                        <div class="form-group">
                            <label class="form-label">เบอร์โทรศัพท์</label>
                            <input type="tel" id="editCustomerPhone" class="form-control" placeholder="081-234-5678">
                        </div>

                        <div class="form-group">
                            <label class="form-label">ชื่อบริษัท</label>
                            <input type="text" id="editCustomerCompany" class="form-control" placeholder="บริษัท ABC จำกัด">
                        </div>

                        <div class="form-group">
                            <label class="form-label">สถานะ</label>
                            <select id="editCustomerStatus" class="form-control">
                                <option value="active">Active - ใช้งานอยู่</option>
                                <option value="trial">Trial - ทดลองใช้งาน</option>
                                <option value="cancelled">Cancelled - ยกเลิกแล้ว</option>
                            </select>
                        </div>

                        <div id="editCustomerError" class="alert alert-danger" style="display: none; margin-top: 1rem;"></div>
                        <div id="editCustomerSuccess" class="alert alert-success" style="display: none; margin-top: 1rem;"></div>

                        <div style="display: flex; gap: 1rem; margin-top: 1.5rem;">
                            <button type="button" id="editCustomerSaveBtn" class="btn btn-primary" style="flex: 1;" onclick="saveEditedCustomer()">
                                <i class="fas fa-save"></i> บันทึกการแก้ไข
                            </button>
                            <button type="button" class="btn btn-outline" style="flex: 1;" onclick="hideEditCustomerModal()">
                                <i class="fas fa-times"></i> ยกเลิก
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Create Customer Modal -->
    <div id="createCustomerModal" class="modal-backdrop hidden">
        <div class="modal-content">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title"><i class="fas fa-user-plus"></i> เพิ่มลูกค้าใหม่</h3>
                    <button class="modal-close-btn" onclick="hideCreateCustomerModal()"><i class="fas fa-times"></i></button>
                </div>
                <div class="card-body">
                    <form id="newCustomerForm" onsubmit="return submitNewCustomer(event);">
                        <div class="form-group">
                            <label class="form-label">อีเมล <span style="color: red;">*</span></label>
                            <input type="email" id="createCustomerEmail" class="form-control" required placeholder="customer@example.com">
                        </div>

                        <div class="form-group">
                            <label class="form-label">ชื่อ-นามสกุล <span style="color: red;">*</span></label>
                            <input type="text" id="createCustomerFullName" class="form-control" required placeholder="สมชาย ใจดี">
                        </div>

                        <div class="form-group">
                            <label class="form-label">รหัสผ่าน <span style="color: red;">*</span></label>
                            <input type="password" id="createCustomerPassword" class="form-control" required placeholder="อย่างน้อย 8 ตัวอักษร">
                            <small style="color: var(--color-gray);">รหัสผ่านต้องมีอย่างน้อย 8 ตัวอักษร</small>
                        </div>

                        <div class="form-group">
                            <label class="form-label">เบอร์โทรศัพท์</label>
                            <input type="tel" id="createCustomerPhone" class="form-control" placeholder="081-234-5678">
                        </div>

                        <div class="form-group">
                            <label class="form-label">ชื่อบริษัท</label>
                            <input type="text" id="createCustomerCompany" class="form-control" placeholder="บริษัท ABC จำกัด">
                        </div>

                        <div class="form-group">
                            <label class="form-label">สถานะ</label>
                            <select id="createCustomerStatus" class="form-control">
                                <option value="trial">Trial - ทดลองใช้งาน</option>
                                <option value="active">Active - ใช้งานอยู่</option>
                                <option value="cancelled">Cancelled - ยกเลิกแล้ว</option>
                            </select>
                        </div>

                        <div id="createCustomerError" class="alert alert-danger" style="display: none; margin-top: 1rem;"></div>
                        <div id="createCustomerSuccess" class="alert alert-success" style="display: none; margin-top: 1rem;"></div>

                        <div style="display: flex; gap: 1rem; margin-top: 1.5rem;">
                            <button type="submit" id="createCustomerSaveBtn" class="btn btn-primary" style="flex: 1;">
                                <i class="fas fa-save"></i> สร้างลูกค้า
                            </button>
                            <button type="button" class="btn btn-outline" style="flex: 1;" onclick="hideCreateCustomerModal()">
                                <i class="fas fa-times"></i> ยกเลิก
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script>

    // Customer Management Functions (Global scope for onclick handlers)
    function showCreateCustomerModal() {
        // Reset form
        document.getElementById('newCustomerForm').reset();
        document.getElementById('createCustomerError').style.display = 'none';
        document.getElementById('createCustomerSuccess').style.display = 'none';
        
        // Show modal
        document.getElementById('createCustomerModal').classList.remove('hidden');
    }

    function hideCreateCustomerModal() {
        document.getElementById('createCustomerModal').classList.add('hidden');
    }

    async function submitNewCustomer(e) {
        e.preventDefault();

        const form = document.getElementById('newCustomerForm');
        const formData = new FormData(form);
        const payload = Object.fromEntries(formData.entries());

        try {
            const res = await apiCall('/api/admin/customer-bot-profiles.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });

            if (!res.success) {
                alert(res.message || 'ไม่สามารถสร้างลูกค้า/บอทได้');
                return false;
            }

            alert('สร้างลูกค้า/บอทสำเร็จ');
            window.location.href = PATH.pages.ADMIN_CUSTOMERS || '/admin/customers.php';
        } catch (err) {
            console.error('submitNewCustomer error', err);
            alert('เกิดข้อผิดพลาดในการสร้างลูกค้า');
        }

        return false;
    }

    async function saveNewCustomer() {
        const errorBox = document.getElementById('createCustomerError');
        const successBox = document.getElementById('createCustomerSuccess');
        const saveBtn = document.getElementById('createCustomerSaveBtn');
        
        errorBox.style.display = 'none';
        successBox.style.display = 'none';
        
        const email = document.getElementById('createCustomerEmail').value.trim();
        const fullName = document.getElementById('createCustomerFullName').value.trim();
        const password = document.getElementById('createCustomerPassword').value;
        const phone = document.getElementById('createCustomerPhone').value.trim();
        const companyName = document.getElementById('createCustomerCompany').value.trim();
        const status = document.getElementById('createCustomerStatus').value;
        
        // Validation
        if (!email || !fullName || !password) {
            errorBox.textContent = 'กรุณากรอกข้อมูลที่จำเป็น (อีเมล, ชื่อ-นามสกุล, รหัสผ่าน)';
            errorBox.style.display = 'block';
            return;
        }
        
        if (password.length < 8) {
            errorBox.textContent = 'รหัสผ่านต้องมีอย่างน้อย 8 ตัวอักษร';
            errorBox.style.display = 'block';
            return;
        }
        
        // Email validation
        const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        if (!emailRegex.test(email)) {
            errorBox.textContent = 'อีเมลไม่ถูกต้อง';
            errorBox.style.display = 'block';
            return;
        }
        
        saveBtn.disabled = true;
        saveBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> กำลังสร้าง...';
        
        try {
            const res = await apiCall('/api/admin/customers.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    email: email,
                    password: password,
                    full_name: fullName,
                    phone: phone || null,
                    company_name: companyName || null,
                    status: status
                })
            });
            
            if (!res.success) {
                errorBox.textContent = res.message || 'ไม่สามารถสร้างลูกค้าได้';
                errorBox.style.display = 'block';
            } else {
                successBox.textContent = 'สร้างลูกค้าสำเร็จ!';
                successBox.style.display = 'block';
                
                // Reload customers list
                loadCustomers();
                
                // Close modal after 1.5 seconds
                setTimeout(() => {
                    hideCreateCustomerModal();
                }, 1500);
            }
        } catch (error) {
            console.error('Error creating customer:', error);
            errorBox.textContent = 'เกิดข้อผิดพลาดในการสร้างลูกค้า';
            errorBox.style.display = 'block';
        } finally {
            saveBtn.disabled = false;
            saveBtn.innerHTML = '<i class="fas fa-save"></i> สร้างลูกค้า';
        }
    }

    async function editCustomer(id) {
        try {
            // Load customer data
            const res = await apiCall(`/api/admin/customers.php?id=${id}`);
            
            if (!res.success || !res.data || !res.data.customer) {
                alert('ไม่สามารถโหลดข้อมูลลูกค้าได้');
                return;
            }
            
            const customer = res.data.customer;
            
            // Populate form
            document.getElementById('editCustomerId').value = customer.id;
            document.getElementById('editCustomerEmail').value = customer.email || '';
            document.getElementById('editCustomerFullName').value = customer.full_name || '';
            document.getElementById('editCustomerPhone').value = customer.phone || '';
            document.getElementById('editCustomerCompany').value = customer.company_name || '';
            document.getElementById('editCustomerStatus').value = customer.status || 'active';
            
            // Reset messages
            document.getElementById('editCustomerError').style.display = 'none';
            document.getElementById('editCustomerSuccess').style.display = 'none';
            
            // Show modal
            document.getElementById('editCustomerModal').classList.remove('hidden');
        } catch (error) {
            console.error('Error loading customer:', error);
            alert('เกิดข้อผิดพลาดในการโหลดข้อมูลลูกค้า');
        }
    }

    function hideEditCustomerModal() {
        document.getElementById('editCustomerModal').classList.add('hidden');
    }

    async function saveEditedCustomer() {
        const errorBox = document.getElementById('editCustomerError');
        const successBox = document.getElementById('editCustomerSuccess');
        const saveBtn = document.getElementById('editCustomerSaveBtn');
        
        errorBox.style.display = 'none';
        successBox.style.display = 'none';
        
        const customerId = document.getElementById('editCustomerId').value;
        const fullName = document.getElementById('editCustomerFullName').value.trim();
        const phone = document.getElementById('editCustomerPhone').value.trim();
        const companyName = document.getElementById('editCustomerCompany').value.trim();
        const status = document.getElementById('editCustomerStatus').value;
        
        // Validation
        if (!fullName) {
            errorBox.textContent = 'กรุณากรอกชื่อ-นามสกุล';
            errorBox.style.display = 'block';
            return;
        }
        
        saveBtn.disabled = true;
        saveBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> กำลังบันทึก...';
        
        try {
            const res = await apiCall(API_ENDPOINTS.ADMIN_CUSTOMERS + `?id=${customerId}`, {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    full_name: fullName,
                    phone: phone || null,
                    company_name: companyName || null,
                    status: status
                })
            });
            
            if (!res.success) {
                errorBox.textContent = res.message || 'ไม่สามารถบันทึกข้อมูลได้';
                errorBox.style.display = 'block';
            } else {
                successBox.textContent = 'บันทึกข้อมูลสำเร็จ!';
                successBox.style.display = 'block';
                
                // Reload customers list
                loadCustomers();
                
                // Close modal after 1.5 seconds
                setTimeout(() => {
                    hideEditCustomerModal();
                }, 1500);
            }
        } catch (error) {
            console.error('Error saving customer:', error);
            errorBox.textContent = 'เกิดข้อผิดพลาดในการบันทึกข้อมูล';
            errorBox.style.display = 'block';
        } finally {
            saveBtn.disabled = false;
            saveBtn.innerHTML = '<i class="fas fa-save"></i> บันทึกการแก้ไข';
        }
    }

    async function deleteCustomer(id) {
        if (!confirm('ต้องการลบลูกค้ารายนี้ใช่หรือไม่?\n\nการลบจะเป็นการลบถาวร รวมถึงข้อมูลทั้งหมดที่เกี่ยวข้อง')) {
            return;
        }
        
        try {
            const res = await apiCall(API_ENDPOINTS.ADMIN_CUSTOMERS + `?id=${id}`, {
                method: 'DELETE'
            });
            
            if (!res.success) {
                alert(res.message || 'ไม่สามารถลบลูกค้าได้');
                return;
            }
            
            alert('ลบลูกค้าเรียบร้อยแล้ว');
            loadCustomers();
            hideCustomerDetailPanel();
        } catch (error) {
            console.error('Error deleting customer:', error);
            alert('เกิดข้อผิดพลาดในการลบลูกค้า');
        }
    }

    // ...existing JS for customers list, detail panel, channels, integrations, bot profiles...

    // ===== Helper for provider hints on integrations =====
    let currentIntegrationHints = null; // hints for the integration currently being edited/created

    // Static default hints by provider (ใช้เวลา create ใหม่ หรือไม่มี provider_hints จาก backend)
    const defaultProviderHints = {
        llm: {
            config_placeholder: '{"endpoint":"https://api.openai.com/v1/chat/completions","model":"gpt-4.1-mini"}',
            help: 'ใส่ API Key ของ LLM (เช่น OpenAI) ด้านบน และกำหนด endpoint + model ให้ตรงกับผู้ให้บริการ'
        },
        openai: {
            config_placeholder: '{"endpoint":"https://api.openai.com/v1/chat/completions","model":"gpt-4.1-mini"}',
            help: 'วาง OpenAI API Key ด้านบน และใช้ endpoint / model ตามแพ็กเกจที่คุณสมัคร'
        },
        gemini: {
            config_placeholder: '{"endpoint":"https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent","model":"gemini-2.5-flash"}',
            help: 'ใช้ Google Gemini (LLM): ใส่ Google AI Studio API Key ด้านบน และใช้ endpoint/model ตามตัวอย่างนี้'
        },
        google_vision: {
            config_placeholder: '{"endpoint":"https://vision.googleapis.com/v1/images:annotate"}',
            help: 'ใช้ Google Cloud Vision API: ใส่ API Key ด้านบน และใช้ endpoint ค่า default ได้เลย'
        },
        google_nlp: {
            config_placeholder: '{"endpoint":"https://language.googleapis.com/v1/documents:analyzeEntitySentiment","language":"th"}',
            help: 'ใช้ Google Cloud Natural Language: ใส่ API Key และกำหนดภาษา เช่น "th" สำหรับภาษาไทย'
        },
        custom: {
            config_placeholder: '{"endpoint":"https://your-backend.example.com/api","type":"custom"}',
            help: 'กำหนด endpoint ของระบบภายในที่ต้องการเรียกและ field อื่น ๆ ตามที่ backend รองรับ'
        }
    };

    function prettyJson(str) {
        if (!str) return '';
        try {
            return JSON.stringify(JSON.parse(str), null, 2);
        } catch (e) {
            return str;
        }
    }

    function applyIntegrationHintsToForm(hints) {
        currentIntegrationHints = hints || null;
        const cfgTextarea = document.getElementById('integrationConfig');
        const helpEl = document.getElementById('integrationConfigHelp');
        const fillBtn = document.getElementById('integrationFillExampleBtn');
        if (!cfgTextarea || !helpEl || !fillBtn) return;

        if (!hints) {
            // ถ้าไม่มี hints ให้ใช้ default ตาม provider ปัจจุบัน
            const provider = document.getElementById('integrationProvider')?.value || '';
            const def = defaultProviderHints[provider] || null;
            if (!cfgTextarea.value) {
                cfgTextarea.placeholder = def ? prettyJson(def.config_placeholder) : '{"endpoint":"https://api.example.com","model":"gpt-4.1-mini"}';
            }
            helpEl.textContent = def ? def.help : '';
            fillBtn.style.display = def ? 'inline-block' : 'none';
            fillBtn.onclick = def ? function () {
                if (cfgTextarea.value && !confirm('ต้องการเขียนทับ Config เดิมด้วยตัวอย่างหรือไม่?')) return;
                cfgTextarea.value = prettyJson(def.config_placeholder || '{}');
            } : null;
            return;
        }

        const placeholder = hints.config_placeholder || '';
        if (!cfgTextarea.value && placeholder) {
            cfgTextarea.placeholder = prettyJson(placeholder);
        }
        helpEl.textContent = hints.help || '';

        fillBtn.style.display = 'inline-block';
        fillBtn.onclick = function () {
            if (cfgTextarea.value && !confirm('ต้องการเขียนทับ Config เดิมด้วยตัวอย่างหรือไม่?')) {
                return;
            }
            cfgTextarea.value = prettyJson(placeholder || '{}');
        };
    }

    function onIntegrationProviderChange() {
        // ทุกครั้งที่เปลี่ยน provider ให้ refresh placeholder/help ตาม default หรือ hints ปัจจุบัน
        applyIntegrationHintsToForm(currentIntegrationHints);
    }

    // ===== Bot Profile guided config helpers =====
    function getRoutingRulesFromUI() {
        const container = document.getElementById('botRoutingRulesContainer');
        const rows = container ? container.querySelectorAll('.bot-routing-row') : [];
        const rules = [];
        rows.forEach(row => {
            const keywordsInput = row.querySelector('.bot-routing-keywords');
            const routeInput = row.querySelector('.bot-routing-route');
            const keywords = (keywordsInput?.value || '')
                .split(',')
                .map(k => k.trim())
                .filter(k => k !== '');
            const routeTo = (routeInput?.value || '').trim();
            if (keywords.length && routeTo) {
                rules.push({ when_any: keywords, route_to: routeTo });
            }
        });
        return rules;
    }

    function addBotRoutingRuleRow(initial = null) {
        const container = document.getElementById('botRoutingRulesContainer');
        if (!container) return;
        if (!container.dataset.initialized) {
            container.innerHTML = '';
            container.dataset.initialized = '1';
        }
        const div = document.createElement('div');
        div.className = 'bot-routing-row';
        div.style.marginBottom = '0.5rem';
        const kw = initial?.when_any?.join(', ') || '';
        const route = initial?.route_to || '';
        div.innerHTML = `
            <div style="display:flex;gap:0.5rem;align-items:center;">
                <input type="text" class="form-control bot-routing-keywords" style="flex:2;" placeholder="คำค้นแยกด้วย , เช่น มีของไหม, สต็อก" value="${kw.replace(/"/g, '&quot;')}">
                <input type="text" class="form-control bot-routing-route" style="flex:1;" placeholder="route_to เช่น product_availability" value="${route.replace(/"/g, '&quot;')}">
                <button type="button" class="btn btn-xs btn-outline" onclick="this.closest('.bot-routing-row').remove()">
                    <i class="fas fa-trash"></i>
                </button>
            </div>
        `;
        container.appendChild(div);
    }

    // ===== NEW Template System Functions =====
    let selectedTemplateData = null; // Store currently selected template
    let availableTemplates = []; // Cache of templates from API

    async function selectTemplateCategory(category) {
        // Highlight selected category card
        document.querySelectorAll('.template-category-card').forEach(card => {
            if (card.dataset.category === category) {
                card.style.borderColor = 'var(--color-primary)';
                card.style.background = 'rgba(99, 102, 241, 0.1)';
            } else {
                card.style.borderColor = '#e5e7eb';
                card.style.background = 'transparent';
            }
        });

        // Load templates for this category
        try {
            const res = await apiCall(`/api/admin/bot-templates.php?category=${category}`);
            if (!res.success || !res.data || !res.data.templates) {
                alert('ไม่สามารถโหลด template ได้');
                return;
            }

            availableTemplates = res.data.templates;
            
            // Populate template dropdown
            const select = document.getElementById('botProfileTemplateSelect');
            const container = document.getElementById('templateSelectContainer');
            
            select.innerHTML = '<option value="">-- เลือก Template --</option>';
            availableTemplates.forEach(tpl => {
                const option = document.createElement('option');
                option.value = tpl.key;
                option.textContent = tpl.name_th;
                option.dataset.description = tpl.description_th || '';
                select.appendChild(option);
            });

            // Show template selector
            container.classList.remove('hidden');
            
            // Auto-select if only one template
            if (availableTemplates.length === 1) {
                select.value = availableTemplates[0].key;
                applySelectedTemplate();
            }
        } catch (error) {
            console.error('Error loading templates:', error);
            alert('เกิดข้อผิดพลาดในการโหลด template');
        }
    }

    async function applySelectedTemplate() {
        const select = document.getElementById('botProfileTemplateSelect');
        const key = select.value;
        
        if (!key) {
            selectedTemplateData = null;
            document.getElementById('templateDescription').style.display = 'none';
            document.getElementById('templateAppliedBadge').classList.add('hidden');
            return;
        }

        // Find template data
        const template = availableTemplates.find(t => t.key === key);
        if (!template) {
            alert('ไม่พบ template นี้');
            return;
        }

        selectedTemplateData = template;

        // Show description
        const descEl = document.getElementById('templateDescription');
        descEl.textContent = template.description_th || template.description_en || '';
        descEl.style.display = template.description_th ? 'block' : 'none';

        // Apply template to form
        applyTemplateToForm(template.config_template);

        // Show applied badge
        document.getElementById('appliedTemplateName').textContent = template.name_th;
        document.getElementById('templateAppliedBadge').classList.remove('hidden');
    }

    function applyTemplateToForm(config) {
        if (!config) return;

        // Set handler key (default to router_v1)
        const handlerInput = document.getElementById('botProfileHandler');
        if (handlerInput && !handlerInput.value) {
            handlerInput.value = 'router_v1';
        }

        // Apply greeting & fallback
        const greetingInput = document.getElementById('botIdentityGreeting');
        const fallbackInput = document.getElementById('botIdentityFallback');
        
        if (greetingInput && config.response_templates?.greeting) {
            greetingInput.value = config.response_templates.greeting;
        }
        if (fallbackInput && config.response_templates?.fallback) {
            fallbackInput.value = config.response_templates.fallback;
        }

        // Apply persona
        if (config.persona) {
            if (config.persona.language) {
                const langEl = document.getElementById('botPersonaLanguage');
                if (langEl) langEl.value = config.persona.language;
            }
            if (config.persona.tone) {
                const toneEl = document.getElementById('botPersonaTone');
                if (toneEl) toneEl.value = config.persona.tone;
            }
            if (config.persona.max_chars) {
                const maxEl = document.getElementById('botPersonaMaxChars');
                if (maxEl) maxEl.value = config.persona.max_chars;
            }
        }

        // Apply image templates
        if (config.response_templates) {
            if (config.response_templates.product_image) {
                const el = document.getElementById('botTemplateProductImage');
                if (el) el.value = config.response_templates.product_image;
            }
            if (config.response_templates.payment_proof) {
                const el = document.getElementById('botTemplatePaymentProof');
                if (el) el.value = config.response_templates.payment_proof;
            }
            if (config.response_templates.image_generic) {
                const el = document.getElementById('botTemplateImageGeneric');
                if (el) el.value = config.response_templates.image_generic;
            }
        }

        // Apply LLM settings
        if (config.llm) {
            const llmCheck = document.getElementById('botLlmEnabled');
            if (llmCheck) llmCheck.checked = !!config.llm.enabled;
            
            const llmDelay = document.getElementById('botLlmReplyDelay');
            if (llmDelay && config.llm.reply_delay_ms) {
                llmDelay.value = config.llm.reply_delay_ms;
            }
        }

        // Apply handoff settings
        if (config.handoff) {
            const handoffCheck = document.getElementById('botHandoffEnabled');
            if (handoffCheck) handoffCheck.checked = !!config.handoff.enabled;
            
            const thresholdEl = document.getElementById('botHandoffThreshold');
            if (thresholdEl && config.handoff.threshold) {
                thresholdEl.value = config.handoff.threshold;
            }
        }

        // Apply buffering settings
        if (config.buffering) {
            const bufCheck = document.getElementById('botBufferingEnabled');
            if (bufCheck) bufCheck.checked = !!config.buffering.enabled;
            
            const debounceEl = document.getElementById('botBufferingDebounce');
            if (debounceEl && config.buffering.debounce_ms) {
                debounceEl.value = config.buffering.debounce_ms;
            }
        }

        // Apply intents JSON
        if (config.intents) {
            const intentsEl = document.getElementById('botIntentsJson');
            if (intentsEl) {
                intentsEl.value = JSON.stringify(config.intents, null, 2);
            }
        }

        // Apply full config to advanced JSON textarea
        const configTextarea = document.getElementById('botProfileConfig');
        if (configTextarea) {
            configTextarea.value = JSON.stringify(config, null, 2);
        }

        // Clear routing rules container and rebuild
        const container = document.getElementById('botRoutingRulesContainer');
        if (container) {
            container.innerHTML = '';
            delete container.dataset.initialized;
        }

        // Populate routing rules
        if (config.routing_policy?.rules) {
            config.routing_policy.rules.forEach(rule => addBotRoutingRuleRow(rule));
        }
    }

    function clearTemplateSelection() {
        selectedTemplateData = null;
        
        // Reset category selection
        document.querySelectorAll('.template-category-card').forEach(card => {
            card.style.borderColor = '#e5e7eb';
            card.style.background = 'transparent';
        });

        // Hide template selector and applied badge
        document.getElementById('templateSelectContainer').classList.add('hidden');
        document.getElementById('templateAppliedBadge').classList.add('hidden');
        document.getElementById('botProfileTemplateSelect').value = '';
        document.getElementById('templateDescription').style.display = 'none';
    }

    // Legacy function kept for backward compatibility (now calls new system)
    function applyBotProfileTemplate(templateKey) {
        console.warn('applyBotProfileTemplate is deprecated, use new template system');
        // This function is no longer used with the new UI
    }


    function syncBotConfigFromForm() {
        const greeting = (document.getElementById('botIdentityGreeting')?.value || '').trim();
        const fallback = (document.getElementById('botIdentityFallback')?.value || '').trim();

        // routing rules
        const rules = getRoutingRulesFromUI();

        // persona
        const persona = {};
        const lang = (document.getElementById('botPersonaLanguage')?.value || '').trim();
        const tone = (document.getElementById('botPersonaTone')?.value || '').trim();
        const maxCharsRaw = (document.getElementById('botPersonaMaxChars')?.value || '').trim();
        if (lang) persona.language = lang;
        if (tone) persona.tone = tone;
        if (maxCharsRaw) {
            const n = parseInt(maxCharsRaw, 10);
            if (!isNaN(n) && n > 0) persona.max_chars = n;
        }

        // image templates
        const tplProductImage = (document.getElementById('botTemplateProductImage')?.value || '').trim();
        const tplPaymentProof = (document.getElementById('botTemplatePaymentProof')?.value || '').trim();
        const tplImageGeneric = (document.getElementById('botTemplateImageGeneric')?.value || '').trim();

        // llm / handoff / buffering
        const llmEnabled = !!document.getElementById('botLlmEnabled')?.checked;
        const llmDelayRaw = (document.getElementById('botLlmReplyDelay')?.value || '').trim();
        const handoffEnabled = !!document.getElementById('botHandoffEnabled')?.checked;
        const handoffThRaw = (document.getElementById('botHandoffThreshold')?.value || '').trim();
        const bufferingEnabled = !!document.getElementById('botBufferingEnabled')?.checked;
        const bufferingDebounceRaw = (document.getElementById('botBufferingDebounce')?.value || '').trim();

        const llm = {};
        if (llmEnabled) llm.enabled = true;
        if (llmDelayRaw) {
            const d = parseInt(llmDelayRaw, 10);
            if (!isNaN(d) && d >= 0) llm.reply_delay_ms = d;
        }

        const handoff = {};
        if (handoffEnabled) handoff.enabled = true;
        if (handoffThRaw) {
            const h = parseFloat(handoffThRaw);
            if (!isNaN(h)) handoff.when_confidence_below = h;
        }

        const buffering = {};
        if (bufferingEnabled) buffering.enabled = true;
        if (bufferingDebounceRaw) {
            const b = parseInt(bufferingDebounceRaw, 10);
            if (!isNaN(b) && b >= 0) buffering.debounce_ms = b;
        }

        // intents JSON fragment
        let intents = undefined;
        const intentsRaw = (document.getElementById('botIntentsJson')?.value || '').trim();
        if (intentsRaw) {
            try {
                const parsed = JSON.parse(intentsRaw);
                if (parsed && typeof parsed === 'object') intents = parsed;
            } catch (e) {
                alert('Intents JSON ไม่ถูกต้อง กรุณาเช็คในช่อง Intent & Slots');
                return;
            }
        }

        const config = {};
        if (Object.keys(persona).length) config.persona = persona;

        config.routing_policy = {
            rules: rules,
            default_router: 'llm_intent'
        };

        const respTemplates = {
            greeting: greeting || 'สวัสดีค่ะ ยินดีให้บริการค่ะ',
            fallback: fallback || 'ขออภัยค่ะ ตอนนี้ยังไม่เข้าใจคำถามนี้ ลองพิมพ์ใหม่อีกครั้งได้เลยนะคะ'
        };
        if (tplProductImage) respTemplates.product_image = tplProductImage;
        if (tplPaymentProof) respTemplates.payment_proof = tplPaymentProof;
        if (tplImageGeneric) respTemplates.image_generic = tplImageGeneric;
        config.response_templates = respTemplates;

        if (intents) config.intents = intents;
        if (Object.keys(handoff).length) config.handoff = handoff;
        if (Object.keys(buffering).length) config.buffering = buffering;
        if (Object.keys(llm).length) config.llm = llm;

        const textarea = document.getElementById('botProfileConfig');
        if (textarea && textarea.value.trim()) {
            if (!confirm('การ Sync จะเขียนทับ config JSON เดิมทั้งหมด ต้องการดำเนินการต่อหรือไม่?')) {
                return;
            }
        }
        if (textarea) {
            textarea.value = JSON.stringify(config, null, 2);
        }
    }

    function populateBotProfileGuidedFieldsFromConfig(configJson) {
        let cfg = {};
        try {
            cfg = configJson ? JSON.parse(configJson) : {};
        } catch (e) {
            cfg = {};
        }
        const greetingInput = document.getElementById('botIdentityGreeting');
        const fallbackInput = document.getElementById('botIdentityFallback');
        if (greetingInput) greetingInput.value = cfg.response_templates?.greeting || '';
        if (fallbackInput) fallbackInput.value = cfg.response_templates?.fallback || '';

        // persona
        const persona = cfg.persona || {};
        if (document.getElementById('botPersonaLanguage')) {
            document.getElementById('botPersonaLanguage').value = persona.language || '';
        }
        if (document.getElementById('botPersonaTone')) {
            document.getElementById('botPersonaTone').value = persona.tone || '';
        }
        if (document.getElementById('botPersonaMaxChars')) {
            document.getElementById('botPersonaMaxChars').value = persona.max_chars || '';
        }

        // image templates
        if (document.getElementById('botTemplateProductImage')) {
            document.getElementById('botTemplateProductImage').value = cfg.response_templates?.product_image || '';
        }
        if (document.getElementById('botTemplatePaymentProof')) {
            document.getElementById('botTemplatePaymentProof').value = cfg.response_templates?.payment_proof || '';
        }
        if (document.getElementById('botTemplateImageGeneric')) {
            document.getElementById('botTemplateImageGeneric').value = cfg.response_templates?.image_generic || '';
        }

        // llm / handoff / buffering
        const llm = cfg.llm || {};
        const handoff = cfg.handoff || {};
        const buffering = cfg.buffering || {};

        if (document.getElementById('botLlmEnabled')) {
            document.getElementById('botLlmEnabled').checked = !!llm.enabled;
        }
        if (document.getElementById('botLlmReplyDelay')) {
            document.getElementById('botLlmReplyDelay').value = llm.reply_delay_ms || '';
        }
        if (document.getElementById('botHandoffEnabled')) {
            document.getElementById('botHandoffEnabled').checked = !!handoff.enabled;
        }
        if (document.getElementById('botHandoffThreshold')) {
            document.getElementById('botHandoffThreshold').value = handoff.when_confidence_below ?? '';
        }
        if (document.getElementById('botBufferingEnabled')) {
            document.getElementById('botBufferingEnabled').checked = !!buffering.enabled;
        }
        if (document.getElementById('botBufferingDebounce')) {
            document.getElementById('botBufferingDebounce').value = buffering.debounce_ms || '';
        }

        // routing rules
        const container = document.getElementById('botRoutingRulesContainer');
        if (container) {
            container.innerHTML = '';
            delete container.dataset.initialized;
            (cfg.routing_policy?.rules || []).forEach(rule => addBotRoutingRuleRow(rule));
        }

        // intents fragment
        if (document.getElementById('botIntentsJson')) {
            const intents = cfg.intents || null;
            document.getElementById('botIntentsJson').value = intents ? JSON.stringify(intents, null, 2) : '';
        }
    }

    // Hook into existing edit/create flows to populate guided fields from JSON whenเปิด modal
    function populateBotProfileGuidedFieldsFromConfig(configJson) {
        let cfg = {};
        try {
            cfg = configJson ? JSON.parse(configJson) : {};
        } catch (e) {
            // keep empty, let admin fix JSON manually
        }
        const greetingInput = document.getElementById('botIdentityGreeting');
        const fallbackInput = document.getElementById('botIdentityFallback');
        if (greetingInput) greetingInput.value = cfg.response_templates?.greeting || '';
        if (fallbackInput) fallbackInput.value = cfg.response_templates?.fallback || '';
        const container = document.getElementById('botRoutingRulesContainer');
        if (container) {
            container.innerHTML = '';
            delete container.dataset.initialized;
            (cfg.routing_policy?.rules || []).forEach(rule => addBotRoutingRuleRow(rule));
        }
    }

    // Wrap existing openBotProfileModal/editBotProfile to call this helper.
    const _origOpenBotProfileModal = typeof openBotProfileModal === 'function' ? openBotProfileModal : null;
    window.openBotProfileModal = function(id) {
        if (_origOpenBotProfileModal) {
            _origOpenBotProfileModal(id);
        }
        // When creating new, reset guided fields
        if (!id) {
            populateBotProfileGuidedFieldsFromConfig(null);
            document.getElementById('botProfileTemplate').value = '';
        }
    };

    const _origEditBotProfile = typeof editBotProfile === 'function' ? editBotProfile : null;
    if (_origEditBotProfile) {
        window.editBotProfile = function(id) {
            _origEditBotProfile(id);
            // The original function should set textarea value; wait a tick to read it
            setTimeout(function() {
                const cfg = document.getElementById('botProfileConfig')?.value || '';
                populateBotProfileGuidedFieldsFromConfig(cfg);
            }, 150);
        };
    }

    // Before saving, keep using existing saveBotProfile which already reads botProfileConfig JSON

    async function loadCustomerIntegrations(userId) {
        const tbody = document.getElementById('integrationsTable');
        if (!tbody) return;
        tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;">กำลังโหลด...</td></tr>';
        try {
            const res = await apiCall(`/api/admin/customer-integrations.php?user_id=${userId}`);
            if (!res.success || !Array.isArray(res.data.integrations) || res.data.integrations.length === 0) {
                tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;color:var(--color-gray);">ยังไม่มี Integration</td></tr>';
                return;
            }
            tbody.innerHTML = res.data.integrations.map(it => {
                const statusBadge = it.is_active ? '<span class="badge badge-success">เปิดใช้งาน</span>' : '<span class="badge badge-secondary">ปิดใช้งาน</span>';
                const configPreview = it.config ? (it.config.length > 60 ? it.config.substring(0, 57) + '...' : it.config) : '-';
                return `
                    <tr>
                        <td>${it.provider}</td>
                        <td>${it.api_key ? '***' : '-'}</td>
                        <td><code>${configPreview}</code></td>
                        <td>${statusBadge}</td>
                        <td>
                            <button class="btn btn-sm btn-outline" onclick="editIntegration(${it.id})"><i class="fas fa-edit"></i></button>
                            <button class="btn btn-sm btn-danger" onclick="deleteIntegration(${it.id})"><i class="fas fa-trash"></i></button>
                        </td>
                    </tr>
                `;
            }).join('');
        } catch (e) {
            console.error('loadCustomerIntegrations error', e);
            tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;color:red;">โหลดข้อมูล Integration ไม่สำเร็จ</td></tr>';
        }
    }

    function openIntegrationModal() {
        if (!selectedCustomerId) {
            alert('กรุณาเลือกลูกค้าก่อน');
            return;
        }
        editingIntegrationId = null;
        document.getElementById('integrationForm').reset();
        currentIntegrationHints = null;
        applyIntegrationHintsToForm(null); // set default based on current provider
        document.getElementById('integrationModal')?.classList.remove('hidden');
    }

    function closeIntegrationModal() { document.getElementById('integrationModal')?.classList.add('hidden'); }

    async function editIntegration(id) {
        try {
            const res = await apiCall(`/api/admin/customer-integrations.php?id=${id}`);
            if (!res.success || !res.data.integration) {
                alert('ไม่พบข้อมูล Integration');
                return;
            }
            const it = res.data.integration;
            editingIntegrationId = it.id;
            document.getElementById('integrationProvider').value = it.provider || 'google_nlp';
            document.getElementById('integrationKey').value = it.api_key || '';
            const configValue = it.config ? (typeof it.config === 'string' ? it.config : JSON.stringify(it.config, null, 2)) : '';
            document.getElementById('integrationConfig').value = configValue;
            document.getElementById('integrationActive').checked = !!it.is_active;
            applyIntegrationHintsToForm(it.provider_hints || null);
            document.getElementById('integrationModal')?.classList.remove('hidden');
        } catch (e) {
            console.error('editIntegration error', e);
            alert('โหลดข้อมูล Integration ไม่สำเร็จ');
        }
    }

    async function saveIntegration() {
        if (!selectedCustomerId) {
            alert('กรุณาเลือกลูกค้าก่อน');
            return;
        }
        const provider = document.getElementById('integrationProvider').value;
        const apiKey = document.getElementById('integrationKey').value.trim();
        const configRaw = document.getElementById('integrationConfig').value.trim();
        const isActive = document.getElementById('integrationActive').checked ? 1 : 0;
        let configJson = null;
        if (configRaw) {
            try {
                configJson = JSON.parse(configRaw);
            } catch (e) {
                alert('Config JSON ไม่ถูกต้อง');
                return;
            }
        }
        const payload = {
            user_id: selectedCustomerId,
            provider,
            api_key: apiKey || null,
            config: configJson,
            is_active: isActive
        };
        try {
            let url = '/api/admin/customer-integrations.php';
            let method = 'POST';
            if (editingIntegrationId) {
                url += `?id=${editingIntegrationId}`;
                method = 'PUT';
            }
            const res = await apiCall(url, {
                method,
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
            if (!res.success) {
                alert(res.message || 'บันทึก Integration ไม่สำเร็จ');
                return;
            }
            closeIntegrationModal();
            loadCustomerIntegrations(selectedCustomerId);
        } catch (e) {
            console.error('saveIntegration error', e);
            alert('เกิดข้อผิดพลาดในการบันทึก Integration');
        }
    }

    // ===== Preset shortcut buttons for config JSON =====
    function bindIntegrationPresets() {
        const cfgTextarea = document.getElementById('integrationConfig');
        const providerSelect = document.getElementById('integrationProvider');
        const btnLlM = document.getElementById('integrationPresetLlM');
        const btnVision = document.getElementById('integrationPresetVision');
        const btnNlp = document.getElementById('integrationPresetNlp');
        const fillBtn = document.getElementById('integrationFillExampleBtn');

        if (providerSelect) {
            providerSelect.addEventListener('change', onIntegrationProviderChange);
        }

        function applyPreset(providerKey) {
            const def = defaultProviderHints[providerKey];
            if (!cfgTextarea || !def) return;
            if (cfgTextarea.value && !confirm('ต้องการเขียนทับ Config เดิมด้วย preset นี้หรือไม่?')) return;
            cfgTextarea.value = prettyJson(def.config_placeholder || '{}');
            if (providerSelect) providerSelect.value = providerKey;
            applyIntegrationHintsToForm(def);
        }

        if (btnLlM) {
            btnLlM.onclick = function () { applyPreset('llm'); };
        }
        if (btnVision) {
            btnVision.onclick = function () { applyPreset('google_vision'); };
        }
        if (btnNlp) {
            btnNlp.onclick = function () { applyPreset('google_nlp'); };
        }
    }

    // ===== Channels API helpers =====
    async function loadCustomerChannels(userId) {
        const tbody = document.getElementById('channelsTable');
        if (!tbody) return;
        tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;">กำลังโหลด...</td></tr>';
        try {
            const res = await apiCall(`/api/admin/customer-channels.php?user_id=${userId}`);
            if (!res.success || !Array.isArray(res.data.channels) || res.data.channels.length === 0) {
                tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;color:var(--color-gray);">ยังไม่มีข้อมูลช่องทาง</td></tr>';
                return;
            }
            tbody.innerHTML = res.data.channels.map(ch => {
                const statusBadge = ch.status === 'active'
                    ? '<span class="badge badge-success">เปิดใช้งาน</span>'
                    : ch.status === 'paused'
                        ? '<span class="badge badge-warning">พักการใช้งาน</span>'
                        : '<span class="badge badge-secondary">ปิดใช้งาน</span>';

                const refreshBtn = (ch.type === 'facebook')
                    ? `<button class="btn btn-sm btn-outline" title="Refresh Facebook Token" onclick="refreshFacebookToken(${ch.id})"><i class="fas fa-sync"></i></button>`
                    : '';

                return `
                    <tr>
                        <td>${ch.name}</td>
                        <td>${ch.type}</td>
                        <td>${ch.inbound_api_key}</td>
                        <td>${ch.bot_profile_name || '-'}</td>
                        <td>${statusBadge}</td>
                        <td>
                            ${refreshBtn}
                            <button class="btn btn-sm btn-outline" onclick="editChannel(${ch.id})"><i class="fas fa-edit"></i></button>
                            <button class="btn btn-sm btn-danger" onclick="deleteChannel(${ch.id})"><i class="fas fa-trash"></i></button>
                        </td>
                    </tr>
                `;
            }).join('');
        } catch (e) {
            console.error('loadCustomerChannels error', e);
            tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;color:red;">โหลดข้อมูลช่องทางไม่สำเร็จ</td></tr>';
        }
    }

    async function refreshFacebookToken(channelId) {
        if (!channelId) return;
        if (!confirm('ต้องการ Refresh Facebook Token สำหรับ Channel นี้ใช่หรือไม่?')) return;
        try {
            const res = await apiCall('/api/admin/refresh-facebook-tokens.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ channel_id: channelId })
            });

            if (!res || !res.success) {
                alert((res && (res.message || res.error)) ? (res.message || res.error) : 'Refresh token ไม่สำเร็จ');
                return;
            }

            const summary = res.data && res.data.summary ? res.data.summary : null;
            const results = res.data && Array.isArray(res.data.results) ? res.data.results : [];
            const first = results[0] || null;
            const msg = first && first.message ? first.message : 'ดำเนินการเรียบร้อย';

            alert(`✅ ${msg}` + (summary ? `\n(refreshed=${summary.refreshed}, skipped=${summary.skipped}, failed=${summary.failed})` : ''));

            // reload channels to reflect any expiry tracking fields if shown later
            if (selectedCustomerId) loadCustomerChannels(selectedCustomerId);
        } catch (e) {
            console.error('refreshFacebookToken error', e);
            alert('เกิดข้อผิดพลาดในการเรียก refresh token');
        }
    }

    // ...existing JS for customers, channels, bot profiles, assign plan...

    window.addEventListener('DOMContentLoaded', function() {
        const btn = document.getElementById('assignPlanSaveBtn');
        if (btn) {
            btn.addEventListener('click', saveAssignedPlan);
        }
        const channelSaveBtn = document.querySelector('#channelForm button.btn.btn-primary');
        if (channelSaveBtn) channelSaveBtn.onclick = saveChannel;
        const integrationSaveBtn = document.querySelector('#integrationForm button.btn.btn-primary');
        if (integrationSaveBtn) integrationSaveBtn.onclick = saveIntegration;
        const botProfileSaveBtn = document.querySelector('#botProfileForm button.btn.btn-primary');
        if (botProfileSaveBtn) botProfileSaveBtn.onclick = saveBotProfile;

        loadCustomers();
        bindIntegrationPresets();
    });

    // ...rest of JS (bot profiles, assign plan, etc.) stays the same...
    </script>

<?php
$inline_script = <<<'JAVASCRIPT'
let isEditMode = false;
let assignPlanUserId = null;
let allPlansCache = [];
let selectedCustomerId = null;
let editingChannelId = null;
let editingIntegrationId = null;
let editingBotProfileId = null;

// Helper: render status badge from user status text
function renderStatusBadge(status) {
    if (!status) return '<span class="badge badge-secondary">ไม่ระบุ</span>';
    const normalized = status.toLowerCase();
    if (normalized === 'active') {
        return '<span class="badge badge-success">ใช้งานอยู่</span>';
    }
    if (normalized === 'trial') {
        return '<span class="badge badge-info">ทดลองใช้งาน</span>';
    }
    if (normalized === 'cancelled' || normalized === 'canceled') {
        return '<span class="badge badge-danger">ยกเลิกแล้ว</span>';
    }
    return `<span class="badge badge-secondary">${status}</span>`;
}

// Load customers list

async function loadCustomers() {
    try {
        const result = await apiCall(API_ENDPOINTS.ADMIN_CUSTOMERS);

        const tbody = document.getElementById('customersTable');
        if (result.success && result.data.customers) {
            if (result.data.customers.length === 0) {
                tbody.innerHTML = '<tr><td colspan="8" style="text-align:center;">ยังไม่มีข้อมูลลูกค้า</td></tr>';
            } else {
                tbody.innerHTML = result.data.customers.map(c => {
                    const planLabel = c.plan_name ? c.plan_name : 'ไม่มี';
                    const statusBadge = renderStatusBadge(c.status || 'active');
                    const createdAt = c.created_at ? new Date(c.created_at).toLocaleDateString('th-TH') : '-';
                    const safeEmail = (c.email || '').replace(/'/g, "&#39;");
                    const safeName = (c.full_name || '').replace(/'/g, "&#39;");
                    return `
                        <tr onclick="showCustomerDetailRow(event, ${c.id}, '${safeEmail}', '${safeName}', '${planLabel}')">
                            <td>${c.email}</td>
                            <td>${c.full_name || '-'}</td>
                            <td>${c.phone || '-'}</td>
                            <td>${c.company_name || '-'}</td>
                            <td><span class="badge badge-primary">${planLabel}</span></td>
                            <td>${statusBadge}</td>
                            <td>${createdAt}</td>
                            <td>
                                <div style="display:flex; gap:0.25rem;" onclick="event.stopPropagation();">
                                    <button class="btn btn-sm btn-outline" onclick="editCustomer(${c.id})" title="แก้ไขลูกค้า">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <button class="btn btn-sm btn-secondary" onclick="openAssignPlanModal(${c.id}, '${safeEmail}')" title="กำหนดแพ็กเกจ">
                                        <i class="fas fa-box"></i>
                                    </button>
                                    <button class="btn btn-sm btn-success" onclick="openExtendSubscriptionModal(${c.id}, '${safeEmail}')" title="เพิ่มวันใช้งาน">
                                        <i class="fas fa-calendar-plus"></i>
                                    </button>
                                    <button class="btn btn-sm btn-danger" onclick="deleteCustomer(${c.id})" title="ลบลูกค้า">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    `;
                }).join('');
            }
        } else {
            tbody.innerHTML = '<tr><td colspan="8" style="text-align:center;">ไม่พบข้อมูลลูกค้า</td></tr>';
        }
    } catch (error) {
        console.error('Error loading customers:', error);
        document.getElementById('customersTable').innerHTML = '<tr><td colspan="8" style="text-align:center;">เกิดข้อผิดพลาดในการโหลดข้อมูลลูกค้า</td></tr>';
    }
}

function showCustomerDetailRow(event, id, email, fullName, planName) {
    selectedCustomerId = id;
    const panel = document.getElementById('customerDetailPanel');
    const summary = document.getElementById('customerDetailSummary');
    if (summary) {
        summary.textContent = `${email} | ${fullName || ''} | แพ็กเกจ: ${planName || 'ไม่มี'}`;
    }
    if (panel) panel.classList.remove('hidden');
    switchCustomerTab('profile');
    loadCustomerProfileSummary(id);
    loadCustomerBotProfiles(id);
    loadCustomerChannels(id);
    loadCustomerIntegrations(id);
}

function hideCustomerDetailPanel() {
  const panel = document.getElementById('customerDetailPanel');
  if (panel) panel.classList.add('hidden');
  selectedCustomerId = null;
}

function switchCustomerTab(tab) {
  document.querySelectorAll('#customerDetailPanel .tab-button').forEach(btn => {
    btn.classList.toggle('active', btn.dataset.tab === tab);
  });
  document.querySelectorAll('#customerDetailPanel .tab-content').forEach(el => {
    el.classList.toggle('hidden', el.id !== `tab-${tab}`);
    if (el.id === `tab-${tab}`) el.classList.add('active'); else el.classList.remove('active');
  });
}

// ---- Channels API helpers ----
async function loadCustomerChannels(userId) {
    const tbody = document.getElementById('channelsTable');
    if (!tbody) return;
    tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;">กำลังโหลด...</td></tr>';
    try {
        const res = await apiCall(`/api/admin/customer-channels.php?user_id=${userId}`);
        if (!res.success || !Array.isArray(res.data.channels) || res.data.channels.length === 0) {
            tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;color:var(--color-gray);">ยังไม่มีข้อมูลช่องทาง</td></tr>';
            return;
        }
        tbody.innerHTML = res.data.channels.map(ch => {
            const statusBadge = ch.status === 'active'
                ? '<span class="badge badge-success">เปิดใช้งาน</span>'
                : ch.status === 'paused'
                    ? '<span class="badge badge-warning">พักการใช้งาน</span>'
                    : '<span class="badge badge-secondary">ปิดใช้งาน</span>';

            const refreshBtn = (ch.type === 'facebook')
                ? `<button class="btn btn-sm btn-outline" title="Refresh Facebook Token" onclick="refreshFacebookToken(${ch.id})"><i class="fas fa-sync"></i></button>`
                : '';

            return `
                <tr>
                    <td>${ch.name}</td>
                    <td>${ch.type}</td>
                    <td>${ch.inbound_api_key}</td>
                    <td>${ch.bot_profile_name || '-'}</td>
                    <td>${statusBadge}</td>
                    <td>
                        ${refreshBtn}
                        <button class="btn btn-sm btn-outline" onclick="editChannel(${ch.id})"><i class="fas fa-edit"></i></button>
                        <button class="btn btn-sm btn-danger" onclick="deleteChannel(${ch.id})"><i class="fas fa-trash"></i></button>
                    </td>
                </tr>
            `;
        }).join('');
    } catch (e) {
        console.error('loadCustomerChannels error', e);
        tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;color:red;">โหลดข้อมูลช่องทางไม่สำเร็จ</td></tr>';
    }
}

async function refreshFacebookToken(channelId) {
    if (!channelId) return;
    if (!confirm('ต้องการ Refresh Facebook Token สำหรับ Channel นี้ใช่หรือไม่?')) return;
    try {
        const res = await apiCall('/api/admin/refresh-facebook-tokens.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ channel_id: channelId })
        });

        if (!res || !res.success) {
            alert((res && (res.message || res.error)) ? (res.message || res.error) : 'Refresh token ไม่สำเร็จ');
            return;
        }

        const summary = res.data && res.data.summary ? res.data.summary : null;
        const results = res.data && Array.isArray(res.data.results) ? res.data.results : [];
        const first = results[0] || null;
        const msg = first && first.message ? first.message : 'ดำเนินการเรียบร้อย';

        alert(`✅ ${msg}` + (summary ? `\n(refreshed=${summary.refreshed}, skipped=${summary.skipped}, failed=${summary.failed})` : ''));

        // reload channels to reflect any expiry tracking fields if shown later
        if (selectedCustomerId) loadCustomerChannels(selectedCustomerId);
    } catch (e) {
        console.error('refreshFacebookToken error', e);
        alert('เกิดข้อผิดพลาดในการเรียก refresh token');
    }
}

function openChannelModal() {
    if (!selectedCustomerId) {
        alert('กรุณาเลือกลูกค้าก่อน');
        return;
    }
    editingChannelId = null;
    document.getElementById('channelForm').reset();
    document.getElementById('channelApiKey').readOnly = false;
    generateChannelKey();
    
    // Reset platform fields
    document.getElementById('fbPageAccessToken').value = '';
    document.getElementById('fbAppSecret').value = '';
    document.getElementById('fbVerifyToken').value = 'autobot_verify_2024';
    document.getElementById('fbPageId').value = '';
    document.getElementById('lineChannelSecret').value = '';
    document.getElementById('lineChannelAccessToken').value = '';
    
    // Show appropriate fields based on default type
    toggleChannelFields();
    
    document.getElementById('channelModal')?.classList.remove('hidden');
}

function closeChannelModal() { document.getElementById('channelModal')?.classList.add('hidden'); }

async function editChannel(id) {
    try {
        const res = await apiCall(`/api/admin/customer-channels.php?id=${id}`);
        if (!res.success || !res.data.channel) {
            alert('ไม่พบข้อมูล Channel');
            return;
        }
        const ch = res.data.channel;
        editingChannelId = ch.id;
        document.getElementById('channelName').value = ch.name || '';
        document.getElementById('channelType').value = ch.type || 'webhook';
        document.getElementById('channelApiKey').value = ch.inbound_api_key || '';
        document.getElementById('channelApiKey').readOnly = false;
        document.getElementById('channelBotProfile').value = ch.bot_profile_id || '';
        document.getElementById('channelActive').checked = ch.status === 'active';
        
        // Load platform-specific config
        let config = {};
        try {
            config = ch.config ? (typeof ch.config === 'string' ? JSON.parse(ch.config) : ch.config) : {};
        } catch (e) {
            console.error('Failed to parse channel config', e);
        }
        
        if (ch.type === 'facebook') {
            document.getElementById('fbPageAccessToken').value = config.page_access_token || '';
            document.getElementById('fbAppSecret').value = config.app_secret || '';
            document.getElementById('fbVerifyToken').value = config.verify_token || 'autobot_verify_2024';
            document.getElementById('fbPageId').value = config.page_id || '';
        } else if (ch.type === 'line') {
            document.getElementById('lineChannelSecret').value = config.channel_secret || '';
            document.getElementById('lineChannelAccessToken').value = config.channel_access_token || '';
        }
        
        // Toggle fields based on type
        toggleChannelFields();
        
        document.getElementById('channelModal')?.classList.remove('hidden');
    } catch (e) {
        console.error('editChannel error', e);
        alert('โหลดข้อมูล Channel ไม่สำเร็จ');
    }
}

async function deleteChannel(id) {
    if (!confirm('ต้องการลบ Channel นี้ใช่หรือไม่?')) return;
    try {
        const res = await apiCall(`/api/admin/customer-channels.php?id=${id}`, { method: 'DELETE' });
        if (!res.success) {
            alert(res.message || 'ลบ Channel ไม่สำเร็จ');
            return;
        }
        loadCustomerChannels(selectedCustomerId);
    } catch (e) {
        console.error('deleteChannel error', e);
        alert('เกิดข้อผิดพลาดในการลบ Channel');
    }
}

function generateChannelKey() {
  const input = document.getElementById('channelApiKey');
  if (!input) return;
  const rand = 'ch_' + Math.random().toString(36).slice(2, 10) + Date.now().toString(36);
  input.value = rand;
}

// Toggle channel-specific fields based on type
function toggleChannelFields() {
    const type = document.getElementById('channelType').value;
    const fbFields = document.getElementById('facebookFields');
    const lineFields = document.getElementById('lineFields');
    const webhookDisplay = document.getElementById('webhookUrlDisplay');
    
    // Hide all platform fields
    if (fbFields) fbFields.style.display = 'none';
    if (lineFields) lineFields.style.display = 'none';
    if (webhookDisplay) webhookDisplay.style.display = 'none';
    
    // Show relevant fields and webhook URL
    if (type === 'facebook') {
        if (fbFields) fbFields.style.display = 'block';
        if (webhookDisplay) {
            webhookDisplay.style.display = 'block';
            updateWebhookUrl('facebook');
        }
    } else if (type === 'line') {
        if (lineFields) lineFields.style.display = 'block';
        if (webhookDisplay) {
            webhookDisplay.style.display = 'block';
            updateWebhookUrl('line');
        }
    }
}

function updateWebhookUrl(platform) {
    const webhookInput = document.getElementById('webhookUrl');
    if (!webhookInput) return;

    // Use PATH helper if available (single source of truth). Fallback to origin.
    const baseUrl = (typeof PATH !== 'undefined' && PATH.base)
        ? (window.location.origin + PATH.base())
        : window.location.origin;

    const urls = {
        'facebook': `${baseUrl}/api/webhooks/facebook.php`,
        'line': `${baseUrl}/api/webhooks/line.php`
    };

    webhookInput.value = urls[platform] || '';
}

function copyWebhookUrl() {
    const webhookInput = document.getElementById('webhookUrl');
    if (!webhookInput) return;
    
    webhookInput.select();
    document.execCommand('copy');
    alert('Copied webhook URL to clipboard!');
}

async function saveChannel() {
    if (!selectedCustomerId) {
        alert('กรุณาเลือกลูกค้าก่อน');
        return;
    }
    
    const type = document.getElementById('channelType').value;
    const payload = {
        user_id: selectedCustomerId,
        name: document.getElementById('channelName').value.trim(),
        type: type,
        inbound_api_key: document.getElementById('channelApiKey').value.trim(),
        bot_profile_id: document.getElementById('channelBotProfile').value || null,
        status: document.getElementById('channelActive').checked ? 'active' : 'disabled',
        config: {}
    };
    
    // Collect platform-specific config
    if (type === 'facebook') {
        const pageAccessToken = document.getElementById('fbPageAccessToken')?.value.trim();
        const appSecret = document.getElementById('fbAppSecret')?.value.trim();
        
        if (!pageAccessToken || !appSecret) {
            alert('กรุณากรอก Page Access Token และ App Secret สำหรับ Facebook');
            return;
        }
        
        payload.config = {
            page_access_token: pageAccessToken,
            app_secret: appSecret,
            verify_token: document.getElementById('fbVerifyToken')?.value.trim() || 'autobot_verify_2024',
            page_id: document.getElementById('fbPageId')?.value.trim() || ''
        };
    } else if (type === 'line') {
        const channelSecret = document.getElementById('lineChannelSecret')?.value.trim();
        const channelAccessToken = document.getElementById('lineChannelAccessToken')?.value.trim();
        
        if (!channelSecret || !channelAccessToken) {
            alert('กรุณากรอก Channel Secret และ Channel Access Token สำหรับ LINE');
            return;
        }
        
        payload.config = {
            channel_secret: channelSecret,
            channel_access_token: channelAccessToken
        };
    }
    
    if (!payload.name || !payload.inbound_api_key) {
        alert('กรุณากรอกชื่อ Channel และ Inbound API Key');
        return;
    }
    
    try {
        let url = '/api/admin/customer-channels.php';
        let method = 'POST';
        if (editingChannelId) {
            url += `?id=${editingChannelId}`;
            method = 'PUT';
        }
        const res = await apiCall(url, {
            method,
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        if (!res.success) {
            alert(res.message || 'บันทึก Channel ไม่สำเร็จ');
            return;
        }
        closeChannelModal();
        loadCustomerChannels(selectedCustomerId);
        alert('บันทึก Channel สำเร็จ!');
    } catch (e) {
        console.error('saveChannel error', e);
        alert('เกิดข้อผิดพลาดในการบันทึก Channel');
    }
}

// ---- Integrations API helpers ----
async function loadCustomerIntegrations(userId) {
    const tbody = document.getElementById('integrationsTable');
    if (!tbody) return;
    tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;">กำลังโหลด...</td></tr>';
    try {
        const res = await apiCall(`/api/admin/customer-integrations.php?user_id=${userId}`);
        if (!res.success || !Array.isArray(res.data.integrations) || res.data.integrations.length === 0) {
            tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;color:var(--color-gray);">ยังไม่มี Integration</td></tr>';
            return;
        }
        tbody.innerHTML = res.data.integrations.map(it => {
            const statusBadge = it.is_active ? '<span class="badge badge-success">เปิดใช้งาน</span>' : '<span class="badge badge-secondary">ปิดใช้งาน</span>';
            const configPreview = it.config ? (it.config.length > 60 ? it.config.substring(0, 57) + '...' : it.config) : '-';
            return `
                <tr>
                    <td>${it.provider}</td>
                    <td>${it.api_key ? '***' : '-'}</td>
                    <td><code>${configPreview}</code></td>
                    <td>${statusBadge}</td>
                    <td>
                        <button class="btn btn-sm btn-outline" onclick="editIntegration(${it.id})"><i class="fas fa-edit"></i></button>
                        <button class="btn btn-sm btn-danger" onclick="deleteIntegration(${it.id})"><i class="fas fa-trash"></i></button>
                    </td>
                </tr>
            `;
        }).join('');
    } catch (e) {
        console.error('loadCustomerIntegrations error', e);
        tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;color:red;">โหลดข้อมูล Integration ไม่สำเร็จ</td></tr>';
    }
}

function openIntegrationModal() {
    if (!selectedCustomerId) {
        alert('กรุณาเลือกลูกค้าก่อน');
        return;
    }
    editingIntegrationId = null;
    document.getElementById('integrationForm').reset();
    applyIntegrationHintsToForm(null);
    document.getElementById('integrationModal')?.classList.remove('hidden');
}

function closeIntegrationModal() { document.getElementById('integrationModal')?.classList.add('hidden'); }

async function editIntegration(id) {
    try {
        const res = await apiCall(`/api/admin/customer-integrations.php?id=${id}`);
        if (!res.success || !res.data.integration) {
            alert('ไม่พบข้อมูล Integration');
            return;
        }
        const it = res.data.integration;
        editingIntegrationId = it.id;
        document.getElementById('integrationProvider').value = it.provider || 'google_nlp';
        document.getElementById('integrationKey').value = it.api_key || '';
        const configValue = it.config ? (typeof it.config === 'string' ? it.config : JSON.stringify(it.config, null, 2)) : '';
        document.getElementById('integrationConfig').value = configValue;
        document.getElementById('integrationActive').checked = !!it.is_active;
        // Bind provider_hints from backend so admin knows what to fill
        applyIntegrationHintsToForm(it.provider_hints || null);
        document.getElementById('integrationModal')?.classList.remove('hidden');
    } catch (e) {
        console.error('editIntegration error', e);
        alert('โหลดข้อมูล Integration ไม่สำเร็จ');
    }
}

async function deleteIntegration(id) {
    if (!confirm('ต้องการลบ Integration นี้ใช่หรือไม่?')) return;
    try {
        const res = await apiCall(`/api/admin/customer-integrations.php?id=${id}`, { method: 'DELETE' });
        if (!res.success) {
            alert(res.message || 'ลบ Integration ไม่สำเร็จ');
            return;
        }
        loadCustomerIntegrations(selectedCustomerId);
    } catch (e) {
        console.error('deleteIntegration error', e);
        alert('เกิดข้อผิดพลาดในการลบ Integration');
    }
}

async function saveIntegration() {
    if (!selectedCustomerId) {
        alert('กรุณาเลือกลูกค้าก่อน');
        return;
    }
    const provider = document.getElementById('integrationProvider').value;
    const apiKey = document.getElementById('integrationKey').value.trim();
    const configRaw = document.getElementById('integrationConfig').value.trim();
    const isActive = document.getElementById('integrationActive').checked ? 1 : 0;
    let configJson = null;
    if (configRaw) {
        try {
            configJson = JSON.parse(configRaw);
        } catch (e) {
            alert('Config JSON ไม่ถูกต้อง');
            return;
        }
    }
    const payload = {
        user_id: selectedCustomerId,
        provider,
        api_key: apiKey || null,
        config: configJson,
        is_active: isActive
    };
    try {
        let url = '/api/admin/customer-integrations.php';
        let method = 'POST';
        if (editingIntegrationId) {
            url += `?id=${editingIntegrationId}`;
            method = 'PUT';
        }
        const res = await apiCall(url, {
            method,
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        if (!res.success) {
            alert(res.message || 'บันทึก Integration ไม่สำเร็จ');
            return;
        }
        closeIntegrationModal();
        loadCustomerIntegrations(selectedCustomerId);
    } catch (e) {
        console.error('saveIntegration error', e);
        alert('เกิดข้อผิดพลาดในการบันทึก Integration');
    }
}

// ---- Bot Profiles API helpers ----
async function loadCustomerBotProfiles(userId) {
    const tbody = document.getElementById('botProfilesTable');
    const select = document.getElementById('channelBotProfile');
    if (tbody) {
        tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;">กำลังโหลด...</td></tr>';
    }
    try {
        const res = await apiCall(`/api/admin/customer-bot-profiles.php?user_id=${userId}`);
        const profiles = (res.success && Array.isArray(res.data.profiles)) ? res.data.profiles : [];
        if (tbody) {
            if (!profiles.length) {
                tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;color:var(--color-gray);">ยังไม่มี Bot Profile</td></tr>';
            } else {
                tbody.innerHTML = profiles.map(p => {
                    const isDefault = p.is_default ? '<span class="badge badge-primary">ค่าเริ่มต้น</span>' : '';
                    return `
                        <tr>
                            <td>${p.name}</td>
                            <td>${p.handler_key}</td>
                            <td>${p.channel_count || 0}</td>
                            <td>${isDefault}</td>
                            <td>
                                <button class="btn btn-sm btn-outline" onclick="editBotProfile(${p.id})"><i class="fas fa-edit"></i></button>
                                <button class="btn btn-sm btn-danger" onclick="deleteBotProfile(${p.id})"><i class="fas fa-trash"></i></button>
                            </td>
                        </tr>
                    `;
                }).join('');
            }
        }
        if (select) {
            select.innerHTML = '<option value="">(ไม่ระบุ / ใช้ค่าเริ่มต้น)</option>';
            profiles.forEach(p => {
                const opt = document.createElement('option');
                opt.value = p.id;
                opt.textContent = p.name + (p.is_default ? ' (default)' : '');
                select.appendChild(opt);
            });
        }
    } catch (e) {
        console.error('loadCustomerBotProfiles error', e);
        if (tbody) {
            tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;color:red;">โหลดข้อมูล Bot Profiles ไม่สำเร็จ</td></tr>';
        }
    }
}

function openBotProfileModal() {
    if (!selectedCustomerId) {
        alert('กรุณาเลือกลูกค้าก่อน');
        return;
    }
    editingBotProfileId = null;
    document.getElementById('botProfileForm').reset();
    document.getElementById('botProfileDefault').checked = false;
    document.getElementById('botProfileModal')?.classList.remove('hidden');
}

function closeBotProfileModal() { document.getElementById('botProfileModal')?.classList.add('hidden'); }

async function editBotProfile(id) {
    try {
        const res = await apiCall(`/api/admin/customer-bot-profiles.php?id=${id}`);
        if (!res.success || !res.data.profile) {
            alert('ไม่พบ Bot Profile');
            return;
        }
        const p = res.data.profile;
        editingBotProfileId = p.id;
        document.getElementById('botProfileName').value = p.name || '';
        document.getElementById('botProfileHandler').value = p.handler_key || '';
        // Fix: Convert JSON object to formatted string for textarea
        const configValue = p.config ? (typeof p.config === 'string' ? p.config : JSON.stringify(p.config, null, 2)) : '';
        document.getElementById('botProfileConfig').value = configValue;
        document.getElementById('botProfileDefault').checked = !!p.is_default;
        document.getElementById('botProfileModal')?.classList.remove('hidden');
    } catch (e) {
        console.error('editBotProfile error', e);
        alert('โหลดข้อมูล Bot Profile ไม่สำเร็จ');
    }
}

async function deleteBotProfile(id) {
    if (!confirm('ต้องการลบ Bot Profile นี้ใช่หรือไม่?')) return;
    try {
        const res = await apiCall(`/api/admin/customer-bot-profiles.php?id=${id}`, { method: 'DELETE' });
        if (!res.success) {
            alert(res.message || 'ลบ Bot Profile ไม่สำเร็จ');
            return;
        }
        loadCustomerBotProfiles(selectedCustomerId);
        loadCustomerChannels(selectedCustomerId);
    } catch (e) {
        console.error('deleteBotProfile error', e);
        alert('เกิดข้อผิดพลาดในการลบ Bot Profile');
    }
}

async function saveBotProfile() {
    if (!selectedCustomerId) {
        alert('กรุณาเลือกลูกค้าก่อน');
        return;
    }
    const name = document.getElementById('botProfileName').value.trim();
    const handlerKey = document.getElementById('botProfileHandler').value.trim();
    const configRaw = document.getElementById('botProfileConfig').value.trim();
    const isDefault = document.getElementById('botProfileDefault').checked ? 1 : 0;
    if (!name || !handlerKey) {
        alert('กรุณากรอกชื่อโปรไฟล์และ Handler Key');
        return;
    }
    let configJson = null;
    if (configRaw) {
        try {
            configJson = JSON.parse(configRaw);
        } catch (e) {
            alert('Config JSON ไม่ถูกต้อง');
            return;
        }
    }
    const payload = {
        user_id: selectedCustomerId,
        name,
        handler_key: handlerKey,
        config: configJson,
        is_default: isDefault
    };
    try {
        let url = '/api/admin/customer-bot-profiles.php';
        let method = 'POST';
        if (editingBotProfileId) {
            url += `?id=${editingBotProfileId}`;
            method = 'PUT';
        }
        const res = await apiCall(url, {
            method,
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        if (!res.success) {
            alert(res.message || 'บันทึก Bot Profile ไม่สำเร็จ');
            return;
        }
        closeBotProfileModal();
        loadCustomerBotProfiles(selectedCustomerId);
    } catch (e) {
        console.error('saveBotProfile error', e);
        alert('เกิดข้อผิดพลาดในการบันทึก Bot Profile');
    }
}

// Assign plan modal logic
function hideAssignPlanModal() {
    document.getElementById('assignPlanModal').classList.add('hidden');
    assignPlanUserId = null;
}

// Extend subscription modal logic
let extendSubUserId = null;

function hideExtendSubscriptionModal() {
    document.getElementById('extendSubscriptionModal').classList.add('hidden');
    extendSubUserId = null;
}

function openExtendSubscriptionModal(userId, email) {
    extendSubUserId = userId;
    document.getElementById('extendSubCustomerInfo').textContent = email;
    document.getElementById('extendSubDays').value = 30;
    document.getElementById('extendSubError').style.display = 'none';
    document.getElementById('extendSubSuccess').style.display = 'none';
    document.getElementById('extendSubscriptionModal').classList.remove('hidden');
}

async function saveExtendSubscription() {
    const errorBox = document.getElementById('extendSubError');
    const successBox = document.getElementById('extendSubSuccess');
    const btn = document.getElementById('extendSubSaveBtn');
    errorBox.style.display = 'none';
    successBox.style.display = 'none';

    const days = parseInt(document.getElementById('extendSubDays').value, 10);
    if (!extendSubUserId || !days || days < 1 || days > 3650) {
        errorBox.textContent = 'กรุณาระบุจำนวนวันที่ถูกต้อง (1-3650 วัน)';
        errorBox.style.display = 'block';
        return;
    }

    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> กำลังบันทึก...';

    try {
        const res = await apiCall(API_ENDPOINTS.ADMIN_SUBSCRIPTIONS_EXTEND, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                user_id: extendSubUserId,
                days: days
            })
        });

        if (!res.success) {
            errorBox.textContent = res.message || 'ไม่สามารถเพิ่มวันใช้งานได้';
            errorBox.style.display = 'block';
        } else {
            const newEnd = res.data?.new_end_date || '';
            successBox.textContent = `เพิ่มวันใช้งานสำเร็จ ${days} วัน` + (newEnd ? ` (หมดอายุ: ${newEnd})` : '');
            successBox.style.display = 'block';
            loadCustomers();
        }
    } catch (error) {
        console.error('Error extending subscription:', error);
        errorBox.textContent = 'เกิดข้อผิดพลาดในการเชื่อมต่อเซิร์ฟเวอร์';
        errorBox.style.display = 'block';
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-calendar-plus"></i> เพิ่มวันใช้งาน';
    }
}

async function openAssignPlanModal(userId, email) {
    assignPlanUserId = userId;
    document.getElementById('assignPlanCustomerInfo').textContent = `${email}`;
    document.getElementById('assignPlanError').style.display = 'none';
    document.getElementById('assignPlanSuccess').style.display = 'none';

    try {
        if (!allPlansCache.length) {
            const res = await apiCall(API_ENDPOINTS.ADMIN_PACKAGES_LIST);
            if (res.success && Array.isArray(res.data)) {
                allPlansCache = res.data.filter(p => p.is_active == 1 || p.is_active === true);
            }
        }

        const select = document.getElementById('assignPlanSelect');
        select.innerHTML = '<option value="">-- เลือกแพ็กเกจ --</option>';
        allPlansCache.forEach(plan => {
            const price = Number(plan.monthly_price || 0).toLocaleString('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            const label = `${plan.name} - ฿${price}/เดือน`;
            const option = document.createElement('option');
            option.value = plan.id;
            option.textContent = label;
            select.appendChild(option);
        });

        document.getElementById('assignPlanModal').classList.remove('hidden');
    } catch (error) {
        console.error('Error loading plans:', error);
        alert('ไม่สามารถโหลดรายการแพ็กเกจได้');
    }
}

async function saveAssignedPlan() {
    const errorBox = document.getElementById('assignPlanError');
    const successBox = document.getElementById('assignPlanSuccess');
    const btn = document.getElementById('assignPlanSaveBtn');
    errorBox.style.display = 'none';
    successBox.style.display = 'none';

    const planId = document.getElementById('assignPlanSelect').value;
    if (!assignPlanUserId || !planId) {
        errorBox.textContent = 'กรุณาเลือกลูกค้าและแพ็กเกจ';
        errorBox.style.display = 'block';
        return;
    }

    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> กำลังบันทึก...';

    try {
        // Use configured API endpoint so PATH.apiCall can handle base path correctly
        const res = await apiCall(API_ENDPOINTS.ADMIN_SUBSCRIPTIONS_ASSIGN, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                user_id: assignPlanUserId,
                plan_id: parseInt(planId, 10)
            })
        });

        if (!res.success) {
            errorBox.textContent = res.message || 'ไม่สามารถกำหนดแพ็กเกจได้';
            errorBox.style.display = 'block';
        } else {
            const msg = res.unchanged ? 'ลูกค้ามีแพ็กเกจนี้ใช้งานอยู่แล้ว' : 'กำหนดแพ็กเกจให้ลูกค้าสำเร็จ';
            successBox.textContent = msg;
            successBox.style.display = 'block';
            loadCustomers();
        }
    } catch (error) {
        console.error('Error assigning plan:', error);
        errorBox.textContent = 'เกิดข้อผิดพลาดในการเชื่อมต่อเซิร์ฟเวอร์';
        errorBox.style.display = 'block';
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-save"></i> บันทึกแพ็กเกจ';
    }
}

// Close modals when clicking outside & initial load
window.addEventListener('click', function(event) {
    const customerModal = document.getElementById('customerModal');
    const assignModal = document.getElementById('assignPlanModal');
    if (event.target === customerModal) {
        hideCustomerModal();
    }
    if (event.target === assignModal) {
        hideAssignPlanModal();
    }
});

window.addEventListener('DOMContentLoaded', function() {
    const btn = document.getElementById('assignPlanSaveBtn');
    if (btn) {
        btn.addEventListener('click', saveAssignedPlan);
    }
    const channelSaveBtn = document.querySelector('#channelForm button.btn.btn-primary');
    if (channelSaveBtn) channelSaveBtn.onclick = saveChannel;
    const integrationSaveBtn = document.querySelector('#integrationForm button.btn.btn-primary');
    if (integrationSaveBtn) integrationSaveBtn.onclick = saveIntegration;
    const botProfileSaveBtn = document.querySelector('#botProfileForm button.btn.btn-primary');
    if (botProfileSaveBtn) botProfileSaveBtn.onclick = saveBotProfile;

    // Wait for core JS (auth.js, admin.js) to load before initializing
    function initPage() {
        loadCustomers();
        bindIntegrationPresets();
    }
    
    // Check if core JS is already loaded, otherwise wait for event
    if (typeof apiCall !== 'undefined') {
        initPage();
    } else {
        document.addEventListener('coreJSLoaded', initPage);
    }
});

async function loadCustomerProfileSummary(userId) {
    const container = document.getElementById('tab-profile');
    if (!container) return;
    container.innerHTML = '<p style="color:var(--color-gray);">กำลังโหลดข้อมูลโปรไฟล์ลูกค้า...</p>';
    try {
        const res = await apiCall(`api/admin/customers.php?id=${userId}`);
        if (!res.success || !res.data || !res.data.customer) {
            container.innerHTML = '<p style="color:red;">โหลดข้อมูลลูกค้าไม่สำเร็จ</p>';
            return;
        }
        const c = res.data.customer;
        const sub = res.data.subscription;
        const inv = res.data.invoicesSummary || {};

        const statusBadge = renderStatusBadge(c.status || 'active');
        const createdAt = c.created_at ? new Date(c.created_at).toLocaleString('th-TH') : '-';
        const lastLogin = c.last_login ? new Date(c.last_login).toLocaleString('th-TH') : '-';

        let subHtml = '<span style="color:var(--color-gray);">ยังไม่มีแพ็กเกจที่ใช้งานอยู่</span>';
        if (sub) {
            const periodStart = sub.current_period_start || '-';
            const periodEnd = sub.current_period_end || '-';
            const nextBill = sub.next_billing_date || '-';
            subHtml = `
                <div><strong>แพ็กเกจ:</strong> ${sub.plan_name || '-'} (${sub.status})</div>
                <div><strong>รอบบิล:</strong> ${periodStart} ถึง ${periodEnd}</div>
                <div><strong>ตัดรอบถัดไป:</strong> ${nextBill}</div>
            `;
        }

        const totalInvoices = inv.total_invoices || 0;
        const pending = inv.pending || 0;
        const paid = inv.paid || 0;
        const totalPaid = inv.total_paid || 0;

        container.innerHTML = `
            <div class="grid" style="grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap:1rem;">
                <div class="card" style="box-shadow:none;border:1px solid #eee;">
                    <div class="card-body">
                        <h4 style="margin-top:0;">โปรไฟล์ลูกค้า</h4>
                        <div><strong>Email:</strong> ${c.email}</div>
                        <div><strong>ชื่อ:</strong> ${c.full_name || '-'}</div>
                        <div><strong>เบอร์โทร:</strong> ${c.phone || '-'}</div>
                        <div><strong>บริษัท:</strong> ${c.company_name || '-'}</div>
                        <div><strong>สถานะ:</strong> ${statusBadge}</div>
                        <div><strong>สมัครเมื่อ:</strong> ${createdAt}</div>
                        <div><strong>เข้าใช้ล่าสุด:</strong> ${lastLogin}</div>
                    </div>
                </div>
                <div class="card" style="box-shadow:none;border:1px solid #eee;">
                    <div class="card-body">
                        <h4 style="margin-top:0;">แพ็กเกจ / การใช้งาน</h4>
                        ${subHtml}
                    </div>
                </div>
                <div class="card" style="box-shadow:none;border:1px solid #eee;">
                    <div class="card-body">
                        <h4 style="margin-top:0;">สรุปบิล</h4>
                        <div><strong>จำนวนบิลทั้งหมด:</strong> ${totalInvoices}</div>
                        <div><strong>ค้างชำระ:</strong> ${pending}</div>
                        <div><strong>ชำระแล้ว:</strong> ${paid}</div>
                        <div><strong>ยอดชำระรวม:</strong> ${totalPaid.toLocaleString('th-TH', {minimumFractionDigits:2, maximumFractionDigits:2})} บาท</div>
                    </div>
                </div>
            </div>
        `;
    } catch (e) {
        console.error('loadCustomerProfileSummary error', e);
        container.innerHTML = '<p style="color:red;">เกิดข้อผิดพลาดในการโหลดข้อมูลโปรไฟล์</p>';
    }
}

JAVASCRIPT;

include('../../includes/admin/footer.php');
?>
