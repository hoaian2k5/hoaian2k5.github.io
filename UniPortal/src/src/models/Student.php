<?php
/**
 * UniPortal – Student Model
 * All queries are read-only, use prepared statements, and match
 * the actual schema: Student, Department, Account, AcademicWarning.
 */

require_once __DIR__ . '/../config/database.php';

class StudentModel {

    // ── Full profile by student_id ────────────────────────────
    public static function getById(string $student_id): array|false {
        $pdo  = get_db();
        $stmt = $pdo->prepare(
            'SELECT s.student_id, s.full_name, s.class_code, s.phone,
                    s.email, s.gender, s.date_of_birth,
                    s.admission_year, s.status,
                    d.department_name, d.department_code
             FROM Student s
             LEFT JOIN Department d ON d.department_id = s.department_id
             WHERE s.student_id = :sid
             LIMIT 1'
        );
        $stmt->execute([':sid' => $student_id]);
        return $stmt->fetch();
    }

    // ── Unread warning count ──────────────────────────────────
    public static function getUnreadWarningCount(string $student_id): int {
        $pdo  = get_db();
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM AcademicWarning
             WHERE student_id = :sid AND is_read = FALSE AND status = \'Active\''
        );
        $stmt->execute([':sid' => $student_id]);
        return (int) $stmt->fetchColumn();
    }

    // ── Recent active warnings (max 5) ───────────────────────
    public static function getActiveWarnings(string $student_id, int $limit = 5): array {
        $pdo  = get_db();
        $stmt = $pdo->prepare(
            'SELECT warning_id, warning_type, message, created_at, is_read
             FROM AcademicWarning
             WHERE student_id = :sid AND status = \'Active\'
             ORDER BY is_read ASC, created_at DESC
             LIMIT :lim'
        );
        $stmt->bindValue(':sid', $student_id);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    // ── Current active semester ───────────────────────────────
    public static function getCurrentSemester(): array|false {
        $pdo  = get_db();
        $stmt = $pdo->prepare(
            'SELECT semester_id, semester_name, academic_year, start_date, end_date
             FROM Semester
             WHERE status = \'Active\'
             ORDER BY start_date DESC
             LIMIT 1'
        );
        $stmt->execute();
        $row = $stmt->fetch();
        if (!$row) {
            // Fallback: most recent semester
            $stmt2 = $pdo->prepare(
                'SELECT semester_id, semester_name, academic_year, start_date, end_date
                 FROM Semester ORDER BY start_date DESC LIMIT 1'
            );
            $stmt2->execute();
            $row = $stmt2->fetch();
        }
        return $row;
    }

    // ── Upcoming class schedule (next 3 sessions) ────────────
    public static function getUpcomingSessions(string $student_id, int $limit = 3): array {
        $pdo  = get_db();
        $stmt = $pdo->prepare(
            'SELECT ats.session_id, ats.session_date, ats.start_time, ats.end_time,
                    ats.topic, cs.room, cs.schedule,
                    c.course_name, c.course_code, c.credits,
                    CONCAT(l.full_name) AS lecturer_name
             FROM AttendanceSession ats
             JOIN ClassSection cs       ON cs.section_id   = ats.section_id
             JOIN Course c              ON c.course_id     = cs.course_id
             JOIN Lecturer l            ON l.lecturer_id   = cs.lecturer_id
             JOIN Enrollment e          ON e.section_id    = cs.section_id
                                       AND e.student_id    = :sid
                                       AND e.status        = \'Enrolled\'
             WHERE ats.session_date >= CURDATE()
             ORDER BY ats.session_date ASC, ats.start_time ASC
             LIMIT :lim'
        );
        $stmt->bindValue(':sid', $student_id);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    // ── Current semester enrollment list ─────────────────────
    public static function getCurrentEnrollments(string $student_id, int $semester_id): array {
        $pdo  = get_db();
        $stmt = $pdo->prepare(
            'SELECT e.enrollment_id, e.status AS enroll_status,
                    c.course_code, c.course_name, c.credits,
                    cs.section_code, cs.room, cs.schedule,
                    l.full_name AS lecturer_name
             FROM Enrollment e
             JOIN ClassSection cs ON cs.section_id = e.section_id
             JOIN Course c        ON c.course_id   = cs.course_id
             JOIN Lecturer l      ON l.lecturer_id = cs.lecturer_id
             WHERE e.student_id  = :sid
               AND cs.semester_id = :sem
             ORDER BY c.course_code ASC'
        );
        $stmt->execute([':sid' => $student_id, ':sem' => $semester_id]);
        return $stmt->fetchAll();
    }
}
