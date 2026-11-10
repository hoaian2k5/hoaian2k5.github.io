-- ==========================================================
-- UniPortal – Academic Demo Seed Data
-- File    : seed_academic_data.sql
-- Purpose : Populate multi-semester academic records so the
--           Student Dashboard, GPA charts, and Attendance
--           pages show realistic calculated data.
--
-- SAFE TO RUN: uses INSERT IGNORE / IF NOT EXISTS.
--   Does NOT drop, truncate, or alter any existing rows.
--
-- Target DB  : uniportal
-- Requires   : schema.sql applied, seed_data.sql applied,
--              migrate_v1.2_seed.sql applied.
--
-- After import, student 23070001 will show:
--   Completed semesters : 3  (HK1+HK2 2023-2024, HK1 2024-2025)
--   Earned credits      : 33 TC  (11 courses × 3 TC)
--   Cumulative GPA      : 3.40 / 4.0
--   GPA history         : 3.58 → 3.36 → 3.20
--   Active semester     : HK2 2024-2025  (3 sections, already enrolled)
--   Attendance rate     : ~87.5 % (current semester)
--   Academic warning    : 1 Attendance warning (already exists)
-- ==========================================================

USE uniportal;
SET FOREIGN_KEY_CHECKS = 0;

-- ==========================================================
-- 1. CURRICULUM
-- ==========================================================

INSERT IGNORE INTO Curriculum
    (curriculum_id, curriculum_name,         major_code, admission_year, required_credits)
VALUES
    (1, 'Chương trình Cử nhân CNTT & Quản trị', 'MIS',  2023, 130),
    (2, 'Chương trình Cử nhân Kinh doanh Quốc tế', 'IB',2023, 130),
    (3, 'Chương trình Cử nhân Phân tích Dữ liệu',  'BDA',2023,130),
    (4, 'Chương trình Cử nhân Tự động hóa & AI',   'AAI',2023,130),
    (5, 'Chương trình Cử nhân Kinh tế Số',         'ICE',2023,130),
    (6, 'Chương trình Cử nhân Kế toán Quốc tế',    'AC', 2023,130),
    (7, 'Chương trình Cử nhân Thương mại Điện tử', 'BEL',2023,130),
    (8, 'Chương trình Cử nhân Khởi nghiệp & ĐM',   'AIT',2023,130);

-- ==========================================================
-- 2. CURRICULUM COURSE  (all 8 courses required for each)
-- ==========================================================

INSERT IGNORE INTO CurriculumCourse
    (curriculum_id, course_id, course_type, semester_recommended, is_required)
VALUES
    -- Curriculum 1 (MIS)
    (1,1,'Required',1,1),(1,7,'Required',1,1),(1,8,'Required',2,1),
    (1,2,'Required',2,1),(1,3,'Required',3,1),(1,4,'Required',3,1),
    (1,5,'Required',4,1),(1,6,'Required',4,1),
    -- Curriculum 2 (IB)
    (2,1,'Required',1,1),(2,2,'Required',1,1),(2,3,'Required',2,1),
    (2,4,'Required',2,1),(2,5,'Required',3,1),(2,6,'Required',3,1),
    (2,7,'Required',4,1),(2,8,'Required',4,1),
    -- Curriculum 3 (BDA)
    (3,5,'Required',1,1),(3,1,'Required',1,1),(3,7,'Required',2,1),
    (3,8,'Required',2,1),(3,2,'Required',3,1),(3,3,'Required',3,1),
    (3,4,'Required',4,1),(3,6,'Required',4,1),
    -- Curriculum 4 (AAI)
    (4,6,'Required',1,1),(4,3,'Required',1,1),(4,1,'Required',2,1),
    (4,5,'Required',2,1),(4,7,'Required',3,1),(4,8,'Required',3,1),
    (4,2,'Required',4,1),(4,4,'Required',4,1),
    -- Curriculum 5 (ICE)
    (5,7,'Required',1,1),(5,8,'Required',1,1),(5,1,'Required',2,1),
    (5,2,'Required',2,1),(5,3,'Required',3,1),(5,4,'Required',3,1),
    (5,5,'Required',4,1),(5,6,'Required',4,1),
    -- Curriculum 6 (AC)
    (6,8,'Required',1,1),(6,1,'Required',1,1),(6,5,'Required',2,1),
    (6,7,'Required',2,1),(6,2,'Required',3,1),(6,3,'Required',3,1),
    (6,4,'Required',4,1),(6,6,'Required',4,1),
    -- Curriculum 7 (BEL)
    (7,2,'Required',1,1),(7,4,'Required',1,1),(7,1,'Required',2,1),
    (7,3,'Required',2,1),(7,5,'Required',3,1),(7,6,'Required',3,1),
    (7,7,'Required',4,1),(7,8,'Required',4,1),
    -- Curriculum 8 (AIT)
    (8,3,'Required',1,1),(8,1,'Required',1,1),(8,5,'Required',2,1),
    (8,6,'Required',2,1),(8,7,'Required',3,1),(8,8,'Required',3,1),
    (8,2,'Required',4,1),(8,4,'Required',4,1);

-- ==========================================================
-- 3. CLASS SECTIONS  (past semesters for demo)
--
-- Existing: section_id 1-12 (see seed_data.sql)
-- New: 13-28  (sections in sem 1, 2, 3 for multiple courses)
--
-- Layout per past semester:
--   Sem 1 (HK1 2023-2024): sections 13-16  (4 courses)
--   Sem 2 (HK2 2023-2024): sections 17-20  (4 courses)
--   Sem 3 (HK1 2024-2025): sections 21-23  (3 courses)
--   + extra sections 24-28 for other students in sem 4
-- ==========================================================

-- ── Semester 1 – HK1 2023-2024 ───────────────────────────────
-- section 13: MIS2101 (course 1), sem 1, lecturer 1
INSERT IGNORE INTO ClassSection (section_id,course_id,semester_id,lecturer_id,section_code,schedule,room,capacity)
VALUES (13,1,1,1,'MIS2101-S1','Thứ 2 & 4 07:30-09:00','B2.101',40);
-- section 14: ICE2101 (course 7), sem 1, lecturer 7
INSERT IGNORE INTO ClassSection (section_id,course_id,semester_id,lecturer_id,section_code,schedule,room,capacity)
VALUES (14,7,1,7,'ICE2101-S1','Thứ 3 & 5 09:15-10:45','B1.203',40);
-- section 15: AC2101 (course 8), sem 1, lecturer 8
INSERT IGNORE INTO ClassSection (section_id,course_id,semester_id,lecturer_id,section_code,schedule,room,capacity)
VALUES (15,8,1,8,'AC2101-S1','Thứ 2 & 6 13:00-14:30','B3.105',35);
-- section 16: BEL2101 (course 2), sem 1, lecturer 2
INSERT IGNORE INTO ClassSection (section_id,course_id,semester_id,lecturer_id,section_code,schedule,room,capacity)
VALUES (16,2,1,2,'BEL2101-S1','Thứ 4 & 6 09:15-10:45','C1.202',40);

-- ── Semester 2 – HK2 2023-2024 ───────────────────────────────
-- section 17: AIT2101 (course 3), sem 2, lecturer 3
INSERT IGNORE INTO ClassSection (section_id,course_id,semester_id,lecturer_id,section_code,schedule,room,capacity)
VALUES (17,3,2,3,'AIT2101-S2','Thứ 2 & 5 07:30-09:00','C2.301',40);
-- section 18: IB2101 (course 4), sem 2, lecturer 4
INSERT IGNORE INTO ClassSection (section_id,course_id,semester_id,lecturer_id,section_code,schedule,room,capacity)
VALUES (18,4,2,4,'IB2101-S2','Thứ 3 & 6 09:15-10:45','B1.105',35);
-- section 19: BDA2101 (course 5), sem 2, lecturer 5
INSERT IGNORE INTO ClassSection (section_id,course_id,semester_id,lecturer_id,section_code,schedule,room,capacity)
VALUES (19,5,2,5,'BDA2101-S2','Thứ 4 & 5 13:00-14:30','B2.205',40);
-- section 20: AAI2101 (course 6), sem 2, lecturer 6
INSERT IGNORE INTO ClassSection (section_id,course_id,semester_id,lecturer_id,section_code,schedule,room,capacity)
VALUES (20,6,2,6,'AAI2101-S2','Thứ 2 & 6 09:15-10:45','C3.102',35);

-- ── Semester 3 – HK1 2024-2025 ───────────────────────────────
-- section 21: MIS2101 advanced (course 1), sem 3, lecturer 1
INSERT IGNORE INTO ClassSection (section_id,course_id,semester_id,lecturer_id,section_code,schedule,room,capacity)
VALUES (21,1,3,1,'MIS2101-H1','Thứ 2 & 4 07:30-09:00','B2.201',40);
-- section 22: ICE2101 advanced (course 7), sem 3, lecturer 7
INSERT IGNORE INTO ClassSection (section_id,course_id,semester_id,lecturer_id,section_code,schedule,room,capacity)
VALUES (22,7,3,7,'ICE2101-H1','Thứ 3 & 5 09:15-10:45','B1.305',40);
-- section 23: BDA2101 (course 5), sem 3, lecturer 5
INSERT IGNORE INTO ClassSection (section_id,course_id,semester_id,lecturer_id,section_code,schedule,room,capacity)
VALUES (23,5,3,5,'BDA2101-H1','Thứ 4 & 6 13:00-14:30','C2.102',35);

-- ── Extra sections in sem 4 for other students ───────────────
-- section 24: AIT2101 sem 4, lecturer 3
INSERT IGNORE INTO ClassSection (section_id,course_id,semester_id,lecturer_id,section_code,schedule,room,capacity)
VALUES (24,3,4,3,'AIT2101-01','Thứ 3 & 5 07:30-09:00','C1.101',40);
-- section 25: IB2101 sem 4, lecturer 4
INSERT IGNORE INTO ClassSection (section_id,course_id,semester_id,lecturer_id,section_code,schedule,room,capacity)
VALUES (25,4,4,4,'IB2101-01','Thứ 2 & 6 09:15-10:45','B1.203',35);
-- section 26: BDA2101 sem 4, lecturer 5
INSERT IGNORE INTO ClassSection (section_id,course_id,semester_id,lecturer_id,section_code,schedule,room,capacity)
VALUES (26,5,4,5,'BDA2101-01','Thứ 4 & 5 13:00-14:30','B3.201',40);
-- section 27: AAI2101 sem 4, lecturer 6
INSERT IGNORE INTO ClassSection (section_id,course_id,semester_id,lecturer_id,section_code,schedule,room,capacity)
VALUES (27,6,4,6,'AAI2101-01','Thứ 2 & 6 07:30-09:00','C2.203',40);
-- section 28: ICE2101 sem 4, lecturer 7
INSERT IGNORE INTO ClassSection (section_id,course_id,semester_id,lecturer_id,section_code,schedule,room,capacity)
VALUES (28,7,4,7,'ICE2101-01','Thứ 3 & 6 09:15-10:45','B2.105',35);

-- ==========================================================
-- 4. ENROLLMENTS
--
-- Existing: enrollment_id 1-25
-- New: 26-100+
--
-- Student 23070001 past semesters: enr 26-36
-- Other students past semesters  : enr 37-82
-- Other students sem 4 additions : enr 83-100
-- ==========================================================

-- ── 23070001 – past semester enrollments ─────────────────────
-- Sem 1: 4 sections (13,14,15,16)
INSERT IGNORE INTO Enrollment (enrollment_id,student_id,section_id,status,enrollment_date)
VALUES (26,'23070001',13,'Completed','2023-09-01 08:00:00');
INSERT IGNORE INTO Enrollment (enrollment_id,student_id,section_id,status,enrollment_date)
VALUES (27,'23070001',14,'Completed','2023-09-01 08:00:00');
INSERT IGNORE INTO Enrollment (enrollment_id,student_id,section_id,status,enrollment_date)
VALUES (28,'23070001',15,'Completed','2023-09-01 08:00:00');
INSERT IGNORE INTO Enrollment (enrollment_id,student_id,section_id,status,enrollment_date)
VALUES (29,'23070001',16,'Completed','2023-09-01 08:00:00');

-- Sem 2: 4 sections (17,18,19,20)
INSERT IGNORE INTO Enrollment (enrollment_id,student_id,section_id,status,enrollment_date)
VALUES (30,'23070001',17,'Completed','2024-02-01 08:00:00');
INSERT IGNORE INTO Enrollment (enrollment_id,student_id,section_id,status,enrollment_date)
VALUES (31,'23070001',18,'Completed','2024-02-01 08:00:00');
INSERT IGNORE INTO Enrollment (enrollment_id,student_id,section_id,status,enrollment_date)
VALUES (32,'23070001',19,'Completed','2024-02-01 08:00:00');
INSERT IGNORE INTO Enrollment (enrollment_id,student_id,section_id,status,enrollment_date)
VALUES (33,'23070001',20,'Completed','2024-02-01 08:00:00');

-- Sem 3: 3 sections (21,22,23)
INSERT IGNORE INTO Enrollment (enrollment_id,student_id,section_id,status,enrollment_date)
VALUES (34,'23070001',21,'Completed','2024-09-01 08:00:00');
INSERT IGNORE INTO Enrollment (enrollment_id,student_id,section_id,status,enrollment_date)
VALUES (35,'23070001',22,'Completed','2024-09-01 08:00:00');
INSERT IGNORE INTO Enrollment (enrollment_id,student_id,section_id,status,enrollment_date)
VALUES (36,'23070001',23,'Completed','2024-09-01 08:00:00');

-- ── Other students – past semesters (23070002..23070010) ──────

-- 23070002 – Sem 1: sections 13,16
INSERT IGNORE INTO Enrollment (enrollment_id,student_id,section_id,status,enrollment_date)
VALUES (37,'23070002',13,'Completed','2023-09-01 08:00:00'),
       (38,'23070002',14,'Completed','2023-09-01 08:00:00'),
       (39,'23070002',17,'Completed','2024-02-01 08:00:00'),
       (40,'23070002',21,'Completed','2024-09-01 08:00:00');

-- 23070003 – past sems
INSERT IGNORE INTO Enrollment (enrollment_id,student_id,section_id,status,enrollment_date)
VALUES (41,'23070003',13,'Completed','2023-09-01 08:00:00'),
       (42,'23070003',15,'Completed','2023-09-01 08:00:00'),
       (43,'23070003',18,'Completed','2024-02-01 08:00:00'),
       (44,'23070003',22,'Completed','2024-09-01 08:00:00');

-- 23070004 – past sems
INSERT IGNORE INTO Enrollment (enrollment_id,student_id,section_id,status,enrollment_date)
VALUES (45,'23070004',14,'Completed','2023-09-01 08:00:00'),
       (46,'23070004',16,'Completed','2023-09-01 08:00:00'),
       (47,'23070004',19,'Completed','2024-02-01 08:00:00'),
       (48,'23070004',23,'Completed','2024-09-01 08:00:00');

-- 23070005 – past sems
INSERT IGNORE INTO Enrollment (enrollment_id,student_id,section_id,status,enrollment_date)
VALUES (49,'23070005',13,'Completed','2023-09-01 08:00:00'),
       (50,'23070005',17,'Completed','2024-02-01 08:00:00'),
       (51,'23070005',20,'Completed','2024-02-01 08:00:00'),
       (52,'23070005',21,'Completed','2024-09-01 08:00:00');

-- 23070006 – past sems (low performer for warnings)
INSERT IGNORE INTO Enrollment (enrollment_id,student_id,section_id,status,enrollment_date)
VALUES (53,'23070006',14,'Completed','2023-09-01 08:00:00'),
       (54,'23070006',15,'Completed','2023-09-01 08:00:00'),
       (55,'23070006',18,'Completed','2024-02-01 08:00:00'),
       (56,'23070006',22,'Completed','2024-09-01 08:00:00');

-- 23070007 – past sems
INSERT IGNORE INTO Enrollment (enrollment_id,student_id,section_id,status,enrollment_date)
VALUES (57,'23070007',13,'Completed','2023-09-01 08:00:00'),
       (58,'23070007',16,'Completed','2023-09-01 08:00:00'),
       (59,'23070007',19,'Completed','2024-02-01 08:00:00'),
       (60,'23070007',23,'Completed','2024-09-01 08:00:00');

-- 23070008 – past sems
INSERT IGNORE INTO Enrollment (enrollment_id,student_id,section_id,status,enrollment_date)
VALUES (61,'23070008',14,'Completed','2023-09-01 08:00:00'),
       (62,'23070008',15,'Completed','2023-09-01 08:00:00'),
       (63,'23070008',17,'Completed','2024-02-01 08:00:00'),
       (64,'23070008',21,'Completed','2024-09-01 08:00:00');

-- 23070009 – past sems
INSERT IGNORE INTO Enrollment (enrollment_id,student_id,section_id,status,enrollment_date)
VALUES (65,'23070009',13,'Completed','2023-09-01 08:00:00'),
       (66,'23070009',16,'Completed','2023-09-01 08:00:00'),
       (67,'23070009',20,'Completed','2024-02-01 08:00:00'),
       (68,'23070009',22,'Completed','2024-09-01 08:00:00');

-- 23070010 – past sems
INSERT IGNORE INTO Enrollment (enrollment_id,student_id,section_id,status,enrollment_date)
VALUES (69,'23070010',14,'Completed','2023-09-01 08:00:00'),
       (70,'23070010',15,'Completed','2023-09-01 08:00:00'),
       (71,'23070010',19,'Completed','2024-02-01 08:00:00'),
       (72,'23070010',23,'Completed','2024-09-01 08:00:00');

-- ── Other students sem 4 additional enrollments ───────────────
-- (sections 24-28 for students not yet enrolled in them)
INSERT IGNORE INTO Enrollment (enrollment_id,student_id,section_id,status,enrollment_date)
VALUES
  (73,'23070004',24,'Enrolled','2025-02-01 08:00:00'),
  (74,'23070005',25,'Enrolled','2025-02-01 08:00:00'),
  (75,'23070006',26,'Enrolled','2025-02-01 08:00:00'),
  (76,'23070007',27,'Enrolled','2025-02-01 08:00:00'),
  (77,'23070008',28,'Enrolled','2025-02-01 08:00:00'),
  (78,'23070009',24,'Enrolled','2025-02-01 08:00:00'),
  (79,'23070010',25,'Enrolled','2025-02-01 08:00:00'),
  (80,'23070011',26,'Enrolled','2025-02-01 08:00:00'),
  (81,'23070012',27,'Enrolled','2025-02-01 08:00:00'),
  (82,'23070013',28,'Enrolled','2025-02-01 08:00:00'),
  (83,'23070014',24,'Enrolled','2025-02-01 08:00:00'),
  (84,'23070015',25,'Enrolled','2025-02-01 08:00:00'),
  (85,'23070016',26,'Enrolled','2025-02-01 08:00:00');

-- ==========================================================
-- 5. ASSESSMENTS  (for new past-semester sections)
--
-- Existing: assessment_id 1-11 (sections 1-3)
-- New: 12-39  (sections 13-28)
--
-- Each past-semester section gets exactly 2 assessments:
--   Midterm  40% (max_score 10)
--   Final    60% (max_score 10)
--
-- Active-semester sections (24-28) also get assessments.
-- ==========================================================

-- Section 13 (MIS2101 sem1)
INSERT IGNORE INTO Assessment (assessment_id,section_id,assessment_name,weight_percent,max_score,due_date)
VALUES (12,13,'Kiểm tra giữa kỳ',40.00,10.00,'2023-11-10 23:59:00'),
       (13,13,'Thi cuối kỳ',     60.00,10.00,'2024-01-15 23:59:00');

-- Section 14 (ICE2101 sem1)
INSERT IGNORE INTO Assessment (assessment_id,section_id,assessment_name,weight_percent,max_score,due_date)
VALUES (14,14,'Kiểm tra giữa kỳ',40.00,10.00,'2023-11-12 23:59:00'),
       (15,14,'Thi cuối kỳ',     60.00,10.00,'2024-01-16 23:59:00');

-- Section 15 (AC2101 sem1)
INSERT IGNORE INTO Assessment (assessment_id,section_id,assessment_name,weight_percent,max_score,due_date)
VALUES (16,15,'Kiểm tra giữa kỳ',40.00,10.00,'2023-11-14 23:59:00'),
       (17,15,'Thi cuối kỳ',     60.00,10.00,'2024-01-17 23:59:00');

-- Section 16 (BEL2101 sem1)
INSERT IGNORE INTO Assessment (assessment_id,section_id,assessment_name,weight_percent,max_score,due_date)
VALUES (18,16,'Kiểm tra giữa kỳ',40.00,10.00,'2023-11-15 23:59:00'),
       (19,16,'Thi cuối kỳ',     60.00,10.00,'2024-01-18 23:59:00');

-- Section 17 (AIT2101 sem2)
INSERT IGNORE INTO Assessment (assessment_id,section_id,assessment_name,weight_percent,max_score,due_date)
VALUES (20,17,'Kiểm tra giữa kỳ',40.00,10.00,'2024-04-15 23:59:00'),
       (21,17,'Thi cuối kỳ',     60.00,10.00,'2024-06-10 23:59:00');

-- Section 18 (IB2101 sem2)
INSERT IGNORE INTO Assessment (assessment_id,section_id,assessment_name,weight_percent,max_score,due_date)
VALUES (22,18,'Kiểm tra giữa kỳ',40.00,10.00,'2024-04-16 23:59:00'),
       (23,18,'Thi cuối kỳ',     60.00,10.00,'2024-06-11 23:59:00');

-- Section 19 (BDA2101 sem2)
INSERT IGNORE INTO Assessment (assessment_id,section_id,assessment_name,weight_percent,max_score,due_date)
VALUES (24,19,'Kiểm tra giữa kỳ',40.00,10.00,'2024-04-17 23:59:00'),
       (25,19,'Thi cuối kỳ',     60.00,10.00,'2024-06-12 23:59:00');

-- Section 20 (AAI2101 sem2)
INSERT IGNORE INTO Assessment (assessment_id,section_id,assessment_name,weight_percent,max_score,due_date)
VALUES (26,20,'Kiểm tra giữa kỳ',40.00,10.00,'2024-04-18 23:59:00'),
       (27,20,'Thi cuối kỳ',     60.00,10.00,'2024-06-13 23:59:00');

-- Section 21 (MIS2101 sem3)
INSERT IGNORE INTO Assessment (assessment_id,section_id,assessment_name,weight_percent,max_score,due_date)
VALUES (28,21,'Kiểm tra giữa kỳ',40.00,10.00,'2024-11-15 23:59:00'),
       (29,21,'Thi cuối kỳ',     60.00,10.00,'2025-01-10 23:59:00');

-- Section 22 (ICE2101 sem3)
INSERT IGNORE INTO Assessment (assessment_id,section_id,assessment_name,weight_percent,max_score,due_date)
VALUES (30,22,'Kiểm tra giữa kỳ',40.00,10.00,'2024-11-16 23:59:00'),
       (31,22,'Thi cuối kỳ',     60.00,10.00,'2025-01-11 23:59:00');

-- Section 23 (BDA2101 sem3)
INSERT IGNORE INTO Assessment (assessment_id,section_id,assessment_name,weight_percent,max_score,due_date)
VALUES (32,23,'Kiểm tra giữa kỳ',40.00,10.00,'2024-11-17 23:59:00'),
       (33,23,'Thi cuối kỳ',     60.00,10.00,'2025-01-12 23:59:00');

-- Section 24 (AIT2101 sem4 – active)
INSERT IGNORE INTO Assessment (assessment_id,section_id,assessment_name,weight_percent,max_score,due_date)
VALUES (34,24,'Bài tập Lab',     30.00,10.00,'2026-12-01 23:59:00'),
       (35,24,'Thi cuối kỳ',     70.00,10.00,'2027-01-15 23:59:00');

-- Section 25 (IB2101 sem4 – active)
INSERT IGNORE INTO Assessment (assessment_id,section_id,assessment_name,weight_percent,max_score,due_date)
VALUES (36,25,'Tiểu luận',       40.00,10.00,'2026-12-05 23:59:00'),
       (37,25,'Thi cuối kỳ',     60.00,10.00,'2027-01-16 23:59:00');

-- Section 26 (BDA2101 sem4 – active)
INSERT IGNORE INTO Assessment (assessment_id,section_id,assessment_name,weight_percent,max_score,due_date)
VALUES (38,26,'Kiểm tra giữa kỳ',40.00,10.00,'2026-12-08 23:59:00'),
       (39,26,'Thi cuối kỳ',     60.00,10.00,'2027-01-17 23:59:00');

-- ==========================================================
-- 6. GRADES
--
-- Existing: grade_id 1-20
-- New: 21-onwards
--
-- Score design (Grade.score 0-100, divide by 10 for GradeScale):
-- ─────────────────────────────────────────────────────────────
-- HK1 2023-2024 target GPA ≈ 3.58 for 23070001
-- Course  Mid(score100) → gp  Final(score100) → gp
-- MIS2101  82→8.2 B+ 3.5       87→8.7 A  3.7    avg=(3.5+3.7)/2=3.60
-- ICE2101  85→8.5 A  3.7       90→9.0 A+ 4.0    avg=(3.7+4.0)/2=3.85
-- AC2101   78→7.8 B  3.0       82→8.2 B+ 3.5    avg=(3.0+3.5)/2=3.25
-- BEL2101  80→8.0 B+ 3.5       85→8.5 A  3.7    avg=(3.5+3.7)/2=3.60
-- HK1 GPA = (3.5+3.7+3.7+4.0+3.0+3.5+3.5+3.7)*3 / (8*3)
--         = 28.6*3/24 = 85.8/24 = 3.575 → 3.58 ✓
--
-- HK2 2023-2024 target GPA ≈ 3.36 for 23070001
-- AIT2101  87→8.7 A  3.7       87→8.7 A  3.7    avg=3.70
-- IB2101   78→7.8 B  3.0       82→8.2 B+ 3.5    avg=3.25
-- BDA2101  82→8.2 B+ 3.5       90→9.0 A+ 4.0    avg=3.75
-- AAI2101  65→6.5 C+ 2.5       72→7.2 B  3.0    avg=2.75
-- HK2 GPA = (3.7+3.7+3.0+3.5+3.5+4.0+2.5+3.0)*3 / (8*3)
--         = 26.9*3/24 = 80.7/24 = 3.3625 → 3.36 ✓
--
-- HK1 2024-2025 target GPA ≈ 3.20 for 23070001
-- MIS2101  87→8.7 A  3.7       82→8.2 B+ 3.5    avg=3.60
-- ICE2101  75→7.5 B  3.0       75→7.5 B  3.0    avg=3.00
-- BDA2101  65→6.5 C+ 2.5       82→8.2 B+ 3.5    avg=3.00
-- HK3 GPA = (3.7+3.5+3.0+3.0+2.5+3.5)*3 / (6*3)
--         = 19.2*3/18 = 57.6/18 = 3.20 ✓
--
-- Cumulative = (85.8 + 80.7 + 57.6) / (24+24+18)
--            = 224.1 / 66 = 3.3954... → 3.40 ✓
-- ==========================================================

-- ── 23070001 – HK1 2023-2024 ─────────────────────────────────
-- Section 13 (MIS2101): enr 26, assessments 12,13
INSERT IGNORE INTO Grade (grade_id,enrollment_id,assessment_id,score,graded_by,graded_at)
VALUES (21,26,12,82.00,1,'2024-01-10 10:00:00'),
       (22,26,13,87.00,1,'2024-01-20 10:00:00');
-- Section 14 (ICE2101): enr 27, assessments 14,15
INSERT IGNORE INTO Grade (grade_id,enrollment_id,assessment_id,score,graded_by,graded_at)
VALUES (23,27,14,85.00,7,'2024-01-11 10:00:00'),
       (24,27,15,90.00,7,'2024-01-21 10:00:00');
-- Section 15 (AC2101): enr 28, assessments 16,17
INSERT IGNORE INTO Grade (grade_id,enrollment_id,assessment_id,score,graded_by,graded_at)
VALUES (25,28,16,78.00,8,'2024-01-12 10:00:00'),
       (26,28,17,82.00,8,'2024-01-22 10:00:00');
-- Section 16 (BEL2101): enr 29, assessments 18,19
INSERT IGNORE INTO Grade (grade_id,enrollment_id,assessment_id,score,graded_by,graded_at)
VALUES (27,29,18,80.00,2,'2024-01-13 10:00:00'),
       (28,29,19,85.00,2,'2024-01-23 10:00:00');

-- ── 23070001 – HK2 2023-2024 ─────────────────────────────────
-- Section 17 (AIT2101): enr 30, assessments 20,21
INSERT IGNORE INTO Grade (grade_id,enrollment_id,assessment_id,score,graded_by,graded_at)
VALUES (29,30,20,87.00,3,'2024-06-01 10:00:00'),
       (30,30,21,87.00,3,'2024-06-15 10:00:00');
-- Section 18 (IB2101): enr 31, assessments 22,23
INSERT IGNORE INTO Grade (grade_id,enrollment_id,assessment_id,score,graded_by,graded_at)
VALUES (31,31,22,78.00,4,'2024-06-02 10:00:00'),
       (32,31,23,82.00,4,'2024-06-16 10:00:00');
-- Section 19 (BDA2101): enr 32, assessments 24,25
INSERT IGNORE INTO Grade (grade_id,enrollment_id,assessment_id,score,graded_by,graded_at)
VALUES (33,32,24,82.00,5,'2024-06-03 10:00:00'),
       (34,32,25,90.00,5,'2024-06-17 10:00:00');
-- Section 20 (AAI2101): enr 33, assessments 26,27
INSERT IGNORE INTO Grade (grade_id,enrollment_id,assessment_id,score,graded_by,graded_at)
VALUES (35,33,26,65.00,6,'2024-06-04 10:00:00'),
       (36,33,27,72.00,6,'2024-06-18 10:00:00');

-- ── 23070001 – HK1 2024-2025 ─────────────────────────────────
-- Section 21 (MIS2101 sem3): enr 34, assessments 28,29
INSERT IGNORE INTO Grade (grade_id,enrollment_id,assessment_id,score,graded_by,graded_at)
VALUES (37,34,28,87.00,1,'2025-01-05 10:00:00'),
       (38,34,29,82.00,1,'2025-01-15 10:00:00');
-- Section 22 (ICE2101 sem3): enr 35, assessments 30,31
INSERT IGNORE INTO Grade (grade_id,enrollment_id,assessment_id,score,graded_by,graded_at)
VALUES (39,35,30,75.00,7,'2025-01-06 10:00:00'),
       (40,35,31,75.00,7,'2025-01-16 10:00:00');
-- Section 23 (BDA2101 sem3): enr 36, assessments 32,33
INSERT IGNORE INTO Grade (grade_id,enrollment_id,assessment_id,score,graded_by,graded_at)
VALUES (41,36,32,65.00,5,'2025-01-07 10:00:00'),
       (42,36,33,82.00,5,'2025-01-17 10:00:00');

-- ── Other students – grades for past semesters ───────────────
-- 23070002: enr 37(MIS s13 a12,13), 38(ICE s14 a14,15), 39(AIT s17 a20,21), 40(MIS s21 a28,29)
INSERT IGNORE INTO Grade (grade_id,enrollment_id,assessment_id,score,graded_by,graded_at)
VALUES
  (43,37,12,76.00,1,'2024-01-10 10:00:00'), (44,37,13,80.00,1,'2024-01-20 10:00:00'),
  (45,38,14,81.00,7,'2024-01-11 10:00:00'), (46,38,15,78.00,7,'2024-01-21 10:00:00'),
  (47,39,20,72.00,3,'2024-06-01 10:00:00'), (48,39,21,68.00,3,'2024-06-15 10:00:00'),
  (49,40,28,74.00,1,'2025-01-05 10:00:00'), (50,40,29,79.00,1,'2025-01-15 10:00:00');

-- 23070003: enr 41(MIS s13 a12,13), 42(AC s15 a16,17), 43(IB s18 a22,23), 44(ICE s22 a30,31)
INSERT IGNORE INTO Grade (grade_id,enrollment_id,assessment_id,score,graded_by,graded_at)
VALUES
  (51,41,12,88.00,1,'2024-01-10 10:00:00'), (52,41,13,92.00,1,'2024-01-20 10:00:00'),
  (53,42,16,85.00,8,'2024-01-12 10:00:00'), (54,42,17,88.00,8,'2024-01-22 10:00:00'),
  (55,43,22,80.00,4,'2024-06-02 10:00:00'), (56,43,23,85.00,4,'2024-06-16 10:00:00'),
  (57,44,30,88.00,7,'2025-01-06 10:00:00'), (58,44,31,91.00,7,'2025-01-16 10:00:00');

-- 23070004: enr 45(ICE s14 a14,15), 46(BEL s16 a18,19), 47(BDA s19 a24,25), 48(BDA s23 a32,33)
INSERT IGNORE INTO Grade (grade_id,enrollment_id,assessment_id,score,graded_by,graded_at)
VALUES
  (59,45,14,62.00,7,'2024-01-11 10:00:00'), (60,45,15,68.00,7,'2024-01-21 10:00:00'),
  (61,46,18,70.00,2,'2024-01-13 10:00:00'), (62,46,19,65.00,2,'2024-01-23 10:00:00'),
  (63,47,24,58.00,5,'2024-06-03 10:00:00'), (64,47,25,60.00,5,'2024-06-17 10:00:00'),
  (65,48,32,61.00,5,'2025-01-07 10:00:00'), (66,48,33,63.00,5,'2025-01-17 10:00:00');

-- 23070005: enr 49(MIS s13 a12,13), 50(AIT s17 a20,21), 51(AAI s20 a26,27), 52(MIS s21 a28,29)
INSERT IGNORE INTO Grade (grade_id,enrollment_id,assessment_id,score,graded_by,graded_at)
VALUES
  (67,49,12,91.00,1,'2024-01-10 10:00:00'), (68,49,13,95.00,1,'2024-01-20 10:00:00'),
  (69,50,20,88.00,3,'2024-06-01 10:00:00'), (70,50,21,90.00,3,'2024-06-15 10:00:00'),
  (71,51,26,85.00,6,'2024-06-04 10:00:00'), (72,51,27,88.00,6,'2024-06-18 10:00:00'),
  (73,52,28,92.00,1,'2025-01-05 10:00:00'), (74,52,29,94.00,1,'2025-01-15 10:00:00');

-- 23070006 (low performer): enr 53,54,55,56
INSERT IGNORE INTO Grade (grade_id,enrollment_id,assessment_id,score,graded_by,graded_at)
VALUES
  (75,53,14,55.00,7,'2024-01-11 10:00:00'), (76,53,15,52.00,7,'2024-01-21 10:00:00'),
  (77,54,16,48.00,8,'2024-01-12 10:00:00'), (78,54,17,50.00,8,'2024-01-22 10:00:00'),
  (79,55,22,42.00,4,'2024-06-02 10:00:00'), (80,55,23,45.00,4,'2024-06-16 10:00:00'),
  (81,56,30,51.00,7,'2025-01-06 10:00:00'), (82,56,31,55.00,7,'2025-01-16 10:00:00');

-- 23070007: enr 57,58,59,60
INSERT IGNORE INTO Grade (grade_id,enrollment_id,assessment_id,score,graded_by,graded_at)
VALUES
  (83,57,12,79.00,1,'2024-01-10 10:00:00'), (84,57,13,83.00,1,'2024-01-20 10:00:00'),
  (85,58,18,77.00,2,'2024-01-13 10:00:00'), (86,58,19,80.00,2,'2024-01-23 10:00:00'),
  (87,59,24,76.00,5,'2024-06-03 10:00:00'), (88,59,25,78.00,5,'2024-06-17 10:00:00'),
  (89,60,32,72.00,5,'2025-01-07 10:00:00'), (90,60,33,75.00,5,'2025-01-17 10:00:00');

-- 23070008: enr 61,62,63,64
INSERT IGNORE INTO Grade (grade_id,enrollment_id,assessment_id,score,graded_by,graded_at)
VALUES
  (91,61,14,83.00,7,'2024-01-11 10:00:00'), (92,61,15,87.00,7,'2024-01-21 10:00:00'),
  (93,62,16,80.00,8,'2024-01-12 10:00:00'), (94,62,17,76.00,8,'2024-01-22 10:00:00'),
  (95,63,20,70.00,3,'2024-06-01 10:00:00'), (96,63,21,74.00,3,'2024-06-15 10:00:00'),
  (97,64,28,78.00,1,'2025-01-05 10:00:00'), (98,64,29,82.00,1,'2025-01-15 10:00:00');

-- 23070009: enr 65,66,67,68
INSERT IGNORE INTO Grade (grade_id,enrollment_id,assessment_id,score,graded_by,graded_at)
VALUES
  (99, 65,12,67.00,1,'2024-01-10 10:00:00'), (100,65,13,65.00,1,'2024-01-20 10:00:00'),
  (101,66,18,70.00,2,'2024-01-13 10:00:00'), (102,66,19,72.00,2,'2024-01-23 10:00:00'),
  (103,67,26,60.00,6,'2024-06-04 10:00:00'), (104,67,27,63.00,6,'2024-06-18 10:00:00'),
  (105,68,30,68.00,7,'2025-01-06 10:00:00'), (106,68,31,65.00,7,'2025-01-16 10:00:00');

-- 23070010: enr 69,70,71,72
INSERT IGNORE INTO Grade (grade_id,enrollment_id,assessment_id,score,graded_by,graded_at)
VALUES
  (107,69,14,74.00,7,'2024-01-11 10:00:00'), (108,69,15,70.00,7,'2024-01-21 10:00:00'),
  (109,70,16,78.00,8,'2024-01-12 10:00:00'), (110,70,17,82.00,8,'2024-01-22 10:00:00'),
  (111,71,24,71.00,5,'2024-06-03 10:00:00'), (112,71,25,69.00,5,'2024-06-17 10:00:00'),
  (113,72,32,76.00,5,'2025-01-07 10:00:00'), (114,72,33,79.00,5,'2025-01-17 10:00:00');

-- ==========================================================
-- 7. ATTENDANCE SESSIONS  (current active semester – sem 4)
--
-- Existing: session_id 1-4 (sections 1,2,3)
-- Add more sessions for section 1 (INS3065-01) to give
-- 23070001 enough sessions for a realistic attendance rate.
-- Also add sessions for sections 2, 3 (other enrolled sections).
-- ==========================================================

-- Section 1 (INS3065-01) – add 10 more sessions
INSERT IGNORE INTO AttendanceSession (session_id,section_id,session_date,start_time,end_time,topic)
VALUES
  (5, 1,'2026-10-07','07:30:00','09:00:00','Giới thiệu môn học và ERD cơ bản'),
  (6, 1,'2026-10-09','07:30:00','09:00:00','Thiết kế cơ sở dữ liệu quan hệ'),
  (7, 1,'2026-10-14','07:30:00','09:00:00','Ngôn ngữ SQL: DDL và DML'),
  (8, 1,'2026-10-16','07:30:00','09:00:00','Truy vấn nâng cao – JOIN & Subquery'),
  (9, 1,'2026-10-21','07:30:00','09:00:00','Stored Procedures & Triggers'),
  (10,1,'2026-10-23','07:30:00','09:00:00','Indexing và tối ưu hóa truy vấn'),
  (11,1,'2026-10-28','07:30:00','09:00:00','Giao dịch và kiểm soát đồng thời'),
  (12,1,'2026-11-04','07:30:00','09:00:00','Bảo mật và phân quyền CSDL'),
  (13,1,'2026-11-11','07:30:00','09:00:00','Hệ thống phân tán và NoSQL'),
  (14,1,'2026-11-25','07:30:00','09:00:00','Ôn tập và kiểm tra tổng hợp');

-- Section 2 (INS3064-02) – add 6 more sessions
INSERT IGNORE INTO AttendanceSession (session_id,section_id,session_date,start_time,end_time,topic)
VALUES
  (15,2,'2026-10-08','09:15:00','10:45:00','Lập trình hướng đối tượng – Java cơ bản'),
  (16,2,'2026-10-10','09:15:00','10:45:00','Collections và Generics trong Java'),
  (17,2,'2026-10-15','09:15:00','10:45:00','Xử lý ngoại lệ và I/O Streams'),
  (18,2,'2026-10-22','09:15:00','10:45:00','Multi-threading và Concurrency'),
  (19,2,'2026-11-05','09:15:00','10:45:00','Thiết kế Pattern – Singleton, Factory'),
  (20,2,'2026-11-12','09:15:00','10:45:00','Ôn tập và bài tập tổng hợp');

-- Section 3 (INS3066-01) – add 5 more sessions
INSERT IGNORE INTO AttendanceSession (session_id,section_id,session_date,start_time,end_time,topic)
VALUES
  (21,3,'2026-10-09','13:00:00','14:30:00','Phân tích nghiệp vụ và yêu cầu hệ thống'),
  (22,3,'2026-10-16','13:00:00','14:30:00','Mô hình hóa Use Case và Activity Diagram'),
  (23,3,'2026-10-23','13:00:00','14:30:00','Thiết kế kiến trúc hệ thống'),
  (24,3,'2026-11-06','13:00:00','14:30:00','Phát triển Agile và Scrum'),
  (25,3,'2026-11-13','13:00:00','14:30:00','Kiểm thử và đảm bảo chất lượng');

-- Section 4 (BEL3001-01) – 4 sessions
INSERT IGNORE INTO AttendanceSession (session_id,section_id,session_date,start_time,end_time,topic)
VALUES
  (26,4,'2026-10-07','09:15:00','10:45:00','Business Communication Fundamentals'),
  (27,4,'2026-10-14','09:15:00','10:45:00','Writing Professional Emails'),
  (28,4,'2026-10-21','09:15:00','10:45:00','Presentation Skills in Business'),
  (29,4,'2026-11-04','09:15:00','10:45:00','Negotiation and Meeting Skills');

-- ==========================================================
-- 8. ATTENDANCE RECORDS  (current semester – sem 4)
--
-- Existing: attendance_id 1-32  (sessions 1-4, already inserted)
-- New records for sessions 5-29.
--
-- Student 23070001 attendance design:
--   Section 1 (sessions 1-2 existing + 5-14 new = 12 sessions):
--     Present×10, Absent×2  → 10/12 = 83.3% ≈ meets ≥80% threshold
--   Section 2 (session 3 + 15-20 = 7 sessions):
--     Present×6, Absent×1   → 6/7 = 85.7%
--   Section 4 (sessions 26-29 = 4 sessions):
--     Present×4             → 100%
-- Combined across all sections: (10+6+4) / (12+7+4) = 20/23 ≈ 87.0%
-- ==========================================================

-- ── Section 1: sessions 5-14 (10 new sessions) ───────────────
-- 23070001 (enrollment_id=1): present all except sessions 8,12 (absent)

-- Session 5 (all 10 students present)
INSERT IGNORE INTO Attendance (session_id,enrollment_id,status,note,recorded_by,recorded_at)
VALUES
  (5,1,'Present','',17,NOW()), (5,2,'Present','',17,NOW()), (5,3,'Present','',17,NOW()),
  (5,4,'Present','',17,NOW()), (5,5,'Present','',17,NOW()), (5,6,'Absent','',17,NOW()),
  (5,7,'Present','',17,NOW()), (5,8,'Present','',17,NOW()), (5,9,'Present','',17,NOW()),
  (5,10,'Present','',17,NOW());

-- Session 6
INSERT IGNORE INTO Attendance (session_id,enrollment_id,status,note,recorded_by,recorded_at)
VALUES
  (6,1,'Present','',17,NOW()), (6,2,'Late','Muộn 10 phút',17,NOW()), (6,3,'Present','',17,NOW()),
  (6,4,'Absent','',17,NOW()), (6,5,'Present','',17,NOW()), (6,6,'Present','',17,NOW()),
  (6,7,'Present','',17,NOW()), (6,8,'Absent','',17,NOW()), (6,9,'Present','',17,NOW()),
  (6,10,'Present','',17,NOW());

-- Session 7
INSERT IGNORE INTO Attendance (session_id,enrollment_id,status,note,recorded_by,recorded_at)
VALUES
  (7,1,'Present','',17,NOW()), (7,2,'Present','',17,NOW()), (7,3,'Late','',17,NOW()),
  (7,4,'Present','',17,NOW()), (7,5,'Present','',17,NOW()), (7,6,'Absent','',17,NOW()),
  (7,7,'Present','',17,NOW()), (7,8,'Present','',17,NOW()), (7,9,'Excused','Có đơn',17,NOW()),
  (7,10,'Present','',17,NOW());

-- Session 8 – 23070001 absent
INSERT IGNORE INTO Attendance (session_id,enrollment_id,status,note,recorded_by,recorded_at)
VALUES
  (8,1,'Absent','',17,NOW()), (8,2,'Present','',17,NOW()), (8,3,'Present','',17,NOW()),
  (8,4,'Present','',17,NOW()), (8,5,'Present','',17,NOW()), (8,6,'Present','',17,NOW()),
  (8,7,'Absent','',17,NOW()), (8,8,'Present','',17,NOW()), (8,9,'Present','',17,NOW()),
  (8,10,'Late','',17,NOW());

-- Session 9
INSERT IGNORE INTO Attendance (session_id,enrollment_id,status,note,recorded_by,recorded_at)
VALUES
  (9,1,'Present','',17,NOW()), (9,2,'Present','',17,NOW()), (9,3,'Present','',17,NOW()),
  (9,4,'Absent','',17,NOW()), (9,5,'Present','',17,NOW()), (9,6,'Present','',17,NOW()),
  (9,7,'Present','',17,NOW()), (9,8,'Excused','Ốm',17,NOW()), (9,9,'Present','',17,NOW()),
  (9,10,'Present','',17,NOW());

-- Session 10
INSERT IGNORE INTO Attendance (session_id,enrollment_id,status,note,recorded_by,recorded_at)
VALUES
  (10,1,'Present','',17,NOW()), (10,2,'Late','',17,NOW()), (10,3,'Present','',17,NOW()),
  (10,4,'Present','',17,NOW()), (10,5,'Present','',17,NOW()), (10,6,'Absent','',17,NOW()),
  (10,7,'Present','',17,NOW()), (10,8,'Present','',17,NOW()), (10,9,'Present','',17,NOW()),
  (10,10,'Absent','',17,NOW());

-- Session 11
INSERT IGNORE INTO Attendance (session_id,enrollment_id,status,note,recorded_by,recorded_at)
VALUES
  (11,1,'Present','',17,NOW()), (11,2,'Present','',17,NOW()), (11,3,'Absent','',17,NOW()),
  (11,4,'Present','',17,NOW()), (11,5,'Present','',17,NOW()), (11,6,'Present','',17,NOW()),
  (11,7,'Late','',17,NOW()), (11,8,'Present','',17,NOW()), (11,9,'Present','',17,NOW()),
  (11,10,'Present','',17,NOW());

-- Session 12 – 23070001 absent
INSERT IGNORE INTO Attendance (session_id,enrollment_id,status,note,recorded_by,recorded_at)
VALUES
  (12,1,'Absent','',17,NOW()), (12,2,'Present','',17,NOW()), (12,3,'Present','',17,NOW()),
  (12,4,'Present','',17,NOW()), (12,5,'Present','',17,NOW()), (12,6,'Absent','',17,NOW()),
  (12,7,'Present','',17,NOW()), (12,8,'Present','',17,NOW()), (12,9,'Late','',17,NOW()),
  (12,10,'Present','',17,NOW());

-- Session 13
INSERT IGNORE INTO Attendance (session_id,enrollment_id,status,note,recorded_by,recorded_at)
VALUES
  (13,1,'Present','',17,NOW()), (13,2,'Present','',17,NOW()), (13,3,'Present','',17,NOW()),
  (13,4,'Absent','',17,NOW()), (13,5,'Late','',17,NOW()), (13,6,'Present','',17,NOW()),
  (13,7,'Present','',17,NOW()), (13,8,'Absent','',17,NOW()), (13,9,'Present','',17,NOW()),
  (13,10,'Present','',17,NOW());

-- Session 14
INSERT IGNORE INTO Attendance (session_id,enrollment_id,status,note,recorded_by,recorded_at)
VALUES
  (14,1,'Present','',17,NOW()), (14,2,'Present','',17,NOW()), (14,3,'Present','',17,NOW()),
  (14,4,'Present','',17,NOW()), (14,5,'Present','',17,NOW()), (14,6,'Present','',17,NOW()),
  (14,7,'Present','',17,NOW()), (14,8,'Present','',17,NOW()), (14,9,'Present','',17,NOW()),
  (14,10,'Late','',17,NOW());

-- ── Section 2: sessions 15-20 ────────────────────────────────
-- enrollment 11=23070001, 12-18 = other students
-- Session 15
INSERT IGNORE INTO Attendance (session_id,enrollment_id,status,note,recorded_by,recorded_at)
VALUES
  (15,11,'Present','',17,NOW()), (15,12,'Present','',17,NOW()), (15,13,'Late','',17,NOW()),
  (15,14,'Absent','',17,NOW()), (15,15,'Present','',17,NOW()), (15,16,'Present','',17,NOW()),
  (15,17,'Present','',17,NOW()), (15,18,'Present','',17,NOW());

-- Session 16 – 23070001 absent
INSERT IGNORE INTO Attendance (session_id,enrollment_id,status,note,recorded_by,recorded_at)
VALUES
  (16,11,'Absent','',17,NOW()), (16,12,'Present','',17,NOW()), (16,13,'Present','',17,NOW()),
  (16,14,'Present','',17,NOW()), (16,15,'Late','',17,NOW()), (16,16,'Absent','',17,NOW()),
  (16,17,'Present','',17,NOW()), (16,18,'Present','',17,NOW());

-- Session 17
INSERT IGNORE INTO Attendance (session_id,enrollment_id,status,note,recorded_by,recorded_at)
VALUES
  (17,11,'Present','',17,NOW()), (17,12,'Present','',17,NOW()), (17,13,'Present','',17,NOW()),
  (17,14,'Present','',17,NOW()), (17,15,'Present','',17,NOW()), (17,16,'Present','',17,NOW()),
  (17,17,'Absent','',17,NOW()), (17,18,'Present','',17,NOW());

-- Session 18
INSERT IGNORE INTO Attendance (session_id,enrollment_id,status,note,recorded_by,recorded_at)
VALUES
  (18,11,'Present','',17,NOW()), (18,12,'Late','',17,NOW()), (18,13,'Present','',17,NOW()),
  (18,14,'Present','',17,NOW()), (18,15,'Present','',17,NOW()), (18,16,'Present','',17,NOW()),
  (18,17,'Present','',17,NOW()), (18,18,'Absent','',17,NOW());

-- Session 19
INSERT IGNORE INTO Attendance (session_id,enrollment_id,status,note,recorded_by,recorded_at)
VALUES
  (19,11,'Present','',17,NOW()), (19,12,'Present','',17,NOW()), (19,13,'Present','',17,NOW()),
  (19,14,'Absent','',17,NOW()), (19,15,'Present','',17,NOW()), (19,16,'Present','',17,NOW()),
  (19,17,'Present','',17,NOW()), (19,18,'Present','',17,NOW());

-- Session 20
INSERT IGNORE INTO Attendance (session_id,enrollment_id,status,note,recorded_by,recorded_at)
VALUES
  (20,11,'Present','',17,NOW()), (20,12,'Present','',17,NOW()), (20,13,'Present','',17,NOW()),
  (20,14,'Present','',17,NOW()), (20,15,'Present','',17,NOW()), (20,16,'Present','',17,NOW()),
  (20,17,'Present','',17,NOW()), (20,18,'Present','',17,NOW());

-- ── Section 3: sessions 21-25 ────────────────────────────────
-- enrollments 19=23070009, 20=23070010, 21=23070011, 22=23070012
INSERT IGNORE INTO Attendance (session_id,enrollment_id,status,note,recorded_by,recorded_at)
VALUES
  (21,19,'Present','',17,NOW()), (21,20,'Present','',17,NOW()),
  (21,21,'Absent','',17,NOW()),  (21,22,'Present','',17,NOW()),
  (22,19,'Present','',17,NOW()), (22,20,'Late','',17,NOW()),
  (22,21,'Present','',17,NOW()), (22,22,'Present','',17,NOW()),
  (23,19,'Absent','',17,NOW()),  (23,20,'Present','',17,NOW()),
  (23,21,'Present','',17,NOW()), (23,22,'Present','',17,NOW()),
  (24,19,'Present','',17,NOW()), (24,20,'Present','',17,NOW()),
  (24,21,'Present','',17,NOW()), (24,22,'Absent','',17,NOW()),
  (25,19,'Present','',17,NOW()), (25,20,'Present','',17,NOW()),
  (25,21,'Present','',17,NOW()), (25,22,'Present','',17,NOW());

-- ── Section 4 (BEL3001-01): sessions 26-29 ───────────────────
-- enrollment 23 = 23070001
INSERT IGNORE INTO Attendance (session_id,enrollment_id,status,note,recorded_by,recorded_at)
VALUES
  (26,23,'Present','',17,NOW()),
  (27,23,'Present','',17,NOW()),
  (28,23,'Present','',17,NOW()),
  (29,23,'Present','',17,NOW());

-- ==========================================================
-- 9. ACADEMIC WARNINGS  (update existing + add new ones)
--
-- Existing: warning_id 6-17 (already present)
-- Update attendance warning for 23070001 to reflect real data
-- Add a new GPA-risk warning for 23070006 (low performer)
-- ==========================================================

-- Update 23070001's attendance warning message to be more specific
UPDATE AcademicWarning
SET message = 'Tỷ lệ chuyên cần học kỳ HK2 2024-2025 đang ở mức 83.3% (vắng 2/12 buổi – INS3065-01). Cần cải thiện để tránh bị cấm thi cuối kỳ.',
    is_read  = FALSE
WHERE warning_id = 6 AND student_id = '23070001';

-- Add new Low-GPA warning for 23070006 (GPA ~ 1.3 from scores above)
INSERT IGNORE INTO AcademicWarning (warning_id,student_id,warning_type,message,status,is_read,created_at)
VALUES (18,'23070006','LowGPA',
  'GPA tích lũy hiện tại ở mức dưới 2.0 (xếp loại Yếu). Sinh viên có nguy cơ bị buộc thôi học nếu không cải thiện trong học kỳ tiếp theo.',
  'Active',FALSE,NOW());

-- Add GraduationRisk warning for 23070006
INSERT IGNORE INTO AcademicWarning (warning_id,student_id,warning_type,message,status,is_read,created_at)
VALUES (19,'23070006','GraduationRisk',
  'Với mức GPA hiện tại và tỷ lệ chuyên cần thấp, sinh viên có nguy cơ cao không đủ điều kiện tốt nghiệp đúng hạn. Đề nghị gặp cố vấn học tập.',
  'Active',FALSE,NOW());

SET FOREIGN_KEY_CHECKS = 1;

-- ==========================================================
-- VERIFICATION QUERIES  (run after import to confirm data)
-- ==========================================================

-- ── V1. Count records inserted ────────────────────────────────
SELECT 'Curriculum'      AS tbl, COUNT(*) AS cnt FROM Curriculum
UNION SELECT 'CurriculumCourse',  COUNT(*) FROM CurriculumCourse
UNION SELECT 'ClassSection',      COUNT(*) FROM ClassSection
UNION SELECT 'Enrollment',        COUNT(*) FROM Enrollment
UNION SELECT 'Assessment',        COUNT(*) FROM Assessment
UNION SELECT 'Grade',             COUNT(*) FROM Grade
UNION SELECT 'AttendanceSession', COUNT(*) FROM AttendanceSession
UNION SELECT 'Attendance',        COUNT(*) FROM Attendance
UNION SELECT 'AcademicWarning',   COUNT(*) FROM AcademicWarning;

-- ── V2. GPA per semester for 23070001 ────────────────────────
SELECT
    sem.semester_name,
    sem.academic_year,
    ROUND(SUM(gs.grade_point * c.credits) / NULLIF(SUM(c.credits),0), 2) AS semester_gpa,
    SUM(c.credits)                                                          AS credits_counted,
    COUNT(DISTINCT e.enrollment_id)                                         AS distinct_enrollments
FROM Enrollment e
JOIN ClassSection cs  ON cs.section_id  = e.section_id
JOIN Semester sem     ON sem.semester_id = cs.semester_id
JOIN Course c         ON c.course_id    = cs.course_id
JOIN Assessment a     ON a.section_id   = cs.section_id
JOIN Grade g          ON g.enrollment_id = e.enrollment_id AND g.assessment_id = a.assessment_id
JOIN GradeScale gs    ON (g.score/10)   BETWEEN gs.min_score AND gs.max_score
WHERE e.student_id = '23070001'
  AND e.status     = 'Completed'
GROUP BY sem.semester_id
ORDER BY sem.start_date;

-- ── V3. Cumulative GPA for 23070001 ──────────────────────────
SELECT
    ROUND(SUM(gs.grade_point * c.credits) / NULLIF(SUM(c.credits),0), 2) AS cumulative_gpa,
    SUM(c.credits)                                                          AS credits_counted,
    COUNT(DISTINCT e.enrollment_id)                                         AS completed_enrollments
FROM Enrollment e
JOIN ClassSection cs  ON cs.section_id  = e.section_id
JOIN Course c         ON c.course_id    = cs.course_id
JOIN Assessment a     ON a.section_id   = cs.section_id
JOIN Grade g          ON g.enrollment_id = e.enrollment_id AND g.assessment_id = a.assessment_id
JOIN GradeScale gs    ON (g.score/10)   BETWEEN gs.min_score AND gs.max_score
WHERE e.student_id = '23070001'
  AND e.status     = 'Completed';

-- ── V4. Attendance rate for 23070001 current semester ─────────
SELECT
    c.course_code, c.course_name,
    COUNT(a.attendance_id)                                                      AS total_sessions,
    SUM(a.status IN ('Present','Late','Excused'))                               AS attended,
    ROUND(100 * SUM(a.status IN ('Present','Late','Excused'))
          / NULLIF(COUNT(a.attendance_id),0), 1)                               AS attend_pct
FROM Enrollment e
JOIN ClassSection cs ON cs.section_id = e.section_id
JOIN Course c        ON c.course_id   = cs.course_id
LEFT JOIN Attendance a ON a.enrollment_id = e.enrollment_id
WHERE e.student_id = '23070001'
  AND cs.semester_id = 4
GROUP BY e.enrollment_id, c.course_code, c.course_name;

-- ── V5. Academic warnings for 23070001 ───────────────────────
SELECT warning_id, warning_type, LEFT(message,60) AS msg_preview,
       status, is_read, DATE(created_at) AS created
FROM AcademicWarning
WHERE student_id = '23070001'
ORDER BY created_at DESC;
