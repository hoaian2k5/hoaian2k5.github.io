<?php
/**
 * UniPortal – Lecturer Model
 * All queries use prepared statements and match the actual schema.
 * Schema key tables: Lecturer, Account, ClassSection, Course, Semester,
 *   Enrollment, Assessment, Grade, AttendanceSession, Attendance,
 *   AcademicWarning, Student, Department.
 */

require_once __DIR__ . '/../config/database.php';

class LecturerModel {

    // ── Full profile ──────────────────────────────────────────
    public static function getById(int $lecturer_id): array|false {
        $pdo  = get_db();
        $stmt = $pdo->prepare(
            'SELECT l.lecturer_id, l.full_name, l.email, l.phone, l.status,
                    d.department_name, d.department_code
             FROM Lecturer l
             LEFT JOIN Department d ON d.department_id = l.department_id
             WHERE l.lecturer_id = :lid LIMIT 1'
        );
        $stmt->execute([':lid' => $lecturer_id]);
        return $stmt->fetch();
    }

    // ── Sections assigned to lecturer (current & all semesters) ─
    public static function getSections(int $lecturer_id, ?int $semester_id = null): array {
        $pdo  = get_db();
        $sql  =
            'SELECT cs.section_id, cs.section_code, cs.schedule, cs.room, cs.capacity,
                    c.course_id, c.course_code, c.course_name, c.credits,
                    sem.semester_id, sem.semester_name, sem.academic_year, sem.status AS sem_status,
                    (SELECT COUNT(*) FROM Enrollment e
                     WHERE e.section_id = cs.section_id
                       AND e.status IN (\'Enrolled\',\'Completed\')) AS enrolled_count,
                    (SELECT COUNT(*) FROM AcademicWarning aw
                     JOIN Enrollment e2 ON e2.student_id = aw.student_id
                     WHERE e2.section_id = cs.section_id
                       AND aw.status = \'Active\') AS warning_count
             FROM ClassSection cs
             JOIN Course c   ON c.course_id   = cs.course_id
             JOIN Semester sem ON sem.semester_id = cs.semester_id
             WHERE cs.lecturer_id = :lid';
        if ($semester_id) {
            $sql .= ' AND cs.semester_id = :sem';
        }
        $sql .= ' ORDER BY sem.start_date DESC, cs.section_code ASC';
        $stmt = $pdo->prepare($sql);
        $params = [':lid' => $lecturer_id];
        if ($semester_id) $params[':sem'] = $semester_id;
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    // ── Students in a section ─────────────────────────────────
    public static function getStudentsInSection(int $section_id, string $search = ''): array {
        $pdo  = get_db();
        $sql  =
            'SELECT s.student_id, s.full_name, s.class_code, s.email, s.gender,
                    e.enrollment_id, e.status AS enroll_status,
                    (SELECT COUNT(*) FROM Attendance a
                     JOIN AttendanceSession ats ON ats.session_id = a.session_id
                     WHERE a.enrollment_id = e.enrollment_id
                       AND a.status IN (\'Present\',\'Late\')) AS attended,
                    (SELECT COUNT(*) FROM AttendanceSession ats2
                     WHERE ats2.section_id = :sid2) AS total_sessions
             FROM Enrollment e
             JOIN Student s ON s.student_id = e.student_id
             WHERE e.section_id = :sid
               AND e.status IN (\'Enrolled\',\'Completed\')';
        if ($search !== '') {
            $sql .= ' AND (s.student_id LIKE :q OR s.full_name LIKE :q)';
        }
        $sql .= ' ORDER BY s.student_id ASC';
        $stmt = $pdo->prepare($sql);
        $params = [':sid' => $section_id, ':sid2' => $section_id];
        if ($search !== '') $params[':q'] = '%'.$search.'%';
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    // ── Assessments in a section ──────────────────────────────
    public static function getAssessments(int $section_id): array {
        $pdo  = get_db();
        $stmt = $pdo->prepare(
            'SELECT a.assessment_id, a.assessment_name, a.weight_percent,
                    a.max_score, a.due_date,
                    (SELECT COUNT(*) FROM Grade g
                     JOIN Enrollment e ON e.enrollment_id = g.enrollment_id
                     WHERE g.assessment_id = a.assessment_id
                       AND e.section_id = :sid
                       AND g.score IS NOT NULL) AS graded_count,
                    (SELECT COUNT(*) FROM Enrollment e2
                     WHERE e2.section_id = :sid2
                       AND e2.status IN (\'Enrolled\',\'Completed\')) AS enrolled_count
             FROM Assessment a
             WHERE a.section_id = :sid3
             ORDER BY a.due_date ASC'
        );
        $stmt->execute([':sid' => $section_id, ':sid2' => $section_id, ':sid3' => $section_id]);
        return $stmt->fetchAll();
    }

    // ── Grades for an assessment ──────────────────────────────
    public static function getGradesForAssessment(int $assessment_id, int $section_id): array {
        $pdo  = get_db();
        $stmt = $pdo->prepare(
            'SELECT s.student_id, s.full_name, s.class_code,
                    e.enrollment_id,
                    g.grade_id, g.score, g.feedback, g.graded_at
             FROM Enrollment e
             JOIN Student s ON s.student_id = e.student_id
             LEFT JOIN Grade g ON g.enrollment_id = e.enrollment_id
                              AND g.assessment_id = :aid
             WHERE e.section_id = :sid
               AND e.status IN (\'Enrolled\',\'Completed\')
             ORDER BY s.student_id ASC'
        );
        $stmt->execute([':aid' => $assessment_id, ':sid' => $section_id]);
        return $stmt->fetchAll();
    }

    // ── Attendance sessions for a section ─────────────────────
    public static function getAttendanceSessions(int $section_id): array {
        $pdo  = get_db();
        $stmt = $pdo->prepare(
            'SELECT ats.session_id, ats.session_date, ats.start_time, ats.end_time, ats.topic,
                    (SELECT COUNT(*) FROM Attendance a WHERE a.session_id = ats.session_id
                      AND a.status = \'Present\') AS present_count,
                    (SELECT COUNT(*) FROM Attendance a WHERE a.session_id = ats.session_id
                      AND a.status = \'Absent\') AS absent_count,
                    (SELECT COUNT(*) FROM Attendance a WHERE a.session_id = ats.session_id) AS recorded_count
             FROM AttendanceSession ats
             WHERE ats.section_id = :sid
             ORDER BY ats.session_date DESC, ats.start_time DESC'
        );
        $stmt->execute([':sid' => $section_id]);
        return $stmt->fetchAll();
    }

    // ── Attendance for one session (full roster) ───────────────
    public static function getSessionAttendance(int $session_id, int $section_id): array {
        $pdo  = get_db();
        $stmt = $pdo->prepare(
            'SELECT s.student_id, s.full_name, s.class_code,
                    e.enrollment_id,
                    a.attendance_id, a.status AS att_status, a.note,
                    (SELECT COUNT(*) FROM Attendance aa
                     JOIN AttendanceSession ats ON ats.session_id = aa.session_id
                     WHERE aa.enrollment_id = e.enrollment_id
                       AND aa.status = \'Absent\') AS total_absent,
                    (SELECT COUNT(*) FROM AttendanceSession ats2
                     WHERE ats2.section_id = :sid2) AS total_sessions
             FROM Enrollment e
             JOIN Student s ON s.student_id = e.student_id
             LEFT JOIN Attendance a ON a.enrollment_id = e.enrollment_id
                                   AND a.session_id = :sess
             WHERE e.section_id = :sid
               AND e.status IN (\'Enrolled\',\'Completed\')
             ORDER BY s.student_id ASC'
        );
        $stmt->execute([':sess' => $session_id, ':sid' => $section_id, ':sid2' => $section_id]);
        return $stmt->fetchAll();
    }

    // ── Warnings for students in lecturer's sections ──────────
    public static function getWarningsForLecturer(int $lecturer_id, int $limit = 20): array {
        $pdo  = get_db();
        $stmt = $pdo->prepare(
            'SELECT DISTINCT aw.warning_id, aw.warning_type, aw.message,
                    aw.created_at, aw.is_read, aw.status AS warn_status,
                    s.student_id, s.full_name, s.class_code,
                    cs.section_code
             FROM AcademicWarning aw
             JOIN Student s ON s.student_id = aw.student_id
             JOIN Enrollment e ON e.student_id = s.student_id
             JOIN ClassSection cs ON cs.section_id = e.section_id
             WHERE cs.lecturer_id = :lid
             ORDER BY aw.is_read ASC, aw.created_at DESC
             LIMIT :lim'
        );
        $stmt->bindValue(':lid', $lecturer_id, PDO::PARAM_INT);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    // ── Count unread warnings for lecturer's students ─────────
    public static function getUnreadWarningCount(int $lecturer_id): int {
        $pdo  = get_db();
        $stmt = $pdo->prepare(
            'SELECT COUNT(DISTINCT aw.warning_id)
             FROM AcademicWarning aw
             JOIN Enrollment e ON e.student_id = aw.student_id
             JOIN ClassSection cs ON cs.section_id = e.section_id
             WHERE cs.lecturer_id = :lid
               AND aw.is_read = FALSE
               AND aw.status = \'Active\''
        );
        $stmt->execute([':lid' => $lecturer_id]);
        return (int)$stmt->fetchColumn();
    }

    // ── Today's sessions for a lecturer ───────────────────────
    public static function getTodaySessions(int $lecturer_id): array {
        $pdo  = get_db();
        $stmt = $pdo->prepare(
            'SELECT ats.session_id, ats.session_date, ats.start_time, ats.end_time, ats.topic,
                    cs.section_id, cs.section_code, cs.room,
                    c.course_code, c.course_name, c.credits,
                    (SELECT COUNT(*) FROM Enrollment e WHERE e.section_id = cs.section_id
                      AND e.status IN (\'Enrolled\',\'Completed\')) AS enrolled_count,
                    (SELECT COUNT(*) FROM Attendance a WHERE a.session_id = ats.session_id) AS recorded_count
             FROM AttendanceSession ats
             JOIN ClassSection cs ON cs.section_id = ats.section_id
             JOIN Course c ON c.course_id = cs.course_id
             WHERE cs.lecturer_id = :lid
               AND ats.session_date = CURDATE()
             ORDER BY ats.start_time ASC'
        );
        $stmt->execute([':lid' => $lecturer_id]);
        return $stmt->fetchAll();
    }

    // ── All sessions in current week ──────────────────────────
    public static function getWeekSessions(int $lecturer_id): array {
        $pdo  = get_db();
        $stmt = $pdo->prepare(
            'SELECT ats.session_id, ats.session_date, ats.start_time, ats.end_time, ats.topic,
                    cs.section_id, cs.section_code, cs.room,
                    c.course_code, c.course_name, c.credits,
                    (SELECT COUNT(*) FROM Enrollment e WHERE e.section_id = cs.section_id
                      AND e.status IN (\'Enrolled\',\'Completed\')) AS enrolled_count
             FROM AttendanceSession ats
             JOIN ClassSection cs ON cs.section_id = ats.section_id
             JOIN Course c ON c.course_id = cs.course_id
             WHERE cs.lecturer_id = :lid
               AND YEARWEEK(ats.session_date, 1) = YEARWEEK(CURDATE(), 1)
             ORDER BY ats.session_date ASC, ats.start_time ASC'
        );
        $stmt->execute([':lid' => $lecturer_id]);
        return $stmt->fetchAll();
    }

    // ── Pending assessment grading counts ─────────────────────
    public static function getPendingGradingStats(int $lecturer_id): array {
        $pdo  = get_db();
        $stmt = $pdo->prepare(
            'SELECT
                COUNT(DISTINCT a.assessment_id) AS total_assessments,
                SUM(
                    (SELECT COUNT(*) FROM Enrollment e WHERE e.section_id = cs.section_id
                      AND e.status IN (\'Enrolled\',\'Completed\'))
                    - (SELECT COUNT(*) FROM Grade g
                       JOIN Enrollment e2 ON e2.enrollment_id = g.enrollment_id
                       WHERE g.assessment_id = a.assessment_id
                         AND e2.section_id = cs.section_id
                         AND g.score IS NOT NULL)
                ) AS pending_grades
             FROM Assessment a
             JOIN ClassSection cs ON cs.section_id = a.section_id
             WHERE cs.lecturer_id = :lid'
        );
        $stmt->execute([':lid' => $lecturer_id]);
        return $stmt->fetch() ?: ['total_assessments' => 0, 'pending_grades' => 0];
    }

    // ── Save/update a single grade ────────────────────────────
    public static function saveGrade(
        int $enrollment_id,
        int $assessment_id,
        float $score,
        int $graded_by,
        string $feedback = ''
    ): bool {
        $pdo  = get_db();
        // Check enrollment is in a section owned by this lecturer
        $check = $pdo->prepare(
            'SELECT cs.lecturer_id FROM Enrollment e
             JOIN ClassSection cs ON cs.section_id = e.section_id
             WHERE e.enrollment_id = :eid LIMIT 1'
        );
        $check->execute([':eid' => $enrollment_id]);
        $row = $check->fetch();
        if (!$row || (int)$row['lecturer_id'] !== $graded_by) return false;

        $stmt = $pdo->prepare(
            'INSERT INTO Grade (enrollment_id, assessment_id, score, feedback, graded_by, graded_at)
             VALUES (:eid, :aid, :score, :fb, :by, NOW())
             ON DUPLICATE KEY UPDATE score = :score2, feedback = :fb2, graded_by = :by2, graded_at = NOW()'
        );
        $stmt->execute([
            ':eid'    => $enrollment_id,
            ':aid'    => $assessment_id,
            ':score'  => $score,
            ':fb'     => $feedback,
            ':by'     => $graded_by,
            ':score2' => $score,
            ':fb2'    => $feedback,
            ':by2'    => $graded_by,
        ]);
        return $stmt->rowCount() > 0;
    }

    // ── Save attendance for a session (batch) ─────────────────
    public static function saveAttendance(
        int $session_id,
        array $records,   // [enrollment_id => ['status'=>'Present'|'Absent'|'Late'|'Excused', 'note'=>'']]
        int $account_id
    ): int {
        $pdo  = get_db();
        $saved = 0;
        foreach ($records as $enrollment_id => $data) {
            $enrollment_id = (int)$enrollment_id;
            $status = in_array($data['status'], ['Present','Absent','Late','Excused'])
                ? $data['status'] : 'Present';
            $note   = substr($data['note'] ?? '', 0, 255);
            $stmt = $pdo->prepare(
                'INSERT INTO Attendance (session_id, enrollment_id, status, note, recorded_by, recorded_at)
                 VALUES (:sess, :eid, :st, :note, :by, NOW())
                 ON DUPLICATE KEY UPDATE status = :st2, note = :note2, recorded_by = :by2, recorded_at = NOW()'
            );
            $stmt->execute([
                ':sess'  => $session_id,
                ':eid'   => $enrollment_id,
                ':st'    => $status,
                ':note'  => $note,
                ':by'    => $account_id,
                ':st2'   => $status,
                ':note2' => $note,
                ':by2'   => $account_id,
            ]);
            $saved += $stmt->rowCount();
        }
        return $saved;
    }

    // ── Current active semester ───────────────────────────────
    public static function getCurrentSemester(): array|false {
        $pdo  = get_db();
        $stmt = $pdo->query(
            'SELECT semester_id, semester_name, academic_year, start_date, end_date
             FROM Semester WHERE status = \'Active\'
             ORDER BY start_date DESC LIMIT 1'
        );
        $row = $stmt->fetch();
        if (!$row) {
            $stmt2 = $pdo->query('SELECT semester_id, semester_name, academic_year, start_date, end_date FROM Semester ORDER BY start_date DESC LIMIT 1');
            $row = $stmt2->fetch();
        }
        return $row;
    }
}
