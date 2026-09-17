CREATE DATABASE IF NOT EXISTS grade_calc CHARACTER SET utf8mb4;
USE grade_calc;

CREATE TABLE users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(50) UNIQUE NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('admin','teacher','student','parent') NOT NULL,
  is_active TINYINT DEFAULT 1
);

CREATE TABLE academic_years (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(20), is_active TINYINT DEFAULT 0
);

CREATE TABLE classes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(50), section VARCHAR(10),
  academic_year_id INT
);

CREATE TABLE subjects (
  id INT AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(20), name VARCHAR(100), is_core TINYINT DEFAULT 1
);

CREATE TABLE students (
  id INT AUTO_INCREMENT PRIMARY KEY,
  admission_no VARCHAR(20) UNIQUE,
  first_name VARCHAR(50), last_name VARCHAR(50),
  class_id INT, dob DATE, gender ENUM('M','F'),
  guardian_name VARCHAR(100), guardian_phone VARCHAR(20)
);

CREATE TABLE exams (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100), term_id INT, class_id INT,
  exam_date DATE,
  status ENUM('draft','ongoing','published') DEFAULT 'draft'
);

CREATE TABLE exam_subjects (
  id INT AUTO_INCREMENT PRIMARY KEY,
  exam_id INT, subject_id INT,
  max_marks INT DEFAULT 100, pass_marks INT DEFAULT 35,
  UNIQUE KEY(exam_id, subject_id)
);

CREATE TABLE marks (
  id INT AUTO_INCREMENT PRIMARY KEY,
  exam_subject_id INT, student_id INT,
  marks_obtained DECIMAL(5,2) NULL,
  entered_by INT, entered_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY(exam_subject_id, student_id)
);

CREATE TABLE grade_scales (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(20), min_marks INT, max_marks INT,
  grade VARCHAR(2), gpa_point DECIMAL(3,2), remark VARCHAR(30)
);

CREATE TABLE results (
  id INT AUTO_INCREMENT PRIMARY KEY,
  exam_id INT, student_id INT,
  total DECIMAL(7,2), average DECIMAL(5,2), gpa DECIMAL(3,2),
  overall_grade VARCHAR(2), rank_in_class INT, is_pass TINYINT,
  UNIQUE KEY(exam_id, student_id)
);

-- Default grade scale (O/L style)
INSERT INTO grade_scales (name,min_marks,max_marks,grade,gpa_point,remark) VALUES
('Default',75,100,'A',4.00,'Excellent'),
('Default',65,74,'B',3.00,'Very Good'),
('Default',55,64,'C',2.00,'Good'),
('Default',35,54,'S',1.00,'Pass'),
('Default',0,34,'F',0.00,'Fail');

-- Default admin (password = "admin123")
INSERT INTO users (username,password_hash,role) VALUES
('admin', '$2y$10$e0NRzP5lT9wUJqZ0eN8vO.5u4sW6y3T1aB7cD9eF2gH4iJ6kL8mN', 'admin');
-- ⚠️ මේ hash එක replace කරන්න: php -r "echo password_hash('admin123',PASSWORD_DEFAULT);"