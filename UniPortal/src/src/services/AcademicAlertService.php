<?php
/**
 * UniPortal – Academic Alert Service
 *
 * recalcGpa($student_id)
 *   → Calculates cumulative GPA from all Completed enrollments.
 *   → Checks attendance in the Active semester.
 *   → Creates / resolves AcademicWarning rows.
 *   → Creates Notification rows for the student's Account.
 *   → Writes to AuditLog.
 *   → Returns ['gpa'=>float|null, 'earned_credits'=>int, 'warnings_created'=>int]
 *
 * Called after every grade save or attendance update.
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/SystemConfig.php';
require_once __DIR__ . '/NotificationService.php';

class AcademicAlertService {

    // ── Main entry point ──────────────────────────────────────
    public static function recalcGpa(string $student_id): array {
        $pdo = get_db();

        // 1. Compute cumulative GPA (same logic as GpaModel)
        $stmt = $pdo->prepare(
            'SELECT
                ROUND(SUM(gs.grade_point * c.credits) / NULLIF(SUM(c.credits), 0), 2) AS cum_gpa,
                SUM(c.credits) AS earned_credits
             FROM Enrollment e
             JOIN ClassSection cs ON cs.section_id  = e.section_id
             JOIN Course        c  ON c.course_id    = cs.course_id
             JOIN Assessment    a  ON a.section_id   = cs.section_id
             JOIN Grade         g  ON g.enrollment_id = e.enrollment_id
                                  AND g.assessment_id  = a.assessment_id
             JOIN GradeScale    gs ON (g.score / 10) BETWEEN gs.min_score AND gs.max_score
             WHERE e.student_id = :sid
               AND e.status     = \'Completed\''
        );
        $stmt->execute([':sid' => $student_id]);
        $row   = $stmt->fetch();
        $gpa   = $row['cum_gpa']      !== null ? (float)$row['cum_gpa']  : null;
        $cred  = (int)($row['earned_credits'] ?? 0);

        $warningsCreated = 0;

        // 2. GPA-based warnings
        if ($gpa !== null) {
            $warningsCreated += self::checkAndCreateWarning(
                $pdo,
                $student_id,
                'LowGPA',
                SystemConfig::isLowGpa($gpa),
                sprintf(
                    'GPA tích lũy hiện tại là %.2f – dưới ngưỡng cảnh báo %.2f. Cần cải thiện kết quả học tập.',
                    $gpa,
                    SystemConfig::getFloat('gpa_warning_threshold', 2.00)
                )
            );

            $warningsCreated += self::checkAndCreateWarning(
                $pdo,
                $student_id,
                'GraduationRisk',
                $gpa < SystemConfig::getFloat('gpa_suspension_threshold', 1.50),
                sprintf(
                    'GPA tích lũy %.2f thấp nguy hiểm. Có nguy cơ đình chỉ học hoặc không đủ điều kiện tốt nghiệp.',
                    $gpa
                )
            );
        }

        // 3. Attendance-based warnings (Active semester only)
        $activeSem = self::getActiveSemesterId($pdo);
        if ($activeSem) {
            $attStmt = $pdo->prepare(
                'SELECT
                    COUNT(*) AS total,
                    SUM(a.status = \'Absent\') AS absent_count
                 FROM Attendance a
                 JOIN Enrollment e  ON e.enrollment_id = a.enrollment_id
                 JOIN ClassSection cs ON cs.section_id = e.section_id
                 WHERE e.student_id  = :sid
                   AND cs.semester_id = :sem'
            );
            $attStmt->execute([':sid' => $student_id, ':sem' => $activeSem]);
            $att = $attStmt->fetch();
            $total  = (int)($att['total'] ?? 0);
            $absent = (int)($att['absent_count'] ?? 0);

            if ($total > 0) {
                $absentPct = $absent / $total * 100;
                $warningsCreated += self::checkAndCreateWarning(
                    $pdo,
                    $student_id,
                    'Attendance',
                    SystemConfig::isAttendanceWarn($absentPct),
                    sprintf(
                        'Tỷ lệ vắng học %.1f%% (%d/%d buổi). %s',
                        $absentPct,
                        $absent,
                        $total,
                        SystemConfig::isAttendanceBan($absentPct)
                            ? 'CÓ NGUY CƠ BỊ CẤM THI!'
                            : 'Cần cải thiện chuyên cần.'
                    )
                );
            }
        }

        return [
            'gpa'              => $gpa,
            'earned_credits'   => $cred,
            'warnings_created' => $warningsCreated,
        ];
    }

    // ── Check condition → create or auto-resolve warning ─────
    private static function checkAndCreateWarning(
        PDO    $pdo,
        string $student_id,
        string $warning_type,
        bool   $condition,
        string $message
    ): int {
        // Find existing Active warning of this type
        $find = $pdo->prepare(
            'SELECT warning_id FROM AcademicWarning
             WHERE student_id=:sid AND warning_type=:type AND status=\'Active\'
             LIMIT 1'
        );
        $find->execute([':sid' => $student_id, ':type' => $warning_type]);
        $existing = $find->fetchColumn();

        if ($condition && !$existing) {
            // Create new warning
            $ins = $pdo->prepare(
                'INSERT INTO AcademicWarning (student_id, warning_type, message, status, is_read)
                 VALUES (:sid, :type, :msg, \'Active\', FALSE)'
            );
            $ins->execute([':sid' => $student_id, ':type' => $warning_type, ':msg' => $message]);

            // Notify student
            $accStmt = $pdo->prepare('SELECT account_id FROM Account WHERE student_id=:sid LIMIT 1');
            $accStmt->execute([':sid' => $student_id]);
            $accId = (int)$accStmt->fetchColumn();
            if ($accId) {
                $typeLabels = [
                    'LowGPA'          => 'GPA thấp',
                    'Attendance'      => 'Cảnh báo chuyên cần',
                    'GraduationRisk'  => 'Nguy cơ tốt nghiệp',
                    'LowGrade'        => 'Điểm thấp',
                ];
                NotificationService::send(
                    $accId,
                    'Warning',
                    '⚠️ Cảnh báo học vụ: ' . ($typeLabels[$warning_type] ?? $warning_type),
                    $message,
                    '/UniPortal/public/assets/student/alerts.php'
                );
            }

            // Audit log
            self::writeAuditLog($pdo, 25, 'CREATE_WARNING', 'AcademicWarning', $student_id,
                null, json_encode(['type' => $warning_type, 'msg' => $message]));

            return 1;
        }

        if (!$condition && $existing) {
            // Auto-resolve when condition cleared
            $res = $pdo->prepare(
                'UPDATE AcademicWarning SET status=\'Resolved\', is_read=TRUE
                 WHERE warning_id=:wid'
            );
            $res->execute([':wid' => $existing]);
        }

        return 0;
    }

    // ── Trigger after grade save ──────────────────────────────
    public static function afterGradeSave(int $enrollment_id): void {
        try {
            $pdo  = get_db();
            $stmt = $pdo->prepare('SELECT student_id FROM Enrollment WHERE enrollment_id=:eid LIMIT 1');
            $stmt->execute([':eid' => $enrollment_id]);
            $sid  = $stmt->fetchColumn();
            if ($sid) self::recalcGpa($sid);
        } catch (Throwable) {}
    }

    // ── Trigger after attendance save ─────────────────────────
    public static function afterAttendanceSave(int $enrollment_id): void {
        self::afterGradeSave($enrollment_id); // same student lookup
    }

    // ── Helpers ───────────────────────────────────────────────
    private static function getActiveSemesterId(PDO $pdo): ?int {
        $row = $pdo->query(
            "SELECT semester_id FROM Semester WHERE status='Active' ORDER BY start_date DESC LIMIT 1"
        )->fetch();
        return $row ? (int)$row['semester_id'] : null;
    }

    public static function writeAuditLog(
        PDO    $pdo,
        int    $actor_id,
        string $action,
        string $target_entity,
        string $target_id,
        ?string $old_json = null,
        ?string $new_json = null
    ): void {
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO AuditLog
                    (actor_id, action, target_entity, target_id,
                     old_value_json, new_value_json, ip_address)
                 VALUES (:actor, :action, :entity, :target,
                         :old, :new, :ip)'
            );
            $stmt->execute([
                ':actor'  => $actor_id,
                ':action' => $action,
                ':entity' => $target_entity,
                ':target' => $target_id,
                ':old'    => $old_json,
                ':new'    => $new_json,
                ':ip'     => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
            ]);
        } catch (Throwable) {}
    }
}
