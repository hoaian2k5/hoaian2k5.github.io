
-- =========================================================
-- STUDENT ACADEMIC MANAGEMENT SYSTEM
-- Database: uniportal
-- MySQL 8.0.16+
-- Total: 16 tables
-- WARNING: Existing tables and their data will be deleted.
-- =========================================================

CREATE DATABASE IF NOT EXISTS uniportal
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE uniportal;

-- =========================================================
-- 0. RESET TABLES
-- =========================================================

SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS AcademicWarning;
DROP TABLE IF EXISTS Attendance;
DROP TABLE IF EXISTS AttendanceSession;
DROP TABLE IF EXISTS Grade;
DROP TABLE IF EXISTS Assessment;
DROP TABLE IF EXISTS Enrollment;
DROP TABLE IF EXISTS ClassSection;
DROP TABLE IF EXISTS CurriculumCourse;
DROP TABLE IF EXISTS GradeScale;
DROP TABLE IF EXISTS Semester;
DROP TABLE IF EXISTS Curriculum;
DROP TABLE IF EXISTS Course;
DROP TABLE IF EXISTS Account;
DROP TABLE IF EXISTS Lecturer;
DROP TABLE IF EXISTS Student;
DROP TABLE IF EXISTS Department;

SET FOREIGN_KEY_CHECKS = 1;

-- =========================================================
-- 1. DEPARTMENT
-- =========================================================

CREATE TABLE Department (
    department_id INT UNSIGNED AUTO_INCREMENT,
    department_name VARCHAR(100) NOT NULL,
    department_code VARCHAR(20) NOT NULL,

    PRIMARY KEY (department_id),
    UNIQUE KEY uq_department_name (department_name),
    UNIQUE KEY uq_department_code (department_code)
) ENGINE = InnoDB;

-- =========================================================
-- 2. STUDENT
-- =========================================================

CREATE TABLE Student (
    student_id CHAR(8) NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    class_code VARCHAR(20) NOT NULL,
    phone VARCHAR(15),
    email VARCHAR(100) NOT NULL,
    gender ENUM('Male', 'Female', 'Other'),
    date_of_birth DATE,
    department_id INT UNSIGNED,
    admission_year YEAR NOT NULL,
    status ENUM(
        'Active',
        'Suspended',
        'Graduated',
        'Withdrawn'
    ) NOT NULL DEFAULT 'Active',

    PRIMARY KEY (student_id),
    UNIQUE KEY uq_student_email (email),
    UNIQUE KEY uq_student_phone (phone),
    KEY idx_student_class (class_code),

    CONSTRAINT fk_student_department
        FOREIGN KEY (department_id)
        REFERENCES Department(department_id)
        ON UPDATE CASCADE
        ON DELETE SET NULL
) ENGINE = InnoDB;

-- =========================================================
-- 3. LECTURER
-- =========================================================

CREATE TABLE Lecturer (
    lecturer_id INT UNSIGNED AUTO_INCREMENT,
    full_name VARCHAR(100) NOT NULL,
    email VARCHAR(100) NOT NULL,
    phone VARCHAR(15),
    department_id INT UNSIGNED,
    status ENUM('Active', 'Inactive')
        NOT NULL DEFAULT 'Active',

    PRIMARY KEY (lecturer_id),
    UNIQUE KEY uq_lecturer_email (email),
    UNIQUE KEY uq_lecturer_phone (phone),

    CONSTRAINT fk_lecturer_department
        FOREIGN KEY (department_id)
        REFERENCES Department(department_id)
        ON UPDATE CASCADE
        ON DELETE SET NULL
) ENGINE = InnoDB;

-- =========================================================
-- 4. ACCOUNT
-- CHECK on student_id / lecturer_id intentionally omitted
-- to avoid MySQL Error 3823.
-- Role-reference consistency must be validated by PHP.
-- =========================================================

CREATE TABLE Account (
    account_id INT UNSIGNED AUTO_INCREMENT,
    username VARCHAR(100) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('Student', 'Lecturer', 'AcademicAffairs')
        NOT NULL,
    student_id CHAR(8),
    lecturer_id INT UNSIGNED,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (account_id),
    UNIQUE KEY uq_account_username (username),
    UNIQUE KEY uq_account_student (student_id),
    UNIQUE KEY uq_account_lecturer (lecturer_id),

    CONSTRAINT fk_account_student
        FOREIGN KEY (student_id)
        REFERENCES Student(student_id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,

    CONSTRAINT fk_account_lecturer
        FOREIGN KEY (lecturer_id)
        REFERENCES Lecturer(lecturer_id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT
) ENGINE = InnoDB;

-- =========================================================
-- 5. COURSE
-- =========================================================

CREATE TABLE Course (
    course_id INT UNSIGNED AUTO_INCREMENT,
    course_code VARCHAR(10) NOT NULL,
    course_name VARCHAR(150) NOT NULL,
    credits TINYINT UNSIGNED NOT NULL,
    department_id INT UNSIGNED,
    description TEXT,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,

    PRIMARY KEY (course_id),
    UNIQUE KEY uq_course_code (course_code),

    CONSTRAINT ck_course_credits
        CHECK (credits > 0),

    CONSTRAINT fk_course_department
        FOREIGN KEY (department_id)
        REFERENCES Department(department_id)
        ON UPDATE CASCADE
        ON DELETE SET NULL
) ENGINE = InnoDB;

-- =========================================================
-- 6. CURRICULUM
-- =========================================================

CREATE TABLE Curriculum (
    curriculum_id INT UNSIGNED AUTO_INCREMENT,
    curriculum_name VARCHAR(150) NOT NULL,
    major_code VARCHAR(20) NOT NULL,
    admission_year YEAR NOT NULL,
    required_credits SMALLINT UNSIGNED NOT NULL,

    PRIMARY KEY (curriculum_id),

    UNIQUE KEY uq_curriculum_major_year
        (major_code, admission_year),

    CONSTRAINT ck_curriculum_credits
        CHECK (required_credits > 0)
) ENGINE = InnoDB;

-- =========================================================
-- 7. CURRICULUM COURSE
-- Many-to-many relationship: Curriculum <-> Course
-- =========================================================

CREATE TABLE CurriculumCourse (
    curriculum_id INT UNSIGNED NOT NULL,
    course_id INT UNSIGNED NOT NULL,
    course_type ENUM('Required', 'Elective') NOT NULL,
    semester_recommended TINYINT UNSIGNED,
    is_required BOOLEAN NOT NULL DEFAULT TRUE,

    PRIMARY KEY (curriculum_id, course_id),

    CONSTRAINT ck_recommended_semester
        CHECK (
            semester_recommended IS NULL
            OR semester_recommended BETWEEN 1 AND 12
        ),

    CONSTRAINT fk_cc_curriculum
        FOREIGN KEY (curriculum_id)
        REFERENCES Curriculum(curriculum_id)
        ON UPDATE CASCADE
        ON DELETE CASCADE,

    CONSTRAINT fk_cc_course
        FOREIGN KEY (course_id)
        REFERENCES Course(course_id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT
) ENGINE = InnoDB;

-- =========================================================
-- 8. SEMESTER
-- =========================================================

CREATE TABLE Semester (
    semester_id INT UNSIGNED AUTO_INCREMENT,
    semester_name VARCHAR(50) NOT NULL,
    academic_year VARCHAR(9) NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    status ENUM('Upcoming', 'Active', 'Completed')
        NOT NULL DEFAULT 'Upcoming',

    PRIMARY KEY (semester_id),

    UNIQUE KEY uq_semester_name_year
        (semester_name, academic_year),

    CONSTRAINT ck_semester_dates
        CHECK (end_date > start_date)
) ENGINE = InnoDB;

-- =========================================================
-- 9. CLASS SECTION
-- A course offering in a particular semester
-- =========================================================

CREATE TABLE ClassSection (
    section_id INT UNSIGNED AUTO_INCREMENT,
    course_id INT UNSIGNED NOT NULL,
    semester_id INT UNSIGNED NOT NULL,
    lecturer_id INT UNSIGNED NOT NULL,
    section_code VARCHAR(20) NOT NULL,
    schedule VARCHAR(255),
    room VARCHAR(30),
    capacity SMALLINT UNSIGNED,

    PRIMARY KEY (section_id),

    UNIQUE KEY uq_section_semester
        (section_code, semester_id),

    CONSTRAINT ck_section_capacity
        CHECK (capacity IS NULL OR capacity > 0),

    CONSTRAINT fk_section_course
        FOREIGN KEY (course_id)
        REFERENCES Course(course_id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,

    CONSTRAINT fk_section_semester
        FOREIGN KEY (semester_id)
        REFERENCES Semester(semester_id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,

    CONSTRAINT fk_section_lecturer
        FOREIGN KEY (lecturer_id)
        REFERENCES Lecturer(lecturer_id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT
) ENGINE = InnoDB;

-- =========================================================
-- 10. ENROLLMENT
-- Connects a student to a class section
-- =========================================================

CREATE TABLE Enrollment (
    enrollment_id INT UNSIGNED AUTO_INCREMENT,
    student_id CHAR(8) NOT NULL,
    section_id INT UNSIGNED NOT NULL,
    enrollment_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    status ENUM('Enrolled', 'Completed', 'Withdrawn')
        NOT NULL DEFAULT 'Enrolled',

    PRIMARY KEY (enrollment_id),

    UNIQUE KEY uq_student_section
        (student_id, section_id),

    KEY idx_enrollment_section (section_id),

    CONSTRAINT fk_enrollment_student
        FOREIGN KEY (student_id)
        REFERENCES Student(student_id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,

    CONSTRAINT fk_enrollment_section
        FOREIGN KEY (section_id)
        REFERENCES ClassSection(section_id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT
) ENGINE = InnoDB;

-- =========================================================
-- 11. ASSESSMENT
-- Components such as attendance, midterm, final exam
-- =========================================================

CREATE TABLE Assessment (
    assessment_id INT UNSIGNED AUTO_INCREMENT,
    section_id INT UNSIGNED NOT NULL,
    assessment_name VARCHAR(100) NOT NULL,
    weight_percent DECIMAL(5,2) NOT NULL,
    max_score DECIMAL(5,2) NOT NULL DEFAULT 10.00,
    due_date DATETIME,

    PRIMARY KEY (assessment_id),

    UNIQUE KEY uq_assessment_name
        (section_id, assessment_name),

    CONSTRAINT ck_assessment_weight
        CHECK (weight_percent > 0 AND weight_percent <= 100),

    CONSTRAINT ck_assessment_max_score
        CHECK (max_score > 0),

    CONSTRAINT fk_assessment_section
        FOREIGN KEY (section_id)
        REFERENCES ClassSection(section_id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT
) ENGINE = InnoDB;

-- =========================================================
-- 12. GRADE
-- score supports a 0-100 scale
-- =========================================================

CREATE TABLE Grade (
    grade_id INT UNSIGNED AUTO_INCREMENT,
    enrollment_id INT UNSIGNED NOT NULL,
    assessment_id INT UNSIGNED NOT NULL,
    score DECIMAL(5,2),
    feedback TEXT,
    graded_by INT UNSIGNED,
    graded_at DATETIME,

    PRIMARY KEY (grade_id),

    UNIQUE KEY uq_enrollment_assessment
        (enrollment_id, assessment_id),

    CONSTRAINT ck_grade_score
        CHECK (score IS NULL OR score BETWEEN 0 AND 100),

    CONSTRAINT fk_grade_enrollment
        FOREIGN KEY (enrollment_id)
        REFERENCES Enrollment(enrollment_id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,

    CONSTRAINT fk_grade_assessment
        FOREIGN KEY (assessment_id)
        REFERENCES Assessment(assessment_id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,

    CONSTRAINT fk_grade_lecturer
        FOREIGN KEY (graded_by)
        REFERENCES Lecturer(lecturer_id)
        ON UPDATE CASCADE
        ON DELETE SET NULL
) ENGINE = InnoDB;

-- =========================================================
-- 13. ATTENDANCE SESSION
-- =========================================================

CREATE TABLE AttendanceSession (
    session_id INT UNSIGNED AUTO_INCREMENT,
    section_id INT UNSIGNED NOT NULL,
    session_date DATE NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    topic VARCHAR(150),

    PRIMARY KEY (session_id),

    UNIQUE KEY uq_section_session
        (section_id, session_date, start_time),

    CONSTRAINT ck_attendance_session_time
        CHECK (end_time > start_time),

    CONSTRAINT fk_session_section
        FOREIGN KEY (section_id)
        REFERENCES ClassSection(section_id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT
) ENGINE = InnoDB;

-- =========================================================
-- 14. ATTENDANCE
-- =========================================================

CREATE TABLE Attendance (
    attendance_id INT UNSIGNED AUTO_INCREMENT,
    session_id INT UNSIGNED NOT NULL,
    enrollment_id INT UNSIGNED NOT NULL,
    status ENUM('Present', 'Absent', 'Late', 'Excused')
        NOT NULL,
    note VARCHAR(255),
    recorded_by INT UNSIGNED,
    recorded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (attendance_id),

    UNIQUE KEY uq_session_enrollment
        (session_id, enrollment_id),

    CONSTRAINT fk_attendance_session
        FOREIGN KEY (session_id)
        REFERENCES AttendanceSession(session_id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,

    CONSTRAINT fk_attendance_enrollment
        FOREIGN KEY (enrollment_id)
        REFERENCES Enrollment(enrollment_id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,

    CONSTRAINT fk_attendance_account
        FOREIGN KEY (recorded_by)
        REFERENCES Account(account_id)
        ON UPDATE CASCADE
        ON DELETE SET NULL
) ENGINE = InnoDB;

-- =========================================================
-- 15. GRADE SCALE
-- Score ranges must follow official university rules
-- =========================================================

CREATE TABLE GradeScale (
    grade_scale_id INT UNSIGNED AUTO_INCREMENT,
    min_score DECIMAL(4,2) NOT NULL,
    max_score DECIMAL(4,2) NOT NULL,
    letter_grade VARCHAR(2) NOT NULL,
    grade_point DECIMAL(2,1) NOT NULL,
    description VARCHAR(100),

    PRIMARY KEY (grade_scale_id),

    UNIQUE KEY uq_grade_scale_letter (letter_grade),

    CONSTRAINT ck_grade_scale_range
        CHECK (
            min_score >= 0
            AND max_score <= 10
            AND min_score <= max_score
        ),

    CONSTRAINT ck_grade_scale_point
        CHECK (grade_point BETWEEN 0 AND 4)
) ENGINE = InnoDB;

-- =========================================================
-- 16. ACADEMIC WARNING
-- =========================================================

CREATE TABLE AcademicWarning (
    warning_id INT UNSIGNED AUTO_INCREMENT,
    student_id CHAR(8) NOT NULL,
    warning_type ENUM(
        'Attendance',
        'LowGrade',
        'LowGPA',
        'GraduationRisk'
    ) NOT NULL,
    message TEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    is_read BOOLEAN NOT NULL DEFAULT FALSE,
    status ENUM('Active', 'Resolved') NOT NULL DEFAULT 'Active',

    PRIMARY KEY (warning_id),

    KEY idx_warning_student_status (student_id, status),

    CONSTRAINT fk_warning_student
        FOREIGN KEY (student_id)
        REFERENCES Student(student_id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT
) ENGINE = InnoDB;

-- =========================================================
-- VERIFY: SHOULD RETURN 16 TABLES
-- =========================================================




