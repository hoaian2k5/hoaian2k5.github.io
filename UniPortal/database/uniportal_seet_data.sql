-- =========================================================
-- SEED DATA: STUDENT ACADEMIC MANAGEMENT SYSTEM
-- Database: uniportal | MySQL 8.0.16+
-- Demo password for all accounts: password
-- IMPORTANT: Run schema.sql first. Run this seed only once on an empty database.
-- Scores in Grade use 0-100; GradeScale uses 0-10, so convert score / 10
-- when calculating letter grades/GPA in the application.
-- =========================================================

USE uniportal;
START TRANSACTION;

-- 1. Department
INSERT INTO Department (department_id, department_name, department_code) VALUES
(1, 'Management Information Systems', 'MIS'),
(2, 'Business English', 'BEL'),
(3, 'Applied Information Technology', 'AIT'),
(4, 'International Business', 'IB'),
(5, 'Business Data Analytics', 'BDA'),
(6, 'Automation and Informatics', 'AAI'),
(7, 'Informatics and Computer Engineering', 'ICE'),
(8, 'Accounting, Analysis and Auditing', 'AC');

-- 2. Student
INSERT INTO Student
(student_id, full_name, class_code, phone, email, gender, date_of_birth, department_id, admission_year, status)
VALUES
('23070001', 'Nguyen Minh Anh', 'MIS2023A', '0901000001', '23070001@student.uniportal.edu', 'Female', '2005-02-14', 1, 2023, 'Active'),
('23070002', 'Tran Duc Long', 'MIS2023A', '0901000002', '23070002@student.uniportal.edu', 'Male', '2005-07-09', 1, 2023, 'Active'),
('23070003', 'Le Thu Ha', 'BEL2023A', '0901000003', '23070003@student.uniportal.edu', 'Female', '2005-04-21', 2, 2023, 'Active'),
('23070004', 'Pham Quang Huy', 'BEL2023A', '0901000004', '23070004@student.uniportal.edu', 'Male', '2005-11-03', 2, 2023, 'Active'),
('23070005', 'Hoang Gia Bao', 'AIT2023A', '0901000005', '23070005@student.uniportal.edu', 'Male', '2005-01-18', 3, 2023, 'Active'),
('23070006', 'Vu Ngoc Mai', 'AIT2023A', '0901000006', '23070006@student.uniportal.edu', 'Female', '2005-08-26', 3, 2023, 'Active'),
('23070007', 'Do Khanh Linh', 'IB2023A', '0901000007', '23070007@student.uniportal.edu', 'Female', '2005-06-12', 4, 2023, 'Active'),
('23070008', 'Bui Tuan Kiet', 'IB2023A', '0901000008', '23070008@student.uniportal.edu', 'Male', '2005-09-30', 4, 2023, 'Active'),
('23070009', 'Dang Phuong Thao', 'BDA2023A', '0901000009', '23070009@student.uniportal.edu', 'Female', '2005-03-05', 5, 2023, 'Active'),
('23070010', 'Ngo Hoang Nam', 'BDA2023A', '0901000010', '23070010@student.uniportal.edu', 'Male', '2005-12-17', 5, 2023, 'Active'),
('23070011', 'Mai Thanh Truc', 'AAI2023A', '0901000011', '23070011@student.uniportal.edu', 'Female', '2005-05-28', 6, 2023, 'Active'),
('23070012', 'Dinh Tien Dat', 'AAI2023A', '0901000012', '23070012@student.uniportal.edu', 'Male', '2005-10-11', 6, 2023, 'Active'),
('23070013', 'Phan Bao Chau', 'ICE2023A', '0901000013', '23070013@student.uniportal.edu', 'Female', '2005-02-02', 7, 2023, 'Active'),
('23070014', 'Ly Anh Tuan', 'ICE2023A', '0901000014', '23070014@student.uniportal.edu', 'Male', '2005-07-23', 7, 2023, 'Active'),
('23070015', 'Trinh Ngoc Yen', 'AC2023A', '0901000015', '23070015@student.uniportal.edu', 'Female', '2005-04-09', 8, 2023, 'Active'),
('23070016', 'Hoang Minh Quan', 'AC2023A', '0901000016', '23070016@student.uniportal.edu', 'Male', '2005-09-14', 8, 2023, 'Active');

-- 3. Lecturer
INSERT INTO Lecturer (lecturer_id, full_name, email, phone, department_id, status) VALUES
(1, 'Dr. Nguyen Thanh Son', 'son.nguyen@uniportal.edu', '0912000001', 1, 'Active'),
(2, 'Ms. Tran Thu Trang', 'trang.tran@uniportal.edu', '0912000002', 2, 'Active'),
(3, 'Mr. Le Quoc Bao', 'bao.le@uniportal.edu', '0912000003', 3, 'Active'),
(4, 'Dr. Pham Ngoc Lan', 'lan.pham@uniportal.edu', '0912000004', 4, 'Active'),
(5, 'Ms. Vu Mai Huong', 'huong.vu@uniportal.edu', '0912000005', 5, 'Active'),
(6, 'Mr. Dang Tuan Anh', 'anh.dang@uniportal.edu', '0912000006', 6, 'Active'),
(7, 'Dr. Bui Minh Duc', 'duc.bui@uniportal.edu', '0912000007', 7, 'Active'),
(8, 'Ms. Hoang Thu Ha', 'ha.hoang@uniportal.edu', '0912000008', 8, 'Active');

-- 4. Account
-- The bcrypt hash below is for the demo password "password".
INSERT INTO Account
(account_id, username, password_hash, role, student_id, lecturer_id, is_active, created_at)
VALUES
(1, '23070001', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.', 'Student', '23070001', NULL, TRUE, '2026-01-05 09:00:00'),
(2, '23070002', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.', 'Student', '23070002', NULL, TRUE, '2026-01-05 09:00:00'),
(3, '23070003', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.', 'Student', '23070003', NULL, TRUE, '2026-01-05 09:00:00'),
(4, '23070004', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.', 'Student', '23070004', NULL, TRUE, '2026-01-05 09:00:00'),
(5, '23070005', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.', 'Student', '23070005', NULL, TRUE, '2026-01-05 09:00:00'),
(6, '23070006', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.', 'Student', '23070006', NULL, TRUE, '2026-01-05 09:00:00'),
(7, '23070007', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.', 'Student', '23070007', NULL, TRUE, '2026-01-05 09:00:00'),
(8, '23070008', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.', 'Student', '23070008', NULL, TRUE, '2026-01-05 09:00:00'),
(9, '23070009', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.', 'Student', '23070009', NULL, TRUE, '2026-01-05 09:00:00'),
(10, '23070010', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.', 'Student', '23070010', NULL, TRUE, '2026-01-05 09:00:00'),
(11, '23070011', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.', 'Student', '23070011', NULL, TRUE, '2026-01-05 09:00:00'),
(12, '23070012', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.', 'Student', '23070012', NULL, TRUE, '2026-01-05 09:00:00'),
(13, '23070013', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.', 'Student', '23070013', NULL, TRUE, '2026-01-05 09:00:00'),
(14, '23070014', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.', 'Student', '23070014', NULL, TRUE, '2026-01-05 09:00:00'),
(15, '23070015', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.', 'Student', '23070015', NULL, TRUE, '2026-01-05 09:00:00'),
(16, '23070016', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.', 'Student', '23070016', NULL, TRUE, '2026-01-05 09:00:00'),
(17, 'lecturer_mis', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.', 'Lecturer', NULL, 1, TRUE, '2026-01-05 09:00:00'),
(18, 'lecturer_bel', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.', 'Lecturer', NULL, 2, TRUE, '2026-01-05 09:00:00'),
(19, 'lecturer_ait', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.', 'Lecturer', NULL, 3, TRUE, '2026-01-05 09:00:00'),
(20, 'lecturer_ib', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.', 'Lecturer', NULL, 4, TRUE, '2026-01-05 09:00:00'),
(21, 'lecturer_bda', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.', 'Lecturer', NULL, 5, TRUE, '2026-01-05 09:00:00'),
(22, 'lecturer_aai', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.', 'Lecturer', NULL, 6, TRUE, '2026-01-05 09:00:00'),
(23, 'lecturer_ice', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.', 'Lecturer', NULL, 7, TRUE, '2026-01-05 09:00:00'),
(24, 'lecturer_ac', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.', 'Lecturer', NULL, 8, TRUE, '2026-01-05 09:00:00'),
(25, 'academic_admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.', 'AcademicAffairs', NULL, NULL, TRUE, '2026-01-05 09:00:00');

-- 5. Course
INSERT INTO Course (course_id, course_code, course_name, credits, department_id, description, is_active) VALUES
(1, 'MIS2101', 'Management Information Systems', 3, 1, 'Introduction to information systems in organizations.', TRUE),
(2, 'BEL2101', 'Business English Communication', 3, 2, 'Professional English for business communication.', TRUE),
(3, 'AIT2101', 'Web Application Development', 3, 3, 'Fundamentals of building web applications.', TRUE),
(4, 'IB2101', 'International Business', 3, 4, 'Core concepts in international trade and business.', TRUE),
(5, 'BDA2101', 'Business Data Analytics', 3, 5, 'Data analysis and interpretation for business decisions.', TRUE),
(6, 'AAI2101', 'Automation Fundamentals', 3, 6, 'Introduction to automation and control systems.', TRUE),
(7, 'ICE2101', 'Database Systems', 3, 7, 'Relational database design and SQL.', TRUE),
(8, 'AC2101', 'Financial Accounting', 3, 8, 'Principles of financial accounting and reporting.', TRUE);

COMMIT;