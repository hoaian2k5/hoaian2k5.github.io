<?php
/**
 * UniPortal – Hồ sơ cá nhân (Student Profile)
 * Figma frame: profile (6:3323)
 */
$root = dirname(__DIR__, 3);
require_once $root . '/src/src/helpers/auth.php';
require_once $root . '/src/src/models/Student.php';

require_student();
if (isset($_GET['logout'])) { auth_logout(); header('Location: /UniPortal/public/index.php'); exit; }

$student_id = $_SESSION['student_id'] ?? '';
$full_name  = $_SESSION['full_name']  ?? '';

$pdo = get_db();

// Load full profile
$student = StudentModel::getById($student_id);

$success = '';
$error   = '';

// Handle phone/address update (permitted editable fields)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    csrf_verify();
    $newPhone = trim($_POST['phone'] ?? '');
    if ($newPhone !== '' && !preg_match('/^[0-9+\-\s]{7,15}$/', $newPhone)) {
        $error = 'Số điện thoại không hợp lệ.';
    } else {
        $upd = $pdo->prepare('UPDATE Student SET phone=:phone WHERE student_id=:sid');
        $upd->execute([':phone' => $newPhone ?: null, ':sid' => $student_id]);
        // Reload
        $student = StudentModel::getById($student_id);
        $success = 'Đã cập nhật thông tin thành công.';
    }
}

// Handle password change
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    csrf_verify();
    $current = $_POST['current_password'] ?? '';
    $new     = $_POST['new_password']     ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    if (strlen($new) < 6) {
        $error = 'Mật khẩu mới phải có ít nhất 6 ký tự.';
    } elseif ($new !== $confirm) {
        $error = 'Mật khẩu xác nhận không khớp.';
    } else {
        // Verify current password
        $a = $pdo->prepare('SELECT password_hash FROM Account WHERE student_id = :sid LIMIT 1');
        $a->execute([':sid' => $student_id]);
        $acc = $a->fetch();
        if (!$acc || !password_verify($current, $acc['password_hash'])) {
            $error = 'Mật khẩu hiện tại không đúng.';
        } else {
            $hash = password_hash($new, PASSWORD_BCRYPT);
            $upd  = $pdo->prepare('UPDATE Account SET password_hash = :h WHERE student_id = :sid');
            $upd->execute([':h' => $hash, ':sid' => $student_id]);
            $success = 'Đổi mật khẩu thành công!';
        }
    }
}

function h($v):string{ return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8'); }

$pageTitle  = 'Hồ sơ cá nhân';
$activePage = 'profile';
$imgBase    = '../../assets/img/';
$assetBase  = '../../assets/';
$warningCnt = StudentModel::getUnreadWarningCount($student_id);
require_once __DIR__ . '/../partials/layout.php';
?>

<!-- Page banner -->
<?php page_banner('Hồ sơ cá nhân','Thông tin cá nhân và tài khoản học vụ','db-nav-profile.svg',$imgBase); ?>

<?php if ($success): ?>
<div style="background:#d1fae5;border:1px solid #6ee7b7;border-radius:10px;padding:12px 16px;color:#065f46;font-weight:600;">
    ✅ <?= h($success) ?>
</div>
<?php endif; ?>
<?php if ($error): ?>
<div style="background:#fee2e2;border:1px solid #fca5a5;border-radius:10px;padding:12px 16px;color:#b91c1c;font-weight:600;">
    ⚠️ <?= h($error) ?>
</div>
<?php endif; ?>

<!-- Tab bar -->
<div class="tab-bar" id="profileTabs">
    <button class="tab-btn active" data-tab="info">Thông tin cá nhân</button>
    <button class="tab-btn" data-tab="account">Tài khoản & Bảo mật</button>
</div>

<!-- Tab: personal info -->
<div class="tab-panel active card" id="tab-info" style="padding:32px;">
    <div style="display:flex;gap:32px;align-items:flex-start;margin-bottom:32px;">
        <!-- Avatar -->
        <div style="flex-shrink:0;display:flex;flex-direction:column;align-items:center;gap:10px;">
            <img src="<?= $imgBase ?>db-profile.png"
                 alt="<?= h($full_name) ?>"
                 style="width:96px;height:96px;border-radius:50%;object-fit:cover;box-shadow:0 0 0 4px #e5eeff;" />
            <div style="font-size:18px;font-weight:700;color:#001634;"><?= h($full_name) ?></div>
            <div style="font-size:12px;color:#64748b;">MSSV: <?= h($student_id) ?></div>
            <?php if ($student && $student['status']): ?>
            <span class="status-pill status-pill--<?= strtolower($student['status']) === 'active' ? 'active' : 'absent' ?>">
                <?= $student['status'] === 'Active' ? 'Đang học' : h($student['status']) ?>
            </span>
            <?php endif; ?>
        </div>
        <!-- Info fields -->
        <div style="flex:1;">
            <div class="profile-grid">
                <div class="form-group">
                    <label>Mã sinh viên</label>
                    <input type="text" value="<?= h($student_id) ?>" readonly />
                </div>
                <div class="form-group">
                    <label>Họ và tên</label>
                    <input type="text" value="<?= h($full_name) ?>" readonly />
                </div>
                <div class="form-group">
                    <label>Email</label>
                    <input type="email" value="<?= h($student['email'] ?? '') ?>" readonly />
                </div>
                <div class="form-group">
                    <label>Số điện thoại</label>
                    <form method="POST" style="display:flex;gap:8px;align-items:center;">
                        <?= csrf_input() ?>
                        <input type="text" name="phone" value="<?= h($student['phone'] ?? '') ?>"
                               placeholder="Nhập số điện thoại" maxlength="15"
                               style="flex:1;height:36px;border:1px solid #e2e8f0;border-radius:8px;padding:0 10px;font-size:13px;outline:none;" />
                        <button type="submit" name="update_profile" value="1"
                                style="padding:6px 14px;background:#102b50;color:#fff;border:none;border-radius:8px;font-size:12px;font-weight:600;cursor:pointer;white-space:nowrap;">
                            Lưu
                        </button>
                    </form>
                </div>
                <div class="form-group">
                    <label>Lớp</label>
                    <input type="text" value="<?= h($_SESSION['class_code'] ?? '') ?>" readonly />
                </div>
                <div class="form-group">
                    <label>Ngành học</label>
                    <input type="text" value="<?= h($_SESSION['department_name'] ?? '') ?>" readonly />
                </div>
                <div class="form-group">
                    <label>Giới tính</label>
                    <input type="text" value="<?= $student['gender'] === 'Female' ? 'Nữ' : ($student['gender'] === 'Male' ? 'Nam' : h($student['gender'] ?? '—')) ?>" readonly />
                </div>
                <div class="form-group">
                    <label>Ngày sinh</label>
                    <input type="text" value="<?= $student['date_of_birth'] ? date('d/m/Y', strtotime($student['date_of_birth'])) : '—' ?>" readonly />
                </div>
                <div class="form-group">
                    <label>Năm nhập học</label>
                    <input type="text" value="<?= h($student['admission_year'] ?? '') ?>" readonly />
                </div>
                <div class="form-group">
                    <label>Tình trạng học vụ</label>
                    <input type="text" value="<?= $student['status'] === 'Active' ? 'Đang học' : h($student['status'] ?? '') ?>" readonly />
                </div>
            </div>
            <p style="font-size:12px;color:#94a3b8;margin-top:16px;">
                * Để cập nhật thông tin, vui lòng liên hệ Phòng Đào tạo (P.103) hoặc email: <a href="mailto:daotao@vnu.edu.vn" style="color:#102b50;">daotao@vnu.edu.vn</a>
            </p>
        </div>
    </div>
</div>

<!-- Tab: account & security -->
<div class="tab-panel card" id="tab-account" style="padding:32px;display:none;">
    <div style="max-width:480px;">
        <div style="font-size:18px;font-weight:700;color:#001634;margin-bottom:4px;">Đổi mật khẩu</div>
        <p style="font-size:13px;color:#44474e;margin-bottom:24px;">Mật khẩu mới phải có ít nhất 6 ký tự.</p>
        <form method="POST">
            <div style="display:flex;flex-direction:column;gap:16px;">
                <div class="form-group">
                    <label>Mật khẩu hiện tại <span style="color:#ef4444;">*</span></label>
                    <input type="password" name="current_password" required placeholder="Nhập mật khẩu hiện tại" />
                </div>
                <div class="form-group">
                    <label>Mật khẩu mới <span style="color:#ef4444;">*</span></label>
                    <input type="password" name="new_password" required minlength="6" placeholder="Tối thiểu 6 ký tự" />
                </div>
                <div class="form-group">
                    <label>Xác nhận mật khẩu mới <span style="color:#ef4444;">*</span></label>
                    <input type="password" name="confirm_password" required minlength="6" placeholder="Nhập lại mật khẩu mới" />
                </div>
                <button type="submit" name="change_password" class="btn-primary" style="align-self:flex-start;padding:10px 24px;">
                    Đổi mật khẩu
                </button>
            </div>
        </form>
        <div style="margin-top:32px;padding-top:24px;border-top:1px solid #f1f5f9;">
            <div style="font-size:14px;font-weight:700;color:#001634;margin-bottom:8px;">Thông tin tài khoản</div>
            <p style="font-size:13px;color:#44474e;">
                Username: <strong><?= h($_SESSION['username'] ?? '') ?></strong><br/>
                Vai trò: <strong>Sinh viên</strong><br/>
                Trạng thái: <strong style="color:#059669;">Hoạt động</strong>
            </p>
        </div>
    </div>
</div>

</div></main>
<script src="../../assets/js/dashboard.js"></script>
<script>
// Tab switching
document.querySelectorAll('.tab-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
        document.querySelectorAll('.tab-panel').forEach(p => p.style.display = 'none');
        this.classList.add('active');
        const panel = document.getElementById('tab-' + this.dataset.tab);
        if (panel) panel.style.display = 'block';
    });
});
</script>
</body></html>
