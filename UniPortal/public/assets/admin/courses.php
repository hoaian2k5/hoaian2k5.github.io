<?php
/**
 * UniPortal – Quản lý Môn học & Lớp học phần
 * Courses CRUD + ClassSection view + lecturer assignment.
 */
$root = dirname(__DIR__, 3);
require_once $root . '/src/src/helpers/auth.php';

require_login();
if ($_SESSION['role'] === 'Student')  { header('Location: /UniPortal/public/assets/student/dashboard.php'); exit; }
if ($_SESSION['role'] === 'Lecturer') { header('Location: /UniPortal/public/assets/lecturer/dashboard.php'); exit; }
if (isset($_GET['logout'])) { auth_logout(); header('Location: /UniPortal/public/index.php'); exit; }

$pdo = get_db();

// ── POST handlers ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['_action'] ?? '';

    if ($action === 'add_course') {
        $code  = strtoupper(trim($_POST['course_code']  ?? ''));
        $name  = trim($_POST['course_name']             ?? '');
        $cred  = (int)($_POST['credits']                ?? 0);
        $dept  = ($_POST['department_id'] ?? '') !== '' ? (int)$_POST['department_id'] : null;
        $desc  = trim($_POST['description']             ?? '');

        if ($code==='' || $name==='' || $cred<1) {
            $_SESSION['_flash'] = ['type'=>'error','message'=>'Vui lòng điền đầy đủ: mã môn, tên môn, số tín chỉ (>0).'];
        } else {
            $ck = $pdo->prepare('SELECT 1 FROM Course WHERE course_code=:c LIMIT 1');
            $ck->execute([':c'=>$code]);
            if ($ck->fetch()) {
                $_SESSION['_flash'] = ['type'=>'error','message'=>"Mã môn học $code đã tồn tại."];
            } else {
                $pdo->prepare(
                    'INSERT INTO Course (course_code,course_name,credits,department_id,description,is_active)
                     VALUES (:code,:name,:cred,:dept,:desc,TRUE)'
                )->execute([':code'=>$code,':name'=>$name,':cred'=>$cred,':dept'=>$dept,':desc'=>$desc]);
                $_SESSION['_flash'] = ['type'=>'success','message'=>"Đã thêm môn học: $code – $name"];
            }
        }
        header('Location: courses.php'); exit;
    }

    if ($action === 'edit_course') {
        $cid   = (int)($_POST['course_id']  ?? 0);
        $name  = trim($_POST['course_name'] ?? '');
        $cred  = (int)($_POST['credits']    ?? 0);
        $dept  = ($_POST['department_id'] ?? '') !== '' ? (int)$_POST['department_id'] : null;
        $desc  = trim($_POST['description'] ?? '');
        $active= isset($_POST['is_active']) ? 1 : 0;

        if ($name==='' || $cred<1 || $cid<1) {
            $_SESSION['_flash'] = ['type'=>'error','message'=>'Thông tin không hợp lệ.'];
        } else {
            $pdo->prepare(
                'UPDATE Course SET course_name=:name,credits=:cred,department_id=:dept,
                        description=:desc,is_active=:active WHERE course_id=:cid'
            )->execute([':name'=>$name,':cred'=>$cred,':dept'=>$dept,':desc'=>$desc,':active'=>$active,':cid'=>$cid]);
            $_SESSION['_flash'] = ['type'=>'success','message'=>"Đã cập nhật môn học #$cid."];
        }
        header('Location: courses.php'); exit;
    }

    if ($action === 'add_section') {
        $cid   = (int)($_POST['course_id']    ?? 0);
        $semid = (int)($_POST['semester_id']  ?? 0);
        $lecid = (int)($_POST['lecturer_id']  ?? 0);
        $code  = strtoupper(trim($_POST['section_code'] ?? ''));
        $sched = trim($_POST['schedule']      ?? '');
        $room  = trim($_POST['room']          ?? '');
        $cap   = ($_POST['capacity'] ?? '') !== '' ? (int)$_POST['capacity'] : null;

        if ($cid<1 || $semid<1 || $lecid<1 || $code==='') {
            $_SESSION['_flash'] = ['type'=>'error','message'=>'Vui lòng chọn môn học, học kỳ, giảng viên và nhập mã lớp.'];
        } else {
            $ck = $pdo->prepare('SELECT 1 FROM ClassSection WHERE section_code=:c AND semester_id=:s LIMIT 1');
            $ck->execute([':c'=>$code,':s'=>$semid]);
            if ($ck->fetch()) {
                $_SESSION['_flash'] = ['type'=>'error','message'=>"Mã lớp $code đã tồn tại trong học kỳ này."];
            } else {
                $pdo->prepare(
                    'INSERT INTO ClassSection (course_id,semester_id,lecturer_id,section_code,schedule,room,capacity)
                     VALUES (:cid,:sem,:lid,:code,:sched,:room,:cap)'
                )->execute([':cid'=>$cid,':sem'=>$semid,':lid'=>$lecid,':code'=>$code,':sched'=>$sched,':room'=>$room,':cap'=>$cap]);
                $_SESSION['_flash'] = ['type'=>'success','message'=>"Đã tạo lớp học phần: $code"];
            }
        }
        header('Location: courses.php?tab=sections'); exit;
    }

    if ($action === 'assign_lecturer') {
        $secid = (int)($_POST['section_id']  ?? 0);
        $lecid = (int)($_POST['lecturer_id'] ?? 0);
        if ($secid>0 && $lecid>0) {
            $pdo->prepare('UPDATE ClassSection SET lecturer_id=:lid WHERE section_id=:sid')
                ->execute([':lid'=>$lecid,':sid'=>$secid]);
            $_SESSION['_flash'] = ['type'=>'success','message'=>"Đã giao lớp #{$secid} cho giảng viên #{$lecid}."];
        }
        header('Location: courses.php?tab=sections'); exit;
    }
}

// ── Flash ─────────────────────────────────────────────────────
$flash = '';
if (isset($_SESSION['_flash'])) { $flash=$_SESSION['_flash']['type'].':'.$_SESSION['_flash']['message']; unset($_SESSION['_flash']); }

// ── View course JSON ──────────────────────────────────────────
if (isset($_GET['view_course'])) {
    $cid = (int)$_GET['view_course'];
    $row = $pdo->prepare(
        'SELECT c.*, d.department_name, d.department_code,
                (SELECT COUNT(*) FROM ClassSection cs WHERE cs.course_id=c.course_id) AS section_count
         FROM Course c LEFT JOIN Department d ON d.department_id=c.department_id
         WHERE c.course_id=:cid LIMIT 1'
    );
    $row->execute([':cid'=>$cid]);
    header('Content-Type: application/json');
    echo json_encode($row->fetch() ?: []);
    exit;
}

$tab = $_GET['tab'] ?? 'courses';

// ── Data: Courses ─────────────────────────────────────────────
$searchC = trim($_GET['qc'] ?? '');
$deptFC  = trim($_GET['dept'] ?? '');
$whereC  = "WHERE 1=1"; $paramsC = [];
if ($searchC!=='') { $whereC.=" AND (c.course_code LIKE :q OR c.course_name LIKE :q)"; $paramsC[':q']='%'.$searchC.'%'; }
if ($deptFC!=='')  { $whereC.=" AND d.department_code=:dept"; $paramsC[':dept']=$deptFC; }

$courses = $pdo->prepare(
    "SELECT c.course_id, c.course_code, c.course_name, c.credits, c.is_active, c.description,
            d.department_name, d.department_code, d.department_id,
            (SELECT COUNT(*) FROM ClassSection cs WHERE cs.course_id=c.course_id) AS section_count
     FROM Course c LEFT JOIN Department d ON d.department_id=c.department_id
     $whereC ORDER BY c.course_code ASC LIMIT 100"
);
$courses->execute($paramsC);
$courseRows = $courses->fetchAll();

// ── Data: Sections ────────────────────────────────────────────
$searchS = trim($_GET['qs'] ?? '');
$semFS   = (int)($_GET['sem'] ?? 0);

$currentSemRow = $pdo->query("SELECT semester_id FROM Semester WHERE status='Active' ORDER BY start_date DESC LIMIT 1")->fetch();
$defaultSem    = $semFS ?: (int)($currentSemRow['semester_id'] ?? 0);

$whereS = "WHERE 1=1"; $paramsS = [];
if ($defaultSem>0) { $whereS.=" AND cs.semester_id=:sem"; $paramsS[':sem']=$defaultSem; }
if ($searchS!=='') { $whereS.=" AND (cs.section_code LIKE :q OR c.course_name LIKE :q)"; $paramsS[':q']='%'.$searchS.'%'; }

$sections = $pdo->prepare(
    "SELECT cs.section_id, cs.section_code, cs.schedule, cs.room, cs.capacity,
            c.course_code, c.course_name, c.credits,
            sem.semester_name, sem.academic_year,
            l.full_name AS lecturer_name, l.lecturer_id,
            (SELECT COUNT(*) FROM Enrollment e WHERE e.section_id=cs.section_id AND e.status IN ('Enrolled','Completed')) AS enrolled
     FROM ClassSection cs
     JOIN Course c ON c.course_id=cs.course_id
     JOIN Semester sem ON sem.semester_id=cs.semester_id
     JOIN Lecturer l ON l.lecturer_id=cs.lecturer_id
     $whereS
     ORDER BY cs.section_code ASC LIMIT 100"
);
$sections->execute($paramsS);
$sectionRows = $sections->fetchAll();

// ── Supporting data ───────────────────────────────────────────
$depts     = $pdo->query('SELECT department_id,department_code,department_name FROM Department ORDER BY department_code')->fetchAll();
$semesters = $pdo->query('SELECT semester_id,semester_name,academic_year FROM Semester ORDER BY start_date DESC')->fetchAll();
$lecturers = $pdo->query('SELECT lecturer_id,full_name,department_id FROM Lecturer WHERE status=\'Active\' ORDER BY full_name ASC')->fetchAll();

// ── Stats ─────────────────────────────────────────────────────
$totalCourses  = (int)$pdo->query("SELECT COUNT(*) FROM Course WHERE is_active=TRUE")->fetchColumn();
$totalSections = (int)$pdo->query("SELECT COUNT(*) FROM ClassSection")->fetchColumn();
$totalStudents = (int)$pdo->query("SELECT COUNT(*) FROM Student WHERE status='Active'")->fetchColumn();

function h($v): string { return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8'); }
$warnCount  = (int)$pdo->query("SELECT COUNT(*) FROM AcademicWarning WHERE status='Active' AND is_read=FALSE")->fetchColumn();
$pageTitle  = 'Môn học & Lớp học phần';
$activePage = 'courses';
$breadcrumb = 'Quản trị › Môn học & Lớp học phần';
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

<!-- Title + add buttons -->
<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
    <h1 style="font-size:22px;font-weight:800;color:#0d1b2e;">Môn học &amp; Lớp học phần</h1>
    <div style="display:flex;gap:8px;">
        <?php if ($tab==='courses'): ?>
        <button onclick="showModal('modalAddCourse')" class="abtn abtn--primary" style="background:#0d1b2e;">➕ Thêm môn học</button>
        <?php else: ?>
        <button onclick="showModal('modalAddSection')" class="abtn abtn--primary" style="background:#0d1b2e;">➕ Tạo lớp học phần</button>
        <?php endif; ?>
    </div>
</div>

<!-- KPI -->
<div class="adm-kpi-row">
    <div class="acard adm-kpi"><div class="adm-kpi__header"><span class="adm-kpi__label">Tổng môn học</span><span class="adm-kpi__icon adm-kpi__icon--blue"><img src="../img/db-nav-grades.svg" alt="" /></span></div>
        <div class="adm-kpi__value"><span class="adm-kpi__num"><?= $totalCourses ?></span></div></div>
    <div class="acard adm-kpi"><div class="adm-kpi__header"><span class="adm-kpi__label">Lớp học phần</span><span class="adm-kpi__icon adm-kpi__icon--green"><img src="../img/db-icon-credits.svg" alt="" /></span></div>
        <div class="adm-kpi__value"><span class="adm-kpi__num"><?= $totalSections ?></span></div></div>
    <div class="acard adm-kpi"><div class="adm-kpi__header"><span class="adm-kpi__label">Sinh viên đang học</span><span class="adm-kpi__icon adm-kpi__icon--yellow"><img src="../img/db-nav-profile.svg" alt="" /></span></div>
        <div class="adm-kpi__value"><span class="adm-kpi__num"><?= $totalStudents ?></span></div></div>
    <div class="acard adm-kpi"><div class="adm-kpi__header"><span class="adm-kpi__label">Bộ môn</span><span class="adm-kpi__icon adm-kpi__icon--blue"><img src="../img/db-icon-ai.svg" alt="" /></span></div>
        <div class="adm-kpi__value"><span class="adm-kpi__num"><?= count($depts) ?></span></div></div>
</div>

<!-- Tabs -->
<div class="adm-tabs" style="background:#fff;border-radius:12px;padding:0 20px;box-shadow:0 1px 3px rgba(0,0,0,.06);">
    <a href="courses.php?tab=courses" class="adm-tab <?= $tab==='courses'?'active':'' ?>">📚 Danh sách môn học (<?= $totalCourses ?>)</a>
    <a href="courses.php?tab=sections" class="adm-tab <?= $tab==='sections'?'active':'' ?>">🏫 Lớp học phần (<?= $totalSections ?>)</a>
</div>

<?php if ($tab === 'courses'): ?>
<!-- ── COURSES TAB ──────────────────────────────────────────── -->
<div class="acard" style="padding:16px 20px;">
    <form method="GET" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
        <input type="hidden" name="tab" value="courses" />
        <div style="position:relative;flex:0 0 280px;">
            <img src="../img/kqht-icon-search.svg" alt="" style="position:absolute;left:10px;top:50%;transform:translateY(-50%);width:13px;opacity:.4;" />
            <input type="search" name="qc" value="<?= h($searchC) ?>" placeholder="Tìm mã môn, tên môn..."
                   style="width:100%;height:36px;background:#f1f5f9;border:1px solid #e2e8f0;border-radius:8px;padding:0 12px 0 30px;font-size:13px;outline:none;" />
        </div>
        <select name="dept" style="height:36px;padding:0 10px;background:#fff;border:1px solid #e2e8f0;border-radius:8px;font-size:13px;">
            <option value="">Tất cả bộ môn</option>
            <?php foreach ($depts as $d): ?>
            <option value="<?= h($d['department_code']) ?>" <?= $deptFC===$d['department_code']?'selected':'' ?>><?= h($d['department_code']) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="abtn abtn--primary abtn--sm">🔍 Lọc</button>
        <?php if ($searchC||$deptFC): ?><a href="courses.php?tab=courses" class="abtn abtn--outline abtn--sm">✕</a><?php endif; ?>
    </form>
</div>
<div class="acard" style="padding:0;overflow:hidden;">
    <div class="adm-table-wrap">
        <table class="adm-table">
            <thead><tr><th>MÃ MÔN</th><th>TÊN MÔN HỌC</th><th style="text-align:center;">TC</th><th>BỘ MÔN</th><th style="text-align:center;">SỐ LỚP</th><th>TRẠNG THÁI</th><th>THAO TÁC</th></tr></thead>
            <tbody>
                <?php if (empty($courseRows)): ?>
                <tr><td colspan="7" style="text-align:center;padding:32px;color:#94a3b8;font-style:italic;">Không tìm thấy môn học nào.</td></tr>
                <?php else: ?>
                <?php foreach ($courseRows as $c): ?>
                <tr>
                    <td><span style="font-family:monospace;font-size:12px;background:#f1f5f9;padding:2px 7px;border-radius:4px;"><?= h($c['course_code']) ?></span></td>
                    <td style="font-weight:600;color:#0d1b2e;"><?= h($c['course_name']) ?></td>
                    <td style="text-align:center;font-weight:700;"><?= (int)$c['credits'] ?></td>
                    <td style="font-size:12px;color:#64748b;"><?= h($c['department_name'] ?? '—') ?></td>
                    <td style="text-align:center;"><?= (int)$c['section_count'] ?></td>
                    <td><span class="apill <?= $c['is_active']?'apill--green':'apill--gray' ?>"><?= $c['is_active']?'Đang dạy':'Tạm dừng' ?></span></td>
                    <td>
                        <button onclick="openEditCourseModal(<?= htmlspecialchars(json_encode($c),ENT_QUOTES) ?>)" class="abtn abtn--outline abtn--sm">✏</button>
                        <button onclick="openViewCourseModal(<?= (int)$c['course_id'] ?>)" class="abtn abtn--outline abtn--sm">👁</button>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php else: ?>
<!-- ── SECTIONS TAB ─────────────────────────────────────────── -->
<div class="acard" style="padding:16px 20px;">
    <form method="GET" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
        <input type="hidden" name="tab" value="sections" />
        <div style="position:relative;flex:0 0 240px;">
            <img src="../img/kqht-icon-search.svg" alt="" style="position:absolute;left:10px;top:50%;transform:translateY(-50%);width:13px;opacity:.4;" />
            <input type="search" name="qs" value="<?= h($searchS) ?>" placeholder="Tìm mã lớp, tên môn..."
                   style="width:100%;height:36px;background:#f1f5f9;border:1px solid #e2e8f0;border-radius:8px;padding:0 12px 0 30px;font-size:13px;outline:none;" />
        </div>
        <select name="sem" style="height:36px;padding:0 10px;background:#fff;border:1px solid #e2e8f0;border-radius:8px;font-size:13px;">
            <option value="">Tất cả học kỳ</option>
            <?php foreach ($semesters as $s): ?>
            <option value="<?= (int)$s['semester_id'] ?>" <?= (int)$s['semester_id']===$defaultSem?'selected':'' ?>>
                <?= h($s['semester_name']) ?> <?= h($s['academic_year']) ?>
            </option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="abtn abtn--primary abtn--sm">🔍 Lọc</button>
        <?php if ($searchS): ?><a href="courses.php?tab=sections" class="abtn abtn--outline abtn--sm">✕</a><?php endif; ?>
    </form>
</div>
<div class="acard" style="padding:0;overflow:hidden;">
    <div class="adm-table-wrap">
        <table class="adm-table">
            <thead><tr><th>MÃ LỚP</th><th>MÔN HỌC</th><th style="text-align:center;">TC</th><th>GIẢNG VIÊN</th><th>LỊCH & PHÒNG</th><th style="text-align:center;">SĨ SỐ</th><th>THAO TÁC</th></tr></thead>
            <tbody>
                <?php if (empty($sectionRows)): ?>
                <tr><td colspan="7" style="text-align:center;padding:32px;color:#94a3b8;font-style:italic;">Không có lớp học phần nào.</td></tr>
                <?php else: ?>
                <?php foreach ($sectionRows as $s): ?>
                <tr>
                    <td><span style="font-family:monospace;font-size:12px;background:#f1f5f9;padding:2px 7px;border-radius:4px;"><?= h($s['section_code']) ?></span>
                        <div style="font-size:10px;color:#94a3b8;"><?= h($s['semester_name']) ?> <?= h($s['academic_year']) ?></div></td>
                    <td style="font-weight:600;color:#0d1b2e;font-size:13px;"><?= h($s['course_name']) ?>
                        <span style="font-size:11px;font-weight:400;color:#94a3b8;">(<?= h($s['course_code']) ?>)</span></td>
                    <td style="text-align:center;font-weight:700;"><?= (int)$s['credits'] ?></td>
                    <td style="font-size:12px;color:#44474e;"><?= h($s['lecturer_name']) ?></td>
                    <td style="font-size:12px;color:#64748b;"><?= h($s['schedule'] ?? '—') ?><?= $s['room']?' • '.h($s['room']):'' ?></td>
                    <td style="text-align:center;font-weight:700;"><?= (int)$s['enrolled'] ?><?= $s['capacity']?' / '.(int)$s['capacity']:'' ?></td>
                    <td>
                        <button onclick="openAssignModal(<?= (int)$s['section_id'] ?>, '<?= h($s['section_code']) ?>', <?= (int)$s['lecturer_id'] ?>)"
                                class="abtn abtn--outline abtn--sm" title="Đổi giảng viên">👨‍🏫</button>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- ══ MODAL: ADD COURSE ═══════════════════════════════════════ -->
<div id="modalAddCourse" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1000;align-items:center;justify-content:center;">
<div style="background:#fff;border-radius:16px;width:100%;max-width:500px;padding:28px;position:relative;max-height:90vh;overflow-y:auto;">
    <button onclick="closeModal('modalAddCourse')" style="position:absolute;top:16px;right:16px;background:none;border:none;font-size:20px;cursor:pointer;">✕</button>
    <h2 style="font-size:18px;font-weight:800;color:#0d1b2e;margin-bottom:20px;">➕ Thêm môn học mới</h2>
    <form method="POST">
        <input type="hidden" name="_action" value="add_course" />
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
            <div><label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">Mã môn *</label>
                <input type="text" name="course_code" placeholder="VD: INS3001" required
                       style="width:100%;height:36px;border:1px solid #e2e8f0;border-radius:8px;padding:0 10px;font-size:13px;outline:none;" /></div>
            <div><label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">Số tín chỉ *</label>
                <input type="number" name="credits" min="1" max="10" value="3" required
                       style="width:100%;height:36px;border:1px solid #e2e8f0;border-radius:8px;padding:0 10px;font-size:13px;outline:none;" /></div>
            <div style="grid-column:1/-1;"><label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">Tên môn học *</label>
                <input type="text" name="course_name" required
                       style="width:100%;height:36px;border:1px solid #e2e8f0;border-radius:8px;padding:0 10px;font-size:13px;outline:none;" /></div>
            <div style="grid-column:1/-1;"><label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">Bộ môn</label>
                <select name="department_id" style="width:100%;height:36px;border:1px solid #e2e8f0;border-radius:8px;padding:0 10px;font-size:13px;background:#fff;outline:none;">
                    <option value="">— Chọn —</option>
                    <?php foreach ($depts as $d): ?><option value="<?= (int)$d['department_id'] ?>"><?= h($d['department_code'].' – '.$d['department_name']) ?></option><?php endforeach; ?>
                </select></div>
            <div style="grid-column:1/-1;"><label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">Mô tả</label>
                <textarea name="description" rows="2" style="width:100%;border:1px solid #e2e8f0;border-radius:8px;padding:8px 10px;font-size:13px;outline:none;resize:vertical;"></textarea></div>
        </div>
        <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:16px;">
            <button type="button" onclick="closeModal('modalAddCourse')" class="abtn abtn--outline">Hủy</button>
            <button type="submit" class="abtn abtn--primary" style="background:#0d1b2e;">Thêm môn học</button>
        </div>
    </form>
</div></div>

<!-- ══ MODAL: EDIT COURSE ══════════════════════════════════════ -->
<div id="modalEditCourse" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1000;align-items:center;justify-content:center;">
<div style="background:#fff;border-radius:16px;width:100%;max-width:500px;padding:28px;position:relative;max-height:90vh;overflow-y:auto;">
    <button onclick="closeModal('modalEditCourse')" style="position:absolute;top:16px;right:16px;background:none;border:none;font-size:20px;cursor:pointer;">✕</button>
    <h2 style="font-size:18px;font-weight:800;color:#0d1b2e;margin-bottom:20px;">✏ Chỉnh sửa môn học</h2>
    <form method="POST">
        <input type="hidden" name="_action"   value="edit_course" />
        <input type="hidden" name="course_id" id="ec_course_id" />
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
            <div><label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">Mã môn (không thể đổi)</label>
                <input type="text" id="ec_code" readonly style="width:100%;height:36px;border:1px solid #e2e8f0;border-radius:8px;padding:0 10px;font-size:13px;background:#f8fafc;outline:none;" /></div>
            <div><label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">Số tín chỉ *</label>
                <input type="number" name="credits" id="ec_credits" min="1" max="10" required
                       style="width:100%;height:36px;border:1px solid #e2e8f0;border-radius:8px;padding:0 10px;font-size:13px;outline:none;" /></div>
            <div style="grid-column:1/-1;"><label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">Tên môn học *</label>
                <input type="text" name="course_name" id="ec_name" required
                       style="width:100%;height:36px;border:1px solid #e2e8f0;border-radius:8px;padding:0 10px;font-size:13px;outline:none;" /></div>
            <div style="grid-column:1/-1;"><label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">Bộ môn</label>
                <select name="department_id" id="ec_dept" style="width:100%;height:36px;border:1px solid #e2e8f0;border-radius:8px;padding:0 10px;font-size:13px;background:#fff;outline:none;">
                    <option value="">— Chọn —</option>
                    <?php foreach ($depts as $d): ?><option value="<?= (int)$d['department_id'] ?>"><?= h($d['department_code'].' – '.$d['department_name']) ?></option><?php endforeach; ?>
                </select></div>
            <div style="grid-column:1/-1;"><label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">Mô tả</label>
                <textarea name="description" id="ec_desc" rows="2" style="width:100%;border:1px solid #e2e8f0;border-radius:8px;padding:8px 10px;font-size:13px;outline:none;resize:vertical;"></textarea></div>
            <div><label style="display:flex;align-items:center;gap:8px;font-size:13px;cursor:pointer;"><input type="checkbox" name="is_active" id="ec_active" /> Đang mở lớp</label></div>
        </div>
        <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:16px;">
            <button type="button" onclick="closeModal('modalEditCourse')" class="abtn abtn--outline">Hủy</button>
            <button type="submit" class="abtn abtn--primary" style="background:#0d1b2e;">Lưu thay đổi</button>
        </div>
    </form>
</div></div>

<!-- ══ MODAL: ADD SECTION ══════════════════════════════════════ -->
<div id="modalAddSection" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1000;align-items:center;justify-content:center;">
<div style="background:#fff;border-radius:16px;width:100%;max-width:520px;padding:28px;position:relative;max-height:90vh;overflow-y:auto;">
    <button onclick="closeModal('modalAddSection')" style="position:absolute;top:16px;right:16px;background:none;border:none;font-size:20px;cursor:pointer;">✕</button>
    <h2 style="font-size:18px;font-weight:800;color:#0d1b2e;margin-bottom:20px;">➕ Tạo lớp học phần mới</h2>
    <form method="POST">
        <input type="hidden" name="_action" value="add_section" />
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
            <div style="grid-column:1/-1;"><label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">Môn học *</label>
                <select name="course_id" required style="width:100%;height:36px;border:1px solid #e2e8f0;border-radius:8px;padding:0 10px;font-size:13px;background:#fff;outline:none;">
                    <option value="">— Chọn môn học —</option>
                    <?php foreach ($courseRows as $c): ?><option value="<?= (int)$c['course_id'] ?>"><?= h($c['course_code'].' – '.$c['course_name']) ?></option><?php endforeach; ?>
                </select></div>
            <div><label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">Mã lớp *</label>
                <input type="text" name="section_code" placeholder="VD: INS3065-01" required
                       style="width:100%;height:36px;border:1px solid #e2e8f0;border-radius:8px;padding:0 10px;font-size:13px;outline:none;" /></div>
            <div><label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">Học kỳ *</label>
                <select name="semester_id" required style="width:100%;height:36px;border:1px solid #e2e8f0;border-radius:8px;padding:0 10px;font-size:13px;background:#fff;outline:none;">
                    <?php foreach ($semesters as $s): ?><option value="<?= (int)$s['semester_id'] ?>"><?= h($s['semester_name'].' '.$s['academic_year']) ?></option><?php endforeach; ?>
                </select></div>
            <div style="grid-column:1/-1;"><label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">Giảng viên *</label>
                <select name="lecturer_id" required style="width:100%;height:36px;border:1px solid #e2e8f0;border-radius:8px;padding:0 10px;font-size:13px;background:#fff;outline:none;">
                    <option value="">— Chọn giảng viên —</option>
                    <?php foreach ($lecturers as $l): ?><option value="<?= (int)$l['lecturer_id'] ?>"><?= h($l['full_name']) ?></option><?php endforeach; ?>
                </select></div>
            <div><label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">Lịch học</label>
                <input type="text" name="schedule" placeholder="VD: Thứ 3 & 5 07:30-09:00"
                       style="width:100%;height:36px;border:1px solid #e2e8f0;border-radius:8px;padding:0 10px;font-size:13px;outline:none;" /></div>
            <div><label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">Phòng</label>
                <input type="text" name="room" placeholder="VD: B1.105"
                       style="width:100%;height:36px;border:1px solid #e2e8f0;border-radius:8px;padding:0 10px;font-size:13px;outline:none;" /></div>
            <div><label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">Sĩ số tối đa</label>
                <input type="number" name="capacity" min="1" max="200" placeholder="45"
                       style="width:100%;height:36px;border:1px solid #e2e8f0;border-radius:8px;padding:0 10px;font-size:13px;outline:none;" /></div>
        </div>
        <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:16px;">
            <button type="button" onclick="closeModal('modalAddSection')" class="abtn abtn--outline">Hủy</button>
            <button type="submit" class="abtn abtn--primary" style="background:#0d1b2e;">Tạo lớp học phần</button>
        </div>
    </form>
</div></div>

<!-- ══ MODAL: ASSIGN LECTURER ══════════════════════════════════ -->
<div id="modalAssign" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1000;align-items:center;justify-content:center;">
<div style="background:#fff;border-radius:16px;width:100%;max-width:420px;padding:28px;position:relative;">
    <button onclick="closeModal('modalAssign')" style="position:absolute;top:16px;right:16px;background:none;border:none;font-size:20px;cursor:pointer;">✕</button>
    <h2 style="font-size:18px;font-weight:800;color:#0d1b2e;margin-bottom:4px;">👨‍🏫 Đổi giảng viên</h2>
    <p id="assignLabel" style="font-size:13px;color:#64748b;margin-bottom:16px;"></p>
    <form method="POST">
        <input type="hidden" name="_action"    value="assign_lecturer" />
        <input type="hidden" name="section_id" id="assign_sid" />
        <label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:6px;">Giảng viên mới *</label>
        <select name="lecturer_id" id="assign_lid" required style="width:100%;height:36px;border:1px solid #e2e8f0;border-radius:8px;padding:0 10px;font-size:13px;background:#fff;outline:none;margin-bottom:16px;">
            <?php foreach ($lecturers as $l): ?><option value="<?= (int)$l['lecturer_id'] ?>"><?= h($l['full_name']) ?></option><?php endforeach; ?>
        </select>
        <div style="display:flex;gap:8px;justify-content:flex-end;">
            <button type="button" onclick="closeModal('modalAssign')" class="abtn abtn--outline">Hủy</button>
            <button type="submit" class="abtn abtn--primary" style="background:#0d1b2e;">Cập nhật</button>
        </div>
    </form>
</div></div>

<?php require __DIR__ . '/_layout_end.php'; ?>

<script>
function showModal(id){document.getElementById(id).style.display='flex';document.body.style.overflow='hidden';}
function closeModal(id){document.getElementById(id).style.display='none';document.body.style.overflow='';}
function setVal(id,val){var el=document.getElementById(id);if(el)el.value=val!==null&&val!==undefined?val:'';}
['modalAddCourse','modalEditCourse','modalAddSection','modalAssign'].forEach(function(id){
    var el=document.getElementById(id);
    if(el)el.addEventListener('click',function(e){if(e.target===this)closeModal(id);});
});
document.addEventListener('keydown',function(e){if(e.key==='Escape')['modalAddCourse','modalEditCourse','modalAddSection','modalAssign'].forEach(closeModal);});

function openEditCourseModal(c){
    setVal('ec_course_id', c.course_id);
    setVal('ec_code',     c.course_code);
    setVal('ec_credits',  c.credits);
    setVal('ec_name',     c.course_name);
    setVal('ec_dept',     c.department_id||'');
    setVal('ec_desc',     c.description||'');
    document.getElementById('ec_active').checked = !!c.is_active;
    showModal('modalEditCourse');
}
function openViewCourseModal(cid){
    fetch('courses.php?view_course='+cid).then(r=>r.json()).then(function(c){
        if(!c||!c.course_id){alert('Không tìm thấy.');return;}
        openEditCourseModal(c);
    });
}
function openAssignModal(sid, code, curLid){
    document.getElementById('assign_sid').value = sid;
    document.getElementById('assign_lid').value = curLid||'';
    document.getElementById('assignLabel').textContent = 'Lớp: ' + code;
    showModal('modalAssign');
}
</script>
