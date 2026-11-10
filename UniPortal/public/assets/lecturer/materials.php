<?php
/**
 * UniPortal – Quản lý Bài đánh giá & Tài liệu
 * Create / edit / delete Assessment items for assigned sections.
 * (The "materials" page per the sidebar nav — repurposed as assessment management)
 */
$root = dirname(__DIR__, 3);
require_once $root . '/src/src/helpers/auth.php';
require_once $root . '/src/src/models/Lecturer.php';

require_lecturer();
if (isset($_GET['logout'])) { auth_logout(); header('Location: /UniPortal/public/index.php'); exit; }

$lecId      = (int)($_SESSION['lecturer_id'] ?? 0);
$pdo        = get_db();
$currentSem = LecturerModel::getCurrentSemester();
$semId      = (int)($currentSem['semester_id'] ?? 0);
$sections   = $semId ? LecturerModel::getSections($lecId, $semId) : [];
$warnCount  = LecturerModel::getUnreadWarningCount($lecId);

// ── Validate section ownership ────────────────────────────────
function lecOwnsSection(int $lecId, int $secId, array $sections): bool {
    foreach ($sections as $s) { if ((int)$s['section_id']===$secId) return true; }
    return false;
}

// ── POST handlers ──────────────────────────────────────────────
$flash = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['_action'] ?? '';

    if ($action === 'add_assessment') {
        $secId  = (int)($_POST['section_id']       ?? 0);
        $aname  = trim($_POST['assessment_name']   ?? '');
        $weight = (float)($_POST['weight_percent'] ?? 0);
        $maxsc  = (float)($_POST['max_score']      ?? 10);
        $due    = trim($_POST['due_date']           ?? '');

        if (!lecOwnsSection($lecId, $secId, $sections)) {
            $flash = 'error:Không có quyền tạo bài đánh giá cho lớp này.';
        } elseif ($aname==='' || $weight<=0 || $weight>100 || $maxsc<=0) {
            $flash = 'error:Vui lòng điền đầy đủ: tên, trọng số (1-100), điểm tối đa (>0).';
        } else {
            // Check total weight <= 100
            $totalW = (float)$pdo->prepare('SELECT IFNULL(SUM(weight_percent),0) FROM Assessment WHERE section_id=:sid')
                ->execute([':sid'=>$secId]) ? (function() use ($pdo,$secId) {
                    $s=$pdo->prepare('SELECT IFNULL(SUM(weight_percent),0) AS t FROM Assessment WHERE section_id=:sid');
                    $s->execute([':sid'=>$secId]); return (float)$s->fetchColumn();
                })() : 0;
            if ($totalW + $weight > 100.01) {
                $flash = 'error:Tổng trọng số vượt quá 100%. Hiện tại đã dùng '.number_format($totalW,1).'%.';
            } else {
                try {
                    $pdo->prepare(
                        'INSERT INTO Assessment (section_id,assessment_name,weight_percent,max_score,due_date)
                         VALUES (:sid,:name,:weight,:max,:due)'
                    )->execute([':sid'=>$secId,':name'=>$aname,':weight'=>$weight,':max'=>$maxsc,':due'=>$due?:null]);
                    $flash = 'success:Đã thêm bài đánh giá: '.$aname;
                } catch (Exception $e) {
                    $flash = 'error:Lỗi: '.$e->getMessage();
                }
            }
        }
    }

    if ($action === 'edit_assessment') {
        $aid    = (int)($_POST['assessment_id']    ?? 0);
        $aname  = trim($_POST['assessment_name']   ?? '');
        $weight = (float)($_POST['weight_percent'] ?? 0);
        $maxsc  = (float)($_POST['max_score']      ?? 10);
        $due    = trim($_POST['due_date']           ?? '');

        // Verify ownership via section
        $secChk = $pdo->prepare(
            'SELECT cs.lecturer_id FROM Assessment a JOIN ClassSection cs ON cs.section_id=a.section_id
             WHERE a.assessment_id=:aid LIMIT 1'
        );
        $secChk->execute([':aid'=>$aid]);
        $secOwner = $secChk->fetch();
        if (!$secOwner || (int)$secOwner['lecturer_id'] !== $lecId) {
            $flash = 'error:Không có quyền chỉnh sửa bài đánh giá này.';
        } elseif ($aname==='' || $weight<=0 || $weight>100 || $maxsc<=0) {
            $flash = 'error:Thông tin không hợp lệ.';
        } else {
            $pdo->prepare(
                'UPDATE Assessment SET assessment_name=:name,weight_percent=:weight,max_score=:max,due_date=:due
                 WHERE assessment_id=:aid'
            )->execute([':name'=>$aname,':weight'=>$weight,':max'=>$maxsc,':due'=>$due?:null,':aid'=>$aid]);
            $flash = 'success:Đã cập nhật bài đánh giá.';
        }
    }

    if ($action === 'delete_assessment') {
        $aid = (int)($_POST['assessment_id'] ?? 0);
        $secChk = $pdo->prepare(
            'SELECT cs.lecturer_id FROM Assessment a JOIN ClassSection cs ON cs.section_id=a.section_id
             WHERE a.assessment_id=:aid LIMIT 1'
        );
        $secChk->execute([':aid'=>$aid]);
        $secOwner = $secChk->fetch();
        if (!$secOwner || (int)$secOwner['lecturer_id'] !== $lecId) {
            $flash = 'error:Không có quyền xóa bài đánh giá này.';
        } else {
            $hasGrades = (int)$pdo->prepare('SELECT COUNT(*) FROM Grade WHERE assessment_id=:aid')
                ->execute([':aid'=>$aid]) ? (function() use ($pdo,$aid) {
                    $s=$pdo->prepare('SELECT COUNT(*) FROM Grade WHERE assessment_id=:aid');
                    $s->execute([':aid'=>$aid]); return (int)$s->fetchColumn();
                })() : 0;
            if ($hasGrades > 0) {
                $flash = 'error:Không thể xóa vì đã có '.($hasGrades).' bản ghi điểm liên quan.';
            } else {
                $pdo->prepare('DELETE FROM Assessment WHERE assessment_id=:aid')->execute([':aid'=>$aid]);
                $flash = 'success:Đã xóa bài đánh giá.';
            }
        }
    }
}

// ── Selected section ──────────────────────────────────────────
$selSection = (int)($_GET['section'] ?? 0);
if (!$selSection && !empty($sections)) $selSection = (int)$sections[0]['section_id'];

$selSectionInfo = null;
foreach ($sections as $s) { if ((int)$s['section_id']===$selSection) { $selSectionInfo=$s; break; } }

$assessments = $selSection ? LecturerModel::getAssessments($selSection) : [];

// Total weight for selected section
$totalWeight = array_sum(array_column($assessments, 'weight_percent'));

function h($v):string{return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}

$pageTitle  = 'Quản lý bài đánh giá';
$activePage = 'materials';
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

<!-- Title -->
<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
    <h1 style="font-size:20px;font-weight:800;color:#001634;">Quản lý Bài đánh giá</h1>
    <button onclick="showModal('modalAdd')" class="lbtn lbtn--primary">➕ Thêm bài đánh giá</button>
</div>
<p style="font-size:13px;color:#64748b;">Tạo và quản lý các bài tập, bài thi, đồ án cho từng lớp học phần.</p>

<!-- Section selector -->
<?php if (!empty($sections)): ?>
<div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
    <span style="font-size:13px;font-weight:600;color:#64748b;">Lớp:</span>
    <?php foreach ($sections as $s): ?>
    <a href="materials.php?section=<?= (int)$s['section_id'] ?>"
       class="lpill <?= (int)$s['section_id']===$selSection?'lpill--blue':'lpill--gray' ?>">
        <?= h($s['section_code']) ?> • <?= (int)$s['enrolled_count'] ?> SV
    </a>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Weight meter -->
<?php if ($selSectionInfo): ?>
<div class="lcard" style="padding:16px 20px;">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;">
        <div>
            <span style="font-size:14px;font-weight:700;color:#001634;"><?= h($selSectionInfo['section_code']) ?></span>
            <span style="font-size:13px;color:#64748b;margin-left:8px;">– <?= h($selSectionInfo['course_name']) ?></span>
        </div>
        <span style="font-size:13px;font-weight:700;color:<?= $totalWeight>100?'#ef4444':($totalWeight==100?'#22c55e':'#f59e0b') ?>;">
            Tổng trọng số: <?= number_format($totalWeight,1) ?>%
            <?= $totalWeight==100?'✅':($totalWeight>100?'⚠️ Vượt 100%':'⚠️ Chưa đủ 100%') ?>
        </span>
    </div>
    <div style="height:8px;background:#e5eeff;border-radius:9999px;overflow:hidden;">
        <div style="height:100%;width:<?= min($totalWeight,100) ?>%;background:<?= $totalWeight>100?'#ef4444':($totalWeight==100?'#22c55e':'#3b82f6') ?>;border-radius:9999px;transition:width .3s;"></div>
    </div>
</div>
<?php endif; ?>

<!-- Assessment table -->
<div class="lcard" style="padding:0;overflow:hidden;">
    <div class="lec-table-wrap">
        <table class="lec-table">
            <thead>
                <tr>
                    <th>Tên bài đánh giá</th>
                    <th style="text-align:center;">Trọng số</th>
                    <th style="text-align:center;">Điểm tối đa</th>
                    <th>Hạn chót</th>
                    <th style="text-align:center;">Đã chấm / Sĩ số</th>
                    <th>Thao tác</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($assessments)): ?>
                <tr><td colspan="6" style="text-align:center;padding:40px;color:#94a3b8;font-style:italic;">
                    Chưa có bài đánh giá nào. Nhấn "Thêm bài đánh giá" để bắt đầu.
                </td></tr>
                <?php else: ?>
                <?php foreach ($assessments as $a):
                    $graded   = (int)$a['graded_count'];
                    $enrolled = (int)$a['enrolled_count'];
                    $pct      = $enrolled>0 ? round($graded/$enrolled*100) : 0;
                    $isOverdue= $a['due_date'] && strtotime($a['due_date']) < time();
                ?>
                <tr>
                    <td>
                        <div style="font-weight:700;color:#001634;font-size:13px;"><?= h($a['assessment_name']) ?></div>
                        <div style="font-size:11px;color:#94a3b8;">ID: #<?= (int)$a['assessment_id'] ?></div>
                    </td>
                    <td style="text-align:center;">
                        <span style="font-size:16px;font-weight:800;color:#3b82f6;"><?= number_format((float)$a['weight_percent'],0) ?>%</span>
                    </td>
                    <td style="text-align:center;font-weight:700;"><?= number_format((float)$a['max_score'],0) ?></td>
                    <td style="font-size:12px;color:<?= $isOverdue?'#ef4444':'#44474e' ?>;">
                        <?php if ($a['due_date']): ?>
                        <?= date('d/m/Y H:i', strtotime($a['due_date'])) ?>
                        <?php if ($isOverdue): ?><div style="font-size:10px;font-weight:700;">Đã hết hạn</div><?php endif; ?>
                        <?php else: ?>
                        <span style="color:#94a3b8;">Không giới hạn</span>
                        <?php endif; ?>
                    </td>
                    <td style="text-align:center;">
                        <div style="font-size:13px;font-weight:700;color:<?= $pct>=100?'#22c55e':($pct>0?'#3b82f6':'#94a3b8') ?>;">
                            <?= $graded ?>/<?= $enrolled ?>
                        </div>
                        <div style="height:4px;background:#e5eeff;border-radius:9999px;overflow:hidden;min-width:60px;margin-top:3px;">
                            <div style="height:100%;width:<?= $pct ?>%;background:<?= $pct>=100?'#22c55e':'#3b82f6' ?>;border-radius:9999px;"></div>
                        </div>
                        <div style="font-size:10px;color:#94a3b8;"><?= $pct ?>%</div>
                    </td>
                    <td>
                        <div style="display:flex;gap:4px;flex-wrap:wrap;">
                            <a href="assignments.php?section=<?= $selSection ?>&assessment=<?= (int)$a['assessment_id'] ?>"
                               class="lbtn lbtn--primary lbtn--sm">→ Chấm điểm</a>
                            <button onclick="openEditModal(<?= htmlspecialchars(json_encode($a),ENT_QUOTES) ?>)"
                                    class="lbtn lbtn--outline lbtn--sm">✏</button>
                            <?php if ($graded === 0): ?>
                            <form method="POST" style="display:inline;" onsubmit="return confirm('Xóa bài đánh giá này?')">
                                <input type="hidden" name="_action"       value="delete_assessment" />
                                <input type="hidden" name="assessment_id" value="<?= (int)$a['assessment_id'] ?>" />
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

<!-- ══ MODAL: ADD ══════════════════════════════════════════════ -->
<div id="modalAdd" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1000;align-items:center;justify-content:center;">
<div style="background:#fff;border-radius:16px;width:100%;max-width:500px;padding:28px;position:relative;max-height:90vh;overflow-y:auto;">
    <button onclick="closeModal('modalAdd')" style="position:absolute;top:16px;right:16px;background:none;border:none;font-size:20px;cursor:pointer;">✕</button>
    <h2 style="font-size:18px;font-weight:800;color:#001634;margin-bottom:20px;">➕ Thêm bài đánh giá</h2>
    <form method="POST">
        <input type="hidden" name="_action" value="add_assessment" />
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
            <div style="grid-column:1/-1;"><label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">Lớp học phần *</label>
                <select name="section_id" required style="width:100%;height:36px;border:1px solid #e2e8f0;border-radius:8px;padding:0 10px;font-size:13px;background:#fff;outline:none;">
                    <?php foreach ($sections as $s): ?>
                    <option value="<?= (int)$s['section_id'] ?>" <?= (int)$s['section_id']===$selSection?'selected':'' ?>>
                        <?= h($s['section_code'].' – '.$s['course_name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select></div>
            <div style="grid-column:1/-1;"><label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">Tên bài đánh giá *</label>
                <input type="text" name="assessment_name" placeholder="VD: Bài tập Lab 01" required
                       style="width:100%;height:36px;border:1px solid #e2e8f0;border-radius:8px;padding:0 10px;font-size:13px;outline:none;" /></div>
            <div><label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">Trọng số (%) *</label>
                <input type="number" name="weight_percent" min="1" max="100" step="1" placeholder="10" required
                       style="width:100%;height:36px;border:1px solid #e2e8f0;border-radius:8px;padding:0 10px;font-size:13px;outline:none;" />
                <div style="font-size:11px;color:#94a3b8;margin-top:3px;">Còn lại: <?= max(0, 100-$totalWeight) ?>%</div>
            </div>
            <div><label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">Điểm tối đa *</label>
                <input type="number" name="max_score" min="1" max="100" value="10" required
                       style="width:100%;height:36px;border:1px solid #e2e8f0;border-radius:8px;padding:0 10px;font-size:13px;outline:none;" /></div>
            <div style="grid-column:1/-1;"><label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">Hạn chót nộp bài</label>
                <input type="datetime-local" name="due_date"
                       style="width:100%;height:36px;border:1px solid #e2e8f0;border-radius:8px;padding:0 10px;font-size:13px;outline:none;" /></div>
        </div>
        <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:16px;">
            <button type="button" onclick="closeModal('modalAdd')" class="lbtn lbtn--outline">Hủy</button>
            <button type="submit" class="lbtn lbtn--primary">Thêm bài đánh giá</button>
        </div>
    </form>
</div></div>

<!-- ══ MODAL: EDIT ═════════════════════════════════════════════ -->
<div id="modalEdit" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1000;align-items:center;justify-content:center;">
<div style="background:#fff;border-radius:16px;width:100%;max-width:480px;padding:28px;position:relative;max-height:90vh;overflow-y:auto;">
    <button onclick="closeModal('modalEdit')" style="position:absolute;top:16px;right:16px;background:none;border:none;font-size:20px;cursor:pointer;">✕</button>
    <h2 style="font-size:18px;font-weight:800;color:#001634;margin-bottom:20px;">✏ Chỉnh sửa bài đánh giá</h2>
    <form method="POST">
        <input type="hidden" name="_action"       value="edit_assessment" />
        <input type="hidden" name="assessment_id" id="edit_aid" />
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
            <div style="grid-column:1/-1;"><label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">Tên bài đánh giá *</label>
                <input type="text" name="assessment_name" id="edit_aname" required
                       style="width:100%;height:36px;border:1px solid #e2e8f0;border-radius:8px;padding:0 10px;font-size:13px;outline:none;" /></div>
            <div><label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">Trọng số (%)</label>
                <input type="number" name="weight_percent" id="edit_weight" min="1" max="100" step="1" required
                       style="width:100%;height:36px;border:1px solid #e2e8f0;border-radius:8px;padding:0 10px;font-size:13px;outline:none;" /></div>
            <div><label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">Điểm tối đa</label>
                <input type="number" name="max_score" id="edit_max" min="1" max="100" required
                       style="width:100%;height:36px;border:1px solid #e2e8f0;border-radius:8px;padding:0 10px;font-size:13px;outline:none;" /></div>
            <div style="grid-column:1/-1;"><label style="font-size:12px;font-weight:600;color:#334155;display:block;margin-bottom:4px;">Hạn chót nộp bài</label>
                <input type="datetime-local" name="due_date" id="edit_due"
                       style="width:100%;height:36px;border:1px solid #e2e8f0;border-radius:8px;padding:0 10px;font-size:13px;outline:none;" /></div>
        </div>
        <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:16px;">
            <button type="button" onclick="closeModal('modalEdit')" class="lbtn lbtn--outline">Hủy</button>
            <button type="submit" class="lbtn lbtn--primary">Lưu thay đổi</button>
        </div>
    </form>
</div></div>

<?php require __DIR__ . '/_layout_end.php'; ?>

<script>
function showModal(id){document.getElementById(id).style.display='flex';document.body.style.overflow='hidden';}
function closeModal(id){document.getElementById(id).style.display='none';document.body.style.overflow='';}
['modalAdd','modalEdit'].forEach(function(id){
    var el=document.getElementById(id);
    if(el)el.addEventListener('click',function(e){if(e.target===this)closeModal(id);});
});
document.addEventListener('keydown',function(e){if(e.key==='Escape')['modalAdd','modalEdit'].forEach(closeModal);});

function openEditModal(a) {
    document.getElementById('edit_aid').value    = a.assessment_id;
    document.getElementById('edit_aname').value  = a.assessment_name;
    document.getElementById('edit_weight').value = a.weight_percent;
    document.getElementById('edit_max').value    = a.max_score;
    // Format due_date to datetime-local
    if (a.due_date) {
        var d = a.due_date.replace(' ','T').substring(0,16);
        document.getElementById('edit_due').value = d;
    } else {
        document.getElementById('edit_due').value = '';
    }
    showModal('modalEdit');
}
</script>
