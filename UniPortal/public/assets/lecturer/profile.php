<?php
/**
 * UniPortal – Hồ sơ Giảng viên & Thiết lập tài khoản
 * Figma: profile screenshot
 */
$root = dirname(__DIR__, 3);
require_once $root . '/src/src/helpers/auth.php';
require_once $root . '/src/src/models/Lecturer.php';

require_lecturer();
if (isset($_GET['logout'])) { auth_logout(); header('Location: /UniPortal/public/index.php'); exit; }

$lecId     = (int)($_SESSION['lecturer_id'] ?? 0);
$currentSem= LecturerModel::getCurrentSemester();
$semId     = (int)($currentSem['semester_id'] ?? 0);
$sections  = $semId ? LecturerModel::getSections($lecId, $semId) : [];
$warnCount = LecturerModel::getUnreadWarningCount($lecId);

$lecturer  = LecturerModel::getById($lecId);

// ── Handle password change ────────────────────────────────────
$flash = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    csrf_verify();
    $cur  = $_POST['current_password'] ?? '';
    $new  = $_POST['new_password']     ?? '';
    $conf = $_POST['confirm_password'] ?? '';
    if (strlen($new) < 6) {
        $flash = 'error:Mật khẩu mới phải có ít nhất 6 ký tự.';
    } elseif ($new !== $conf) {
        $flash = 'error:Mật khẩu xác nhận không khớp.';
    } else {
        $pdo = get_db();
        $s = $pdo->prepare('SELECT password_hash FROM Account WHERE lecturer_id = :lid LIMIT 1');
        $s->execute([':lid' => $lecId]);
        $acc = $s->fetch();
        if (!$acc || !password_verify($cur, $acc['password_hash'])) {
            $flash = 'error:Mật khẩu hiện tại không đúng.';
        } else {
            $hash = password_hash($new, PASSWORD_BCRYPT);
            $pdo->prepare('UPDATE Account SET password_hash = :h WHERE lecturer_id = :lid')
                ->execute([':h' => $hash, ':lid' => $lecId]);
            $flash = 'success:Đổi mật khẩu thành công!';
        }
    }
}

// Hours per week estimate
$totalCredits = array_sum(array_column($sections, 'credits'));

$pageTitle  = 'Hồ sơ cá nhân';
$activePage = 'profile';
require __DIR__ . '/_layout.php';
?>

<!-- ═══════════ PAGE TITLE + ACTIONS ═════════════════════════ -->
<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
    <div>
        <h1 style="font-size:22px;font-weight:800;color:#001634;">Hồ sơ Giảng viên &amp; Thiết lập Tài khoản</h1>
    </div>
    <div style="display:flex;gap:8px;">
        <a href="#" class="lbtn lbtn--outline">✏️ Chỉnh sửa thông tin liên hệ</a>
        <button onclick="document.getElementById('pwSection').scrollIntoView({behavior:'smooth'})" class="lbtn lbtn--primary">🔑 Đổi mật khẩu tài khoản</button>
    </div>
</div>

<?php if ($flash): [$ftype,$fmsg] = explode(':',$flash,2); ?>
<div class="lec-flash lec-flash--<?= $ftype==='success'?'success':'error' ?>">
    <?= $ftype==='success'?'✅':'⚠️' ?> <?= htmlspecialchars($fmsg) ?>
</div>
<?php endif; ?>

<!-- ═══════════ PROFILE CARD ═════════════════════════════════ -->
<div class="lcard" style="padding:28px;">
    <div style="display:flex;gap:24px;align-items:flex-start;flex-wrap:wrap;">
        <!-- Avatar -->
        <div style="flex-shrink:0;text-align:center;">
            <div style="width:88px;height:88px;border-radius:50%;background:#e5eeff;display:flex;align-items:center;justify-content:center;font-size:28px;font-weight:800;color:#001634;margin-bottom:8px;border:3px solid #fff;box-shadow:0 0 0 3px #e5eeff;">
                <?php
                $lname = $lecturer['full_name'] ?? ($_SESSION['full_name'] ?? '?');
                $parts = explode(' ', trim($lname));
                $ini = strtoupper(substr(end($parts),0,1));
                echo htmlspecialchars($ini);
                ?>
            </div>
        </div>

        <!-- Info block -->
        <div style="flex:1;min-width:0;">
            <div style="display:flex;align-items:center;gap:8px;margin-bottom:4px;flex-wrap:wrap;">
                <span style="font-size:22px;font-weight:800;color:#001634;">
                    <?= htmlspecialchars($lecturer['full_name'] ?? $_SESSION['full_name'] ?? '—') ?>
                </span>
                <?php if (($lecturer['status']??'Active') === 'Active'): ?>
                <span class="lpill lpill--green" style="font-size:11px;">● Đang công tác – Giảng dạy chính quy</span>
                <?php endif; ?>
            </div>
            <div style="font-size:14px;color:#64748b;margin-bottom:6px;">
                Tiến sĩ – Giảng viên &nbsp;|&nbsp;
                <span style="font-weight:600;color:#3b82f6;">Mã CB: GV-2016-<?= str_pad($lecId,'4','0',STR_PAD_LEFT) ?></span>
            </div>
            <div style="font-size:13px;color:#44474e;margin-bottom:12px;">
                🏛️ <?= htmlspecialchars($lecturer['department_name'] ?? $_SESSION['department_name'] ?? '—') ?>
                (Bộ môn Hệ thống thông tin)
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;max-width:520px;">
                <div>
                    <div style="font-size:11px;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:.4px;">Email</div>
                    <div style="font-size:13px;color:#001634;"><?= htmlspecialchars($lecturer['email'] ?? $_SESSION['email'] ?? '—') ?></div>
                </div>
                <div>
                    <div style="font-size:11px;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:.4px;">Số điện thoại liên lạc</div>
                    <div style="font-size:13px;color:#001634;"><?= htmlspecialchars($lecturer['phone'] ?? $_SESSION['phone'] ?? '—') ?></div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ═══════════ ROW 2: TEACHING ASSIGNMENT + SECURITY ═════════ -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;align-items:start;">

    <!-- Teaching assignment -->
    <div class="lcard" style="padding:24px;">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;">
            <div style="font-size:15px;font-weight:700;color:#001634;">
                📚 Phân công giảng dạy &amp; Học phần phụ trách
            </div>
            <?php if ($currentSem): ?>
            <span class="lpill lpill--blue" style="font-size:11px;">
                HK<?= htmlspecialchars($currentSem['semester_name']) ?>
                (<?= htmlspecialchars($currentSem['academic_year']) ?>)
            </span>
            <?php endif; ?>
        </div>

        <!-- Progress bar -->
        <?php $totalHrs = $totalCredits * 15; $target = 180; $pct = round($totalHrs/$target*100); ?>
        <div style="margin-bottom:14px;">
            <div style="display:flex;justify-content:space-between;margin-bottom:4px;">
                <span style="font-size:12px;color:#64748b;">Tiến độ điền mức giờ giảng năm học</span>
                <span style="font-size:12px;font-weight:700;color:#001634;"><?= $totalHrs ?> / <?= $target ?> giờ (<?= $pct ?>%)</span>
            </div>
            <div style="height:8px;background:#e5eeff;border-radius:9999px;overflow:hidden;">
                <div style="height:100%;width:<?= $pct ?>%;background:#fdcc35;border-radius:9999px;"></div>
            </div>
            <div style="font-size:11px;color:#94a3b8;margin-top:3px;">
                Đã hoàn thành: <?= $pct ?>% kế hoạch khoa học &nbsp;•&nbsp;
                Còn lại: <?= max(0,45 - $totalCredits) ?> giờ (điều kiện hoàn trả HK2)
            </div>
        </div>

        <!-- Course list -->
        <?php if (empty($sections)): ?>
        <div class="lec-empty"><p>Chưa có học phần nào trong học kỳ này.</p></div>
        <?php else: ?>
        <?php foreach ($sections as $i => $s): ?>
        <div style="display:flex;align-items:center;gap:12px;padding:10px 12px;border:1px solid #e2e8f0;border-radius:8px;margin-bottom:8px;">
            <div style="width:28px;height:28px;border-radius:50%;background:#e5eeff;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:800;color:#1e40af;flex-shrink:0;">
                <?= str_pad($i+1,'2','0',STR_PAD_LEFT) ?>
            </div>
            <div style="flex:1;min-width:0;">
                <div style="font-size:12px;font-weight:700;color:#3b82f6;"><?= htmlspecialchars($s['section_code']) ?></div>
                <div style="font-size:13px;font-weight:700;color:#001634;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                    <?= htmlspecialchars($s['course_name']) ?>
                </div>
                <div style="font-size:11px;color:#64748b;">
                    <?= (int)$s['credits'] ?> TC &nbsp;•&nbsp; Lớp: <?= htmlspecialchars($s['section_code']) ?>
                    (<?= (int)$s['enrolled_count'] ?> SV)
                </div>
            </div>
            <span class="lpill lpill--green" style="font-size:10px;flex-shrink:0;">● Giảng dạy</span>
            <a href="classes.php?section=<?= (int)$s['section_id'] ?>" style="font-size:12px;color:#3b82f6;flex-shrink:0;">🔗</a>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- Security + notifications column -->
    <div style="display:flex;flex-direction:column;gap:16px;">

        <!-- Password change -->
        <div class="lcard" style="padding:24px;" id="pwSection">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;">
                <div style="font-size:15px;font-weight:700;color:#001634;">🔐 Bảo mật tài khoản &amp; Đăng nhập</div>
                <span class="lpill lpill--gray" style="font-size:10px;">2FA: Bật</span>
            </div>
            <div style="margin-bottom:12px;padding:10px 12px;background:#f0fdf4;border:1px solid #86efac;border-radius:8px;font-size:12px;color:#166534;">
                🔒 Xác thực hai yếu tố (2FA): Đã liên kết ứng dụng Authenticator &amp; SMS
            </div>
            <form method="POST" style="display:flex;flex-direction:column;gap:10px;">
                <div class="lec-profile-field">
                    <label>Mật khẩu hiện tại</label>
                    <input type="password" name="current_password" placeholder="Nhập mật khẩu đang dùng" />
                </div>
                <div class="lec-profile-field">
                    <label>Mật khẩu mới</label>
                    <input type="password" name="new_password" minlength="6"
                           placeholder="Tối thiểu 8 ký tự gồm chữ, số &amp; ký tự đặc biệt" />
                </div>
                <div class="lec-profile-field">
                    <label>Xác nhận mật khẩu mới</label>
                    <input type="password" name="confirm_password" minlength="6" placeholder="Nhập lại mật khẩu mới vào đặt" />
                </div>
                <button type="submit" name="change_password" class="lbtn lbtn--primary">🔑 Cập nhật mật khẩu</button>
            </form>
            <div style="margin-top:10px;padding:8px 10px;background:#f8fafc;border-radius:6px;font-size:11px;color:#64748b;">
                Phiên đăng nhập gần nhất: Hôm nay 07:15 &nbsp;•&nbsp;
                Chrome macOS &nbsp;•&nbsp; Cụm máy VNU Cầu Giấy (118.xxx)
            </div>
        </div>

        <!-- Notification preferences -->
        <div class="lcard" style="padding:24px;">
            <div style="font-size:15px;font-weight:700;color:#001634;margin-bottom:14px;">
                🔔 Cài đặt thông báo học vụ
                <span class="lpill lpill--gray" style="font-size:10px;margin-left:6px;">Lưu tùy chọn</span>
            </div>
            <div style="display:flex;flex-direction:column;gap:12px;">
                <?php $notifications = [
                    ['checked'=>true, 'label'=>'Nhận email khi sinh viên nộp bài tập mới',
                     'sub'=>'Gửi thông báo tổng hợp về bài tập vào các lớp INS3065, INS3064'],
                    ['checked'=>true, 'label'=>'Cảnh báo khẩn khi sinh viên chạm ngưỡng cấm thi',
                     'sub'=>'Sinh viên vắng quá 30% tổng số tiết theo quy chế đào tạo', 'urgent'=>true],
                    ['checked'=>true, 'label'=>'Nhắc nhở chấm bài trước hạn 48 giờ',
                     'sub'=>'Hệ thống sẽ thông báo đầy đủ về đảm bảo tiến độ trả điểm'],
                    ['checked'=>false, 'label'=>'Báo cáo tổng hợp chuyên cần hàng tuần',
                     'sub'=>'Tự xuất và tải xuống tự động máy chủ vào sáng Thứ Hai'],
                ]; ?>
                <?php foreach ($notifications as $notif): ?>
                <div style="display:flex;align-items:flex-start;gap:10px;padding:10px;background:#f8fafc;border-radius:8px;<?= ($notif['urgent']??false)?'background:#fff5f5;border:1px solid #fecaca;':'' ?>">
                    <input type="checkbox" <?= $notif['checked']?'checked':'' ?>
                           style="width:16px;height:16px;margin-top:2px;flex-shrink:0;accent-color:#3b82f6;" />
                    <div>
                        <div style="font-size:13px;font-weight:600;color:#001634;">
                            <?php if ($notif['urgent']??false): ?>
                            <span style="color:#ef4444;">🚨</span>
                            <?php endif; ?>
                            <?= htmlspecialchars($notif['label']) ?>
                        </div>
                        <div style="font-size:11px;color:#64748b;margin-top:2px;"><?= htmlspecialchars($notif['sub']) ?></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- System settings -->
        <div class="lcard" style="padding:24px;">
            <div style="font-size:15px;font-weight:700;color:#001634;margin-bottom:14px;">⚙️ Tùy chọn hệ thống</div>
            <div style="display:flex;gap:8px;margin-bottom:14px;">
                <button class="lbtn lbtn--outline lbtn--sm" style="flex:1;justify-content:center;">🌐 Ngôn ngữ hiển thị</button>
                <button class="lbtn lbtn--primary lbtn--sm" style="flex:1;justify-content:center;">🇻🇳 Tiếng Việt (Mặc định)</button>
            </div>
            <a href="?logout=1" class="lbtn lbtn--red" style="width:100%;justify-content:center;">
                🔴 Đăng xuất khỏi hệ thống
            </a>
        </div>

    </div>
</div>

<?php require __DIR__ . '/_layout_end.php'; ?>
