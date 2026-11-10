<?php
/**
 * UniPortal – Admin: Data Import & Export
 * Import students (CSV), import grades (CSV).
 * Export student list (CSV), grade report (CSV).
 */
$root = dirname(__DIR__, 3);
require_once $root . '/src/src/helpers/auth.php';
require_once $root . '/src/src/services/AcademicAlertService.php';

require_login();
if ($_SESSION['role'] !== 'AcademicAffairs') { header('Location: /UniPortal/public/index.php'); exit; }
if (isset($_GET['logout'])) { auth_logout(); header('Location: /UniPortal/public/index.php'); exit; }

$pdo       = get_db();
$adminAccId= (int)($_SESSION['account_id'] ?? 25);

function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$flash = '';
$importErrors  = [];
$importSuccess = 0;

// ── EXPORT: Student list ──────────────────────────────────────
if (isset($_GET['export']) && $_GET['export'] === 'students') {
    $semId = (int)($_GET['sem'] ?? 0);
    $rows  = $pdo->query(
        "SELECT s.student_id, s.full_name, s.class_code, s.email, s.phone,
                s.gender, s.date_of_birth, s.admission_year, s.status,
                d.department_name,
                ROUND(SUM(gs.grade_point*c.credits)/NULLIF(SUM(c.credits),0),2) AS cum_gpa
         FROM Student s
         LEFT JOIN Department d ON d.department_id=s.department_id
         LEFT JOIN Enrollment e ON e.student_id=s.student_id AND e.status='Completed'
         LEFT JOIN ClassSection cs ON cs.section_id=e.section_id
         LEFT JOIN Course c ON c.course_id=cs.course_id
         LEFT JOIN Assessment a ON a.section_id=cs.section_id
         LEFT JOIN Grade g ON g.enrollment_id=e.enrollment_id AND g.assessment_id=a.assessment_id
         LEFT JOIN GradeScale gs ON (g.score/10) BETWEEN gs.min_score AND gs.max_score
         GROUP BY s.student_id ORDER BY s.student_id"
    )->fetchAll();

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="students_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF)); // BOM for Excel
    fputcsv($out, ['MSSV','Họ tên','Lớp','Email','Điện thoại','Giới tính','Ngày sinh','Năm nhập học','Trạng thái','Khoa','GPA tích lũy']);
    foreach ($rows as $r) {
        fputcsv($out, [
            $r['student_id'], $r['full_name'], $r['class_code'],
            $r['email'], $r['phone'] ?? '', $r['gender'] ?? '',
            $r['date_of_birth'] ?? '', $r['admission_year'],
            $r['status'], $r['department_name'] ?? '',
            $r['cum_gpa'] ?? '',
        ]);
    }
    fclose($out);
    exit;
}

// ── EXPORT: Grade report ──────────────────────────────────────
if (isset($_GET['export']) && $_GET['export'] === 'grades') {
    $semId = (int)($_GET['sem'] ?? 4);
    $rows  = $pdo->prepare(
        "SELECT s.student_id, s.full_name, s.class_code,
                c.course_code, c.course_name, c.credits,
                cs.section_code, a.assessment_name, a.weight_percent,
                g.score,
                gs.letter_grade, gs.grade_point
         FROM Enrollment e
         JOIN Student s ON s.student_id=e.student_id
         JOIN ClassSection cs ON cs.section_id=e.section_id
         JOIN Course c ON c.course_id=cs.course_id
         JOIN Assessment a ON a.section_id=cs.section_id
         LEFT JOIN Grade g ON g.enrollment_id=e.enrollment_id AND g.assessment_id=a.assessment_id
         LEFT JOIN GradeScale gs ON g.score IS NOT NULL AND (g.score/10) BETWEEN gs.min_score AND gs.max_score
         WHERE cs.semester_id=:sem
         ORDER BY s.student_id, c.course_code, a.assessment_name"
    );
    $rows->execute([':sem' => $semId]);
    $data = $rows->fetchAll();

    $semName = '';
    $semRow = $pdo->prepare('SELECT semester_name, academic_year FROM Semester WHERE semester_id=:id LIMIT 1');
    $semRow->execute([':id'=>$semId]);
    if ($sr = $semRow->fetch()) $semName = $sr['semester_name'] . '_' . str_replace('-','', $sr['academic_year']);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="grades_' . $semName . '_' . date('Ymd') . '.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
    fputcsv($out, ['MSSV','Họ tên','Lớp','Mã môn','Tên môn','TC','Mã lớp HP','Bài đánh giá','Trọng số(%)','Điểm (0-100)','Điểm chữ','Hệ 4']);
    foreach ($data as $r) {
        fputcsv($out, [
            $r['student_id'], $r['full_name'], $r['class_code'],
            $r['course_code'], $r['course_name'], $r['credits'],
            $r['section_code'], $r['assessment_name'],
            number_format((float)$r['weight_percent'], 0),
            $r['score'] !== null ? number_format((float)$r['score'], 1) : '',
            $r['letter_grade'] ?? '',
            $r['grade_point'] !== null ? number_format((float)$r['grade_point'], 1) : '',
        ]);
    }
    fclose($out);
    exit;
}

// ── IMPORT: Students ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['import_students'])) {
    csrf_verify();
    if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
        $flash = 'error:Vui lòng chọn file CSV.';
    } else {
        $f    = $_FILES['csv_file'];
        $ext  = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
        if ($ext !== 'csv') {
            $flash = 'error:Chỉ chấp nhận file .csv';
        } else {
            $handle = fopen($f['tmp_name'], 'r');
            $header = fgetcsv($handle); // skip header
            $row    = 0;
            $depts  = $pdo->query('SELECT department_id, department_code FROM Department')->fetchAll(PDO::FETCH_KEY_PAIR);

            while (($data = fgetcsv($handle)) !== false) {
                $row++;
                if (count($data) < 7) {
                    $importErrors[] = "Dòng $row: Thiếu cột (cần ít nhất 7 cột).";
                    continue;
                }
                [$sid, $name, $class, $email, $phone, $gender, $year] = array_map('trim', $data);
                $deptCode = trim($data[7] ?? '');
                $deptId   = $depts[$deptCode] ?? null;

                if (!preg_match('/^\d{8}$/', $sid)) {
                    $importErrors[] = "Dòng $row: MSSV '$sid' không hợp lệ."; continue;
                }
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $importErrors[] = "Dòng $row: Email '$email' không hợp lệ."; continue;
                }

                // Check duplicate
                $ck = $pdo->prepare('SELECT 1 FROM Student WHERE student_id=:s LIMIT 1');
                $ck->execute([':s'=>$sid]);
                if ($ck->fetch()) {
                    $importErrors[] = "Dòng $row: MSSV $sid đã tồn tại – bỏ qua."; continue;
                }

                $pdo->beginTransaction();
                try {
                    $pdo->prepare(
                        'INSERT INTO Student (student_id,full_name,class_code,email,phone,gender,admission_year,department_id,status)
                         VALUES (:sid,:name,:class,:email,:phone,:gender,:year,:dept,\'Active\')'
                    )->execute([
                        ':sid'=>$sid, ':name'=>$name, ':class'=>$class,
                        ':email'=>$email, ':phone'=>$phone?:null,
                        ':gender'=>in_array($gender,['Male','Female','Other'])?$gender:null,
                        ':year'=>(int)$year, ':dept'=>$deptId,
                    ]);
                    $hash = password_hash($sid, PASSWORD_BCRYPT);
                    $pdo->prepare("INSERT INTO Account (username,password_hash,role,student_id,is_active) VALUES (:u,:h,'Student',:s,1)")
                        ->execute([':u'=>$sid,':h'=>$hash,':s'=>$sid]);
                    $pdo->commit();
                    $importSuccess++;
                } catch (Throwable $e) {
                    $pdo->rollBack();
                    $importErrors[] = "Dòng $row: Lỗi – " . $e->getMessage();
                }
            }
            fclose($handle);
            $flash = 'success:Import hoàn tất: ' . $importSuccess . ' sinh viên được thêm.' . (count($importErrors) ? ' Có ' . count($importErrors) . ' lỗi.' : '');
            AcademicAlertService::writeAuditLog($pdo,$adminAccId,'IMPORT_STUDENTS','Student','batch',null,json_encode(['count'=>$importSuccess]));
        }
    }
}

// ── IMPORT: Grades ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['import_grades'])) {
    csrf_verify();
    if (!isset($_FILES['grades_csv']) || $_FILES['grades_csv']['error'] !== UPLOAD_ERR_OK) {
        $flash = 'error:Vui lòng chọn file CSV điểm.';
    } else {
        $f   = $_FILES['grades_csv'];
        $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
        if ($ext !== 'csv') { $flash = 'error:Chỉ chấp nhận file .csv'; }
        else {
            $handle = fopen($f['tmp_name'], 'r');
            fgetcsv($handle); // skip header
            $row = 0; $importSuccess = 0;
            while (($data = fgetcsv($handle)) !== false) {
                $row++;
                // Format: enrollment_id, assessment_id, score
                if (count($data) < 3) { $importErrors[] = "Dòng $row: Thiếu cột."; continue; }
                [$eid, $aid, $score] = array_map('trim', $data);
                if (!is_numeric($eid) || !is_numeric($aid) || !is_numeric($score)) {
                    $importErrors[] = "Dòng $row: Dữ liệu không hợp lệ."; continue;
                }
                $scoreF = (float)$score;
                if ($scoreF < 0 || $scoreF > 100) { $importErrors[] = "Dòng $row: Điểm phải 0-100."; continue; }

                // Verify enrollment exists
                $chk = $pdo->prepare('SELECT 1 FROM Enrollment WHERE enrollment_id=:eid LIMIT 1');
                $chk->execute([':eid'=>(int)$eid]);
                if (!$chk->fetch()) { $importErrors[] = "Dòng $row: enrollment_id $eid không tồn tại."; continue; }

                try {
                    $pdo->prepare(
                        'INSERT INTO Grade (enrollment_id,assessment_id,score,graded_by,graded_at)
                         VALUES (:eid,:aid,:score,:by,NOW())
                         ON DUPLICATE KEY UPDATE score=:score2, graded_by=:by2, graded_at=NOW()'
                    )->execute([':eid'=>(int)$eid,':aid'=>(int)$aid,':score'=>$scoreF,':by'=>$adminAccId,':score2'=>$scoreF,':by2'=>$adminAccId]);
                    AcademicAlertService::afterGradeSave((int)$eid);
                    $importSuccess++;
                } catch (Throwable $e) {
                    $importErrors[] = "Dòng $row: " . $e->getMessage();
                }
            }
            fclose($handle);
            $flash = 'success:Đã nhập ' . $importSuccess . ' điểm.' . (count($importErrors) ? ' Có ' . count($importErrors) . ' lỗi.' : '');
        }
    }
}

$semesters = $pdo->query('SELECT semester_id, semester_name, academic_year FROM Semester ORDER BY start_date DESC')->fetchAll();
$activeSemId = 4;
foreach ($semesters as $s) { if ($s['status'] ?? '' === 'Active') { $activeSemId = (int)$s['semester_id']; break; } }

$warnCount  = (int)$pdo->query("SELECT COUNT(*) FROM AcademicWarning WHERE status='Active' AND is_read=FALSE")->fetchColumn();
$pageTitle  = 'Import & Export dữ liệu';
$activePage = 'import_export';
$breadcrumb = 'Quản trị › Import & Export';
require __DIR__ . '/_layout.php';
?>

<?php if ($flash): [$ft,$fm] = explode(':', $flash, 2); ?>
<div style="padding:12px 16px;border-radius:10px;font-weight:600;font-size:13px;margin-bottom:4px;
            background:<?= $ft==='success'?'#d1fae5':'#fee2e2' ?>;
            border:1px solid <?= $ft==='success'?'#6ee7b7':'#fca5a5' ?>;
            color:<?= $ft==='success'?'#065f46':'#b91c1c' ?>;">
    <?= $ft==='success' ? '✅' : '⚠️' ?> <?= h($fm) ?>
</div>
<?php endif; ?>

<?php if (!empty($importErrors)): ?>
<div style="background:#fff7ed;border:1px solid #fed7aa;border-radius:10px;padding:12px 16px;max-height:200px;overflow-y:auto;">
    <div style="font-weight:700;font-size:13px;color:#c2410c;margin-bottom:6px;">
        ⚠️ <?= count($importErrors) ?> lỗi trong quá trình import:
    </div>
    <?php foreach ($importErrors as $err): ?>
    <div style="font-size:12px;color:#92400e;margin-bottom:2px;">• <?= h($err) ?></div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<h1 style="font-size:20px;font-weight:800;color:#0d1b2e;">Import &amp; Export Dữ liệu</h1>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;">

<!-- EXPORT COLUMN -->
<div style="display:flex;flex-direction:column;gap:16px;">
    <div class="acard" style="padding:22px 24px;">
        <div style="font-size:15px;font-weight:700;color:#0d1b2e;margin-bottom:12px;">⬇ Xuất dữ liệu (CSV)</div>

        <div style="display:flex;flex-direction:column;gap:10px;">
            <div style="padding:14px;background:#f8fafc;border-radius:8px;">
                <div style="font-size:13px;font-weight:600;color:#334155;margin-bottom:8px;">Danh sách sinh viên</div>
                <div style="font-size:12px;color:#64748b;margin-bottom:10px;">
                    Xuất toàn bộ sinh viên với GPA tích lũy (UTF-8 BOM, tương thích Excel).
                </div>
                <a href="import_export.php?export=students"
                   class="abtn abtn--primary abtn--sm" style="display:inline-flex;align-items:center;gap:6px;">
                    ⬇ Tải xuống CSV sinh viên
                </a>
            </div>

            <div style="padding:14px;background:#f8fafc;border-radius:8px;">
                <div style="font-size:13px;font-weight:600;color:#334155;margin-bottom:8px;">Bảng điểm theo học kỳ</div>
                <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                    <select id="exportSemId" style="height:32px;padding:0 8px;border:1px solid #e2e8f0;border-radius:6px;font-size:12px;background:#fff;">
                        <?php foreach ($semesters as $sem): ?>
                        <option value="<?= (int)$sem['semester_id'] ?>" <?= (int)$sem['semester_id']===$activeSemId?'selected':'' ?>>
                            <?= h($sem['semester_name']) ?> <?= h($sem['academic_year']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <a id="exportGradesBtn"
                       href="import_export.php?export=grades&sem=<?= $activeSemId ?>"
                       class="abtn abtn--primary abtn--sm" style="display:inline-flex;align-items:center;gap:6px;">
                        ⬇ Tải xuống CSV điểm
                    </a>
                    <script>
                    document.getElementById('exportSemId').addEventListener('change', function() {
                        document.getElementById('exportGradesBtn').href = 'import_export.php?export=grades&sem=' + this.value;
                    });
                    </script>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- IMPORT COLUMN -->
<div style="display:flex;flex-direction:column;gap:16px;">

    <!-- Import students -->
    <div class="acard" style="padding:22px 24px;">
        <div style="font-size:15px;font-weight:700;color:#0d1b2e;margin-bottom:4px;">⬆ Import sinh viên (CSV)</div>
        <div style="font-size:12px;color:#64748b;margin-bottom:14px;">
            File CSV cần có các cột theo thứ tự:<br/>
            <code style="background:#f1f5f9;padding:2px 5px;border-radius:3px;">MSSV, Họ tên, Lớp, Email, Điện thoại, Giới tính (Male/Female), Năm nhập học, Mã khoa</code>
        </div>
        <!-- Template download -->
        <div style="margin-bottom:12px;">
            <a href="import_export.php?download_template=students"
               style="font-size:12px;color:#3b82f6;text-decoration:none;">📥 Tải file mẫu (template)</a>
        </div>
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="import_students" value="1" />
            <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                <input type="file" name="csv_file" accept=".csv" required style="font-size:13px;" />
                <button type="submit" class="abtn abtn--primary abtn--sm">⬆ Import</button>
            </div>
            <div style="font-size:11px;color:#94a3b8;margin-top:6px;">
                • Tài khoản được tự động tạo với mật khẩu = MSSV<br/>
                • MSSV đã tồn tại sẽ bị bỏ qua
            </div>
        </form>
    </div>

    <!-- Import grades -->
    <div class="acard" style="padding:22px 24px;">
        <div style="font-size:15px;font-weight:700;color:#0d1b2e;margin-bottom:4px;">⬆ Import điểm (CSV)</div>
        <div style="font-size:12px;color:#64748b;margin-bottom:14px;">
            File CSV cần có 3 cột: <code style="background:#f1f5f9;padding:2px 5px;border-radius:3px;">enrollment_id, assessment_id, score (0-100)</code><br/>
            GPA và cảnh báo học vụ sẽ tự động cập nhật.
        </div>
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="import_grades" value="1" />
            <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                <input type="file" name="grades_csv" accept=".csv" required style="font-size:13px;" />
                <button type="submit" class="abtn abtn--primary abtn--sm">⬆ Import điểm</button>
            </div>
            <div style="font-size:11px;color:#94a3b8;margin-top:6px;">
                Điểm đã tồn tại sẽ được ghi đè. Không thể hoàn tác.
            </div>
        </form>
    </div>
</div>
</div>

<?php require __DIR__ . '/_layout_end.php'; ?>
<?php
// Template download
if (isset($_GET['download_template']) && $_GET['download_template'] === 'students') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="student_import_template.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
    fputcsv($out, ['MSSV','Họ tên','Lớp','Email','Điện thoại','Giới tính','Năm nhập học','Mã khoa']);
    fputcsv($out, ['23070099','Nguyen Van A','QH-2023-MIS-CQ','23070099@vnu.edu.vn','0912345678','Male','2023','MIS']);
    fclose($out);
    exit;
}
?>
