USE grade_calc;

-- 1. Attendance
CREATE TABLE IF NOT EXISTS attendance (
  id INT AUTO_INCREMENT PRIMARY KEY,
  student_id INT, class_id INT, att_date DATE,
  status ENUM('present','absent','late','excused') DEFAULT 'present',
  remark VARCHAR(255),
  UNIQUE KEY(student_id, att_date)
);

-- 2. Timetable
CREATE TABLE IF NOT EXISTS timetable (
  id INT AUTO_INCREMENT PRIMARY KEY,
  class_id INT, subject_id INT, teacher_id INT,
  day_of_week TINYINT, start_time TIME, end_time TIME,
  room VARCHAR(50)
);

-- 3. Fees
CREATE TABLE IF NOT EXISTS fees (
  id INT AUTO_INCREMENT PRIMARY KEY,
  student_id INT, amount DECIMAL(10,2),
  description VARCHAR(255), due_date DATE, paid_date DATE,
  status ENUM('pending','paid','overdue') DEFAULT 'pending',
  method VARCHAR(50), receipt_no VARCHAR(50)
);

-- 4. Settings (key-value)
CREATE TABLE IF NOT EXISTS settings (
  key_name VARCHAR(50) PRIMARY KEY, value TEXT
);
INSERT IGNORE INTO settings (key_name,value) VALUES
('school_name','GradeCalc School'),
('school_address','123 Main Street, Colombo'),
('school_phone','+94 11 234 5678'),
('school_email','info@gradecalc.lk'),
('theme','light'),
('language','en'),
('school_id','1');

-- 5. Schools (multi-school)
CREATE TABLE IF NOT EXISTS schools (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100), address VARCHAR(255), phone VARCHAR(20),
  email VARCHAR(100), logo VARCHAR(255), is_active TINYINT DEFAULT 1
);
INSERT IGNORE INTO schools (id,name,address) VALUES (1,'GradeCalc School','Colombo');

-- 6. Email log
CREATE TABLE IF NOT EXISTS email_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  to_email VARCHAR(100), subject VARCHAR(255), body TEXT,
  status VARCHAR(20) DEFAULT 'queued',
  sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 7. Certificates
CREATE TABLE IF NOT EXISTS certificates (
  id INT AUTO_INCREMENT PRIMARY KEY,
  student_id INT, cert_type VARCHAR(50),
  title VARCHAR(255), issued_date DATE,
  ref_no VARCHAR(50) UNIQUE
);

-- 8. Add photo column to students
ALTER TABLE students ADD COLUMN photo VARCHAR(255) NULL;
ALTER TABLE students ADD COLUMN email VARCHAR(100) NULL;
ALTER TABLE students ADD COLUMN user_id INT NULL;