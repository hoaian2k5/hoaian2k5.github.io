-- ============================================================
-- UniPortal – Migration v1.2: Attendance seed data + admin nav
-- Safe to run multiple times (INSERT IGNORE / IF NOT EXISTS).
-- Apply with: mysql -u root uniportal < migrate_v1.2_seed.sql
-- ============================================================

USE uniportal;

-- ── Ensure last_login_at exists on Account ───────────────────
ALTER TABLE Account
    ADD COLUMN IF NOT EXISTS last_login_at DATETIME NULL;

-- ============================================================
-- SEED: Attendance records for sessions 1-4
-- session 1 & 2 → section 1 (INS3065-01), date 2026-11-18
-- session 3     → section 2 (INS3064-02), date 2026-11-20
-- session 4     → section 3 (INS3066-01), date 2026-11-19
-- Enrollment IDs: 1-10 in section 1, 11-18 in section 2,
--                 19-22 in section 3 (from seed data)
-- ============================================================

-- Verify enrollment IDs for each section
-- Section 1 (INS3065-01): enrollments 1-10
-- Section 2 (INS3064-02): enrollments 11-18
-- Section 3 (INS3066-01): enrollments 19-22

-- Session 1 – section 1, 2026-11-18 07:30-09:00
INSERT IGNORE INTO Attendance (session_id, enrollment_id, status, note, recorded_by, recorded_at) VALUES
(1, 1,  'Present', '',                    17, NOW()),
(1, 2,  'Present', '',                    17, NOW()),
(1, 3,  'Absent',  'Không báo lý do',     17, NOW()),
(1, 4,  'Present', '',                    17, NOW()),
(1, 5,  'Present', '',                    17, NOW()),
(1, 6,  'Late',    'Muộn 15 phút',        17, NOW()),
(1, 7,  'Present', '',                    17, NOW()),
(1, 8,  'Absent',  '',                    17, NOW()),
(1, 9,  'Present', '',                    17, NOW()),
(1, 10, 'Present', '',                    17, NOW());

-- Session 2 – section 1, 2026-11-18 09:15-10:45
INSERT IGNORE INTO Attendance (session_id, enrollment_id, status, note, recorded_by, recorded_at) VALUES
(2, 1,  'Present', '',                    17, NOW()),
(2, 2,  'Late',    'Muộn 10 phút',        17, NOW()),
(2, 3,  'Present', '',                    17, NOW()),
(2, 4,  'Present', '',                    17, NOW()),
(2, 5,  'Present', '',                    17, NOW()),
(2, 6,  'Present', '',                    17, NOW()),
(2, 7,  'Absent',  '',                    17, NOW()),
(2, 8,  'Present', '',                    17, NOW()),
(2, 9,  'Excused', 'Có đơn xin phép',     17, NOW()),
(2, 10, 'Present', '',                    17, NOW());

-- Session 3 – section 2 (INS3064-02), 2026-11-20
INSERT IGNORE INTO Attendance (session_id, enrollment_id, status, note, recorded_by, recorded_at) VALUES
(3, 11, 'Present', '',                    17, NOW()),
(3, 12, 'Present', '',                    17, NOW()),
(3, 13, 'Present', '',                    17, NOW()),
(3, 14, 'Absent',  '',                    17, NOW()),
(3, 15, 'Present', '',                    17, NOW()),
(3, 16, 'Present', '',                    17, NOW()),
(3, 17, 'Late',    '',                    17, NOW()),
(3, 18, 'Present', '',                    17, NOW());

-- Session 4 – section 3 (INS3066-01), 2026-11-19
INSERT IGNORE INTO Attendance (session_id, enrollment_id, status, note, recorded_by, recorded_at) VALUES
(4, 19, 'Present', '',                    17, NOW()),
(4, 20, 'Absent',  '',                    17, NOW()),
(4, 21, 'Present', '',                    17, NOW()),
(4, 22, 'Present', '',                    17, NOW());

-- ── Seed more Grade rows so GPA pages look populated ─────────
-- enrollment 1 (student 23070001, section 1): add more scores
INSERT IGNORE INTO Grade (enrollment_id, assessment_id, score, graded_by, graded_at) VALUES
(1,  3,  85.00, 1, NOW()),
(1,  4,  78.00, 1, NOW()),
(2,  2,  82.00, 1, NOW()),
(2,  3,  79.00, 1, NOW()),
(3,  2,  70.00, 1, NOW()),
(3,  3,  88.00, 1, NOW()),
(4,  2,  65.00, 1, NOW()),
(4,  3,  72.00, 1, NOW()),
(5,  2,  91.00, 1, NOW()),
(5,  3,  87.00, 1, NOW()),
(11, 6,  76.00, 1, NOW()),
(12, 6,  83.00, 1, NOW()),
(13, 6,  69.00, 1, NOW()),
(14, 6,  92.00, 1, NOW());

-- ── Ensure Enrollment status for graded rows is Completed ────
-- (needed for GPA cumulative calculation)
UPDATE Enrollment SET status = 'Completed'
WHERE enrollment_id IN (1,2,3,4,5)
  AND section_id IN (
      SELECT section_id FROM ClassSection
      WHERE semester_id IN (SELECT semester_id FROM Semester WHERE status='Completed')
  );

-- ── Update accounts last_login_at ───────────────────────────
UPDATE Account SET last_login_at = NOW()
WHERE last_login_at IS NULL AND is_active = TRUE;

SELECT 'Migration v1.2 applied successfully' AS result;
