<?php
/**
 * UniPortal – Lịch giảng dạy (Lecturer Schedule)
 * Shows weekly/full session list for assigned sections.
 */
$root = dirname(__DIR__, 3);
require_once $root . '/src/src/helpers/auth.php';
require_once $root . '/src/src/models/Lecturer.php';

require_lecturer();
if (isset($_GET['logout'])) { auth_logout(); header('Location: /UniPortal/public/index.php'); exit; }

$lecId      = (int)($_SESSION['lecturer_id'] ?? 0);
$accountId  = (int)($_SESSION['account_id'] ?? 0);
$pdo        = get_db();
$currentSem = LecturerModel::getCurrentSemester();
$semId      = (int)($currentSem['semester_id'] ?? 0);
$sections   = $semId ? LecturerModel::getSections($lecId, $semId) : [];
$warnCount  = LecturerModel::getUnreadWarningCount($lecId);

// ── POST: create a new attendance session ─────────────────────
$flash = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['_action'] ?? '';

    if ($action === 'add_session') {
        $secId  = (int)($_POST['section_id'] ?? 0);
        $secId  = (int)($_POST['section_id'] ?? 0);
        $date   = trim($_POST['session_date'] ?? '');
        $start  = trim($_POST['start_time']   ?? '');
        $end    = trim($_POST['end_time']     ?? '');
        $topic  = trim($_POST['topic']        ?? '');

        // Verify lecturer owns this section
        $owns = false;
        foreach ($sections as $s) { if ((int)$s['section_id']===$secId) { $owns=true; break; } }

        if (!$owns || !$date || !$start || !$end) {
            $flash = 'error:Vui lòng điền đầy đủ thông tin buổi học.';
        } elseif ($end <= $start) {
            $flash = 'error:Giờ kết thúc phải sau giờ bắt đầu.';
        } else {
            try {
                $pdo->prepare(
                    'INSERT INTO AttendanceSession (section_id,session_date,start_time,end_time,topic)
                     VALUES (:sid,:date,:start,:end,:topic)'
                )->execute([':sid'=>$secId,':date'=>$date,':start'=>$start,':end'=>$end,':topic'=>$topic]);
                $flash = 'success:Đã tạo buổi học mới.';
            } catch (Exception $e) {
                $flash = 'error:Buổi học đã tồn tại hoặc lỗi: '.$e->getMessage();
            }
        }
    }
    if ($action === 'delete_session') {
        $sessId = (int)($_POST['session_id'] ?? 0);
        // Safety: only delete if it belongs to one of the lecturer's sections
        $chk = $pdo->prepare(
            'SELECT 1 FROM AttendanceSession ats JOIN ClassSection cs ON cs.section_id=ats.section_id
             WHERE ats.session_id=:s AND cs.lecturer_id=:l LIMIT 1'
        );
        $chk->execute([':s'=>$sessId,':l'=>$lecId]);
        if ($chk->fetch()) {
            // Only allow delete if no attendance recorded
            $hasAtt = $pdo->prepare('SELECT COUNT(*) FROM Attendance WHERE session_id=:s');
            $hasAtt->execute([':s'=>$sessId]);
            if ((int)$hasAtt->fetchColumn() === 0) {
                $pdo->prepare('DELETE FROM AttendanceSession WHERE session_id=:s')->execute([':s'=>$sessId]);
                $flash = 'success:Đã xóa buổi học.';
            } else {
                $flash = 'error:Không thể xóa buổi học đã có dữ liệu điểm danh.';
            }
        } else {
            $flash = 'error:Không có quyền xóa buổi học này.';
        }
    }
}

// ── Selected section filter ───────────────────────────────────
$selSection = (int)($_GET['section'] ?? 0);
if (!$selSection && !empty($sections)) $selSection = (int)$sections[0]['section_id'];

$selSectionInfo = null;
foreach ($sections as $s) { if ((int)$s['section_id']===$selSection) { $selSectionInfo=$s; break; } }

// ── All sessions for this section ────────────────────────────
$allSessions = [];
if ($selSection) {
    $st = $pdo->prepare(
        'SELECT ats.session_id, ats.session_date, ats.start_time, ats.end_time, ats.topic,
                (SELECT COUNT(*) FROM Attendance a WHERE a.session_id=ats.session_id) AS recorded,
                (SELECT COUNT(*) FROM Enrollment e WHERE e.section_id=ats.section_id AND e.status IN (\'Enrolled\',\'Completed\')) AS total_students
         FROM AttendanceSession ats
         WHERE ats.section_id=:sid
         ORDER BY ats.session_date DESC, ats.start_time DESC'
    );
    $st->execute([':sid'=>$selSection]);
    $allSessions = $st->fetchAll();
}

// ── This week's sessions across all sections ──────────────────
$weekSessions = LecturerModel::getWeekSessions($lecId);
$todaySessions = LecturerModel::getTodaySessions($lecId);

// ── Stats ────────────────────────────────────────────────────
$totalSessions = array_sum(array_map(fn($s)=>$s['section_id'], array_fill(0,count($sections),['section_id'=>0])));
$allMySessionCount = (int)$pdo->prepare(
    'SELECT COUNT(*) FROM AttendanceSession ats
     JOIN ClassSection cs ON cs.section_id=ats.section_id
     WHERE cs.lecturer_id=:l'
)->execute([':l'=>$lecId]) ? (function() use ($pdo,$lecId) {
    $s=$pdo->prepare('SELECT COUNT(*) FROM AttendanceSession ats JOIN ClassSection cs ON cs.section_id=ats.section_id WHERE cs.lecturer_id=:l');
    $s->execute([':l'=>$lecId]); return (int)$s->fetchColumn(); })() : 0;

function h($v):string{return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
function fmt_time(string $t):string{return substr($t,0,5);}
$dayNames = ['Mon'=>'T2','Tue'=>'T3','Wed'=>'T4','Thu'=>'T5','Fri'=>'T6','Sat'=>'T7','Sun'=>'CN'];

$pageTitle  = 'Lịch giảng dạy';
$activePage = 'schedule';
require __DIR__ . '/_layout.php';
?>

<!-- Flash -->
<?php if ($flash): [$ft,$fm]=explode(':',$flash,2); ?>
<div style="padding:12px 16px;border-radius:10px;font-weight:600;font-size:13px;margin-bottom:4px;
            background:<?= $ft==='success'?'#d1fae5':'#fee2e2' ?>;border:1px solid <?= $ft==='success'?'#6ee7b7':'#fca5a5' ?>;
            color:<?= $ft==='success'?'#065f46':'#b91c1c' ?>;">
    <?= $ft==='success'?'✅':'⚠️' ?> <?= h($fm) ?>
</div>
<?php endif; ?>

<!-- Page title + add session button -->
<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
    <h1 style="font-size:20px;font-weight:800;color:#001634;">Lịch giảng dạy</h1>
    <button onclick="showModal('modalAddSession')" class="lbtn lbtn--primary">➕ Tạo buổi học mới</button>
</div>

<!-- KPI row -->
<div class="lec-kpi-row">
    <div class="lcard lec-kpi">
        <div class="lec-kpi__top"><span class="lec-kpi__label">Lớp phụ trách</span>
            <span class="lec-kpi__icon lec-kpi__icon--blue"><img src="../img/db-nav-grades.svg" alt="" /></span></div>
        <div class="lec-kpi__value"><span class="lec-kpi__num"><?= count($sections) ?></span><span class="lec-kpi__unit">lớp</span></div>
    </div>
    <div class="lcard lec-kpi">
        <div class="lec-kpi__top"><span class="lec-kpi__label">Tổng buổi học</span>
            <span class="lec-kpi__icon lec-kpi__icon--yellow"><img src="../img/db-icon-schedule-time.svg" alt="" /></span></div>
        <div class="lec-kpi__value"><span class="lec-kpi__num"><?= $allMySessionCount ?></span><span class="lec-kpi__unit">buổi</span></div>
    </div>
    <div class="lcard lec-kpi">
        <div class="lec-kpi__top"><span class="lec-kpi__label">Tuần này</span></div>
        <div class="lec-kpi__value"><span class="lec-kpi__num"><?= count($weekSessions) ?></span><span class="lec-kpi__unit">buổi</span></div>
    </div>
    <div class="lcard lec-kpi">
        <div class="lec-kpi__top"><span class="lec-kpi__label">Hôm nay</span>
            <span class="lec-kpi__icon lec-kpi__icon--green"><img src="../img/db-icon-attendance.svg" alt="" /></span></div>
        <div class="lec-kpi__value"><span class="lec-kpi__num"><?= count($todaySessions) ?></span><span class="lec-kpi__unit">buổi</span></div>
    </div>
</div>

<!-- This week -->
<?php if (!empty($weekSessions)): ?>
<div class="lcard" style="padding:20px 24px;">
    <div style="font-size:16px;font-weight:700;color:#001634;margin-bottom:12px;">📅 Tuần này – <?= date('d/m') ?> đến <?= date('d/m',strtotime('next sunday')) ?></div>
    <div style="display:flex;flex-direction:column;gap:8px;">
        <?php foreach ($weekSessions as $sess):
            $isToday = $sess['session_date'] === date('Y-m-d');
            $dow = $dayNames[date('D',strtotime($sess['session_date']))] ?? date('D',strtotime($sess['session_date']));
        ?>
        <div style="display:flex;gap:12px;align-items:center;padding:12px 16px;background:<?= $isToday?'#eff4ff':'#f8fafc' ?>;border-radius:10px;<?= $isToday?'border-left:4px solid #3b82f6;':'' ?>">
            <div style="flex-shrink:0;text-align:center;min-width:48px;">
                <div style="font-size:11px;font-weight:700;color:<?= $isToday?'#2563eb':'#94a3b8' ?>;text-transform:uppercase;"><?= $dow ?></div>
                <div style="font-size:22px;font-weight:800;color:<?= $isToday?'#2563eb':'#001634' ?>;line-height:1;"><?= date('d',strtotime($sess['session_date'])) ?></div>
            </div>
            <div style="width:1px;background:#e2e8f0;align-self:stretch;flex-shrink:0;"></div>
            <div style="flex:1;min-width:0;">
                <div style="font-weight:700;font-size:13px;color:#001634;"><?= h($sess['course_code']) ?> – <?= h($sess['course_name']) ?></div>
                <div style="font-size:12px;color:#64748b;margin-top:2px;">
                    🕐 <?= fmt_time($sess['start_time']) ?>–<?= fmt_time($sess['end_time']) ?>
                    <?php if ($sess['room']): ?> &nbsp;📍 <?= h($sess['room']) ?><?php endif; ?>
                    &nbsp;👥 <?= (int)$sess['enrolled_count'] ?> sinh viên
                    <?php if ($sess['topic']): ?> &nbsp;📝 <?= h($sess['topic']) ?><?php endif; ?>
                </div>
            </div>
            <div style="display:flex;gap:6px;flex-shrink:0;">
                <a href="grading.php?section=<?= (int)$sess['section_id'] ?>&session=<?= (int)$sess['session_id'] ?>"
                   class="lbtn lbtn--yellow lbtn--sm">✅ Điểm danh</a>
                <a href="classes.php?section=<?= (int)$sess['section_id'] ?>" class="lbtn lbtn--outline lbtn--sm">Chi tiết</a>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- Section selector + session list -->
<?php if (!empty($sections)): ?>
<div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
    <span style="font-size:13px;font-weight:600;color:#64748b;">Xem theo lớp:</span>
    <?php foreach ($sections as $s): ?>
    <a href="schedule.php?section=<?= (int)$s['section_id'] ?>"
       class="lpill <?= (int)$s['section_id']===$selSection?'lpill--blue':'lpill--gray' ?>">
        <?= h($s['section_code']) ?>
    </a>
    <?php endforeach; ?>
</div>

<?php if ($selSectionInfo): ?>
<div style="font-size:13px;color:#64748b;padding:2px 0;">
    <strong><?= h($selSectionInfo['section_code']) ?></strong> – <?= h($selSectionInfo['course_name']) ?> |
    Học kỳ: <?= h($selSectionInfo['semester_name']) ?> <?= h($selSectionInfo['academic_year']) ?>
</div>
<?php endif; ?>

<div class="lcard" style="padding:0;overflow:hidden;">
    <div style="padding:12px 16px;border-bottom:1px solid #f1f5f9;font-size:13px;color:#64748b;">
        <?= count($allSessions) ?> buổi học trong lớp <?= $selSectionInfo ? h($selSectionInfo['section_code']) : '' ?>
    </div>
    <div class="lec-table-wrap">
        <table class="lec-table">
            <thead>
                <tr>
                    <th>Ngày</th>
                    <th>Giờ học</th>
                    <th>Chủ đề / Nội dung</th>
                    <th style="text-align:center;">Điểm danh</th>
                    <th>Thao tác</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($allSessions)): ?>
                <tr><td colspan="5" style="text-align:center;padding:32px;color:#94a3b8;font-style:italic;">
                    Chưa có buổi học nào. Nhấn "Tạo buổi học mới" để thêm.
                </td></tr>
                <?php else: ?>
                <?php foreach ($allSessions as $sess):
                    $recPct = $sess['total_students']>0 ? round($sess['recorded']/$sess['total_students']*100) : 0;
                    $isToday = $sess['session_date'] === date('Y-m-d');
                ?>
                <tr style="<?= $isToday?'background:#fffbeb;':'' ?>">
                    <td>
                        <div style="font-weight:700;color:#001634;"><?= date('d/m/Y',strtotime($sess['session_date'])) ?></div>
                        <div style="font-size:11px;color:#94a3b8;"><?= $dayNames[date('D',strtotime($sess['session_date']))] ?? '' ?><?= $isToday?' • HÔM NAY':'' ?></div>
                    </td>
                    <td style="font-size:13px;font-weight:600;"><?= fmt_time($sess['start_time']) ?> – <?= fmt_time($sess['end_time']) ?></td>
                    <td style="font-size:12px;color:#44474e;"><?= $sess['topic'] ? h($sess['topic']) : '<span style="color:#94a3b8;">—</span>' ?></td>
                    <td style="text-align:center;">
                        <?php if ($sess['recorded'] > 0): ?>
                        <span class="lpill lpill--green" style="font-size:10px;">✅ <?= (int)$sess['recorded'] ?>/<?= (int)$sess['total_students'] ?></span>
                        <?php else: ?>
                        <span class="lpill lpill--gray" style="font-size:10px;">Chưa điểm danh</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div style="display:flex;gap:4px;">
                            <a href="grading.php?section=<?= $selSection ?>&session=<?= (int)$sess['session_id'] ?>"
                               class="lbtn lbtn--yellow lbtn--sm">✅ Điểm danh</a>
                            <?php if ($sess['recorded'] == 0): ?>
                            <form method="POST" style="display:inline;" onsubmit="return confirm('Xóa buổi học này?')">
                                <input type="hidden" name="_action"    value="delete_session" />
                                <input type="hidden" name="session_id" value="<?= (int)$sess['session_id'] ?>" />
                                <button type="submit" class="lbtn lbtn--outline lbtn--sm" style="color:#ef4444;">🗑</button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- MODAL: Add session -->
<div id="modalAddSession" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1000;align-items:center;justify-content:center;">
<div style="background:#fff;border-radius:16px;width:100%;max-width:460px;padding:28px;position:relative;">
    <button onclick="closeModal('modalAddSession')" style="position:absolute;top:16px;right:16px;background:none;border:none;font-size:20px;cursor:pointer;">✕</button>
    <h2 style="font-size:18px;font-weight:800;color:#001634;margin-bottom:20px;">➕ Tạo buổi học mới</h2>
    <form method="POST">
        <input type="hidden" name="_action" value="add_session" />
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
            <div style="grid-column:1/-1;"><label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">Lớp học phần *</label>
                <select name="section_id" required style="width:100%;height:36px;border:1px solid #e2e8f0;border-radius:8px;padding:0 10px;font-size:13px;background:#fff;outline:none;">
                    <?php foreach ($sections as $s): ?>
                    <option value="<?= (int)$s['section_id'] ?>" <?= (int)$s['section_id']===$selSection?'selected':'' ?>>
                        <?= h($s['section_code'].' – '.$s['course_name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select></div>
            <div><label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">Ngày học *</label>
                <input type="date" name="session_date" value="<?= date('Y-m-d') ?>" required
                       style="width:100%;height:36px;border:1px solid #e2e8f0;border-radius:8px;padding:0 10px;font-size:13px;outline:none;" /></div>
            <div><label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">Giờ bắt đầu *</label>
                <input type="time" name="start_time" value="07:30" required
                       style="width:100%;height:36px;border:1px solid #e2e8f0;border-radius:8px;padding:0 10px;font-size:13px;outline:none;" /></div>
            <div><label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">Giờ kết thúc *</label>
                <input type="time" name="end_time" value="09:00" required
                       style="width:100%;height:36px;border:1px solid #e2e8f0;border-radius:8px;padding:0 10px;font-size:13px;outline:none;" /></div>
            <div style="grid-column:1/-1;"><label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">Chủ đề / Nội dung buổi học</label>
                <input type="text" name="topic" placeholder="VD: Chương 3: Truy vấn SQL nâng cao"
                       style="width:100%;height:36px;border:1px solid #e2e8f0;border-radius:8px;padding:0 10px;font-size:13px;outline:none;" /></div>
        </div>
        <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:16px;">
            <button type="button" onclick="closeModal('modalAddSession')" class="lbtn lbtn--outline">Hủy</button>
            <button type="submit" class="lbtn lbtn--primary">Tạo buổi học</button>
        </div>
    </form>
</div></div>

<?php require __DIR__ . '/_layout_end.php'; ?>

<script>
function showModal(id){document.getElementById(id).style.display='flex';document.body.style.overflow='hidden';}
function closeModal(id){document.getElementById(id).style.display='none';document.body.style.overflow='';}
var m=document.getElementById('modalAddSession');
if(m)m.addEventListener('click',function(e){if(e.target===this)closeModal('modalAddSession');});
document.addEventListener('keydown',function(e){if(e.key==='Escape')closeModal('modalAddSession');});
</script>
