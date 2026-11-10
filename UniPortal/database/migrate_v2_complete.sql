-- ============================================================
-- UniPortal – Complete Migration v2
-- Creates missing tables and fills seed data for all features.
-- SAFE to run on a database that already has the base schema.
-- Apply with: mysql -u root uniportal < migrate_v2_complete.sql
-- ============================================================

USE uniportal;

-- ── Extend Semester with reg window columns ──────────────────
ALTER TABLE Semester
    ADD COLUMN IF NOT EXISTS reg_open  DATETIME NULL COMMENT 'Registration window opens',
    ADD COLUMN IF NOT EXISTS reg_close DATETIME NULL COMMENT 'Registration window closes';

-- ── Extend Account with last_login_at ────────────────────────
ALTER TABLE Account
    ADD COLUMN IF NOT EXISTS last_login_at DATETIME NULL;

-- ============================================================
-- TABLE: AuditLog
-- Records admin and system actions for audit/compliance.
-- ============================================================
CREATE TABLE IF NOT EXISTS AuditLog (
    log_id          BIGINT UNSIGNED AUTO_INCREMENT,
    actor_id        INT UNSIGNED        NOT NULL COMMENT 'account_id of the user who performed the action',
    action          VARCHAR(50)         NOT NULL COMMENT 'e.g. CREATE_WARNING, SAVE_GRADE, LOCK_ACCOUNT',
    target_entity   VARCHAR(50)         NOT NULL COMMENT 'Table or entity affected',
    target_id       VARCHAR(50)         NOT NULL COMMENT 'Primary key of affected row',
    old_value_json  TEXT                    NULL,
    new_value_json  TEXT                    NULL,
    ip_address      VARCHAR(45)             NULL,
    created_at      DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (log_id),
    KEY idx_audit_actor   (actor_id),
    KEY idx_audit_entity  (target_entity, target_id),
    KEY idx_audit_created (created_at)
) ENGINE = InnoDB COMMENT = 'Immutable audit trail of all system actions';

-- ============================================================
-- TABLE: Notification
-- Per-account notification queue.
-- ============================================================
CREATE TABLE IF NOT EXISTS Notification (
    notification_id BIGINT UNSIGNED AUTO_INCREMENT,
    account_id      INT UNSIGNED        NOT NULL,
    type            VARCHAR(30)         NOT NULL
                    COMMENT 'Warning | Assignment | Grade | Attendance | Registration | Payment | System',
    title           VARCHAR(200)        NOT NULL,
    body            TEXT                    NULL,
    link            VARCHAR(500)            NULL,
    is_read         BOOLEAN             NOT NULL DEFAULT FALSE,
    created_at      DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (notification_id),
    KEY idx_notif_account (account_id, is_read),

    CONSTRAINT fk_notif_account
        FOREIGN KEY (account_id)
        REFERENCES Account(account_id)
        ON UPDATE CASCADE
        ON DELETE CASCADE
) ENGINE = InnoDB;

-- ============================================================
-- TABLE: SystemConfig
-- Key-value store for academic policy settings.
-- ============================================================
CREATE TABLE IF NOT EXISTS SystemConfig (
    config_key      VARCHAR(80)         NOT NULL,
    config_value    VARCHAR(500)        NOT NULL DEFAULT '',
    description     VARCHAR(300)            NULL,
    updated_at      DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (config_key)
) ENGINE = InnoDB COMMENT = 'Academic policy configuration';

-- ============================================================
-- TABLE: Assignment
-- Lecturer-created assignments linked to a ClassSection.
-- ============================================================
CREATE TABLE IF NOT EXISTS Assignment (
    assignment_id   INT UNSIGNED AUTO_INCREMENT,
    section_id      INT UNSIGNED        NOT NULL,
    title           VARCHAR(200)        NOT NULL,
    description     TEXT                    NULL,
    attachment_path VARCHAR(500)            NULL,
    attachment_name VARCHAR(255)            NULL,
    attachment_size BIGINT                  NULL,
    attachment_mime VARCHAR(100)            NULL,
    due_date        DATETIME            NOT NULL,
    max_score       DECIMAL(5,2)        NOT NULL DEFAULT 10.00,
    is_deleted      BOOLEAN             NOT NULL DEFAULT FALSE,
    created_by      INT UNSIGNED            NULL COMMENT 'account_id of creator',
    created_at      DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME                NULL ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (assignment_id),
    KEY idx_assign_section  (section_id),
    KEY idx_assign_due      (due_date),

    CONSTRAINT fk_assign_section
        FOREIGN KEY (section_id)
        REFERENCES ClassSection(section_id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT
) ENGINE = InnoDB;

-- ============================================================
-- TABLE: Submission
-- Student file submissions for Assignments.
-- ============================================================
CREATE TABLE IF NOT EXISTS Submission (
    submission_id   INT UNSIGNED AUTO_INCREMENT,
    assignment_id   INT UNSIGNED        NOT NULL,
    enrollment_id   INT UNSIGNED        NOT NULL,
    file_path       VARCHAR(500)            NULL,
    file_name       VARCHAR(255)            NULL,
    file_size       BIGINT                  NULL,
    file_mime       VARCHAR(100)            NULL,
    submitted_at    DATETIME                NULL,
    score           DECIMAL(5,2)            NULL,
    feedback        TEXT                    NULL,
    grade_status    ENUM('Draft','Published') NOT NULL DEFAULT 'Draft',

    PRIMARY KEY (submission_id),
    UNIQUE KEY uq_submission (assignment_id, enrollment_id),
    KEY idx_submission_assign (assignment_id),

    CONSTRAINT fk_sub_assignment
        FOREIGN KEY (assignment_id)
        REFERENCES Assignment(assignment_id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,

    CONSTRAINT fk_sub_enrollment
        FOREIGN KEY (enrollment_id)
        REFERENCES Enrollment(enrollment_id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT
) ENGINE = InnoDB;

-- ============================================================
-- TABLE: Tuition
-- Per-student per-semester tuition record.
-- ============================================================
CREATE TABLE IF NOT EXISTS Tuition (
    tuition_id      INT UNSIGNED AUTO_INCREMENT,
    student_id      CHAR(8)             NOT NULL,
    semester_id     INT UNSIGNED        NOT NULL,
    credits         SMALLINT UNSIGNED   NOT NULL DEFAULT 0
                    COMMENT 'Number of credits enrolled this semester',
    amount_vnd      BIGINT UNSIGNED     NOT NULL DEFAULT 0
                    COMMENT 'Total tuition amount in VND',
    due_date        DATE                    NULL,
    status          ENUM('Unpaid','Partial','Paid','Waived') NOT NULL DEFAULT 'Unpaid',
    notes           TEXT                    NULL,
    created_at      DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME                NULL ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (tuition_id),
    UNIQUE KEY uq_tuition (student_id, semester_id),
    KEY idx_tuition_sem (semester_id),

    CONSTRAINT fk_tuition_student
        FOREIGN KEY (student_id)
        REFERENCES Student(student_id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,

    CONSTRAINT fk_tuition_semester
        FOREIGN KEY (semester_id)
        REFERENCES Semester(semester_id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT
) ENGINE = InnoDB;

-- ============================================================
-- TABLE: Payment
-- Payment evidence submissions linked to Tuition records.
-- ============================================================
CREATE TABLE IF NOT EXISTS Payment (
    payment_id      INT UNSIGNED AUTO_INCREMENT,
    tuition_id      INT UNSIGNED            NULL,
    student_id      CHAR(8)             NOT NULL,
    amount_vnd      BIGINT UNSIGNED     NOT NULL,
    method          ENUM('BankTransfer','Cash','Online') NOT NULL DEFAULT 'BankTransfer',
    reference_no    VARCHAR(100)            NULL,
    evidence_path   VARCHAR(500)            NULL,
    evidence_name   VARCHAR(255)            NULL,
    note            TEXT                    NULL,
    status          ENUM('Pending','Confirmed','Rejected') NOT NULL DEFAULT 'Pending',
    reviewed_by     INT UNSIGNED            NULL COMMENT 'account_id of reviewer',
    reviewed_at     DATETIME                NULL,
    created_at      DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (payment_id),
    KEY idx_payment_student  (student_id),
    KEY idx_payment_tuition  (tuition_id),

    CONSTRAINT fk_payment_tuition
        FOREIGN KEY (tuition_id)
        REFERENCES Tuition(tuition_id)
        ON UPDATE CASCADE
        ON DELETE SET NULL,

    CONSTRAINT fk_payment_student
        FOREIGN KEY (student_id)
        REFERENCES Student(student_id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT
) ENGINE = InnoDB;

-- ============================================================
-- SEED: SystemConfig defaults
-- ============================================================
INSERT INTO SystemConfig (config_key, config_value, description) VALUES
-- GPA warning thresholds
('gpa_warning_threshold',    '2.00', 'Cumulative GPA below this triggers LowGPA warning'),
('gpa_suspension_threshold', '1.50', 'Cumulative GPA below this triggers GraduationRisk warning'),
-- Graduation classification thresholds
('grad_excellent_gpa',       '3.60', 'Minimum GPA for Xuất sắc'),
('grad_good_gpa',            '3.20', 'Minimum GPA for Giỏi'),
('grad_average_gpa',         '2.50', 'Minimum GPA for Khá'),
('grad_pass_gpa',            '2.00', 'Minimum GPA for Trung bình (pass)'),
-- Attendance warning thresholds
('attendance_warn_pct',      '15',   'Absent % at or above this triggers Attendance warning'),
('attendance_ban_pct',       '20',   'Absent % at or above this triggers exam-ban risk'),
-- Graduation credit requirement
('required_credits',         '130',  'Total credits required for graduation'),
-- Tuition rate
('tuition_per_credit_vnd',   '800000', 'Tuition fee per credit in VND'),
-- Late submission policy
('late_submission_allowed',  '1',    '1 = students may submit after deadline (marked late)')
AS new_vals
ON DUPLICATE KEY UPDATE config_value = new_vals.config_value;

-- ============================================================
-- SEED: GradeScale (standard VNU-IS 10-point to 4.0 scale)
-- score_10 = Grade.score / 10
-- ============================================================
INSERT IGNORE INTO GradeScale
    (min_score, max_score, letter_grade, grade_point, description)
VALUES
    (9.0, 10.0, 'A+', 4.0, 'Xuất sắc'),
    (8.5,  9.0, 'A',  3.7, 'Giỏi'),
    (8.0,  8.5, 'B+', 3.5, 'Khá giỏi'),
    (7.0,  8.0, 'B',  3.0, 'Khá'),
    (6.5,  7.0, 'C+', 2.5, 'Trung bình khá'),
    (5.5,  6.5, 'C',  2.0, 'Trung bình'),
    (5.0,  5.5, 'D+', 1.5, 'Dưới trung bình'),
    (4.0,  5.0, 'D',  1.0, 'Yếu'),
    (0.0,  4.0, 'F',  0.0, 'Kém / Không đạt');

-- ============================================================
-- SEED: Semesters (HK1 2023-24, HK2 2023-24, HK1 2024-25, HK2 2024-25 active)
-- ============================================================
INSERT IGNORE INTO Semester
    (semester_id, semester_name, academic_year, start_date, end_date, status, reg_open, reg_close)
VALUES
    (1, 'Học kỳ 1', '2023-2024', '2023-09-04', '2024-01-26', 'Completed',
        '2023-08-01', '2023-09-01'),
    (2, 'Học kỳ 2', '2023-2024', '2024-02-05', '2024-06-28', 'Completed',
        '2024-01-10', '2024-02-01'),
    (3, 'Học kỳ 1', '2024-2025', '2024-09-02', '2025-01-24', 'Completed',
        '2024-08-01', '2024-09-01'),
    (4, 'Học kỳ 2', '2024-2025', '2025-02-03', '2025-06-27', 'Active',
        '2025-01-10', '2025-02-01');

-- Active semester reg window for current demo date (2026)
INSERT IGNORE INTO Semester
    (semester_id, semester_name, academic_year, start_date, end_date, status, reg_open, reg_close)
VALUES
    (5, 'Học kỳ 1', '2025-2026', '2025-09-01', '2026-01-23', 'Completed',
        '2025-08-01', '2025-09-01'),
    (6, 'Học kỳ 2', '2025-2026', '2026-02-02', '2026-06-26', 'Completed',
        '2026-01-10', '2026-02-01'),
    (7, 'Học kỳ 1', '2026-2027', '2026-09-07', '2027-01-22', 'Active',
        '2026-08-01 00:00:00', '2027-01-01 00:00:00');

-- Update existing semester 4 to Completed if the new active one is 7
UPDATE Semester SET status='Completed' WHERE semester_id=4 AND status='Active';

-- ============================================================
-- SEED: ClassSections for new semesters (5, 6, 7)
-- ============================================================
INSERT IGNORE INTO ClassSection
    (section_id, course_id, semester_id, lecturer_id, section_code, schedule, room, capacity)
VALUES
    -- Semester 5 (HK1 2025-2026)
    (29, 1, 5, 1, 'MIS2101-5A', 'Thứ 2 & 4 07:30-09:00', 'B2.101', 40),
    (30, 7, 5, 7, 'ICE2101-5A', 'Thứ 3 & 5 09:15-10:45', 'B1.203', 40),
    (31, 3, 5, 3, 'AIT2101-5A', 'Thứ 2 & 5 13:00-14:30', 'C2.301', 40),
    -- Semester 6 (HK2 2025-2026)
    (32, 2, 6, 2, 'BEL2101-6A', 'Thứ 2 & 4 07:30-09:00', 'C1.202', 40),
    (33, 5, 6, 5, 'BDA2101-6A', 'Thứ 3 & 5 09:15-10:45', 'B2.205', 40),
    (34, 8, 6, 8, 'AC2101-6A',  'Thứ 4 & 6 13:00-14:30', 'B3.105', 35),
    -- Semester 7 (HK1 2026-2027 – active)
    (35, 7, 7, 7, 'INS3065-01', 'Thứ 2 & 4 07:30-09:00', 'B2.301', 40),
    (36, 1, 7, 1, 'MIS3101-01', 'Thứ 3 & 5 09:15-10:45', 'B1.401', 40),
    (37, 3, 7, 3, 'AIT3201-01', 'Thứ 4 & 6 13:00-14:30', 'C2.201', 35);

-- ============================================================
-- SEED: Enrollments for student 23070001 in semesters 5 & 6 (Completed) + 7 (Active)
-- ============================================================
INSERT IGNORE INTO Enrollment (student_id, section_id, status, enrollment_date)
VALUES
    -- Sem 5
    ('23070001', 29, 'Completed', '2025-09-01 08:00:00'),
    ('23070001', 30, 'Completed', '2025-09-01 08:00:00'),
    ('23070001', 31, 'Completed', '2025-09-01 08:00:00'),
    -- Sem 6
    ('23070001', 32, 'Completed', '2026-02-02 08:00:00'),
    ('23070001', 33, 'Completed', '2026-02-02 08:00:00'),
    ('23070001', 34, 'Completed', '2026-02-02 08:00:00'),
    -- Sem 7 (current)
    ('23070001', 35, 'Enrolled', '2026-09-07 08:00:00'),
    ('23070001', 36, 'Enrolled', '2026-09-07 08:00:00'),
    ('23070001', 37, 'Enrolled', '2026-09-07 08:00:00');

-- ============================================================
-- SEED: Assessments for new sections
-- ============================================================
INSERT IGNORE INTO Assessment (section_id, assessment_name, weight_percent, max_score, due_date)
VALUES
    -- Sem 5
    (29, 'Kiểm tra giữa kỳ', 40.00, 10.00, '2025-11-15 23:59:00'),
    (29, 'Thi cuối kỳ',      60.00, 10.00, '2026-01-10 23:59:00'),
    (30, 'Kiểm tra giữa kỳ', 40.00, 10.00, '2025-11-16 23:59:00'),
    (30, 'Thi cuối kỳ',      60.00, 10.00, '2026-01-11 23:59:00'),
    (31, 'Kiểm tra giữa kỳ', 40.00, 10.00, '2025-11-17 23:59:00'),
    (31, 'Thi cuối kỳ',      60.00, 10.00, '2026-01-12 23:59:00'),
    -- Sem 6
    (32, 'Kiểm tra giữa kỳ', 40.00, 10.00, '2026-04-15 23:59:00'),
    (32, 'Thi cuối kỳ',      60.00, 10.00, '2026-06-10 23:59:00'),
    (33, 'Kiểm tra giữa kỳ', 40.00, 10.00, '2026-04-16 23:59:00'),
    (33, 'Thi cuối kỳ',      60.00, 10.00, '2026-06-11 23:59:00'),
    (34, 'Kiểm tra giữa kỳ', 40.00, 10.00, '2026-04-17 23:59:00'),
    (34, 'Thi cuối kỳ',      60.00, 10.00, '2026-06-12 23:59:00'),
    -- Sem 7 (active – due dates in future)
    (35, 'Bài tập / Seminar', 30.00, 10.00, '2026-11-30 23:59:00'),
    (35, 'Thi cuối kỳ',       70.00, 10.00, '2027-01-15 23:59:00'),
    (36, 'Kiểm tra giữa kỳ',  40.00, 10.00, '2026-11-28 23:59:00'),
    (36, 'Thi cuối kỳ',       60.00, 10.00, '2027-01-16 23:59:00'),
    (37, 'Đồ án thực hành',    40.00, 10.00, '2026-12-05 23:59:00'),
    (37, 'Thi cuối kỳ',        60.00, 10.00, '2027-01-17 23:59:00');

-- ============================================================
-- SEED: Grades for student 23070001 in semesters 5 & 6
-- Targeting cumulative GPA ~3.45 after 7 semesters
-- ============================================================
-- First get enrollment IDs for the new enrollments
-- We'll use subqueries to find them

INSERT IGNORE INTO Grade (enrollment_id, assessment_id, score, graded_by, graded_at)
SELECT e.enrollment_id,
       (SELECT a.assessment_id FROM Assessment a WHERE a.section_id=e.section_id ORDER BY a.assessment_id ASC LIMIT 1),
       85.00, 1, '2026-01-05 10:00:00'
FROM Enrollment e WHERE e.student_id='23070001' AND e.section_id=29;

INSERT IGNORE INTO Grade (enrollment_id, assessment_id, score, graded_by, graded_at)
SELECT e.enrollment_id,
       (SELECT a.assessment_id FROM Assessment a WHERE a.section_id=e.section_id ORDER BY a.assessment_id DESC LIMIT 1),
       88.00, 1, '2026-01-15 10:00:00'
FROM Enrollment e WHERE e.student_id='23070001' AND e.section_id=29;

INSERT IGNORE INTO Grade (enrollment_id, assessment_id, score, graded_by, graded_at)
SELECT e.enrollment_id,
       (SELECT a.assessment_id FROM Assessment a WHERE a.section_id=e.section_id ORDER BY a.assessment_id ASC LIMIT 1),
       80.00, 7, '2026-01-06 10:00:00'
FROM Enrollment e WHERE e.student_id='23070001' AND e.section_id=30;

INSERT IGNORE INTO Grade (enrollment_id, assessment_id, score, graded_by, graded_at)
SELECT e.enrollment_id,
       (SELECT a.assessment_id FROM Assessment a WHERE a.section_id=e.section_id ORDER BY a.assessment_id DESC LIMIT 1),
       85.00, 7, '2026-01-16 10:00:00'
FROM Enrollment e WHERE e.student_id='23070001' AND e.section_id=30;

INSERT IGNORE INTO Grade (enrollment_id, assessment_id, score, graded_by, graded_at)
SELECT e.enrollment_id,
       (SELECT a.assessment_id FROM Assessment a WHERE a.section_id=e.section_id ORDER BY a.assessment_id ASC LIMIT 1),
       75.00, 3, '2026-01-07 10:00:00'
FROM Enrollment e WHERE e.student_id='23070001' AND e.section_id=31;

INSERT IGNORE INTO Grade (enrollment_id, assessment_id, score, graded_by, graded_at)
SELECT e.enrollment_id,
       (SELECT a.assessment_id FROM Assessment a WHERE a.section_id=e.section_id ORDER BY a.assessment_id DESC LIMIT 1),
       82.00, 3, '2026-01-17 10:00:00'
FROM Enrollment e WHERE e.student_id='23070001' AND e.section_id=31;

-- Sem 6
INSERT IGNORE INTO Grade (enrollment_id, assessment_id, score, graded_by, graded_at)
SELECT e.enrollment_id,
       (SELECT a.assessment_id FROM Assessment a WHERE a.section_id=e.section_id ORDER BY a.assessment_id ASC LIMIT 1),
       88.00, 2, '2026-06-05 10:00:00'
FROM Enrollment e WHERE e.student_id='23070001' AND e.section_id=32;

INSERT IGNORE INTO Grade (enrollment_id, assessment_id, score, graded_by, graded_at)
SELECT e.enrollment_id,
       (SELECT a.assessment_id FROM Assessment a WHERE a.section_id=e.section_id ORDER BY a.assessment_id DESC LIMIT 1),
       90.00, 2, '2026-06-15 10:00:00'
FROM Enrollment e WHERE e.student_id='23070001' AND e.section_id=32;

INSERT IGNORE INTO Grade (enrollment_id, assessment_id, score, graded_by, graded_at)
SELECT e.enrollment_id,
       (SELECT a.assessment_id FROM Assessment a WHERE a.section_id=e.section_id ORDER BY a.assessment_id ASC LIMIT 1),
       82.00, 5, '2026-06-06 10:00:00'
FROM Enrollment e WHERE e.student_id='23070001' AND e.section_id=33;

INSERT IGNORE INTO Grade (enrollment_id, assessment_id, score, graded_by, graded_at)
SELECT e.enrollment_id,
       (SELECT a.assessment_id FROM Assessment a WHERE a.section_id=e.section_id ORDER BY a.assessment_id DESC LIMIT 1),
       87.00, 5, '2026-06-16 10:00:00'
FROM Enrollment e WHERE e.student_id='23070001' AND e.section_id=33;

INSERT IGNORE INTO Grade (enrollment_id, assessment_id, score, graded_by, graded_at)
SELECT e.enrollment_id,
       (SELECT a.assessment_id FROM Assessment a WHERE a.section_id=e.section_id ORDER BY a.assessment_id ASC LIMIT 1),
       78.00, 8, '2026-06-07 10:00:00'
FROM Enrollment e WHERE e.student_id='23070001' AND e.section_id=34;

INSERT IGNORE INTO Grade (enrollment_id, assessment_id, score, graded_by, graded_at)
SELECT e.enrollment_id,
       (SELECT a.assessment_id FROM Assessment a WHERE a.section_id=e.section_id ORDER BY a.assessment_id DESC LIMIT 1),
       83.00, 8, '2026-06-17 10:00:00'
FROM Enrollment e WHERE e.student_id='23070001' AND e.section_id=34;

-- Mark semesters 5 & 6 enrollments as Completed
UPDATE Enrollment SET status='Completed'
WHERE student_id='23070001' AND section_id IN (29,30,31,32,33,34);

-- ============================================================
-- SEED: AttendanceSessions for active semester sections (35,36,37)
-- ============================================================
INSERT IGNORE INTO AttendanceSession (section_id, session_date, start_time, end_time, topic)
VALUES
    (35, '2026-09-09', '07:30:00', '09:00:00', 'Giới thiệu môn học – ERD & mô hình dữ liệu'),
    (35, '2026-09-11', '07:30:00', '09:00:00', 'SQL DDL – Tạo bảng và ràng buộc'),
    (35, '2026-09-16', '07:30:00', '09:00:00', 'SQL DML – INSERT, UPDATE, DELETE'),
    (35, '2026-09-18', '07:30:00', '09:00:00', 'SELECT và mệnh đề WHERE'),
    (35, '2026-09-23', '07:30:00', '09:00:00', 'JOIN nhiều bảng'),
    (35, '2026-09-25', '07:30:00', '09:00:00', 'Truy vấn con và CTE'),
    (35, '2026-09-30', '07:30:00', '09:00:00', 'Stored Procedures & Functions'),
    (35, '2026-10-07', '07:30:00', '09:00:00', 'Index và tối ưu hóa hiệu năng'),
    (35, '2026-10-09', '07:30:00', '09:00:00', 'Giao dịch và kiểm soát đồng thời'),
    (35, '2026-10-14', '07:30:00', '09:00:00', 'Bảo mật CSDL và phân quyền'),
    (36, '2026-09-09', '09:15:00', '10:45:00', 'Giới thiệu MIS và hệ thống thông tin tổ chức'),
    (36, '2026-09-11', '09:15:00', '10:45:00', 'Mô hình quy trình nghiệp vụ (BPM)'),
    (36, '2026-09-16', '09:15:00', '10:45:00', 'ERP và tích hợp hệ thống'),
    (36, '2026-09-18', '09:15:00', '10:45:00', 'Phân tích yêu cầu và UML use-case'),
    (36, '2026-09-23', '09:15:00', '10:45:00', 'Thiết kế hệ thống và wireframe'),
    (36, '2026-09-25', '09:15:00', '10:45:00', 'An toàn thông tin và quản trị rủi ro'),
    (36, '2026-09-30', '09:15:00', '10:45:00', 'BI và hệ hỗ trợ ra quyết định'),
    (36, '2026-10-07', '09:15:00', '10:45:00', 'Cloud và xu hướng chuyển đổi số'),
    (37, '2026-09-10', '13:00:00', '14:30:00', 'Phát triển ứng dụng web – HTML/CSS/JS cơ bản'),
    (37, '2026-09-12', '13:00:00', '14:30:00', 'PHP và lập trình server-side'),
    (37, '2026-09-17', '13:00:00', '14:30:00', 'MySQL và kết nối CSDL từ PHP'),
    (37, '2026-09-19', '13:00:00', '14:30:00', 'REST API cơ bản'),
    (37, '2026-09-24', '13:00:00', '14:30:00', 'Framework Laravel – routing & MVC'),
    (37, '2026-09-26', '13:00:00', '14:30:00', 'Authentication & session management'),
    (37, '2026-10-01', '13:00:00', '14:30:00', 'File upload & xử lý media'),
    (37, '2026-10-08', '13:00:00', '14:30:00', 'Deployment và môi trường sản xuất');

-- ============================================================
-- SEED: Attendance records for student 23070001 in active semester
-- ============================================================
-- Get enrollment IDs for student 23070001 in sections 35, 36, 37
-- and create attendance for the first 8 sessions of section 35 (some absent)

INSERT IGNORE INTO Attendance (session_id, enrollment_id, status, note, recorded_by, recorded_at)
SELECT ats.session_id,
       e.enrollment_id,
       CASE WHEN ats.session_id % 7 = 0 THEN 'Absent'
            WHEN ats.session_id % 11 = 0 THEN 'Late'
            ELSE 'Present'
       END AS status,
       '' AS note,
       7 AS recorded_by,
       NOW() AS recorded_at
FROM AttendanceSession ats
JOIN ClassSection cs ON cs.section_id = ats.section_id
JOIN Enrollment e    ON e.section_id  = cs.section_id AND e.student_id = '23070001'
WHERE cs.section_id = 35 AND ats.session_date < CURDATE();

INSERT IGNORE INTO Attendance (session_id, enrollment_id, status, note, recorded_by, recorded_at)
SELECT ats.session_id,
       e.enrollment_id,
       'Present' AS status, '' AS note, 1 AS recorded_by, NOW()
FROM AttendanceSession ats
JOIN ClassSection cs ON cs.section_id = ats.section_id
JOIN Enrollment e    ON e.section_id  = cs.section_id AND e.student_id = '23070001'
WHERE cs.section_id = 36 AND ats.session_date < CURDATE();

INSERT IGNORE INTO Attendance (session_id, enrollment_id, status, note, recorded_by, recorded_at)
SELECT ats.session_id,
       e.enrollment_id,
       'Present' AS status, '' AS note, 3 AS recorded_by, NOW()
FROM AttendanceSession ats
JOIN ClassSection cs ON cs.section_id = ats.section_id
JOIN Enrollment e    ON e.section_id  = cs.section_id AND e.student_id = '23070001'
WHERE cs.section_id = 37 AND ats.session_date < CURDATE();

-- ============================================================
-- SEED: Tuition records for student 23070001
-- ============================================================
INSERT IGNORE INTO Tuition (student_id, semester_id, credits, amount_vnd, due_date, status)
SELECT '23070001', s.semester_id,
       CASE s.semester_id WHEN 7 THEN 9 ELSE 12 END AS credits,
       CASE s.semester_id WHEN 7 THEN 7200000 ELSE 9600000 END AS amount_vnd,
       DATE_ADD(s.start_date, INTERVAL 30 DAY) AS due_date,
       CASE WHEN s.status='Active' THEN 'Unpaid' ELSE 'Paid' END AS status
FROM Semester s
WHERE s.semester_id IN (1,2,3,4,5,6,7)
  AND NOT EXISTS (
    SELECT 1 FROM Tuition t
    WHERE t.student_id='23070001' AND t.semester_id=s.semester_id
  );

-- ============================================================
-- SEED: AuditLog initial entries
-- ============================================================
INSERT IGNORE INTO AuditLog (actor_id, action, target_entity, target_id, new_value_json, ip_address)
VALUES
    (25, 'SYSTEM_INIT',    'SystemConfig', '0',   '{"note":"Migration v2 applied"}', '127.0.0.1'),
    (25, 'SEED_GRADE_SCALE','GradeScale',  '0',   '{"scales":9}',                    '127.0.0.1'),
    (25, 'SEED_SEMESTERS', 'Semester',    '0',    '{"count":7}',                     '127.0.0.1');

-- ============================================================
-- Update account last_login_at
-- ============================================================
UPDATE Account SET last_login_at = NOW()
WHERE last_login_at IS NULL AND is_active = TRUE;

SELECT 'Migration v2 applied successfully!' AS result;
