<?php
/**
 * UniPortal – Admin: Curriculum Management
 * Create/edit curricula, assign/remove courses.
 */
$root = dirname(__DIR__, 3);
require_once $root . '/src/src/helpers/auth.php';

require_login();
if ($_SESSION['role'] !== 'AcademicAffairs') { header('Location: /UniPortal/public/index.php'); exit; }
if (isset($_GET['logout'])) { auth_logout(); header('Location: /UniPortal/public/index.php'); exit; }

$pdo = get_db();

// ── Flash helper ──────────────────────────────────────────────
function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function flash_save(string $msg): void { $_SESSION['_flash'] = $msg; }
function flash_read(): string { $f = $_SESSION['_flash'] ?? ''; unset($_SESSION['_flash']); return $f; }

// ── POST handlers ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['_action'] ?? '';

    if ($action === 'save_curriculum') {
        $cid     = (int)($_POST['curriculum_id']   ?? 0);
        $name    = trim($_POST['curriculum_name']  ?? '');
        $major   = strtoupper(trim($_POST['major_code'] ?? ''));
        $year    = (int)($_POST['admission_year']  ?? date('Y'));
        $credits = (int)($_POST['required_credits'] ?? 130);

        if ($name === '' || $major === '' || $credits < 1) {
            flash_save('error:Vui lòng điền đầy đủ: tên chương trình, mã ngành, tổng tín chỉ.');
        } else {
            if ($cid > 0) {
                $pdo->prepare(
                    'UPDATE Curriculum SET curriculum_name=:n, major_code=:m,
                            admission_year=:y, required_credits=:c WHERE curriculum_id=:id'
                )->execute([':n'=>$name,':m'=>$major,':y'=>$year,':c'=>$credits,':id'=>$cid]);
                flash_save('success:Đã cập nhật chương trình đào tạo.');
            } else {
                // Check duplicate major+year
                $ck = $pdo->prepare('SELECT 1 FROM Curriculum WHERE major_code=:m AND admission_year=:y LIMIT 1');
                $ck->execute([':m'=>$major,':y'=>$year]);
                if ($ck->fetch()) {
                    flash_save('error:Đã tồn tại chương trình cho ngành ' . $major . ' năm ' . $year . '.');
                } else {
                    $pdo->prepare(
                        'INSERT INTO Curriculum (curriculum_name, major_code, admission_year, required_credits)
                         VALUES (:n, :m, :y, :c)'
                    )->execute([':n'=>$name,':m'=>$major,':y'=>$year,':c'=>$credits]);
                    flash_save('success:Đã thêm chương trình đào tạo: ' . $name);
                }
            }
        }
        header('Location: curriculum.php'); exit;
    }

    if ($action === 'add_course') {
        $cid       = (int)($_POST['curriculum_id'] ?? 0);
        $courseId  = (int)($_POST['course_id']     ?? 0);
        $ctype     = in_array($_POST['course_type'] ?? '', ['Required','Elective']) ? $_POST['course_type'] : 'Required';
        $semRec    = ($_POST['semester_recommended'] ?? '') !== '' ? (int)$_POST['semester_recommended'] : null;
        $isReq     = isset($_POST['is_required']) ? 1 : 0;

        if ($cid < 1 || $courseId < 1) {
            flash_save('error:Chọn chương trình và môn học hợp lệ.');
        } else {
            $ck = $pdo->prepare('SELECT 1 FROM CurriculumCourse WHERE curriculum_id=:cid AND course_id=:coid LIMIT 1');
            $ck->execute([':cid'=>$cid,':coid'=>$courseId]);
            if ($ck->fetch()) {
                flash_save('error:Môn học đã có trong chương trình này.');
            } else {
                $pdo->prepare(
                    'INSERT INTO CurriculumCourse (curriculum_id, course_id, course_type, semester_recommended, is_required)
                     VALUES (:cid, :coid, :ct, :sr, :ir)'
                )->execute([':cid'=>$cid,':coid'=>$courseId,':ct'=>$ctype,':sr'=>$semRec,':ir'=>$isReq]);
                flash_save('success:Đã thêm môn học vào chương trình.');
            }
        }
        header('Location: curriculum.php?cid=' . $cid); exit;
    }

    if ($action === 'remove_course') {
        $cid      = (int)($_POST['curriculum_id'] ?? 0);
        $courseId = (int)($_POST['course_id']     ?? 0);
        $pdo->prepare('DELETE FROM CurriculumCourse WHERE curriculum_id=:cid AND course_id=:coid')
            ->execute([':cid'=>$cid,':coid'=>$courseId]);
        flash_save('success:Đã xóa môn khỏi chương trình.');
        header('Location: curriculum.php?cid=' . $cid); exit;
    }
}

$flash = flash_read();

// ── View mode ─────────────────────────────────────────────────
$selCid = (int)($_GET['cid'] ?? 0);

// JSON for modal
if (isset($_GET['get_curriculum'])) {
    $row = $pdo->prepare('SELECT * FROM Curriculum WHERE curriculum_id=:id LIMIT 1');
    $row->execute([':id' => (int)$_GET['get_curriculum']]);
    header('Content-Type: application/json');
    echo json_encode($row->fetch() ?: []);
    exit;
}

$curricula = $pdo->query('SELECT * FROM Curriculum ORDER BY major_code, admission_year DESC')->fetchAll();
$courses   = $pdo->query(
    'SELECT c.*, d.department_name FROM Course c LEFT JOIN Department d ON d.department_id=c.department_id
     WHERE c.is_active=1 ORDER BY c.course_code'
)->fetchAll();

$currCourses = [];
$selCurriculum = null;
if ($selCid) {
    $selCurriculum = $pdo->prepare('SELECT * FROM Curriculum WHERE curriculum_id=:id LIMIT 1');
    $selCurriculum->execute([':id' => $selCid]);
    $selCurriculum = $selCurriculum->fetch();

    $ccSt = $pdo->prepare(
        'SELECT cc.*, c.course_code, c.course_name, c.credits, d.department_name
         FROM CurriculumCourse cc
         JOIN Course c ON c.course_id=cc.course_id
         LEFT JOIN Department d ON d.department_id=c.department_id
         WHERE cc.curriculum_id=:cid ORDER BY cc.semester_recommended, c.course_code'
    );
    $ccSt->execute([':cid' => $selCid]);
    $currCourses = $ccSt->fetchAll();
}

$totalCredits = array_sum(array_column($currCourses, 'credits'));
$assignedIds  = array_column($currCourses, 'course_id');
$availCourses = array_filter($courses, fn($c) => !in_array((int)$c['course_id'], array_map('intval', $assignedIds)));

$warnCount  = (int)$pdo->query("SELECT COUNT(*) FROM AcademicWarning WHERE status='Active' AND is_read=FALSE")->fetchColumn();
$pageTitle  = 'Chương trình đào tạo';
$activePage = 'curriculum';
$breadcrumb = 'Quản trị › Chương trình đào tạo';
require __DIR__ . '/_layout.php';
?>

<?php if ($flash): [$ft,$fm] = explode(':', $flash, 2); ?>
<div style="padding:12px 16px;border-radius:10px;font-weight:600;font-size:13px;margin-bottom:2px;
            background:<?= $ft==='success'?'#d1fae5':'#fee2e2' ?>;
            border:1px solid <?= $ft==='success'?'#6ee7b7':'#fca5a5' ?>;
            color:<?= $ft==='success'?'#065f46':'#b91c1c' ?>;">
    <?= $ft==='success' ? '✅' : '⚠️' ?> <?= h($fm) ?>
</div>
<?php endif; ?>

<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
    <h1 style="font-size:20px;font-weight:800;color:#0d1b2e;">Chương trình Đào tạo (Curriculum)</h1>
    <button onclick="openCreateModal()" class="abtn abtn--primary" style="background:#0d1b2e;">➕ Thêm chương trình</button>
</div>

<div style="display:grid;grid-template-columns:300px 1fr;gap:20px;align-items:start;">

<!-- LEFT: Curriculum list -->
<div class="acard" style="padding:0;overflow:hidden;">
    <div style="padding:12px 16px;border-bottom:1px solid #f1f5f9;font-size:13px;font-weight:600;color:#334155;">
        <?= count($curricula) ?> chương trình
    </div>
    <?php if (empty($curricula)): ?>
    <div style="padding:24px;text-align:center;color:#94a3b8;font-size:13px;">Chưa có chương trình nào.</div>
    <?php else: ?>
    <?php foreach ($curricula as $cur): ?>
    <a href="curriculum.php?cid=<?= (int)$cur['curriculum_id'] ?>"
       style="display:flex;align-items:center;justify-content:space-between;padding:12px 16px;
              border-bottom:1px solid #f8fafc;text-decoration:none;
              background:<?= (int)$cur['curriculum_id']===$selCid?'#eff4ff':'#fff' ?>;
              border-left:<?= (int)$cur['curriculum_id']===$selCid?'3px solid #3b82f6':'3px solid transparent' ?>;">
        <div>
            <div style="font-weight:700;font-size:13px;color:#0d1b2e;"><?= h($cur['curriculum_name']) ?></div>
            <div style="font-size:11px;color:#64748b;margin-top:2px;">
                <?= h($cur['major_code']) ?> • <?= (int)$cur['admission_year'] ?> • <?= (int)$cur['required_credits'] ?> TC
            </div>
        </div>
        <button onclick="event.preventDefault(); openEditModal(<?= (int)$cur['curriculum_id'] ?>)"
                style="background:none;border:none;font-size:14px;cursor:pointer;color:#64748b;padding:4px;">✏</button>
    </a>
    <?php endforeach; ?>
    <?php endif; ?>
</div>

<!-- RIGHT: Course list for selected curriculum -->
<div>
    <?php if (!$selCurriculum): ?>
    <div class="acard" style="padding:40px;text-align:center;color:#94a3b8;">
        <div style="font-size:32px;margin-bottom:8px;">📚</div>
        <p>Chọn một chương trình ở bên trái để xem và quản lý môn học.</p>
    </div>
    <?php else: ?>
    <!-- Curriculum header -->
    <div class="acard" style="padding:16px 20px;background:#eff4ff;border-left:4px solid #3b82f6;margin-bottom:12px;">
        <div style="display:flex;align-items:center;justify-content:space-between;">
            <div>
                <div style="font-size:16px;font-weight:700;color:#1e40af;"><?= h($selCurriculum['curriculum_name']) ?></div>
                <div style="font-size:12px;color:#3b82f6;margin-top:3px;">
                    Ngành: <?= h($selCurriculum['major_code']) ?> •
                    K.<?= (int)$selCurriculum['admission_year'] ?> •
                    Yêu cầu: <?= (int)$selCurriculum['required_credits'] ?> TC •
                    Đã có: <strong><?= $totalCredits ?> TC</strong> (<?= count($currCourses) ?> môn)
                </div>
            </div>
        </div>
    </div>

    <!-- Add course form -->
    <?php if (!empty($availCourses)): ?>
    <div class="acard" style="padding:16px 20px;margin-bottom:12px;">
        <div style="font-size:13px;font-weight:600;color:#334155;margin-bottom:10px;">➕ Thêm môn học vào chương trình</div>
        <form method="POST" style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;">
            <input type="hidden" name="_action"      value="add_course" />
            <input type="hidden" name="curriculum_id" value="<?= (int)$selCid ?>" />
            <div>
                <label style="font-size:11px;font-weight:600;color:#334155;display:block;margin-bottom:3px;">Môn học *</label>
                <select name="course_id" required style="height:36px;padding:0 10px;background:#fff;border:1px solid #e2e8f0;border-radius:8px;font-size:13px;min-width:240px;">
                    <option value="">— Chọn môn —</option>
                    <?php foreach ($availCourses as $co): ?>
                    <option value="<?= (int)$co['course_id'] ?>">
                        <?= h($co['course_code']) ?> – <?= h($co['course_name']) ?> (<?= (int)$co['credits'] ?> TC)
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label style="font-size:11px;font-weight:600;color:#334155;display:block;margin-bottom:3px;">Loại</label>
                <select name="course_type" style="height:36px;padding:0 10px;background:#fff;border:1px solid #e2e8f0;border-radius:8px;font-size:13px;">
                    <option value="Required">Bắt buộc</option>
                    <option value="Elective">Tự chọn</option>
                </select>
            </div>
            <div>
                <label style="font-size:11px;font-weight:600;color:#334155;display:block;margin-bottom:3px;">Học kỳ đề xuất</label>
                <select name="semester_recommended" style="height:36px;padding:0 10px;background:#fff;border:1px solid #e2e8f0;border-radius:8px;font-size:13px;">
                    <option value="">—</option>
                    <?php for ($s=1; $s<=12; $s++): ?>
                    <option value="<?= $s ?>">HK<?= $s ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div style="padding-top:16px;">
                <label style="display:flex;align-items:center;gap:6px;font-size:13px;cursor:pointer;">
                    <input type="checkbox" name="is_required" value="1" checked />
                    <span>Bắt buộc tốt nghiệp</span>
                </label>
            </div>
            <div style="padding-top:14px;">
                <button type="submit" class="abtn abtn--primary abtn--sm">➕ Thêm</button>
            </div>
        </form>
    </div>
    <?php endif; ?>

    <!-- Courses table -->
    <div class="acard" style="padding:0;overflow:hidden;">
        <div class="adm-table-wrap">
            <table class="adm-table">
                <thead>
                    <tr>
                        <th>Mã môn</th>
                        <th>Tên môn học</th>
                        <th style="text-align:center;">TC</th>
                        <th>Loại</th>
                        <th style="text-align:center;">HK đề xuất</th>
                        <th>Bắt buộc TN</th>
                        <th style="width:60px;"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($currCourses)): ?>
                    <tr><td colspan="7" style="text-align:center;padding:32px;color:#94a3b8;font-style:italic;">Chưa có môn học nào trong chương trình này.</td></tr>
                    <?php else: ?>
                    <?php foreach ($currCourses as $cc): ?>
                    <tr>
                        <td><span style="font-family:monospace;font-size:12px;background:#f1f5f9;padding:2px 6px;border-radius:4px;"><?= h($cc['course_code']) ?></span></td>
                        <td style="font-weight:600;color:#0d1b2e;font-size:13px;"><?= h($cc['course_name']) ?></td>
                        <td style="text-align:center;font-weight:700;"><?= (int)$cc['credits'] ?></td>
                        <td>
                            <span class="apill <?= $cc['course_type']==='Required'?'apill--blue':'apill--gray' ?>" style="font-size:10px;">
                                <?= $cc['course_type']==='Required'?'Bắt buộc':'Tự chọn' ?>
                            </span>
                        </td>
                        <td style="text-align:center;">
                            <?= $cc['semester_recommended'] ? 'HK' . (int)$cc['semester_recommended'] : '—' ?>
                        </td>
                        <td style="text-align:center;">
                            <?= $cc['is_required'] ? '✅' : '—' ?>
                        </td>
                        <td>
                            <form method="POST" style="display:inline;"
                                  onsubmit="return confirm('Xóa <?= h(addslashes($cc['course_code'])) ?> khỏi chương trình?')">
                                <input type="hidden" name="_action"      value="remove_course" />
                                <input type="hidden" name="curriculum_id" value="<?= (int)$selCid ?>" />
                                <input type="hidden" name="course_id"    value="<?= (int)$cc['course_id'] ?>" />
                                <button type="submit" style="background:none;border:none;font-size:14px;color:#ef4444;cursor:pointer;">🗑</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
                <?php if (!empty($currCourses)): ?>
                <tfoot>
                    <tr style="background:#f8fafc;">
                        <td colspan="2" style="padding:10px 12px;font-weight:700;font-size:13px;color:#334155;">Tổng cộng</td>
                        <td style="text-align:center;font-weight:800;font-size:14px;color:#0d1b2e;"><?= $totalCredits ?></td>
                        <td colspan="4" style="font-size:12px;color:#64748b;padding:10px 12px;">
                            TC / <?= (int)$selCurriculum['required_credits'] ?> TC yêu cầu
                            <?php if ($totalCredits >= (int)$selCurriculum['required_credits']): ?>
                            <span style="color:#059669;font-weight:700;">✅ Đủ tín chỉ</span>
                            <?php else: ?>
                            <span style="color:#f59e0b;font-weight:700;">
                                ⚠️ Còn thiếu <?= (int)$selCurriculum['required_credits'] - $totalCredits ?> TC
                            </span>
                            <?php endif; ?>
                        </td>
                    </tr>
                </tfoot>
                <?php endif; ?>
            </table>
        </div>
    </div>
    <?php endif; ?>
</div>
</div>

<!-- MODAL: Create/Edit Curriculum -->
<div id="modalCurriculum" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1000;align-items:center;justify-content:center;">
<div style="background:#fff;border-radius:16px;width:100%;max-width:480px;padding:28px;position:relative;">
    <button onclick="this.closest('#modalCurriculum').style.display='none'"
            style="position:absolute;top:16px;right:16px;background:none;border:none;font-size:22px;cursor:pointer;">✕</button>
    <h2 id="modalCurrTitle" style="font-size:18px;font-weight:800;color:#0d1b2e;margin-bottom:20px;">Thêm chương trình đào tạo</h2>
    <form method="POST">
        <input type="hidden" name="_action"       value="save_curriculum" />
        <input type="hidden" name="curriculum_id" id="editCurrId" value="0" />
        <div style="display:grid;gap:14px;">
            <div>
                <label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">Tên chương trình *</label>
                <input type="text" name="curriculum_name" id="editCurrName" required maxlength="150"
                       placeholder="VD: Chương trình Cử nhân CNTT & Quản trị"
                       style="width:100%;height:38px;border:1px solid #e2e8f0;border-radius:8px;padding:0 12px;font-size:13px;outline:none;" />
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;">
                <div>
                    <label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">Mã ngành *</label>
                    <input type="text" name="major_code" id="editCurrMajor" required maxlength="20"
                           placeholder="MIS"
                           style="width:100%;height:38px;border:1px solid #e2e8f0;border-radius:8px;padding:0 10px;font-size:13px;outline:none;" />
                </div>
                <div>
                    <label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">Năm tuyển sinh</label>
                    <input type="number" name="admission_year" id="editCurrYear"
                           min="2000" max="2099" value="<?= date('Y') ?>"
                           style="width:100%;height:38px;border:1px solid #e2e8f0;border-radius:8px;padding:0 10px;font-size:13px;outline:none;" />
                </div>
                <div>
                    <label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">Tổng TC *</label>
                    <input type="number" name="required_credits" id="editCurrCredits"
                           min="30" max="300" value="130" required
                           style="width:100%;height:38px;border:1px solid #e2e8f0;border-radius:8px;padding:0 10px;font-size:13px;outline:none;" />
                </div>
            </div>
        </div>
        <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:20px;">
            <button type="button" onclick="document.getElementById('modalCurriculum').style.display='none'" class="abtn abtn--outline">Hủy</button>
            <button type="submit" class="abtn abtn--primary" style="background:#0d1b2e;">Lưu</button>
        </div>
    </form>
</div></div>

<?php require __DIR__ . '/_layout_end.php'; ?>

<script>
function openCreateModal() {
    document.getElementById('modalCurrTitle').textContent = 'Thêm chương trình đào tạo';
    document.getElementById('editCurrId').value = 0;
    document.getElementById('editCurrName').value = '';
    document.getElementById('editCurrMajor').value = '';
    document.getElementById('editCurrYear').value = new Date().getFullYear();
    document.getElementById('editCurrCredits').value = 130;
    document.getElementById('modalCurriculum').style.display = 'flex';
}
function openEditModal(cid) {
    fetch('curriculum.php?get_curriculum=' + cid)
        .then(r => r.json())
        .then(function(cur) {
            if (!cur || !cur.curriculum_id) return;
            document.getElementById('modalCurrTitle').textContent = 'Chỉnh sửa chương trình';
            document.getElementById('editCurrId').value      = cur.curriculum_id;
            document.getElementById('editCurrName').value    = cur.curriculum_name;
            document.getElementById('editCurrMajor').value   = cur.major_code;
            document.getElementById('editCurrYear').value    = cur.admission_year;
            document.getElementById('editCurrCredits').value = cur.required_credits;
            document.getElementById('modalCurriculum').style.display = 'flex';
        });
}
document.getElementById('modalCurriculum').addEventListener('click', function(e) {
    if (e.target === this) this.style.display = 'none';
});
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') document.getElementById('modalCurriculum').style.display = 'none';
});
</script>
