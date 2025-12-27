<?php
/**
 * Admin Knowledge Base Management
 */
define('INCLUDE_CHECK', true);

$page_title = "จัดการ Knowledge Base - Admin Panel";
$current_page = "knowledge-base";

include('../../includes/admin/header.php');
include('../../includes/admin/sidebar.php');
?>

<main class="main-content">
        <div class="page-header">
            <h1 class="page-title">📚 จัดการ Knowledge Base</h1>
            <p style="color:var(--color-gray);">จัดการข้อมูล Q&A, สินค้า, บริการของลูกค้าแต่ละราย</p>
        </div>

        <!-- Customer Selector -->
        <div class="card">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-user"></i> เลือกลูกค้า</h3>
            </div>
            <div class="card-body">
                <div class="form-group">
                    <label class="form-label">ลูกค้า</label>
                    <select id="customerSelect" class="form-control" onchange="loadKnowledgeBase()">
                        <option value="">-- เลือกลูกค้า --</option>
                    </select>
                </div>
            </div>
        </div>

        <!-- Filters & Actions -->
        <div class="card" id="kbSection" style="display:none;">
            <div class="card-header card-header--between">
                <h3 class="card-title"><i class="fas fa-database"></i> Knowledge Base Entries</h3>
                <button class="btn btn-primary" onclick="openKBModal()">
                    <i class="fas fa-plus"></i> เพิ่มข้อมูล
                </button>
            </div>
            <div class="card-body">
                <!-- Filters -->
                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:1rem;margin-bottom:1.5rem;">
                    <div class="form-group" style="margin:0;">
                        <label class="form-label">หมวดหมู่</label>
                        <select id="categoryFilter" class="form-control" onchange="loadKnowledgeBase()">
                            <option value="">ทั้งหมด</option>
                            <option value="product">📦 สินค้า</option>
                            <option value="service">🔧 บริการ</option>
                            <option value="pricing">💰 ราคา</option>
                            <option value="faq">❓ FAQ</option>
                            <option value="general">📄 ทั่วไป</option>
                        </select>
                    </div>
                    <div class="form-group" style="margin:0;">
                        <label class="form-label">ค้นหา</label>
                        <input type="text" id="searchInput" class="form-control" placeholder="ค้นหาคำถาม/คำตอบ..." onkeyup="debounceSearch()">
                    </div>
                </div>

                <!-- Table -->
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th style="width:50px;">ID</th>
                                <th style="width:100px;">หมวดหมู่</th>
                                <th>คำถาม / คำตอบ</th>
                                <th style="width:200px;">Keywords</th>
                                <th style="width:80px;">Priority</th>
                                <th style="width:150px;">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="kbTableBody">
                            <tr>
                                <td colspan="6" style="text-align:center;padding:2rem;color:var(--color-gray);">
                                    เลือกลูกค้าเพื่อโหลดข้อมูล
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    <!-- KB Entry Modal -->
    <div id="kbModal" class="modal-backdrop hidden">
        <div class="modal-content" style="max-width:800px;">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title"><i class="fas fa-edit"></i> <span id="modalTitle">เพิ่มข้อมูล</span></h3>
                    <button class="modal-close-btn" onclick="closeKBModal()"><i class="fas fa-times"></i></button>
                </div>
                <div class="card-body">
                    <form id="kbForm">
                        <input type="hidden" id="kbEntryId">
                        
                        <!-- หมวดหมู่ -->
                        <div class="form-group">
                            <label class="form-label">
                                หมวดหมู่ <span style="color:red;">*</span>
                                <i class="fas fa-info-circle" title="เลือกประเภทข้อมูล" style="color:var(--color-gray);cursor:help;"></i>
                            </label>
                            <select id="kbCategory" class="form-control" required style="font-size:1.1rem;padding:0.75rem;">
                                <option value="product">📦 สินค้า - หนังสือ, เสื้อผ้า, อาหาร ฯลฯ</option>
                                <option value="service">🔧 บริการ - ผ่อน 0%, จัดส่งฟรี, รับประกัน</option>
                                <option value="pricing">💰 ราคา - โปรโมชั่น, ส่วนลด</option>
                                <option value="faq">❓ FAQ - เวลาทำการ, วิธีสั่งซื้อ, ที่อยู่</option>
                                <option value="general">📄 ทั่วไป - ข้อมูลอื่น ๆ</option>
                            </select>
                        </div>

                        <!-- ชื่อเรียก (สำหรับ admin) -->
                        <div class="form-group">
                            <label class="form-label">
                                ชื่อเรียก (สำหรับคุณดูเอง)
                                <i class="fas fa-info-circle" title="ตั้งชื่อให้จำง่าย ๆ ว่าข้อมูลนี้เกี่ยวกับอะไร" style="color:var(--color-gray);cursor:help;"></i>
                            </label>
                            <input type="text" id="kbQuestion" class="form-control" placeholder="เช่น: หนังสือ Atomic Habits">
                            <small style="color:var(--color-gray);">💡 ตัวอย่าง: "หนังสือ Atomic Habits", "บริการจัดส่งฟรี", "เวลาทำการ"</small>
                        </div>

                        <!-- คำตอบ -->
                        <div class="form-group">
                            <label class="form-label">
                                คำตอบที่บอทจะส่งให้ลูกค้า <span style="color:red;">*</span>
                                <i class="fas fa-info-circle" title="ข้อความที่ต้องการให้บอทตอบลูกค้า" style="color:var(--color-gray);cursor:help;"></i>
                            </label>
                            <textarea id="kbAnswer" class="form-control" rows="5" required 
                                placeholder="มีค่ะ 📚 Atomic Habits โดย James Clear&#10;ราคา 350 บาท&#10;พร้อมส่งทันทีค่ะ"
                                style="font-size:1rem;"></textarea>
                            <small style="color:var(--color-gray);">💡 เขียนเหมือนคุณจะตอบลูกค้าจริง ๆ ใส่อิโมจิได้ด้วยนะ 😊</small>
                        </div>

                        <!-- Keywords Matching Mode -->
                        <div class="form-group">
                            <label class="form-label">
                                Keywords Matching
                                <i class="fas fa-info-circle" title="เลือกรูปแบบการจับคู่คำค้นหา" style="color:var(--color-gray);cursor:help;"></i>
                            </label>
                            
                            <!-- Mode Toggle -->
                            <div style="display:flex;gap:1rem;margin-bottom:1rem;background:#f3f4f6;padding:0.75rem;border-radius:8px;">
                                <label style="display:flex;align-items:center;gap:0.5rem;cursor:pointer;font-weight:500;">
                                    <input type="radio" name="keywordMode" value="simple" checked onchange="toggleKeywordMode()">
<span>🔍 Simple (มีคำใดคำหนึ่งก็ตอบ)</span>
                                </label>
                                <label style="displayflex;align-items:center;gap:0.5rem;cursor:pointer;font-weight:500;">
                                    <input type="radio" name="keywordMode" value="advanced" onchange="toggleKeywordMode()">
                                    <span>✨ Advanced (กำหนดเงื่อนไขละเอียด)</span>
                                </label>
                            </div>

                            <!-- Simple Mode -->
                            <div id="simpleKeywordsSection" style="display:block;">
                                <div id="keywordsContainer" style="
                                    border:1px solid var(--color-border);
                                    border-radius:8px;
                                    padding:0.5rem;
                                    min-height:60px;
                                    display:flex;
                                    flex-wrap:wrap;
                                    gap:0.5rem;
                                    cursor:text;
                                    background:white;
                                " onclick="document.getElementById('keywordInput').focus()">
                                    <!-- Tags will be added here -->
                                    <input type="text" id="keywordInput" placeholder="พิมพ์คำค้นหา แล้วกด Enter" 
                                        style="border:none;outline:none;flex:1;min-width:150px;font-size:1rem;"
                                        onkeydown="handleKeywordInput(event)">
                                </div>
                                <small style="color:var(--color-gray);display:block;margin-top:0.5rem;">
                                    💡 <strong>ตัวอย่าง:</strong> "iPhone", "มีไหม", "ราคา"
                                    <br>📝 พิมพ์คำ แล้วกด <kbd>Enter</kbd> เพื่อเพิ่ม | คลิก X เพื่อลบ
                                </small>
                            </div>

                            <!-- Advanced Mode -->
                            <div id="advancedKeywordsSection" style="display:none;">
                                <!-- require_all -->
                                <div style="margin-bottom:1rem;padding:1rem;background:#f0fdf4;border:1px solid #86efac;border-radius:8px;">
                                    <label style="display:flex;align-items:center;gap:0.5rem;font-weight:500;margin-bottom:0.5rem;">
                                        <span style="font-size:1.2rem;">✅</span>
                                        <span>ต้องมีคำนี้ทุกคำ (require_all)</span>
                                    </label>
                                    <small style="color:#166534;display:block;margin-bottom:0.5rem;">
                                        ป้องกันไม่ให้ตอบผิด - เช่น ถ้าถามเรื่องร้าน ต้องมีคำว่า "ร้าน" ด้วย
                                    </small>
                                    <div id="requireAllContainer" class="tag-container"></div>
                                    <input type="text" id="requireAllInput" placeholder="พิมพ์แล้วกด Enter" class="tag-input" onkeydown="handleTagInput(event, 'requireAll')">
                                </div>

                                <!-- require_any -->
                                <div style="margin-bottom:1rem;padding:1rem;background:#eff6ff;border:1px solid#93c5fd;border-radius:8px;">
                                    <label style="display:flex;align-items:center;gap:0.5rem;font-weight:500;margin-bottom:0.5rem;">
                                        <span style="font-size:1.2rem;">🔍</span>
                                        <span>ต้องมีอย่างน้อย 1 คำ (require_any)</span>
                                    </label>
                                    <small style="color:#1e40af;display:block;margin-bottom:0.5rem;">
                                        คำที่ลูกค้าอาจใช้ถาม เช่น "ที่อยู่", "โลเคชั่น", "พิกัด", "แผนที่"
                                    </small>
                                    <div id="requireAnyContainer" class="tag-container"></div>
                                    <input type="text" id="requireAnyInput" placeholder="พิมพ์แล้วกด Enter" class="tag-input" onkeydown="handleTagInput(event, 'requireAny')">
                                </div>

                                <!-- exclude_any -->
                                <div style="margin-bottom:1rem;padding:1rem;background:#fef2f2;border:1px solid #fca5a5;border-radius:8px;">
                                    <label style="display:flex;align-items:center;gap:0.5rem;font-weight:500;margin-bottom:0.5rem;">
                                        <span style="font-size:1.2rem;">🚫</span>
                                        <span>ห้ามมีคำเหล่านี้ (exclude_any)</span>
                                    </label>
                                    <small style="color:#991b1b;display:block;margin-bottom:0.5rem;">
                                        ถ้ามีคำนี้จะไม่ตอบ - ป้องกัน false positive เช่น "ที่อยู่ของฉัน", "บ้านผม"
                                    </small>
                                    <div id="excludeAnyContainer" class="tag-container"></div>
                                    <input type="text" id="excludeAnyInput" placeholder="พิมพ์แล้วกด Enter" class="tag-input" onkeydown="handleTagInput(event, 'excludeAny')">
                                </div>

                                <!-- min_query_len -->
                                <div style="margin-bottom:1rem;">
                                    <label style="display:flex;align-items:center;gap:0.5rem;">
                                        <input type="checkbox" id="enableMinQueryLen">
                                        <span>กำหนดความยาวข้อความขั้นต่ำ:</span>
                                        <input type="number" id="minQueryLen" min="1" max="100" value="6" style="width:80px;padding:0.25rem 0.5rem;border:1px solid var(--color-border);border-radius:4px;" disabled>
                                        <span style="color:var(--color-gray);font-size:0.9rem;">ตัวอักษร</span>
                                    </label>
                                </div>
                            </div>

                            <input type="hidden" id="kbKeywords" required>
                            
                            <!-- JSON Editor (Advanced Users) -->
                            <div style="margin-top:1rem;padding:1rem;background:#f9fafb;border:1px solid var(--color-border);border-radius:8px;">
                                <label style="display:flex;align-items:center;gap:0.5rem;margin-bottom:0.5rem;font-weight:500;">
                                    <span>🔧 JSON Editor (สำหรับผู้ใช้ขั้นสูง)</span>
                                    <button type="button" 
                                        class="btn btn-xs btn-outline" 
                                        onclick="copyJsonToUI()"
                                        style="margin-left:auto;"
                                        title="นำ JSON ไป sync กับ UI ด้านบน">
                                        ⬆️ Sync to UI
                                    </button>
                                </label>
                                <textarea id="keywordsJsonEditor" 
                                    class="form-control" 
                                    rows="6" 
                                    placeholder='{"mode": "advanced", "require_all": ["ร้าน"], "require_any": ["ที่อยู่", "โลเคชั่น"], "exclude_any": []}'
                                    style="font-family:monospace;font-size:0.9rem;background:white;"
                                    onchange="validateAndSyncJson()"></textarea>
                                <div id="jsonError" style="color:var(--color-danger);font-size:0.85rem;margin-top:0.5rem;display:none;"></div>
                                <small style="color:var(--color-gray);display:block;margin-top:0.5rem;">
                                    💡 แก้ไข JSON ตรงนี้ได้เลย แล้วกด "Sync to UI" เพื่ออัพเดท UI ด้านบน<br>
                                    📝 UI จะอัพเดท JSON อัตโนมัติเมื่อมีการเปลี่ยนแปลง
                                </small>
                            </div>
                            
                            <style>
                                .tag-container {
                                    display: flex;
                                    flex-wrap: wrap;
                                    gap: 0.5rem;
                                    min-height: 40px;
                                    padding: 0.5rem;
                                    background: white;
                                    border: 1px solid var(--color-border);
                                    border-radius: 6px;
                                    margin-bottom: 0.5rem;
                                }
                                .tag-input {
                                    width: 100%;
                                    padding: 0.5rem;
                                    border: 1px solid var(--color-border);
                                    border-radius: 6px;
                                    font-size: 0.95rem;
                                }
                                kbd {
                                    background: #e5e7eb;
                                    padding: 0.15rem 0.4rem;
                                    border-radius: 3px;
                                    font-size: 0.85rem;
                                    font-family: monospace;
                                }
                            </style>
                        </div>

                        <!-- Advanced Section (Collapsible) -->
                        <div style="border-top:1px dashed var(--color-border);padding-top:1rem;margin-top:1.5rem;">
                            <button type="button" class="btn btn-sm btn-outline" onclick="toggleAdvanced()" style="width:100%;">
                                <i class="fas fa-cog"></i> <span id="advancedToggleText">แสดงตัวเลือกขั้นสูง</span>
                            </button>
                            
                            <div id="advancedSection" style="display:none;margin-top:1rem;">
                                <!-- Priority -->
                                <div class="form-group">
                                    <label class="form-label">
                                        ความสำคัญ
                                        <i class="fas fa-info-circle" title="ตัวเลขยิ่งสูง ยิ่งแสดงก่อน" style="color:var(--color-gray);cursor:help;"></i>
                                    </label>
                                    <input type="number" id="kbPriority" class="form-control" value="100" min="0" max="999">
                                    <small style="color:var(--color-gray);">ค่าปกติคือ 100 | ค่าสูง แสดงก่อน (ไม่ต้องแก้ก็ได้)</small>
                                </div>

                                <!-- Metadata - Field Builder -->
                                <div class="form-group">
                                    <label class="form-label">
                                        ข้อมูลเพิ่มเติม (Metadata)
                                        <i class="fas fa-info-circle" title="เพิ่มข้อมูลเพิ่มเติม เช่น ราคา รหัสสินค้า" style="color:var(--color-gray);cursor:help;"></i>
                                    </label>
                                    
                                    <!-- Quick preset buttons -->
                                    <div style="margin-bottom:0.75rem;">
                                        <small style="color:var(--color-gray);display:block;margin-bottom:0.5rem;">เพิ่มฟิลด์เร็ว:</small>
                                        <button type="button" class="btn btn-xs btn-outline" onclick="addMetadataField('price', 'number')" style="margin-right:0.5rem;">
                                            💰 ราคา
                                        </button>
                                        <button type="button" class="btn btn-xs btn-outline" onclick="addMetadataField('product_id', 'text')" style="margin-right:0.5rem;">
                                            🏷️ รหัสสินค้า
                                        </button>
                                        <button type="button" class="btn btn-xs btn-outline" onclick="addMetadataField('in_stock', 'checkbox')" style="margin-right:0.5rem;">
                                            📦 มี Stock
                                        </button>
                                        <button type="button" class="btn btn-xs btn-outline" onclick="addMetadataField('', 'text')">
                                            ➕ ฟิลด์อื่น
                                        </button>
                                    </div>
                                    
                                    <!-- Metadata fields container -->
                                    <div id="metadataFieldsContainer" style="
                                        border:1px solid var(--color-border);
                                        border-radius:8px;
                                        padding:0.75rem;
                                        min-height:60px;
                                        background:#f9fafb;
                                    ">
                                        <div id="metadataFieldsList"></div>
                                        <div style="text-align:center;color:var(--color-gray);font-size:0.9rem;" id="metadataEmptyState">
                                            คลิกปุ่มด้านบนเพื่อเพิ่มข้อมูล
                                        </div>
                                    </div>
                                    
                                    <input type="hidden" id="kbMetadata">
                                </div>
                            </div>
                        </div>

                        <!-- Active -->
                        <div class="form-group" style="margin-top:1rem;">
                            <label style="display:flex;align-items:center;gap:0.5rem;font-size:1.1rem;">
                                <input type="checkbox" id="kbActive" checked>
                                <span>✅ เปิดใช้งานทันที</span>
                            </label>
                        </div>

                        <div style="display:flex;gap:1rem;margin-top:1.5rem;">
                            <button type="submit" class="btn btn-primary" style="flex:1;">
                                <i class="fas fa-save"></i> บันทึก
                            </button>
                            <button type="button" class="btn btn-outline" style="flex:1;" onclick="closeKBModal()">
                                ยกเลิก
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Admin API call helper with token
        async function adminApiCall(endpoint, options = {}) {
            const token = localStorage.getItem('admin_token');
            
            const headers = {
                'Content-Type': 'application/json',
                ...(options.headers || {})
            };
            
            if (token) {
                headers['Authorization'] = `Bearer ${token}`;
            }
            
            const fetchOptions = {
                ...options,
                headers
            };
            
            // Use PATH.api() for proper environment detection
            const url = endpoint.startsWith('http') ? endpoint : PATH.api(endpoint);
            const response = await fetch(url, fetchOptions);
            return await response.json();
        }

        let currentUserId = null;
        let searchTimeout = null;
        
        // Simple mode keywords
        let keywords = [];
        
        // Advanced mode tags
        let advancedTags = {
            requireAll: [],
            requireAny: [],
            excludeAny: []
        };

        // Toggle between Simple and Advanced keyword modes
        function toggleKeywordMode() {
            const mode = document.querySelector('input[name="keywordMode"]:checked').value;
            const simpleSection = document.getElementById('simpleKeywordsSection');
            const advancedSection = document.getElementById('advancedKeywordsSection');
            
            if (mode === 'simple') {
                simpleSection.style.display = 'block';
                advancedSection.style.display = 'none';
            } else {
                simpleSection.style.display = 'none';
                advancedSection.style.display = 'block';
            }
            
            updateHiddenKeywordsField();
        }

        // Enable/disable min_query_len input
        document.addEventListener('DOMContentLoaded', () => {
            const enableCheckbox = document.getElementById('enableMinQueryLen');
            const minInput = document.getElementById('minQueryLen');
            
            if (enableCheckbox && minInput) {
                enableCheckbox.addEventListener('change', (e) => {
                    minInput.disabled = !e.target.checked;
                    updateHiddenKeywordsField();
                });
            }
        });

        // Toggle advanced section
        function toggleAdvanced() {
            const section = document.getElementById('advancedSection');
            const text = document.getElementById('advancedToggleText');
            if (section.style.display === 'none') {
                section.style.display = 'block';
                text.textContent = 'ซ่อนตัวเลือกขั้นสูง';
            } else {
                section.style.display = 'none';
                text.textContent = 'แสดงตัวเลือกขั้นสูง';
            }
        }

        // === Simple Mode: Keyword tag system ===
        function handleKeywordInput(event) {
            if (event.key === 'Enter' || event.key === ',') {
                event.preventDefault();
                addKeyword();
            } else if (event.key === 'Backspace' && event.target.value === '' && keywords.length > 0) {
                removeKeyword(keywords.length - 1);
            }
        }

        function addKeyword() {
            const input = document.getElementById('keywordInput');
            const value = input.value.trim();
            
            if (value && !keywords.includes(value)) {
                keywords.push(value);
                renderKeywords();
                input.value = '';
                updateHiddenKeywordsField();
            }
        }

        function removeKeyword(index) {
            keywords.splice(index, 1);
            renderKeywords();
            updateHiddenKeywordsField();
        }

        function renderKeywords() {
            const container = document.getElementById('keywordsContainer');
            const input = document.getElementById('keywordInput');
            
            const existingTags = container.querySelectorAll('.keyword-tag');
            existingTags.forEach(tag => tag.remove());
            
            keywords.forEach((keyword, index) => {
                const tag = document.createElement('span');
                tag.className = 'keyword-tag';
                tag.style.cssText = `
                    display:inline-flex;
                    align-items:center;
                    gap:0.25rem;
                    background:var(--color-primary);
                    color:white;
                    padding:0.4rem 0.6rem;
                    border-radius:6px;
                    font-size:0.9rem;
                `;
                tag.innerHTML = `
                    ${escapeHtml(keyword)}
                    <button type="button" onclick="removeKeyword(${index})" style="
                        background:transparent;
                        border:none;
                        color:white;
                        cursor:pointer;
                        padding:0;
                        margin-left:0.25rem;
                        font-size:1rem;
                        line-height:1;
                    ">×</button>
                `;
                container.insertBefore(tag, input);
            });
        }

        // === Advanced Mode: Tag management ===
        function handleTagInput(event, type) {
            if (event.key === 'Enter' || event.key === ',') {
                event.preventDefault();
                addTag(type);
            } else if (event.key === 'Backspace' && event.target.value === '' && advancedTags[type].length > 0) {
                removeTag(type, advancedTags[type].length - 1);
            }
        }

        function addTag(type) {
            const input = document.getElementById(`${type}Input`);
            const value = input.value.trim();
            
            if (value && !advancedTags[type].includes(value)) {
                advancedTags[type].push(value);
                renderTags(type);
                input.value = '';
                updateHiddenKeywordsField();
            }
        }

        function removeTag(type, index) {
            advancedTags[type].splice(index, 1);
            renderTags(type);
            updateHiddenKeywordsField();
        }

        function renderTags(type) {
            const container = document.getElementById(`${type}Container`);
            container.innerHTML = '';
            
            advancedTags[type].forEach((tag, index) => {
                const tagEl = document.createElement('span');
                tagEl.style.cssText = `
                    display:inline-flex;
                    align-items:center;
                    gap:0.25rem;
                    background:var(--color-primary);
                    color:white;
                    padding:0.4rem 0.6rem;
                    border-radius:6px;
                    font-size:0.9rem;
                `;
                tagEl.innerHTML = `
                    ${escapeHtml(tag)}
                    <button type="button" onclick="removeTag('${type}', ${index})" style="
                        background:transparent;
                        border:none;
                        color:white;
                        cursor:pointer;
                        padding:0;
                        margin-left:0.25rem;
                        font-size:1rem;
                        line-height:1;
                    ">×</button>
                `;
                container.appendChild(tagEl);
            });
        }

        // Update hidden keywords field with current mode data
        function updateHiddenKeywordsField() {
            const mode = document.querySelector('input[name="keywordMode"]:checked')?.value || 'simple';
            const hiddenField = document.getElementById('kbKeywords');
            let jsonData;
            
            if (mode === 'simple') {
                // Simple: just array of strings
                jsonData = keywords;
            } else {
                // Advanced: object with rules
                const rules = {
                    mode: 'advanced',
                    require_all: advancedTags.requireAll,
                    require_any: advancedTags.requireAny,
                    exclude_any: advancedTags.excludeAny
                };
                
                const enableMinLen = document.getElementById('enableMinQueryLen');
                const minLen = document.getElementById('minQueryLen');
                if (enableMinLen && enableMinLen.checked && minLen) {
                    rules.min_query_len = parseInt(minLen.value) || 6;
                }
                
                jsonData = rules;
            }
            
            hiddenField.value = JSON.stringify(jsonData);
            
            // ✅ Also update JSON editor
            updateJsonEditor(jsonData);
        }

        // Update the JSON editor textarea with formatted JSON
        function updateJsonEditor(data) {
            const editor = document.getElementById('keywordsJsonEditor');
            if (editor) {
                editor.value = JSON.stringify(data, null, 2);
                clearJsonError();
            }
        }

        // Validate and sync JSON from editor to hidden field (auto-save)
        function validateAndSyncJson() {
            const editor = document.getElementById('keywordsJsonEditor');
            const hiddenField = document.getElementById('kbKeywords');
            
            try {
                const parsed = JSON.parse(editor.value);
                hiddenField.value = JSON.stringify(parsed);
                clearJsonError();
            } catch (e) {
                showJsonError('JSON format ไม่ถูกต้อง: ' + e.message);
            }
        }

        // Copy JSON from editor to UI (manual sync with button)
        function copyJsonToUI() {
            const editor = document.getElementById('keywordsJsonEditor');
            
            try {
                const parsed = JSON.parse(editor.value);
                
                // Load into UI
                loadKeywordsFromData(parsed);
                
                clearJsonError();
                
                // Show success feedback
                const btn = event.target.closest('button');
                const originalText = btn.innerHTML;
                btn.innerHTML = '✅ Synced!';
                btn.style.background = 'var(--color-success)';
                btn.style.color = 'white';
                
                setTimeout(() => {
                    btn.innerHTML = originalText;
                    btn.style.background = '';
                    btn.style.color = '';
                }, 2000);
                
            } catch (e) {
                showJsonError('JSON format ไม่ถูกต้อง: ' + e.message);
            }
        }

        // Show JSON error message
        function showJsonError(message) {
            const errorDiv = document.getElementById('jsonError');
            if (errorDiv) {
                errorDiv.textContent = '❌ ' + message;
                errorDiv.style.display = 'block';
            }
        }

        // Clear JSON error message
        function clearJsonError() {
            const errorDiv = document.getElementById('jsonError');
            if (errorDiv) {
                errorDiv.style.display = 'none';
            }
        }


        // Metadata Field Builder
        let metadataFields = [];
        let metadataFieldId = 0;

        function addMetadataField(key = '', type = 'text') {
            const id = metadataFieldId++;
            const fieldObj = { id, key, value: type === 'checkbox' ? false : '', type };
            metadataFields.push(fieldObj);
            renderMetadataFields();
        }

        function removeMetadataField(id) {
            metadataFields = metadataFields.filter(f => f.id !== id);
            renderMetadataFields();
        }

        function updateMetadataField(id, key, value) {
            const field = metadataFields.find(f => f.id === id);
            if (field) {
                if (key !== undefined) field.key = key;
                if (value !== undefined) field.value = value;
                updateMetadataJSON();
            }
        }

        function renderMetadataFields() {
            const container = document.getElementById('metadataFieldsList');
            const emptyState = document.getElementById('metadataEmptyState');
            
            if (metadataFields.length === 0) {
                container.innerHTML = '';
                emptyState.style.display = 'block';
            } else {
                emptyState.style.display = 'none';
                container.innerHTML = metadataFields.map(field => `
                    <div style="display:flex;gap:0.5rem;margin-bottom:0.5rem;align-items:center;">
                        <input type="text" 
                            placeholder="ชื่อฟิลด์ (เช่น price)" 
                            value="${escapeHtml(field.key)}"
                            onchange="updateMetadataField(${field.id}, this.value, undefined)"
                            style="flex:1;padding:0.5rem;border:1px solid var(--color-border);border-radius:4px;">
                        ${field.type === 'checkbox' ? `
                            <label style="display:flex;align-items:center;gap:0.25rem;padding:0.5rem;background:white;border:1px solid var(--color-border);border-radius:4px;min-width:100px;">
                                <input type="checkbox" 
                                    ${field.value ? 'checked' : ''}
                                    onchange="updateMetadataField(${field.id}, undefined, this.checked)">
                                <span style="font-size:0.9rem;">${field.value ? 'มี' : 'ไม่มี'}</span>
                            </label>
                        ` : `
                            <input type="${field.type}" 
                                placeholder="ค่า" 
                                value="${escapeHtml(field.value)}"
                                onchange="updateMetadataField(${field.id}, undefined, this.value)"
                                style="flex:1;padding:0.5rem;border:1px solid var(--color-border);border-radius:4px;">
                        `}
                        <button type="button" onclick="removeMetadataField(${field.id})" 
                            style="padding:0.5rem;background:var(--color-danger);color:white;border:none;border-radius:4px;cursor:pointer;" title="ลบ">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                `).join('');
            }
            updateMetadataJSON();
        }

        function updateMetadataJSON() {
            const obj = {};
            metadataFields.forEach(field => {
                if (field.key && field.key.trim()) {
                    obj[field.key.trim()] = field.value;
                }
            });
            document.getElementById('kbMetadata').value = Object.keys(obj).length > 0 ? JSON.stringify(obj) : '';
        }

        function loadMetadataFromJSON(jsonStr) {
            metadataFields = [];
            metadataFieldId = 0;
            
            if (!jsonStr || jsonStr.trim() === '') {
                renderMetadataFields();
                return;
            }
            
            try {
                const obj = JSON.parse(jsonStr);
                Object.entries(obj).forEach(([key, value]) => {
                    const type = typeof value === 'number' ? 'number' : 
                                typeof value === 'boolean' ? 'checkbox' : 'text';
                    const id = metadataFieldId++;
                    metadataFields.push({ id, key, value, type });
                });
                renderMetadataFields();
            } catch (e) {
                console.error('Invalid JSON:', e);
            }
        }

        // Load customers on page load
        async function loadCustomers() {
            try {
                const res = await adminApiCall('/api/admin/customers.php');
                if (res.success && res.data && res.data.customers) {
                    const select = document.getElementById('customerSelect');
                    select.innerHTML = '<option value="">-- เลือกลูกค้า --</option>';
                    
                    res.data.customers.forEach(customer => {
                        const option = document.createElement('option');
                        option.value = customer.id;
                        option.textContent = `${customer.full_name || customer.email} (${customer.company_name || 'N/A'})`;
                        select.appendChild(option);
                    });
                }
            } catch (error) {
                console.error('Error loading customers:', error);
            }
        }

        // Load KB entries
        async function loadKnowledgeBase() {
            const userId = document.getElementById('customerSelect').value;
            if (!userId) {
                document.getElementById('kbSection').style.display = 'none';
                return;
            }

            currentUserId = userId;
            document.getElementById('kbSection').style.display = 'block';

            const category = document.getElementById('categoryFilter').value;
            const search = document.getElementById('searchInput').value;

            let url = `/api/admin/knowledge-base.php?user_id=${userId}`;
            if (category) url += `&category=${category}`;
            if (search) url += `&search=${encodeURIComponent(search)}`;

            try {
                const res = await adminApiCall(url);
                const tbody = document.getElementById('kbTableBody');

                if (!res.success || !res.data || !res.data.entries || res.data.entries.length === 0) {
                    tbody.innerHTML = `
                        <tr>
                            <td colspan="6" style="text-align:center;padding:2rem;color:var(--color-gray);">
                                ไม่มีข้อมูล - คลิก "เพิ่มข้อมูล" เพื่อเริ่มต้น
                            </td>
                        </tr>
                    `;
                    return;
                }

                tbody.innerHTML = res.data.entries.map(entry => {
                    const isAdvanced = entry.keywords && typeof entry.keywords === 'object' && entry.keywords.mode === 'advanced';
                    const keywordTags = isAdvanced 
                        ? [...(entry.keywords.require_all || []), ...(entry.keywords.require_any || [])]
                        : (Array.isArray(entry.keywords) ? entry.keywords : []);
                    
                    return `
                    <tr>
                        <td>${entry.id}</td>
                        <td>${getCategoryBadge(entry.category)}</td>
                        <td>
                            <div style="margin-bottom:0.5rem;">
                                <strong>${escapeHtml(entry.question || 'N/A')}</strong>
                            </div>
                            <div style="color:var(--color-gray);font-size:0.9rem;">
                                ${escapeHtml(entry.answer).substring(0, 100)}${entry.answer.length > 100 ? '...' : ''}
                            </div>
                        </td>
                        <td>
                            <div style="margin-bottom:0.25rem;">
                                ${isAdvanced ? '<span style="background:#f0fdf4;color:#166534;padding:0.15rem 0.4rem;border-radius:4px;font-size:0.7rem;font-weight:500;">✨ Advanced</span>' : '<span style="background:#e5e7eb;color:#374151;padding:0.15rem 0.4rem;border-radius:4px;font-size:0.7rem;font-weight:500;">Simple</span>'}
                            </div>
                            <div style="display:flex;flex-wrap:wrap;gap:0.25rem;">
                                ${keywordTags.slice(0, 3).map(kw => `
                                    <span style="background:#e5e7eb;padding:0.15rem 0.4rem;border-radius:4px;font-size:0.75rem;">
                                        ${escapeHtml(kw)}
                                    </span>
                                `).join('')}
                                ${keywordTags.length > 3 ? `<span style="color:var(--color-gray);font-size:0.75rem;">+${keywordTags.length - 3}</span>` : ''}
                            </div>
                        </td>
                        <td>${entry.priority}</td>
                        <td>
                            <button class="btn btn-sm btn-outline" onclick="editKBEntry(${entry.id})" title="แก้ไข">
                                <i class="fas fa-edit"></i>
                            </button>
                            <button class="btn btn-sm btn-outline" onclick="deleteKBEntry(${entry.id})" title="ลบ" style="color:var(--color-danger);">
                                <i class="fas fa-trash"></i>
                            </button>
                        </td>
                    </tr>
                `}).join('');
            } catch (error) {
                console.error('Error loading KB:', error);
                alert('เกิดข้อผิดพลาดในการโหลดข้อมูล');
            }
        }

        // Open modal for new entry
        function openKBModal() {
            if (!currentUserId) {
                alert('กรุณาเลือกลูกค้าก่อน');
                return;
            }
            
            document.getElementById('modalTitle').textContent = 'เพิ่มข้อมูลใหม่';
            document.getElementById('kbForm').reset();
            document.getElementById('kbEntryId').value = '';
            document.getElementById('kbActive').checked = true;
            document.getElementById('kbPriority').value = '100';
            
            // Reset to Simple mode
            document.querySelector('input[name="keywordMode"][value="simple"]').checked = true;
            toggleKeywordMode();
            
            // Reset simple keywords
            keywords = [];
            renderKeywords();
            
            // Reset advanced tags
            advancedTags = { requireAll: [], requireAny: [], excludeAny: [] };
            renderTags('requireAll');
            renderTags('requireAny');
            renderTags('excludeAny');
            document.getElementById('enableMinQueryLen').checked = false;
            document.getElementById('minQueryLen').disabled = true;
            
            // Reset metadata fields
            metadataFields = [];
            metadataFieldId = 0;
            renderMetadataFields();
            
            // Reset JSON editor
            document.getElementById('keywordsJsonEditor').value = '';
            clearJsonError();
            
            // Hide advanced section
            document.getElementById('advancedSection').style.display = 'none';
            document.getElementById('advancedToggleText').textContent = 'แสดงตัวเลือกขั้นสูง';
            
            document.getElementById('kbModal').classList.remove('hidden');
        }

        // Edit entry
        async function editKBEntry(id) {
            try {
                const res = await adminApiCall(`/api/admin/knowledge-base.php?id=${id}`);
                if (res.success && res.data && res.data.entry) {
                    const entry = res.data.entry;
                    
                    document.getElementById('modalTitle').textContent = 'แก้ไขข้อมูล';
                    document.getElementById('kbEntryId').value = entry.id;
                    document.getElementById('kbCategory').value = entry.category;
                    document.getElementById('kbQuestion').value = entry.question;
                    document.getElementById('kbAnswer').value = entry.answer;
                    
                    // Load keywords (auto-detect format)
                    loadKeywordsFromData(entry.keywords);
                    
                    // Load metadata fields
                    const metadataStr = entry.metadata && Object.keys(entry.metadata).length > 0 
                        ? JSON.stringify(entry.metadata, null, 2) 
                        : '';
                    loadMetadataFromJSON(metadataStr);
                    
                    document.getElementById('kbPriority').value = entry.priority;
                    document.getElementById('kbActive').checked = !!entry.is_active;
                    
                    document.getElementById('kbModal').classList.remove('hidden');
                }
            } catch (error) {
                console.error('Error loading entry:', error);
                alert('ไม่สามารถโหลดข้อมูลได้');
            }
        }

        // Helper: Load keywords from data (auto-detect format)
        function loadKeywordsFromData(keywordsData) {
            // Check if advanced format (object with mode='advanced')
            if (keywordsData && typeof keywordsData === 'object' && keywordsData.mode === 'advanced') {
                // Advanced mode
                document.querySelector('input[name="keywordMode"][value="advanced"]').checked = true;
                toggleKeywordMode();
                
                advancedTags.requireAll = keywordsData.require_all || [];
                advancedTags.requireAny = keywordsData.require_any || [];
                advancedTags.excludeAny = keywordsData.exclude_any || [];
                
                renderTags('requireAll');
                renderTags('requireAny');
                renderTags('excludeAny');
                
                if (keywordsData.min_query_len) {
                    document.getElementById('enableMinQueryLen').checked = true;
                    document.getElementById('minQueryLen').value = keywordsData.min_query_len;
                    document.getElementById('minQueryLen').disabled = false;
                } else {
                    document.getElementById('enableMinQueryLen').checked = false;
                    document.getElementById('minQueryLen').disabled = true;
                }
            } else {
                // Simple mode (array)
                document.querySelector('input[name="keywordMode"][value="simple"]').checked = true;
                toggleKeywordMode();
                
                keywords = Array.isArray(keywordsData) ? keywordsData : [];
                renderKeywords();
            }
            
            updateHiddenKeywordsField();
        }

        // Delete entry
        async function deleteKBEntry(id) {
            if (!confirm('ต้องการลบข้อมูลนี้?')) return;

            try {
                const res = await adminApiCall(`/api/admin/knowledge-base.php?id=${id}`, {
                    method: 'DELETE'
                });

                if (res.success) {
                    alert('ลบข้อมูลสำเร็จ');
                    loadKnowledgeBase();
                } else {
                    alert('ไม่สามารถลบข้อมูลได้: ' + (res.message || 'Unknown error'));
                }
            } catch (error) {
                console.error('Error deleting entry:', error);
                alert('เกิดข้อผิดพลาดในการลบข้อมูล');
            }
        }

        // Close modal
        function closeKBModal() {
            document.getElementById('kbModal').classList.add('hidden');
        }

        // Save KB entry
        document.getElementById('kbForm').addEventListener('submit', async (e) => {
            e.preventDefault();

            const entryId = document.getElementById('kbEntryId').value;
            
            // Parse keywords from hidden field (already JSON)
            let keywords;
            try {
                keywords = JSON.parse(document.getElementById('kbKeywords').value);
            } catch (e) {
                alert('Keywords format ไม่ถูกต้อง');
                return;
            }

            // ✅ Validate advanced mode has at least one keyword rule
            if (keywords && typeof keywords === 'object' && keywords.mode === 'advanced') {
                const hasRequireAll = Array.isArray(keywords.require_all) && keywords.require_all.length > 0;
                const hasRequireAny = Array.isArray(keywords.require_any) && keywords.require_any.length > 0;
                
                if (!hasRequireAll && !hasRequireAny) {
                    alert('⚠️ Advanced mode ต้องมีอย่างน้อย 1 คำใน "ต้องมีคำนี้ทุกคำ" หรือ "ต้องมีอย่างน้อย 1 คำ"\n\nกรุณาเพิ่มคำค้นหาก่อนบันทึก');
                    return;
                }
            }

            let metadata = {};
            const metadataStr = document.getElementById('kbMetadata').value.trim();
            if (metadataStr) {
                try {
                    metadata = JSON.parse(metadataStr);
                } catch (e) {
                    alert('Metadata JSON ไม่ถูกต้อง');
                    return;
                }
            }

            const data = {
                user_id: parseInt(currentUserId),
                category: document.getElementById('kbCategory').value,
                question: document.getElementById('kbQuestion').value,
                answer: document.getElementById('kbAnswer').value,
                keywords: keywords,  // Send as-is (array or object)
                metadata: metadata,
                priority: parseInt(document.getElementById('kbPriority').value) || 0,
                is_active: document.getElementById('kbActive').checked ? 1 : 0
            };

            try {
                let url = '/api/admin/knowledge-base.php';
                let method = 'POST';

                if (entryId) {
                    url += `?id=${entryId}`;
                    method = 'PUT';
                }

                const res = await adminApiCall(url, {
                    method: method,
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(data)
                });

                if (res.success) {
                    alert(entryId ? 'แก้ไขข้อมูลสำเร็จ' : 'เพิ่มข้อมูลสำเร็จ');
                    closeKBModal();
                    loadKnowledgeBase();
                } else {
                    alert('ไม่สามารถบันทึกข้อมูลได้: ' + (res.message || 'Unknown error'));
                }
            } catch (error) {
                console.error('Error saving entry:', error);
                alert('เกิดข้อผิดพลาดในการบันทึกข้อมูล');
            }
        });

        // Helper functions
        function getCategoryBadge(category) {
            const badges = {
                'product': '📦 สินค้า',
                'service': '🔧 บริการ',
                'pricing': '💰 ราคา',
                'faq': '❓ FAQ',
                'general': '📄 ทั่วไป'
            };
            return badges[category] || category;
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        function debounceSearch() {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => {
                loadKnowledgeBase();
            }, 500);
        }

        // Initialize
        window.addEventListener('DOMContentLoaded', () => {
            loadCustomers();
        });

        // Close modal on backdrop click
        window.addEventListener('click', (e) => {
            if (e.target === document.getElementById('kbModal')) {
                closeKBModal();
            }
        });
    </script>
</main>

<?php
include('../../includes/admin/footer.php');
?>
