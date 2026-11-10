<?php
/**
 * UniPortal – Quản lý điểm danh (ATT)
 * Figma: att screenshot
 */
$root = dirname(__DIR__, 3);
require_once $root . '/src/src/helpers/auth.php';
require_once $root . '/src/src/models/Lecturer.php';

require_lecturer();
if (isset($_GET['logout'])) { auth_logout(); header('Location: /UniPortal/public/index.php'); exit; }

$lecId      = (int)($_SESSION['lecturer_id'] ?? 0);
$accountId  = (int)($_SESSION['account_id'] ?? 0);
$currentSem = LecturerModel::getCurrentSemester();
$semId      = (int)($currentSem['semester_id'] ?? 0);
$sections   = $semId ? LecturerModel::getSections($lecId, $semId) : [];
$warnCount  = LecturerModel::getUnreadWarningCount($lecId);

// ── Handle attendance save ────────────────────────────────────
$flash = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_attendance'])) {
    csrf_verify();
    $sessionId = (int)($_POST['session_id'] ?? 0);
    $records   = $_POST['att'] ?? [];
    if ($sessionId && !empty($records)) {
        $saved = LecturerModel::saveAttendance($sessionId, $records, $accountId);
        $flash = "success:Đã lưu điểm danh $saved sinh viên thành công.";
    } else {
        $flash = 'error:Vui lòng chọn buổi học và nhập điểm danh.';
    }
}

// ── Selected section & session ────────────────────────────────
$selSection = (int)($_GET['section'] ?? ($sections[0]['section_id'] ?? 0));
$selSession = (int)($_GET['session'] ?? 0);

$selSectionInfo = null;
foreach ($sections as $s) {
    if ((int)$s['section_id'] === $selSection) { $selSectionInfo = $s; break; }
}

$sessions = $selSection ? LecturerModel::getAttendanceSessions($selSection) : [];
if (!$selSession && !empty($sessions)) {
    $selSession = (int)$sessions[0]['session_id'];
}

$selSessionInfo = null;
$rosterRows = [];
if ($selSession && $selSection) {
    foreach ($sessions as $sess) {
        if ((int)$sess['session_id'] === $selSession) { $selSessionInfo = $sess; break; }
    }
    $rosterRows = LecturerModel::getSessionAttendance($selSession, $selSection);
}

// Stats
$presentCount = count(array_filter($rosterRows, fn($r) => $r['att_status'] === 'Present'));
$absentCount  = count(array_filter($rosterRows, fn($r) => $r['att_status'] === 'Absent'));
$lateCount    = count(array_filter($rosterRows, fn($r) => $r['att_status'] === 'Late'));
$excusedCount = count(array_filter($rosterRows, fn($r) => $r['att_status'] === 'Excused'));
$totalSV      = count($rosterRows);
$attRate      = $totalSV > 0 ? round(($presentCount + $lateCount + $excusedCount) / $totalSV * 100, 1) : 0;

$pageTitle  = 'Quản lý điểm danh';
$activePage = 'grading';
require __DIR__ . '/_layout.php';

function initials(string $n): string {
    $r=''; foreach(explode(' ',trim($n)) as $w) $r.=strtoupper(substr($w,0,1));
    return substr($r,-2);
}
?>

<!-- ═══════════ PAGE HEADER ══════════════════════════════════ -->
<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
    <h1 style="font-size:20px;font-weight:800;color:#001634;">Điểm Danh Sinh Viên</h1>
    <div style="display:flex;gap:8px;flex-wrap:wrap;">
        <a href="#" class="lbtn lbtn--outline">📅 Lịch sử</a>
        <a href="#" class="lbtn lbtn--outline">📊 Xuất Excel (.xlsx)</a>
    </div>
</div>

<?php if ($flash): [$ftype, $fmsg] = explode(':', $flash, 2); ?>
<div class="lec-flash lec-flash--<?= $ftype === 'success' ? 'success' : 'error' ?>">
    <?= $ftype === 'success' ? '✅' : '⚠️' ?> <?= htmlspecialchars($fmsg) ?>
</div>
<?php endif; ?>

<!-- ═══════════ SECTION + SESSION SELECTOR ═══════════════════ -->
<div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
    <span style="font-size:13px;font-weight:600;color:#64748b;">Học phần &amp; Lớp:</span>
    <?php foreach ($sections as $s): ?>
    <a href="?section=<?= (int)$s['section_id'] ?>"
       class="lpill <?= (int)$s['section_id'] === $selSection ? 'lpill--blue' : 'lpill--gray' ?>">
        <?= htmlspecialchars($s['section_code']) ?>
    </a>
    <?php endforeach; ?>
    <?php if (!empty($sessions)): ?>
    <span style="color:#e2e8f0;">|</span>
    <span style="font-size:13px;font-weight:600;color:#64748b;">Buổi học:</span>
    <?php foreach ($sessions as $sess): ?>
    <a href="?section=<?= (int)$selSection ?>&session=<?= (int)$sess['session_id'] ?>"
       class="lpill <?= (int)$sess['session_id'] === $selSession ? 'lpill--blue' : 'lpill--gray' ?>">
        <?= date('d/m', strtotime($sess['session_date'])) ?>
        <?= date('H:i', strtotime($sess['start_time'])) ?>
    </a>
    <?php endforeach; ?>
    <?php endif; ?>
</div>

<?php if ($selSectionInfo && $selSessionInfo): ?>

<!-- ═══════════ SESSION INFO BAR ═════════════════════════════ -->
<div class="lec-section-banner">
    <div class="lec-section-banner__info">
        <div style="display:flex;align-items:center;gap:8px;margin-bottom:4px;">
            <span style="font-size:11px;font-weight:700;background:rgba(253,204,53,.3);color:#fff;padding:2px 8px;border-radius:4px;">
                Ngày giảng dạy: <?= date('d/m/Y', strtotime($selSessionInfo['session_date'])) ?>
            </span>
            <?php if ($selSessionInfo['recorded_count'] < 1): ?>
            <span class="lpill" style="background:rgba(239,68,68,.2);color:#fca5a5;font-size:10px;">Chưa chốt sổ điểm danh</span>
            <?php else: ?>
            <span class="lpill" style="background:rgba(34,197,94,.2);color:#86efac;font-size:10px;">Đã chốt</span>
            <?php endif; ?>
        </div>
        <span class="lec-section-banner__code"><?= htmlspecialchars($selSectionInfo['section_code']) ?></span>
        <span class="lec-section-banner__name"><?= htmlspecialchars($selSectionInfo['course_name']) ?></span>
        <div class="lec-section-banner__meta">
            <span>📚 Học phần &amp; Lớp: <?= htmlspecialchars($selSectionInfo['section_code']) ?></span>
            <span>🕐 Khung giờ &amp; Địa điểm: <?= date('H:i', strtotime($selSessionInfo['start_time'])) ?>–<?= date('H:i', strtotime($selSessionInfo['end_time'])) ?> • <?= htmlspecialchars($selSectionInfo['room'] ?? 'TBA') ?></span>
            <span>📋 Trạng thái sổ điểm danh: <?= $selSessionInfo['recorded_count'] > 0 ? 'Đã chốt' : 'Chưa chốt sổ điểm danh' ?></span>
        </div>
    </div>
    <div class="lec-section-banner__actions">
        <a href="?section=<?= (int)$selSection ?>&session=<?= (int)$selSession ?>" class="lbtn lbtn--outline" style="color:#fff;border-color:rgba(255,255,255,.3);">Đổi ca giảng khác</a>
    </div>
</div>

<!-- ═══════════ STAT CARDS ════════════════════════════════════ -->
<div style="display:grid;grid-template-columns:52px 1fr 1fr 1fr 1fr 200px;gap:16px;align-items:start;">
    <div class="lcard" style="padding:16px 12px;text-align:center;">
        <div style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.3px;margin-bottom:4px;">Sĩ số lớp</div>
        <img src="../img/db-icon-credits.svg" alt="" style="width:20px;margin:4px auto;" />
        <div style="font-size:26px;font-weight:800;color:#001634;"><?= $totalSV ?></div>
        <div style="font-size:11px;color:#94a3b8;">sinh viên</div>
    </div>
    <div class="lcard" style="padding:16px;border-left:4px solid #22c55e;">
        <div style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;margin-bottom:4px;">Có mặt ✅</div>
        <div style="font-size:32px;font-weight:800;color:#166534;"><?= $presentCount ?></div>
        <div style="font-size:12px;color:#94a3b8;"><?= $attRate ?>%</div>
        <div style="height:4px;background:#dcfce7;border-radius:9999px;margin-top:6px;overflow:hidden;">
            <div style="height:100%;width:<?= $attRate ?>%;background:#22c55e;border-radius:9999px;"></div>
        </div>
    </div>
    <div class="lcard" style="padding:16px;border-left:4px solid #ef4444;">
        <div style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;margin-bottom:4px;">Vắng không phép ❌</div>
        <div style="font-size:32px;font-weight:800;color:#b91c1c;"><?= $absentCount ?></div>
        <div style="font-size:12px;color:#94a3b8;"><?= $totalSV > 0 ? round($absentCount/$totalSV*100,1) : 0 ?>%</div>
    </div>
    <div class="lcard" style="padding:16px;border-left:4px solid #6366f1;">
        <div style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;margin-bottom:4px;">Vắng có phép 📝</div>
        <div style="font-size:32px;font-weight:800;color:#4338ca;"><?= $excusedCount ?></div>
        <div style="font-size:12px;color:#94a3b8;"><?= $totalSV > 0 ? round($excusedCount/$totalSV*100,1) : 0 ?>%</div>
    </div>
    <div class="lcard" style="padding:16px;border-left:4px solid #f59e0b;">
        <div style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;margin-bottom:4px;">Đi muộn ⏰</div>
        <div style="font-size:32px;font-weight:800;color:#92400e;"><?= $lateCount ?></div>
        <div style="font-size:12px;color:#94a3b8;"><?= $totalSV > 0 ? round($lateCount/$totalSV*100,1) : 0 ?>%</div>
    </div>
    <div class="lcard" style="padding:16px;">
        <div style="font-size:12px;font-weight:700;color:#001634;margin-bottom:8px;">⚡ Tác vụ nhanh</div>
        <div style="display:flex;flex-direction:column;gap:5px;">
            <button onclick="markAll('Present')" class="lbtn lbtn--green lbtn--sm">✅ Điểm danh tất cả có mặt</button>
            <button onclick="" class="lbtn lbtn--outline lbtn--sm">📷 Quét mã QR tự động</button>
        </div>
    </div>
</div>

<!-- ═══════════ STATUS TABS ═══════════════════════════════════ -->
<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;">
    <div class="lec-tabs">
        <button class="lec-tab active">Tất cả (<?= $totalSV ?>)</button>
        <button class="lec-tab">Có mặt (<?= $presentCount ?>)</button>
        <button class="lec-tab">Vắng không phép (<?= $absentCount ?>)</button>
        <button class="lec-tab">Vắng có phép (<?= $excusedCount ?>)</button>
        <button class="lec-tab">Đi muộn (<?= $lateCount ?>)</button>
    </div>
    <div style="position:relative;">
        <img src="../img/kqht-icon-search.svg" alt="" style="position:absolute;left:10px;top:50%;transform:translateY(-50%);width:13px;opacity:.4;" />
        <input type="search" id="attSearch" placeholder="Tìm tên sinh viên, MSSV..."
               style="width:220px;height:34px;background:#f1f5f9;border:none;border-radius:8px;padding:0 12px 0 30px;font-size:13px;outline:none;" />
    </div>
</div>

<!-- ═══════════ ATTENDANCE ROSTER ════════════════════════════ -->
<form method="POST" id="attForm">
    <?= csrf_input() ?>
    <input type="hidden" name="save_attendance" value="1" />
    <input type="hidden" name="session_id" value="<?= (int)$selSession ?>" />

    <div class="lcard" style="padding:0;overflow:hidden;">
        <div class="lec-table-wrap">
            <table class="lec-table" id="attTable">
                <thead>
                    <tr>
                        <th style="width:44px;text-align:center;">STT</th>
                        <th>Học viên & MSSV</th>
                        <th style="width:260px;">Điểm danh buổi <?= (int)$selSession ?> (Hôm nay)</th>
                        <th>Ghi chú ca học</th>
                        <th>Tỷ lệ vắng</th>
                        <th>Chuyên cần môn</th>
                        <th>Rủi ro học vụ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($rosterRows)): ?>
                    <tr><td colspan="7" style="text-align:center;padding:40px;color:#94a3b8;font-style:italic;">
                        Chưa có danh sách sinh viên cho buổi học này.
                    </td></tr>
                    <?php else: ?>
                    <?php foreach ($rosterRows as $i => $row):
                        $ini      = initials($row['full_name']);
                        $curAtt   = $row['att_status'] ?? '';
                        $totAbs   = (int)$row['total_absent'];
                        $totSess  = max(1,(int)$row['total_sessions']);
                        $absPct   = round($totAbs/$totSess*100, 1);
                        $attPct   = round((1 - $totAbs/$totSess)*100, 1);
                        $barCls   = $attPct >= 90 ? 'ok' : ($attPct >= 80 ? 'warn' : 'crit');
                        $riskLbl  = $attPct < 80 ? '🚨 Cấm thi' : ($attPct < 90 ? '⚠️ Cần nhắc nhở' : '✅ An toàn');
                        $riskCls  = $attPct < 80 ? 'lpill--red' : ($attPct < 90 ? 'lpill--orange' : 'lpill--green');
                    ?>
                    <tr style="<?= $absPct >= 20 ? 'background:#fff5f5;' : '' ?>">
                        <td style="text-align:center;font-size:12px;color:#94a3b8;font-weight:<?= $absPct>=20?'800':'400' ?>;color:<?= $absPct>=20?'#ef4444':'#94a3b8' ?>;">
                            <?= str_pad($i+1,2,'0',STR_PAD_LEFT) ?>
                        </td>
                        <td>
                            <div style="display:flex;align-items:center;gap:8px;">
                                <span class="lec-av" style="width:32px;height:32px;"><?= htmlspecialchars($ini) ?></span>
                                <div>
                                    <div style="font-weight:700;font-size:13px;color:<?= $absPct>=20?'#b91c1c':'#001634' ?>;">
                                        <?= htmlspecialchars($row['full_name']) ?>
                                        <?php if ($absPct>=20): ?><span style="color:#ef4444;font-size:10px;"> △</span><?php endif; ?>
                                    </div>
                                    <div style="font-size:11px;color:#64748b;"><?= htmlspecialchars($row['student_id']) ?></div>
                                </div>
                            </div>
                        </td>
                        <td>
                            <div class="att-radio-group">
                                <?php
                                $statuses = ['Present'=>'Đi muộn','Absent'=>'Vắng không phép','Excused'=>'Vắng có phép'];
                                // Figma shows "Di muon / Co mat / Vang" so map them
                                $statuses2 = [
                                    'Late'    => ['label'=>'Đi muộn',    'css'=>'late'],
                                    'Present' => ['label'=>'Có mặt',     'css'=>'present'],
                                    'Absent'  => ['label'=>'Vắng mặt',   'css'=>'absent'],
                                ];
                                foreach ($statuses2 as $val => $info): ?>
                                <input type="radio"
                                       id="att_<?= (int)$row['enrollment_id'] ?>_<?= $val ?>"
                                       name="att[<?= (int)$row['enrollment_id'] ?>][status]"
                                       value="<?= $val ?>"
                                       <?= $curAtt === $val ? 'checked' : '' ?>
                                       <?= (!$curAtt && $val === 'Present') ? 'checked' : '' ?> />
                                <label for="att_<?= (int)$row['enrollment_id'] ?>_<?= $val ?>"
                                       class="<?= $info['css'] ?>"><?= $info['label'] ?></label>
                                <?php endforeach; ?>
                            </div>
                        </td>
                        <td>
                            <input type="text"
                                   name="att[<?= (int)$row['enrollment_id'] ?>][note]"
                                   value="<?= htmlspecialchars($row['note'] ?? '') ?>"
                                   placeholder="Nhập ghi chú..."
                                   style="width:160px;height:30px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;padding:0 8px;font-size:12px;outline:none;" />
                        </td>
                        <td>
                            <div style="font-size:12px;font-weight:700;color:<?= $absPct>=20?'#b91c1c':'#44474e' ?>;">
                                <?= $totAbs ?>/<?= $totSess ?> buổi
                            </div>
                            <div style="font-size:11px;color:#94a3b8;">(<?= $absPct ?>% vắng)</div>
                        </td>
                        <td>
                            <div class="att-bar-wrap" style="min-width:80px;">
                                <div class="att-bar">
                                    <div class="att-bar__fill att-bar__fill--<?= $barCls ?>" style="width:<?= $attPct ?>%"></div>
                                </div>
                                <span class="att-pct att-pct--<?= $barCls ?>"><?= $attPct ?>%</span>
                            </div>
                            <div style="font-size:11px;color:#94a3b8;margin-top:2px;">Xuất sắc</div>
                        </td>
                        <td><span class="lpill <?= $riskCls ?>" style="font-size:11px;"><?= $riskLbl ?></span></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div style="padding:14px 16px;display:flex;align-items:center;justify-content:space-between;border-top:1px solid #f1f5f9;background:#f8fafc;flex-wrap:wrap;gap:10px;">
            <div style="font-size:12px;color:#64748b;">
                <span style="color:#f59e0b;">● Trạng thái dữ liệu ca học: Chưa lưu bản ghi buổi <?= (int)$selSession ?></span><br/>
                <span style="font-size:11px;color:#94a3b8;">Lần cuối đồng bộ máy chủ UB: 07:16 sáng nay</span>
            </div>
            <div style="display:flex;gap:8px;">
                <button type="submit" name="action" value="draft" class="lbtn lbtn--outline">Huỷ thay đổi</button>
                <button type="submit" class="lbtn lbtn--primary">✅ Lưu dữ liệu điểm danh buổi <?= (int)$selSession ?></button>
            </div>
        </div>
    </form>
</div>

<?php else: ?>
<div class="lcard">
    <div class="lec-empty">
        <div class="lec-empty__icon">📋</div>
        <p>
            <?php if (empty($sections)): ?>
            Chưa có lớp học phần nào trong học kỳ hiện tại.
            <?php elseif (empty($sessions)): ?>
            Chưa có buổi học nào được tạo cho lớp này. Các buổi học sẽ hiển thị khi được thêm vào lịch giảng dạy.
            <?php else: ?>
            Chọn lớp và buổi học ở trên để bắt đầu điểm danh.
            <?php endif; ?>
        </p>
    </div>
</div>
<?php endif; ?>

<script>
function markAll(status) {
    document.querySelectorAll('input[type=radio][value="' + status + '"]').forEach(function(r) {
        r.checked = true;
    });
}
document.getElementById('attSearch')?.addEventListener('input', function() {
    var q = this.value.toLowerCase();
    document.querySelectorAll('#attTable tbody tr').forEach(function(tr) {
        tr.style.display = tr.textContent.toLowerCase().includes(q) ? '' : 'none';
    });
});
</script>

<?php require __DIR__ . '/_layout_end.php'; ?>
