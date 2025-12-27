<?php
/**
 * Admin Packages Management
 */
define('INCLUDE_CHECK', true);

$page_title = "จัดการแพ็คเกจ - Admin Panel";
$current_page = "packages";

include('../../includes/admin/header.php');
include('../../includes/admin/sidebar.php');
?>

<main class="main-content">
    <div class="page-header">
        <div style="display: flex; justify-content: space-between; align-items: center;">
            <div>
                <h1 class="page-title"><i class="fas fa-box"></i> จัดการแพ็คเกจ</h1>
                <p class="page-subtitle">สร้างและจัดการแพ็คเกจสำหรับลูกค้า</p>
            </div>
            <button class="btn btn-primary btn-lg" onclick="showCreatePackageModal()">
                <i class="fas fa-plus"></i> สร้างแพ็คเกจใหม่
            </button>
        </div>
    </div>

    <!-- Packages List -->
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">แพ็คเกจทั้งหมด</h3>
        </div>
        <div class="card-body">
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>ชื่อแพ็คเกจ</th>
                            <th>ราคา/เดือน</th>
                            <th>จำกัด Messages</th>
                            <th>จำกัด API Calls</th>
                            <th>ราคาเกิน/Message</th>
                            <th>ราคาเกิน/API Call</th>
                            <th>สถานะ</th>
                            <th>การจัดการ</th>
                        </tr>
                    </thead>
                    <tbody id="packagesTable">
                        <!-- Sample Data -->
                        <tr>
                            <td><strong>Starter</strong></td>
                            <td>฿990</td>
                            <td>1,000</td>
                            <td>1,000</td>
                            <td>฿0.50</td>
                            <td>฿1.00</td>
                            <td><span class="badge badge-success">เปิดใช้งาน</span></td>
                            <td>
                                <button class="btn btn-sm btn-outline" onclick="editPackage(1)">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <button class="btn btn-sm btn-danger" onclick="deletePackage(1)">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </td>
                        </tr>
                        <tr>
                            <td><strong>Professional</strong></td>
                            <td>฿2,990</td>
                            <td>5,000</td>
                            <td>5,000</td>
                            <td>฿0.40</td>
                            <td>฿0.80</td>
                            <td><span class="badge badge-success">เปิดใช้งาน</span></td>
                            <td>
                                <button class="btn btn-sm btn-outline" onclick="editPackage(2)">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <button class="btn btn-sm btn-danger" onclick="deletePackage(2)">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </td>
                        </tr>
                        <tr>
                            <td><strong>Enterprise</strong></td>
                            <td>฿9,990</td>
                            <td>ไม่จำกัด</td>
                            <td>ไม่จำกัด</td>
                            <td>-</td>
                            <td>-</td>
                            <td><span class="badge badge-success">เปิดใช้งาน</span></td>
                            <td>
                                <button class="btn btn-sm btn-outline" onclick="editPackage(3)">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <button class="btn btn-sm btn-danger" onclick="deletePackage(3)">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Create/Edit Package Modal -->
    <div id="packageModal" class="modal-backdrop hidden">
        <div class="modal-content">
            <div class="card">
                <button type="button" class="modal-close-btn" onclick="hidePackageModal()" aria-label="Close">
                    <i class="fas fa-times"></i>
                </button>
                <div class="card-header">
                    <h3 class="card-title"><i class="fas fa-box"></i> <span id="modalTitle">สร้างแพ็คเกจใหม่</span></h3>
                </div>
                <div class="card-body">
                    <form id="packageForm">
                        <div class="row">
                            <div class="col-12">
                                <div class="form-group">
                                    <label class="form-label"><i class="fas fa-tag"></i> ชื่อแพ็คเกจ</label>
                                    <input type="text" id="packageName" class="form-control"
                                        placeholder="เช่น Starter, Professional" required>
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-6">
                                <div class="form-group">
                                    <label class="form-label"><i class="fas fa-dollar-sign"></i> ราคา/เดือน (บาท)</label>
                                    <input type="number" id="monthlyPrice" class="form-control" placeholder="990" required>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="form-group">
                                    <label class="form-label"><i class="fas fa-calendar"></i> ระยะเวลา (วัน)</label>
                                    <input type="number" id="billingPeriod" class="form-control" value="30" required>
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-6">
                                <div class="form-group">
                                    <label class="form-label"><i class="fas fa-comments"></i> จำกัด Messages</label>
                                    <input type="number" id="messageLimit" class="form-control" placeholder="1000">
                                    <small style="color: var(--color-gray);">0 = ไม่จำกัด</small>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="form-group">
                                    <label class="form-label"><i class="fas fa-plug"></i> จำกัด API Calls</label>
                                    <input type="number" id="apiLimit" class="form-control" placeholder="1000">
                                    <small style="color: var(--color-gray);">0 = ไม่จำกัด</small>
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-6">
                                <div class="form-group">
                                    <label class="form-label"><i class="fas fa-plus-circle"></i> ราคาเกิน Message (บาท)</label>
                                    <input type="number" step="0.01" id="overageMessage" class="form-control" placeholder="0.50">
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="form-group">
                                    <label class="form-label"><i class="fas fa-plus-circle"></i> ราคาเกิน API (บาท)</label>
                                    <input type="number" step="0.01" id="overageApi" class="form-control" placeholder="1.00">
                                </div>
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="form-label"><i class="fas fa-align-left"></i> รายละเอียด</label>
                            <textarea id="description" class="form-control" rows="3" placeholder="รายละเอียดแพ็คเกจ..."></textarea>
                        </div>

                        <div class="form-group">
                            <label style="display: flex; align-items: center; gap: 0.5rem;">
                                <input type="checkbox" id="isActive" checked>
                                <span><i class="fas fa-check-circle"></i> เปิดใช้งานแพ็คเกจนี้</span>
                            </label>
                        </div>

                        <div style="display: flex; gap: 1rem; margin-top: 2rem;">
                            <button type="submit" class="btn btn-primary" style="flex: 1;">
                                <i class="fas fa-save"></i> บันทึก
                            </button>
                            <button type="button" class="btn btn-outline" onclick="hidePackageModal()" style="flex: 1;">
                                <i class="fas fa-times"></i> ยกเลิก
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</main>

<?php
$extra_scripts = ['assets/js/admin-packages.js'];
include('../../includes/admin/footer.php');
?>
