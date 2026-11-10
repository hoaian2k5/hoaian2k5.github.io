<?php
/**
 * UniPortal – GPA Model
 *
 * Grade.score is stored 0-100. GradeScale uses 0-10 for min/max_score.
 * Conversion: score_10 = Grade.score / 10
 * GPA point comes from GradeScale.grade_point matched on score_10.
 *
 * Weighted GPA = SUM(grade_point * credits) / SUM(credits)
 * Only 'Completed' enrollments contribute to GPA.
 */

require_once __DIR__ . '/../config/database.php';

class GpaModel {

    // ── Cumulative GPA & total earned credits ─────────────────
    public static function getCumulative(string $student_id): array {
        $pdo  = get_db();
        $stmt = $pdo->prepare(
            'SELECT
                ROUND(
                    SUM(gs.grade_point * c.credits) / NULLIF(SUM(c.credits), 0),
                    2
                )                          AS cumulative_gpa,
                SUM(c.credits)             AS earned_credits
             FROM Enrollment e
             JOIN ClassSection cs  ON cs.section_id  = e.section_id
             JOIN Course c         ON c.course_id    = cs.course_id
             JOIN Assessment a     ON a.section_id   = cs.section_id
             JOIN Grade g          ON g.enrollment_id = e.enrollment_id
                                  AND g.assessment_id = a.assessment_id
             JOIN GradeScale gs    ON (g.score / 10) BETWEEN gs.min_score AND gs.max_score
             WHERE e.student_id = :sid
               AND e.status     = \'Completed\'
             LIMIT 1'
        );
        $stmt->execute([':sid' => $student_id]);
        $row = $stmt->fetch();
        return [
            'cumulative_gpa' => $row['cumulative_gpa'] ?? null,
            'earned_credits' => (int)($row['earned_credits'] ?? 0),
        ];
    }

    // ── GPA for a specific semester ───────────────────────────
    public static function getSemesterGpa(string $student_id, int $semester_id): float|null {
        $pdo  = get_db();
        $stmt = $pdo->prepare(
            'SELECT
                ROUND(
                    SUM(gs.grade_point * c.credits) / NULLIF(SUM(c.credits), 0),
                    2
                ) AS semester_gpa
             FROM Enrollment e
             JOIN ClassSection cs  ON cs.section_id   = e.section_id
             JOIN Course c         ON c.course_id     = cs.course_id
             JOIN Assessment a     ON a.section_id    = cs.section_id
             JOIN Grade g          ON g.enrollment_id = e.enrollment_id
                                  AND g.assessment_id = a.assessment_id
             JOIN GradeScale gs    ON (g.score / 10) BETWEEN gs.min_score AND gs.max_score
             WHERE e.student_id    = :sid
               AND cs.semester_id  = :sem
               AND e.status        = \'Completed\''
        );
        $stmt->execute([':sid' => $student_id, ':sem' => $semester_id]);
        $val = $stmt->fetchColumn();
        return $val !== false ? (float)$val : null;
    }

    // ── Per-semester GPA history (all completed semesters) ───
    public static function getGpaHistory(string $student_id): array {
        $pdo  = get_db();
        $stmt = $pdo->prepare(
            'SELECT
                sem.semester_id,
                sem.semester_name,
                sem.academic_year,
                sem.start_date,
                ROUND(
                    SUM(gs.grade_point * c.credits) / NULLIF(SUM(c.credits), 0),
                    2
                ) AS semester_gpa,
                SUM(c.credits) AS credits_this_sem
             FROM Enrollment e
             JOIN ClassSection cs  ON cs.section_id   = e.section_id
             JOIN Semester sem     ON sem.semester_id  = cs.semester_id
             JOIN Course c         ON c.course_id     = cs.course_id
             JOIN Assessment a     ON a.section_id    = cs.section_id
             JOIN Grade g          ON g.enrollment_id = e.enrollment_id
                                  AND g.assessment_id = a.assessment_id
             JOIN GradeScale gs    ON (g.score / 10) BETWEEN gs.min_score AND gs.max_score
             WHERE e.student_id = :sid
               AND e.status     = \'Completed\'
             GROUP BY sem.semester_id, sem.semester_name, sem.academic_year, sem.start_date
             ORDER BY sem.start_date ASC'
        );
        $stmt->execute([':sid' => $student_id]);
        return $stmt->fetchAll();
    }

    // ── Attendance rate across current semester ───────────────
    public static function getAttendanceRate(string $student_id, int $semester_id): float|null {
        $pdo  = get_db();
        $stmt = $pdo->prepare(
            'SELECT
                COUNT(*)                                          AS total_sessions,
                SUM(a.status IN (\'Present\', \'Late\'))         AS attended
             FROM Attendance a
             JOIN Enrollment e  ON e.enrollment_id = a.enrollment_id
             JOIN ClassSection cs ON cs.section_id = e.section_id
             WHERE e.student_id  = :sid
               AND cs.semester_id = :sem'
        );
        $stmt->execute([':sid' => $student_id, ':sem' => $semester_id]);
        $row = $stmt->fetch();
        if (!$row || (int)$row['total_sessions'] === 0) return null;
        return round((float)$row['attended'] / (float)$row['total_sessions'] * 100, 1);
    }

    // ── Graduation classification from GradeScale thresholds ──
    // Returns label such as 'Xuất sắc', 'Giỏi', 'Khá', 'Trung bình', 'Yếu'
    public static function getClassification(float|null $gpa): string {
        if ($gpa === null) return 'Chưa có dữ liệu';
        if ($gpa >= 3.60) return 'Xuất sắc';
        if ($gpa >= 3.20) return 'Giỏi';
        if ($gpa >= 2.50) return 'Khá';
        if ($gpa >= 2.00) return 'Trung bình';
        return 'Yếu';
    }
}
