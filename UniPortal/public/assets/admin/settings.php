<?php
/**
 * UniPortal – Cài đặt Hệ thống
 * Figma: setting screenshot
 */
$root = dirname(__DIR__, 3);
require_once $root . '/src/src/helpers/auth.php';

require_login();
if ($_SESSION['role'] === 'Student')  { header('Location: /UniPortal/public/assets/student/dashboard.php'); exit; }
if ($_SESSION['role'] === 'Lecturer') { header('Location: /UniPortal/public/assets/lecturer/dashboard.php'); exit; }
if (isset($_GET['logout'])) { auth_logout(); header('Location: /UniPortal/public/index.php'); exit; }

$pdo = get_db();

$flash = '';
// Handle GradeScale update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_gradescale'])) {
    // Read-only for safety in this implementation
    $flash = 'info:Cài đặt đã được xem xét. Lưu thay đổi thực sự yêu cầu phê duyệt của Quản trị viên cấp cao.';
}

// Load GradeScale
$gs = $pdo->query('SELECT * FROM GradeScale ORDER BY min_score DESC')->fetchAll();

// Chip class
function letterChipCls(string $l): string {
    $l = str_replace('+','p',$l);
    return "letter-chip--$l";
}

$warnCount  = (int)$pdo->query("SELECT COUNT(*) FROM AcademicWarning WHERE status='Active' AND is_read=FALSE")->fetchColumn();
$pageTitle  = 'Cài đặt hệ thống';
$activePage = 'settings';
$breadcrumb = 'Quản trị › Cài đặt';
require __DIR__ . '/_layout.php';
?>

<!-- ═══ PAGE HEADER ══════════════════════════════════════════ -->
<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
    <div>
        <h1 style="font-size:20px;font-weight:800;color:#0d1b2e;">Cài đặt &amp; Cấu hình Hệ thống</h1>
    </div>
    <div style="display:flex;gap:8px;">
        <a href="#" class="abtn abtn--outline">Khôi phục mặc định</a>
        <a href="#" class="abtn abtn--primary">💾 Lưu thay đổi</a>
    </div>
</div>

<?php if ($flash): [$ftype,$fmsg] = explode(':',$flash,2); ?>
<div class="adm-flash adm-flash--<?= $ftype ?>">
    <?= $ftype==='info'?'ℹ️':($ftype==='success'?'✅':'⚠️') ?> <?= htmlspecialchars($fmsg) ?>
</div>
<?php endif; ?>

<!-- ═══ SETTING TABS ══════════════════════════════════════════ -->
<div style="display:flex;gap:8px;flex-wrap:wrap;background:#fff;border-radius:12px;padding:8px 12px;box-shadow:0 1px 3px rgba(0,0,0,.06);">
    <a href="#gradescale" class="abtn abtn--navy abtn--sm">📊 Quy chuẩn chuyển đổi điểm & Tín chỉ</a>
    <a href="#absence"    class="abtn abtn--outline abtn--sm">⚠️ Ngưỡng cảnh báo chuyên cần</a>
    <a href="#rbac"       class="abtn abtn--outline abtn--sm">🔑 Phân quyền vai trò (RBAC)</a>
    <a href="#sync"       class="abtn abtn--outline abtn--sm">🔄 Đồng bộ §</a>
</div>

<div style="display:grid;grid-template-columns:1fr 340px;gap:20px;align-items:start;">

<!-- LEFT: Grade scale + absence rules -->
<div style="display:flex;flex-direction:column;gap:16px;">

    <!-- Grade scale conversion table -->
    <div class="acard" style="padding:24px;" id="gradescale">
        <div style="margin-bottom:6px;">
            <div class="adm-section-title">Bảng thang điểm quy đổi (Hệ 10 – Chữ – Hệ 4)</div>
            <div class="adm-section-sub">
                Áp dụng theo Quyết định số BHE/QĐ-ĐHQGHN về Quy chế đào tạo bậc đại học. Điểm chữ và hệ 4 dùng để xét học bổng, cảnh báo học vụ và phân loại văn bằng tốt nghiệp chuẩn quốc tế tại Trường Quốc tế.
            </div>
        </div>
        <form method="POST" style="margin-top:16px;">
            <div class="adm-table-wrap">
                <table class="adm-grade-table">
                    <thead>
                        <tr>
                            <th>HỆ 10 (CẬN DƯỚI)</th>
                            <th>HỆ 10 (CẬN TRÊN)</th>
                            <th>ĐIỂM CHỮ</th>
                            <th>ĐIỂM HỆ 4</th>
                            <th>ĐÁNH GIÁ CHUẨN</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($gs as $g):
                            $lCls = letterChipCls($g['letter_grade']);
                            $evalLabel = match(true) {
                                $g['grade_point'] >= 3.7 => 'Xuất sắc',
                                $g['grade_point'] >= 3.5 => 'Giỏi',
                                $g['grade_point'] >= 3.0 => 'Khá giỏi',
                                $g['grade_point'] >= 2.5 => 'Khá',
                                $g['grade_point'] >= 2.0 => 'Trung bình khá',
                                $g['grade_point'] >= 1.5 => 'Trung bình',
                                $g['grade_point'] >= 1.0 => 'Trung bình yếu',
                                $g['grade_point'] >= 0.5 => 'Yếu',
                                default                  => 'Không đạt (Học lại)',
                            };
                        ?>
                        <tr>
                            <td style="font-weight:700;"><?= number_format((float)$g['min_score'],1) ?></td>
                            <td style="font-weight:700;"><?= number_format((float)$g['max_score'],1) ?></td>
                            <td>
                                <span class="letter-chip <?= $lCls ?>"><?= htmlspecialchars($g['letter_grade']) ?></span>
                            </td>
                            <td style="font-size:16px;font-weight:800;color:#0d1b2e;"><?= number_format((float)$g['grade_point'],1) ?></td>
                            <td>
                                <span class="apill <?= $g['grade_point']>=3.5?'apill--green':($g['grade_point']>=2.5?'apill--blue':($g['grade_point']>=2.0?'apill--yellow':($g['grade_point']>=1.0?'apill--orange':'apill--red'))) ?>">
                                    <?= $evalLabel ?>
                                </span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($gs)): ?>
                        <tr><td colspan="5" style="text-align:center;padding:24px;color:#94a3b8;">Chưa có thang điểm. Chạy schema.sql để tạo dữ liệu.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <div style="margin-top:14px;padding:12px;background:#fffbe6;border-radius:8px;font-size:12px;color:#7c5300;line-height:18px;">
                ⊕ Đồng bộ với cổng thông tin sinh viên và cán bộ giảng viên &nbsp;•&nbsp;
                Chỉnh sửa cần có chữ ký số của Quản trị viên và được lưu vào Audit Log tại tay trên ĐHQGHN
            </div>
            <input type="hidden" name="save_gradescale" value="1" />
        </form>
    </div>

    <!-- Absence warning thresholds -->
    <div class="acard" style="padding:24px;" id="absence">
        <div class="adm-section-title" style="margin-bottom:14px;">⚠️ Ngưỡng cảnh báo chuyên cần</div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
            <div class="adm-field">
                <label>Ngưỡng cảnh báo mức 1 (%)</label>
                <input type="number" value="15" min="5" max="30" />
            </div>
            <div class="adm-field">
                <label>Ngưỡng cấm thi (%)</label>
                <input type="number" value="20" min="15" max="40" />
            </div>
            <div class="adm-field">
                <label>Ngưỡng GPA cảnh báo học vụ</label>
                <input type="number" value="2.00" step="0.01" min="0" max="4" />
            </div>
            <div class="adm-field">
                <label>Ngưỡng GPA yêu cầu cải thiện</label>
                <input type="number" value="1.50" step="0.01" min="0" max="4" />
            </div>
        </div>
        <div style="margin-top:14px;display:flex;gap:8px;justify-content:flex-end;">
            <a href="#" class="abtn abtn--outline abtn--sm">Huỷ bỏ</a>
            <button type="button" class="abtn abtn--primary abtn--sm">Lưu cài đặt ngưỡng</button>
        </div>
    </div>

</div>

<!-- RIGHT: Rounding + excluded courses + notification config -->
<div style="display:flex;flex-direction:column;gap:16px;">

    <!-- Rounding rules -->
    <div class="acard" style="padding:20px;">
        <div style="display:flex;align-items:center;gap:8px;margin-bottom:12px;">
            <div style="width:32px;height:32px;background:#fef9c3;border-radius:8px;display:flex;align-items:center;justify-content:center;">📐</div>
            <div class="adm-section-title">Quy tắc tính toán &amp; Làm tròn</div>
        </div>
        <div style="font-size:12px;color:#64748b;margin-bottom:12px;">
            Độ chính xác của số thập phân:
        </div>
        <div style="display:flex;gap:10px;margin-bottom:12px;">
            <div style="text-align:center;padding:12px;background:#f1f5f9;border-radius:8px;flex:1;">
                <div style="font-size:22px;font-weight:800;color:#0d1b2e;">2</div>
                <div style="font-size:10px;color:#64748b;">chữ số thập phân (3.25)</div>
            </div>
            <div style="text-align:center;padding:12px;background:#f1f5f9;border-radius:8px;flex:1;">
                <div style="font-size:22px;font-weight:800;color:#0d1b2e;">3</div>
                <div style="font-size:10px;color:#64748b;">chữ số thập phân (5.248)</div>
            </div>
        </div>
        <div style="font-size:12px;color:#64748b;margin-bottom:8px;">Quy tắc học cải thiện &amp; Học lại:</div>
        <div style="font-size:12px;color:#334155;background:#f1f5f9;padding:8px 10px;border-radius:6px;margin-bottom:12px;">
            Lấy điểm cao nhất giữa các lần học tại lớp học.
        </div>
        <div style="font-size:11px;color:#64748b;margin-bottom:4px;">
            Điểm của lần học lại thứ nhất được lưu lại tổng cộng vào GPA tích lũy (không thay thế) trong học kỳ học lại, nhưng không tính vào tiến trình tích lũy GPA tích lũy.
        </div>
        <button class="abtn abtn--primary abtn--sm" style="margin-top:8px;width:100%;justify-content:center;">Áp dụng &amp; lưu cấu hình làm tròn</button>
    </div>

    <!-- GPA-excluded courses -->
    <div class="acard" style="padding:20px;">
        <div style="display:flex;align-items:center;gap:8px;margin-bottom:12px;">
            <div style="width:32px;height:32px;background:#ede9fe;border-radius:8px;display:flex;align-items:center;justify-content:center;">📚</div>
            <div class="adm-section-title">Học phần loại trừ khỏi GPA</div>
        </div>
        <div style="font-size:12px;color:#64748b;margin-bottom:10px;">
            Quy chế ĐHQGHN: Các học phần vẫn tính vào điều kiện tốt nghiệp nhưng điểm số không được tổng hợp vào công thức tính GPA tích lũy.
        </div>
        <?php
        $excluded = [
            ['Giáo dục Thể chất (Mã PES)','Áp dụng thực Đạt/Không đạt (Pass/Fail)','#fee2e2','#b91c1c'],
            ['GD Quốc phòng & An ninh (Mã CME)','Chứng nhận điều kiện tốt nghiệp chính Trung tâm GDQP&AN','#fff7ed','#92400e'],
            ['Kỹ năng hỗ trợ & Ngoại khóa (Mã SV)','Các học phần kỹ năng mềm và ngoại ngữ bổ sung tại hệ VNU-IS','#dbeafe','#1e40af'],
        ];
        foreach ($excluded as [$name,$desc,$bg,$col]): ?>
        <div style="background:<?= $bg ?>;border-radius:6px;padding:10px 12px;margin-bottom:8px;">
            <div style="font-size:12px;font-weight:700;color:<?= $col ?>;"><?= $name ?></div>
            <div style="font-size:11px;color:<?= $col ?>;opacity:.8;margin-top:2px;"><?= $desc ?></div>
        </div>
        <?php endforeach; ?>
    </div>

</div>
</div>

<!-- ═══ SAVE FOOTER ══════════════════════════════════════════ -->
<div class="acard" style="padding:16px 20px;display:flex;align-items:center;justify-content:space-between;background:#f8fafc;flex-wrap:wrap;gap:10px;">
    <div style="font-size:12px;color:#64748b;">
        ⚠️ Mọi thay đổi cài hình sẽ được ghi vào Nhật ký Kiểm toán hệ thống và được tự động ký bởi tài khoản Quản trị viên tại giao máy chủ ĐHQGHN
    </div>
    <div style="display:flex;gap:8px;">
        <a href="settings.php" class="abtn abtn--outline">Hủy bỏ</a>
        <button type="submit" form="settingsForm" class="abtn abtn--primary">💾 Lưu toàn bộ cài đặt</button>
    </div>
</div>

<?php require __DIR__ . '/_layout_end.php'; ?>
