🎓 GradeCalc
<div align="center">
A complete school grade management system built with PHP and MySQL.

Handles students, exams, marks, attendance, fees, and certificates — all in one place.

</div>
📖 Overview
GradeCalc replaces spreadsheets and manual paperwork with a centralized web platform for schools. It manages the full academic workflow — from enrollment → exams → marks → results → report cards — plus attendance, timetables, fees, and certificates.

Built with vanilla PHP (no framework), it runs on any XAMPP / WAMP / LAMP stack without build tools or complex setup.

💡 Perfect for: Small & medium schools · Tuition centers · Coaching institutes · Private tutors

✨ Features
🎯 Academic
👨‍🎓 Student records with photos and CSV bulk import

📚 Classes, sections, subjects, and academic years

📝 Exam creation with per-subject max/pass marks

✍️ Marks entry with live grade preview

📊 Automatic calculation of totals, averages, GPA, rank, and pass/fail

📄 Printable report cards

🏆 Class leaderboard with medal rankings

🗂️ Administrative
📅 Daily attendance (Present / Absent / Late / Excused)

🕐 Weekly class timetable

💰 Fee tracking with auto-generated receipt numbers

🎓 Certificate generator (completion and merit awards)

📈 Analytics dashboard with performance charts

⚠️ Weak student detection

⚙️ System
🔐 Role-based access — Admin, Teacher, Student, Parent

📊 Dedicated dashboard per role

🌙 Dark mode and multi-language (English / Sinhala / Tamil)

📱 PWA installable on mobile

💾 One-click SQL backup & restore

🛠 Tech Stack
<table> <tr> <th>Layer</th> <th>Technology</th> </tr> <tr> <td>⚙️ Backend</td> <td>PHP 8.0+ (procedural)</td> </tr> <tr> <td>🗄️ Database</td> <td>MySQL 8.0 / MariaDB</td> </tr> <tr> <td>🎨 Frontend</td> <td>HTML5, CSS3, Vanilla JavaScript</td> </tr> <tr> <td>📊 Charts</td> <td>Chart.js 4.4</td> </tr> <tr> <td>🖥️ Server</td> <td>Apache (XAMPP / WAMP / LAMP)</td> </tr> </table>
🔐 Security
Bcrypt password hashing

PDO prepared statements

CSRF tokens

XSS escaping

Session-based authentication

Role-based access control

🚀 Installation
Requirements
Tool	Version
PHP	8.0 or higher
MySQL	5.7 or higher
Apache	2.4+
Steps
1️⃣ Clone the repository
bash
(https://github.com/dilshankumara001001/-School-Grade-Management-System)
2️⃣ Move to web server directory
bash
# XAMPP (Windows)
move gradecalc C:\xampp\htdocs\

# Linux / macOS
sudo cp -r gradecalc /var/www/html/
3️⃣ Create the database
Open phpMyAdmin and run:

sql
CREATE DATABASE grade_calc CHARACTER SET utf8mb4;
4️⃣ Import the schema
bash
mysql -u root -p grade_calc < schema_v2.sql
💡 Optional: Load demo data (10 records per table)

bash
mysql -u root -p grade_calc < sample_data.sql
5️⃣ Configure database credentials
Edit config.php:

php
define('DB_HOST', 'localhost');
define('DB_NAME', 'grade_calc');
define('DB_USER', 'root');
define('DB_PASS', '');
6️⃣ Set folder permissions (Linux / macOS)
bash
chmod -R 755 uploads/
7️⃣ Open in browser
text
http://localhost/grade_calculator/
🔑 Default Login
Username	Password	Role
admin	admin123	👑 Admin
⚠️ Change the password immediately from Profile after first login.

📘 Usage
👑 Admin
Configure Settings — school name, address, grade scales

Add Classes and Subjects

Add Students manually or via CSV import

Create an Exam and assign subjects

Enter Marks (or let teachers do it)

View Results, print report cards

Track Attendance, Fees, issue Certificates

👨‍🏫 Teacher
Enter marks for assigned subjects

Mark daily attendance

View class performance analytics

👨‍🎓 Student
View personal performance chart

Check attendance percentage

See rank and recent results

Access class timetable

👨‍👩‍👧 Parent
View children's exam results and attendance

Receive email notifications when results are published

📁 Project Structure
text
grade_calculator/
│
├── 📄 index.php          # Main router and all pages
├── ⚙️  config.php         # DB connection and helpers
├── 🔌 api.php            # AJAX endpoints
├── 💾 backup.php         # Standalone backup downloader
├── 📋 manifest.json      # PWA manifest
├── ⚡ sw.js              # Service worker
│
├── 🗄️  schema.sql         # Core database schema
├── 🗄️  schema_v2.sql      # Extended features schema
├── 🗄️  sample_data.sql    # Demo data
│
├── 📁 uploads/           # Student photos
└── 📁 assets/
    ├── 🎨 style.css      # Stylesheet
    └── ⚡ app.js         # Interactions and charts
🗄️ Database Schema
16 tables grouped by purpose:

Group	Tables
👥 Users	users, schools
🎓 Academic	students, classes, subjects, academic_years
📝 Exams	exams, exam_subjects, marks, results, grade_scales
📋 Records	attendance, timetable, fees, certificates
⚙️ System	settings, email_log
🗺 Roadmap
□ Real SMTP integration (PHPMailer)
□ WhatsApp notifications (Twilio)
□ Excel import/export
□ Teacher allocation module
□ Two-factor authentication
□ REST API for mobile clients
🤝 Contributing
Fork the repository

Create a feature branch

bash
git checkout -b feature/your-feature
Commit your changes

bash
git commit -m 'Add your feature'
Push to the branch

bash
git push origin feature/your-feature
Open a Pull Request

📝 Please follow PSR-12 for PHP code and test on all four roles before submitting.

📄 License
This project is licensed under the MIT License — see the LICENSE file for details.



<div align="center">


</div> 
