<?php
require 'config.php';

// ---- AUTO SETUP ----
$userCount = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
if($userCount == 0) {
  $pdo->prepare("INSERT INTO users (username,password_hash,full_name,role,is_active) VALUES (?,?,?,?,1)")
    ->execute(['admin', password_hash('admin123',PASSWORD_DEFAULT),'Administrator','admin']);
}

$page = $_GET['p'] ?? 'login';

// ---- LOGIN ----
if($page==='login' && $_SERVER['REQUEST_METHOD']==='POST') {
  $st=$pdo->prepare("SELECT * FROM users WHERE username=? AND is_active=1");
  $st->execute([$_POST['username']]); $u=$st->fetch();
  if($u && ($_POST['password']===$u['password_hash'] || password_verify($_POST['password'],$u['password_hash']))) {
    $_SESSION['user']=$u; redirect('index.php?p=dashboard');
  }
  $error="Invalid username or password";
}
if($page==='logout') { session_destroy(); redirect('index.php'); }
if($page!=='login' && empty($_SESSION['user'])) redirect('index.php?p=login');
$user = $_SESSION['user'] ?? null;

// ==================== POST HANDLERS ====================

// STUDENTS
if($page==='students' && $_SERVER['REQUEST_METHOD']==='POST') {
  check_csrf();
  try {
    $photo = uploadPhoto($_FILES['photo'] ?? [], 'stu');
    $pdo->prepare("INSERT INTO students (admission_no,first_name,last_name,class_id,gender,guardian_name,guardian_phone,dob,email,photo) VALUES (?,?,?,?,?,?,?,?,?,?)")
      ->execute([$_POST['adm'],$_POST['fn'],$_POST['ln'],$_POST['class']?:null,
        $_POST['gender']?:'M',$_POST['guardian']??'',$_POST['phone']??'',
        $_POST['dob']?:null,$_POST['email']??null,$photo]);
    flash('Student added'); redirect('index.php?p=students');
  } catch(Exception $e) { $error="Add failed: ".$e->getMessage(); }
}
if($page==='student_edit' && $_SERVER['REQUEST_METHOD']==='POST') {
  check_csrf();
  $photo = uploadPhoto($_FILES['photo'] ?? [], 'stu');
  $sql = "UPDATE students SET admission_no=?,first_name=?,last_name=?,class_id=?,gender=?,guardian_name=?,guardian_phone=?,dob=?,email=?";
  $params = [$_POST['adm'],$_POST['fn'],$_POST['ln'],$_POST['class']?:null,
    $_POST['gender'],$_POST['guardian'],$_POST['phone'],$_POST['dob']?:null,$_POST['email']??null];
  if($photo) { $sql.=",photo=?"; $params[]=$photo; }
  $sql.=" WHERE id=?"; $params[]=$_POST['id'];
  $pdo->prepare($sql)->execute($params);
  flash('Student updated'); redirect('index.php?p=student&id='.$_POST['id']);
}
if($page==='student_delete') {
  $pdo->prepare("DELETE FROM students WHERE id=?")->execute([(int)$_GET['id']]);
  flash('Student deleted'); redirect('index.php?p=students');
}

// CSV IMPORT
if($page==='students_import' && $_SERVER['REQUEST_METHOD']==='POST') {
  check_csrf();
  $count=0; $errs=[];
  if(!empty($_FILES['csv']['tmp_name'])) {
    $fh=fopen($_FILES['csv']['tmp_name'],'r');
    $header=fgetcsv($fh);
    while($row=fgetcsv($fh)) {
      if(count($row)<3) continue;
      try {
        $pdo->prepare("INSERT INTO students (admission_no,first_name,last_name,class_id,gender,guardian_name,guardian_phone) VALUES (?,?,?,?,?,?,?)")
          ->execute([$row[0],$row[1],$row[2],(int)($row[3]??null),$row[4]??'M',$row[5]??'',$row[6]??'']);
        $count++;
      } catch(Exception $e) { $errs[]=$row[0].": ".$e->getMessage(); }
    }
    fclose($fh);
  }
  flash("Imported $count students".($errs?" (".count($errs)." errors)":''));
  redirect('index.php?p=students');
}

// CLASSES / SUBJECTS / EXAMS / MARKS / USERS / GRADES
if($page==='classes' && $_SERVER['REQUEST_METHOD']==='POST') {
  check_csrf();
  $pdo->prepare("INSERT INTO classes (name,section,academic_year_id) VALUES (?,?,?)")
    ->execute([$_POST['name'],$_POST['section'],$_POST['year']?:null]);
  flash('Class added'); redirect('index.php?p=classes');
}
if($page==='class_delete') { $pdo->prepare("DELETE FROM classes WHERE id=?")->execute([(int)$_GET['id']]); flash('Class deleted'); redirect('index.php?p=classes'); }
if($page==='subjects' && $_SERVER['REQUEST_METHOD']==='POST') {
  check_csrf();
  $pdo->prepare("INSERT INTO subjects (code,name,is_core) VALUES (?,?,?)")->execute([$_POST['code'],$_POST['name'],$_POST['core']??1]);
  flash('Subject added'); redirect('index.php?p=subjects');
}
if($page==='subject_delete') { $pdo->prepare("DELETE FROM subjects WHERE id=?")->execute([(int)$_GET['id']]); flash('Subject deleted'); redirect('index.php?p=subjects'); }
if($page==='exams_save' && $_SERVER['REQUEST_METHOD']==='POST') {
  check_csrf();
  $pdo->prepare("INSERT INTO exams (name,exam_date,class_id,status) VALUES (?,?,?,'ongoing')")->execute([$_POST['name'],$_POST['date'],$_POST['class']]);
  flash('Exam created'); redirect('index.php?p=exams');
}
if($page==='exam_delete') {
  $eid=(int)$_GET['id'];
  $pdo->prepare("DELETE FROM exams WHERE id=?")->execute([$eid]);
  $pdo->prepare("DELETE FROM exam_subjects WHERE exam_id=?")->execute([$eid]);
  $pdo->prepare("DELETE FROM results WHERE exam_id=?")->execute([$eid]);
  flash('Exam deleted'); redirect('index.php?p=exams');
}
if($page==='exam_publish') {
  $pdo->prepare("UPDATE exams SET status=? WHERE id=?")->execute([$_GET['status']??'ongoing',(int)$_GET['id']]);
  $exam_id = (int)$_GET['id'];
  if(($_GET['status']??'')==='published') {
    $stu = $pdo->query("SELECT s.*,r.average,r.overall_grade,r.rank_in_class FROM students s
      JOIN results r ON r.student_id=s.id WHERE r.exam_id=$exam_id")->fetchAll();
    foreach($stu as $s) if($s['email']) {
      sendEmail($pdo,$s['email'],"Exam Results Published",
        "Dear Parent,\n\n".$s['first_name']."'s results are published.\nAverage: ".number_format($s['average'],2)."\nGrade: ".$s['overall_grade']."\nRank: #".$s['rank_in_class']);
    }
  }
  flash("Exam status updated"); redirect('index.php?p=exam_manage&id='.$exam_id);
}
if($page==='exam_manage' && $_SERVER['REQUEST_METHOD']==='POST') {
  check_csrf(); $eid=(int)$_POST['exam_id'];
  try { $pdo->prepare("INSERT IGNORE INTO exam_subjects (exam_id,subject_id,max_marks,pass_marks) VALUES (?,?,?,?)")->execute([$eid,$_POST['subject'],$_POST['max'],$_POST['pass']]); flash('Subject added'); } catch(Exception $e) {}
  redirect('index.php?p=exam_manage&id='.$eid);
}
if($page==='es_delete') { $pdo->prepare("DELETE FROM exam_subjects WHERE id=?")->execute([(int)$_GET['id']]); flash('Subject removed'); redirect('index.php?p=exam_manage&id='.(int)$_GET['exam']); }
if($page==='marks' && $_SERVER['REQUEST_METHOD']==='POST') {
  check_csrf(); $es_id=(int)$_POST['es_id'];
  foreach($_POST['marks'] as $sid=>$m) {
    $pdo->prepare("INSERT INTO marks (exam_subject_id,student_id,marks_obtained,entered_by) VALUES (?,?,?,?)
      ON DUPLICATE KEY UPDATE marks_obtained=VALUES(marks_obtained),entered_by=VALUES(entered_by)")
      ->execute([$es_id,$sid,$m===''?null:$m,$user['id']]);
  }
  $exam_id = $pdo->query("SELECT exam_id FROM exam_subjects WHERE id=$es_id")->fetchColumn();
  recomputeExamResults($pdo,$exam_id);
  flash('Marks saved'); redirect('index.php?p=marks&es='.$es_id);
}
if($page==='users' && $_SERVER['REQUEST_METHOD']==='POST') {
  check_csrf();
  try { $pdo->prepare("INSERT INTO users (username,password_hash,full_name,role,is_active) VALUES (?,?,?,?,1)")
    ->execute([$_POST['username'],password_hash($_POST['password'],PASSWORD_DEFAULT),$_POST['full_name'],$_POST['role']]);
    flash('User created');
  } catch(Exception $e) { flash('Error: '.$e->getMessage(),'error'); }
  redirect('index.php?p=users');
}
if($page==='user_toggle') { $pdo->prepare("UPDATE users SET is_active=1-is_active WHERE id=?")->execute([(int)$_GET['id']]); flash('User updated'); redirect('index.php?p=users'); }
if($page==='grades' && $_SERVER['REQUEST_METHOD']==='POST') {
  check_csrf();
  $pdo->prepare("INSERT INTO grade_scales (name,min_marks,max_marks,grade,gpa_point,remark) VALUES (?,?,?,?,?,?)")
    ->execute([$_POST['name'],$_POST['min'],$_POST['max'],$_POST['grade'],$_POST['gpa'],$_POST['remark']]);
  flash('Grade added'); redirect('index.php?p=grades');
}
if($page==='grade_delete') { $pdo->prepare("DELETE FROM grade_scales WHERE id=?")->execute([(int)$_GET['id']]); flash('Grade deleted'); redirect('index.php?p=grades'); }

// ===== ATTENDANCE =====
if($page==='attendance' && $_SERVER['REQUEST_METHOD']==='POST') {
  check_csrf();
  $date = $_POST['att_date']; $class_id = (int)$_POST['class_id'];
  foreach($_POST['status'] as $sid=>$st) {
    $pdo->prepare("INSERT INTO attendance (student_id,class_id,att_date,status) VALUES (?,?,?,?)
      ON DUPLICATE KEY UPDATE status=VALUES(status)")
      ->execute([$sid,$class_id,$date,$st]);
  }
  flash('Attendance saved for '.$date); redirect('index.php?p=attendance&class='.$class_id.'&date='.$date);
}

// ===== TIMETABLE =====
if($page==='timetable' && $_SERVER['REQUEST_METHOD']==='POST') {
  check_csrf();
  try { $pdo->prepare("INSERT INTO timetable (class_id,subject_id,teacher_id,day_of_week,start_time,end_time,room) VALUES (?,?,?,?,?,?,?)")
    ->execute([$_POST['class_id'],$_POST['subject_id'],$_POST['teacher_id']?:null,$_POST['day'],$_POST['start'],$_POST['end'],$_POST['room']??'']);
    flash('Timetable entry added');
  } catch(Exception $e) { flash('Error','error'); }
  redirect('index.php?p=timetable&class='.$_POST['class_id']);
}
if($page==='tt_delete') { $pdo->prepare("DELETE FROM timetable WHERE id=?")->execute([(int)$_GET['id']]); flash('Deleted'); redirect('index.php?p=timetable&class='.(int)$_GET['class']); }

// ===== FEES =====
if($page==='fees' && $_SERVER['REQUEST_METHOD']==='POST') {
  check_csrf();
  $rno = 'RCP'.date('Ymd').str_pad(rand(1,9999),4,'0',STR_PAD_LEFT);
  $pdo->prepare("INSERT INTO fees (student_id,amount,description,due_date,paid_date,status,method,receipt_no) VALUES (?,?,?,?,?,?,?,?)")
    ->execute([$_POST['student_id'],$_POST['amount'],$_POST['description'],$_POST['due_date'],
      $_POST['paid_date']?:null,$_POST['status']??'pending',$_POST['method']??'',$_POST['paid_date']?$rno:null]);
  flash('Fee record added'); redirect('index.php?p=fees');
}
if($page==='fee_mark_paid') {
  $id=(int)$_GET['id'];
  $rno='RCP'.date('Ymd').str_pad(rand(1,9999),4,'0',STR_PAD_LEFT);
  $pdo->prepare("UPDATE fees SET status='paid',paid_date=CURDATE(),receipt_no=? WHERE id=?")->execute([$rno,$id]);
  flash("Marked paid (Receipt: $rno)"); redirect('index.php?p=fees');
}
if($page==='fee_delete') { $pdo->prepare("DELETE FROM fees WHERE id=?")->execute([(int)$_GET['id']]); flash('Deleted'); redirect('index.php?p=fees'); }

// ===== PASSWORD CHANGE =====
if($page==='profile' && $_SERVER['REQUEST_METHOD']==='POST') {
  check_csrf();
  $cur = $_POST['current']; $new = $_POST['new']; $conf = $_POST['confirm'];
  if($new !== $conf) { flash('New passwords do not match','error'); }
  elseif(strlen($new) < 6) { flash('Password must be 6+ characters','error'); }
  elseif(!password_verify($cur, $user['password_hash']) && $cur !== $user['password_hash']) { flash('Current password is wrong','error'); }
  else {
    $pdo->prepare("UPDATE users SET password_hash=?, full_name=? WHERE id=?")
      ->execute([password_hash($new,PASSWORD_DEFAULT), $_POST['full_name'], $user['id']]);
    $_SESSION['user']['full_name']=$_POST['full_name'];
    flash('Profile updated');
  }
  redirect('index.php?p=profile');
}

// ===== LANGUAGE / THEME =====
if($page==='settings' && $_SERVER['REQUEST_METHOD']==='POST') {
  check_csrf();
  if(isset($_POST['theme']))    setSetting($pdo,'theme',$_POST['theme']);
  if(isset($_POST['language'])) setSetting($pdo,'language',$_POST['language']);
  if(isset($_POST['school_name'])) {
    setSetting($pdo,'school_name',$_POST['school_name']);
    setSetting($pdo,'school_address',$_POST['school_address']??'');
    setSetting($pdo,'school_phone',$_POST['school_phone']??'');
    setSetting($pdo,'school_email',$_POST['school_email']??'');
  }
  flash('Settings saved'); redirect('index.php?p=settings');
}

// ===== BACKUP =====
if($page==='backup_download') {
  $sql = createBackup($pdo);
  header('Content-Type: application/sql');
  header('Content-Disposition: attachment; filename="backup_'.date('Ymd_His').'.sql"');
  echo $sql; exit;
}
if($page==='backup_restore' && $_SERVER['REQUEST_METHOD']==='POST') {
  check_csrf();
  if(!empty($_FILES['sqlfile']['tmp_name'])) {
    $sql = file_get_contents($_FILES['sqlfile']['tmp_name']);
    try {
      $pdo->exec($sql);
      flash('Database restored successfully');
    } catch(Exception $e) { flash('Restore failed: '.$e->getMessage(),'error'); }
  }
  redirect('index.php?p=backup');
}

// ===== CERTIFICATES =====
if($page==='cert_create' && $_SERVER['REQUEST_METHOD']==='POST') {
  check_csrf();
  $ref='CERT'.date('Y').str_pad(rand(1,99999),5,'0',STR_PAD_LEFT);
  $pdo->prepare("INSERT INTO certificates (student_id,cert_type,title,issued_date,ref_no) VALUES (?,?,?,CURDATE(),?)")
    ->execute([$_POST['student_id'],$_POST['type'],$_POST['title'],$ref]);
  flash('Certificate issued: '.$ref);
  redirect('index.php?p=certificate&id='.$pdo->lastInsertId());
}

$flash = getFlash();
$schoolName = getSetting($pdo,'school_name','GradeCalc School');
$schoolAddr = getSetting($pdo,'school_address','');
$theme = getSetting($pdo,'theme','light');
$lang  = getSetting($pdo,'language','en');
?><!DOCTYPE html>
<html lang="<?= h($lang) ?>" data-theme="<?= h($theme) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= h($schoolName) ?> — Grade Management</title>
<link rel="manifest" href="manifest.json">
<meta name="theme-color" content="#6366f1">
<link rel="stylesheet" href="assets/style.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
</head>
<body>

<?php if($page==='login'): ?>

<div class="login-wrap">
  <form method="post" class="login-box">
    <h1>🎓 <?= h($schoolName) ?></h1>
    <p class="subtitle">School Grade Management System</p>
    <?php if(!empty($error)): ?><div class="flash flash-error"><?= h($error) ?></div><?php endif; ?>
    <input name="username" placeholder="Username" required autofocus>
    <input name="password" type="password" placeholder="Password" required>
    <button type="submit">Sign In →</button>
    <p class="hint">Default: <b>admin</b> / <b>admin123</b></p>
  </form>
</div>

<?php elseif($page==='certificate'): 
  $cid = (int)($_GET['id'] ?? 0);
  $c = $pdo->query("SELECT c.*,s.first_name,s.last_name,s.admission_no,cl.name cname,cl.section csec FROM certificates c JOIN students s ON s.id=c.student_id LEFT JOIN classes cl ON cl.id=s.class_id WHERE c.id=$cid")->fetch();
  if($c): ?>
  <div class="cert-page">
    <div class="cert-actions no-print">
      <button onclick="window.print()">🖨 Print / Save as PDF</button>
      <a href="?p=certificates" class="btn btn-secondary">← Back</a>
    </div>
    <div class="certificate" id="cert">
      <div class="cert-border">
        <div class="cert-head">
          <div class="cert-logo">🎓</div>
          <h1><?= h($schoolName) ?></h1>
          <p><?= h($schoolAddr) ?></p>
        </div>
        <div class="cert-title"><?= h(strtoupper($c['title'])) ?></div>
        <p class="cert-body">This is to certify that</p>
        <div class="cert-name"><?= h($c['first_name'].' '.$c['last_name']) ?></div>
        <p class="cert-body">Admission No. <b><?= h($c['admission_no']) ?></b> of <?= h($c['cname'].' '.$c['csec']) ?></p>
        <p class="cert-body">has successfully <?= $c['cert_type']==='completion'?'completed the course requirements':'achieved outstanding performance' ?> at <b><?= h($schoolName) ?></b>.</p>
        <div class="cert-foot">
          <div><div class="cert-line"></div>Date: <?= h($c['issued_date']) ?></div>
          <div><div class="cert-line"></div>Ref: <?= h($c['ref_no']) ?></div>
          <div><div class="cert-line"></div>Principal</div>
        </div>
        <div class="cert-seal">SEAL</div>
      </div>
    </div>
  </div>
<?php else: echo "<h1>Certificate not found</h1>"; endif; ?>

<?php else: ?>

<div class="app">
  <aside class="sidebar no-print">
    <div class="brand">🎓 <span><?= h(explode(' ',$schoolName)[0]) ?></span></div>
    <nav>
      <a href="?p=dashboard" class="<?= $page==='dashboard'?'active':'' ?>"><span class="icon">📊</span><?= t('dashboard',$pdo) ?></a>
      <?php if($user['role']!=='parent' && $user['role']!=='student'): ?>
      <a href="?p=students" class="<?= in_array($page,['students','student','student_edit'])?'active':'' ?>"><span class="icon">👨‍🎓</span><?= t('students',$pdo) ?></a>
      <a href="?p=classes" class="<?= $page==='classes'?'active':'' ?>"><span class="icon">📚</span><?= t('classes',$pdo) ?></a>
      <a href="?p=subjects" class="<?= $page==='subjects'?'active':'' ?>"><span class="icon">📖</span><?= t('subjects',$pdo) ?></a>
      <div class="section">Examinations</div>
      <a href="?p=exams" class="<?= in_array($page,['exams','exam_manage'])?'active':'' ?>"><span class="icon">📝</span><?= t('exams',$pdo) ?></a>
      <a href="?p=marks" class="<?= $page==='marks'?'active':'' ?>"><span class="icon">✍️</span><?= t('marks',$pdo) ?></a>
      <a href="?p=attendance" class="<?= $page==='attendance'?'active':'' ?>"><span class="icon">📅</span><?= t('attendance',$pdo) ?></a>
      <a href="?p=timetable" class="<?= $page==='timetable'?'active':'' ?>"><span class="icon">🕐</span><?= t('timetable',$pdo) ?></a>
      <?php endif; ?>
      <a href="?p=results" class="<?= $page==='results'?'active':'' ?>"><span class="icon">📈</span><?= t('results',$pdo) ?></a>
      <a href="?p=leaderboard" class="<?= $page==='leaderboard'?'active':'' ?>"><span class="icon">🏆</span><?= t('leaderboard',$pdo) ?></a>
      <?php if($user['role']==='admin'): ?>
      <a href="?p=fees" class="<?= $page==='fees'?'active':'' ?>"><span class="icon">💰</span><?= t('fees',$pdo) ?></a>
      <a href="?p=certificates" class="<?= in_array($page,['certificates','certificate'])?'active':'' ?>"><span class="icon">🎓</span><?= t('certificates',$pdo) ?></a>
      <a href="?p=analytics" class="<?= $page==='analytics'?'active':'' ?>"><span class="icon">📉</span><?= t('analytics',$pdo) ?></a>
      <?php endif; ?>
      <?php if($user['role']==='student'): ?>
      <div class="section">My</div>
      <a href="?p=timetable" class="<?= $page==='timetable'?'active':'' ?>"><span class="icon">🕐</span>My Timetable</a>
      <?php endif; ?>
      <div class="section"><?= $user['role']==='parent'?'My':'Settings' ?></div>
      <?php if($user['role']==='parent'): ?>
      <a href="?p=parent_dashboard" class="<?= $page==='parent_dashboard'?'active':'' ?>"><span class="icon">👶</span>My Children</a>
      <?php elseif($user['role']==='admin'): ?>
      <a href="?p=grades" class="<?= $page==='grades'?'active':'' ?>"><span class="icon">🎯</span>Grade Scales</a>
      <a href="?p=users" class="<?= $page==='users'?'active':'' ?>"><span class="icon">👥</span>Users</a>
      <a href="?p=backup" class="<?= $page==='backup'?'active':'' ?>"><span class="icon">💾</span><?= t('backup',$pdo) ?></a>
      <a href="?p=settings" class="<?= $page==='settings'?'active':'' ?>"><span class="icon">⚙️</span>Settings</a>
      <?php endif; ?>
      <a href="?p=profile" class="<?= $page==='profile'?'active':'' ?>"><span class="icon">👤</span><?= t('profile',$pdo) ?></a>
      <a href="?p=logout"><span class="icon">🚪</span><?= t('logout',$pdo) ?></a>
    </nav>
  </aside>

  <div class="main">
    <div class="topbar no-print">
      <h2><?php
        $titles = ['dashboard'=>'Dashboard','students'=>'Students','student'=>'Student Profile',
          'student_edit'=>'Edit Student','classes'=>'Classes','subjects'=>'Subjects',
          'exams'=>'Exams','exam_manage'=>'Manage Exam','marks'=>'Marks Entry',
          'results'=>'Results','leaderboard'=>'Leaderboard','grades'=>'Grade Scales',
          'users'=>'Users','report'=>'Report Card','attendance'=>'Attendance',
          'timetable'=>'Timetable','fees'=>'Fees','analytics'=>'Analytics',
          'certificates'=>'Certificates','certificate'=>'Certificate','profile'=>'My Profile',
          'settings'=>'Settings','backup'=>'Backup & Restore','parent_dashboard'=>'My Children'];
        echo h($titles[$page] ?? 'Dashboard');
      ?></h2>
      <div class="user-info">
        <button id="theme-toggle" class="icon-btn" title="Toggle theme">🌓</button>
        <span><?= h($user['full_name'] ?: $user['username']) ?> · <?= ucfirst($user['role']) ?></span>
        <div class="avatar"><?= strtoupper(substr($user['username'],0,1)) ?></div>
      </div>
    </div>

    <div class="content">

    <?php if($flash): ?>
      <div class="flash flash-<?= h($flash['type']) ?>"><?= h($flash['msg']) ?></div>
    <?php endif; ?>

<?php /* ============================================================
   DASHBOARD — ROLE BASED
============================================================ */ ?>
    <?php if($page==='dashboard'): ?>

    <?php if($user['role'] === 'admin'): ?>
      <?php /* ============ 👑 ADMIN DASHBOARD ============ */
        $stats = [
          'students'=>$pdo->query("SELECT COUNT(*) FROM students")->fetchColumn(),
          'teachers'=>$pdo->query("SELECT COUNT(*) FROM users WHERE role='teacher'")->fetchColumn(),
          'exams'=>$pdo->query("SELECT COUNT(*) FROM exams")->fetchColumn(),
          'classes'=>$pdo->query("SELECT COUNT(*) FROM classes")->fetchColumn(),
        ];
        $topStudents = $pdo->query("SELECT r.average,s.first_name,s.last_name,s.admission_no,s.id FROM results r JOIN students s ON s.id=r.student_id ORDER BY r.average DESC LIMIT 5")->fetchAll();
        $examTrend = $pdo->query("SELECT e.name,AVG(r.average) avg FROM exams e LEFT JOIN results r ON r.exam_id=e.id GROUP BY e.id ORDER BY e.exam_date LIMIT 10")->fetchAll();
        $gradeDist = $pdo->query("SELECT overall_grade,COUNT(*) c FROM results GROUP BY overall_grade")->fetchAll();
        $todayAtt = $pdo->query("SELECT SUM(status='present') p, COUNT(*) t FROM attendance WHERE att_date=CURDATE()")->fetch();
        $feesTotal = $pdo->query("SELECT SUM(amount) FROM fees")->fetchColumn() ?: 0;
        $feesPaid  = $pdo->query("SELECT SUM(amount) FROM fees WHERE status='paid'")->fetchColumn() ?: 0;
        $passRate  = $pdo->query("SELECT AVG(is_pass)*100 FROM results")->fetchColumn();
      ?>
      <div class="page-head">
        <div>
          <h1>👑 Admin Dashboard</h1>
          <p>Welcome back, <b><?= h($user['full_name']) ?></b> · Complete system overview</p>
        </div>
        <div>
          <a href="?p=exams" class="btn">📝 New Exam</a>
        </div>
      </div>

      <div class="stat-grid">
        <div class="stat"><div class="label">Students</div><div class="value"><?= $stats['students'] ?></div><div class="icon">👨‍🎓</div></div>
        <div class="stat"><div class="label">Teachers</div><div class="value"><?= $stats['teachers'] ?></div><div class="icon">👨‍🏫</div></div>
        <div class="stat"><div class="label">Exams</div><div class="value"><?= $stats['exams'] ?></div><div class="icon">📝</div></div>
        <div class="stat"><div class="label">Classes</div><div class="value"><?= $stats['classes'] ?></div><div class="icon">📚</div></div>
      </div>

      <div class="stat-grid">
        <div class="stat" style="background:linear-gradient(135deg,#ecfdf5,#d1fae5)">
          <div class="label">Fees Collected</div>
          <div class="value" style="font-size:22px;color:#047857"><?= money($feesPaid) ?></div>
          <div class="icon">💰</div>
        </div>
        <div class="stat" style="background:linear-gradient(135deg,#fef2f2,#fee2e2)">
          <div class="label">Fees Pending</div>
          <div class="value" style="font-size:22px;color:#b91c1c"><?= money($feesTotal - $feesPaid) ?></div>
          <div class="icon">⚠️</div>
        </div>
        <div class="stat">
          <div class="label">Attendance Today</div>
          <div class="value"><?= ($todayAtt['t']??0)?($todayAtt['p']??0).'/'.$todayAtt['t']:'-' ?></div>
          <div class="icon">📅</div>
        </div>
        <div class="stat">
          <div class="label">Pass Rate</div>
          <div class="value"><?= $passRate ? number_format($passRate,1).'%' : '-' ?></div>
          <div class="icon">✅</div>
        </div>
      </div>

      <div class="grid-2">
        <div class="card">
          <h3>📈 Exam Performance Trend</h3>
          <canvas id="trendChart" height="120"></canvas>
        </div>
        <div class="card">
          <h3>🎯 Grade Distribution</h3>
          <canvas id="gradeChart" height="120"></canvas>
        </div>
      </div>

      <div class="grid-2">
        <div class="card">
          <h3>🏆 Top Performers</h3>
          <?php if(!$topStudents): ?><p style="color:#94a3b8">No results yet</p>
          <?php else: ?>
            <table>
              <?php foreach($topStudents as $i=>$s): ?>
                <tr>
                  <td style="width:40px"><b><?= ['🥇','🥈','🥉'][$i] ?? '#'.($i+1) ?></b></td>
                  <td>
                    <a href="?p=student&id=<?= $s['id'] ?>" class="user-cell">
                      <div class="avatar-sm"><?= strtoupper(substr($s['first_name'],0,1)) ?></div>
                      <div><b><?= h($s['first_name'].' '.$s['last_name']) ?></b>
                        <div style="font-size:12px;color:#94a3b8"><?= h($s['admission_no']) ?></div></div>
                    </a>
                  </td>
                  <td class="num"><b><?= number_format($s['average'],2) ?></b></td>
                </tr>
              <?php endforeach; ?>
            </table>
          <?php endif; ?>
        </div>
        <div class="card">
          <h3>⚡ Quick Actions</h3>
          <div class="quick-grid">
            <a href="?p=students" class="qa">👨‍🎓<span>Students</span></a>
            <a href="?p=exams" class="qa">📝<span>Exams</span></a>
            <a href="?p=marks" class="qa">✍️<span>Marks</span></a>
            <a href="?p=attendance" class="qa">📅<span>Attendance</span></a>
            <a href="?p=fees" class="qa">💰<span>Fees</span></a>
            <a href="?p=analytics" class="qa">📉<span>Analytics</span></a>
            <a href="?p=certificates" class="qa">🎓<span>Certificates</span></a>
            <a href="?p=backup" class="qa">💾<span>Backup</span></a>
            <a href="?p=users" class="qa">👥<span>Users</span></a>
          </div>
        </div>
      </div>

      <script>
      window.addEventListener('DOMContentLoaded',()=>{
        new Chart(document.getElementById('trendChart'),{type:'line',
          data:{labels:<?= json_encode(array_column($examTrend,'name')) ?>,
            datasets:[{label:'Avg',data:<?= json_encode(array_map(fn($r)=>round($r['avg']??0,2),$examTrend)) ?>,
              borderColor:'#6366f1',backgroundColor:'rgba(99,102,241,.1)',fill:true,tension:.4,borderWidth:3,pointRadius:5}]},
          options:{plugins:{legend:{display:false}},scales:{y:{beginAtZero:true,max:100}}}});
        const gd=<?= json_encode($gradeDist) ?>;
        new Chart(document.getElementById('gradeChart'),{type:'doughnut',
          data:{labels:gd.map(x=>x.overall_grade),datasets:[{data:gd.map(x=>x.c),
            backgroundColor:['#10b981','#06b6d4','#f59e0b','#ea580c','#ef4444']}]},
          options:{plugins:{legend:{position:'bottom'}}}});
      });
      </script>

    <?php elseif($user['role'] === 'teacher'): ?>
      <?php /* ============ 👨‍🏫 TEACHER DASHBOARD ============ */
        $studentCount = $pdo->query("SELECT COUNT(*) FROM students")->fetchColumn();
        $classCount   = $pdo->query("SELECT COUNT(*) FROM classes")->fetchColumn();
        $subjectCount = $pdo->query("SELECT COUNT(*) FROM subjects")->fetchColumn();
        $examCount    = $pdo->query("SELECT COUNT(*) FROM exams WHERE status IN ('ongoing','draft')")->fetchColumn();
        $todayAtt     = $pdo->query("SELECT SUM(status='present') p, COUNT(*) t FROM attendance WHERE att_date=CURDATE()")->fetch();
        $myClasses = $pdo->query("SELECT c.id, c.name, c.section,
          (SELECT COUNT(*) FROM students WHERE class_id=c.id) scount
          FROM classes c ORDER BY c.name, c.section LIMIT 6")->fetchAll();
        $recentExams = $pdo->query("SELECT e.*, c.name cname, c.section csec,
          (SELECT COUNT(*) FROM exam_subjects WHERE exam_id=e.id) subj_count
          FROM exams e LEFT JOIN classes c ON c.id=e.class_id
          ORDER BY e.id DESC LIMIT 5")->fetchAll();
        $classPerf = $pdo->query("SELECT c.name cname, c.section csec, AVG(r.average) avg_pct
          FROM classes c LEFT JOIN exams e ON e.class_id=c.id
          LEFT JOIN results r ON r.exam_id=e.id
          GROUP BY c.id HAVING avg_pct IS NOT NULL ORDER BY avg_pct DESC LIMIT 6")->fetchAll();
      ?>
      <div class="page-head">
        <div>
          <h1>👨‍🏫 Teacher Dashboard</h1>
          <p>Welcome, <b><?= h($user['full_name']) ?></b> · Your teaching overview</p>
        </div>
        <div>
          <a href="?p=marks" class="btn">✍️ Enter Marks</a>
        </div>
      </div>

      <div class="stat-grid">
        <div class="stat"><div class="label">Students</div><div class="value"><?= $studentCount ?></div><div class="icon">👨‍🎓</div></div>
        <div class="stat"><div class="label">Classes</div><div class="value"><?= $classCount ?></div><div class="icon">📚</div></div>
        <div class="stat"><div class="label">Subjects</div><div class="value"><?= $subjectCount ?></div><div class="icon">📖</div></div>
        <div class="stat"><div class="label">Active Exams</div><div class="value"><?= $examCount ?></div><div class="icon">📝</div></div>
      </div>

      <div class="grid-2">
        <div class="card">
          <h3>📅 Today's Attendance</h3>
          <?php if(($todayAtt['t']??0) > 0): ?>
            <div style="text-align:center;padding:24px">
              <div style="font-size:60px;font-weight:800;color:#6366f1;letter-spacing:-2px;line-height:1">
                <?= $todayAtt['p'] ?>/<?= $todayAtt['t'] ?>
              </div>
              <div style="color:#64748b;margin-top:12px;font-size:15px">
                <b style="color:#10b981"><?= number_format(($todayAtt['p']/$todayAtt['t'])*100,1) ?>%</b> present today
              </div>
              <a href="?p=attendance" class="btn" style="margin-top:20px">📅 Mark Attendance</a>
            </div>
          <?php else: ?>
            <div style="text-align:center;padding:30px 20px">
              <div style="font-size:56px;opacity:.4">📅</div>
              <p style="color:#64748b;margin:14px 0;font-size:14px">Attendance not marked today</p>
              <a href="?p=attendance" class="btn">Mark Now →</a>
            </div>
          <?php endif; ?>
        </div>

        <div class="card">
          <h3>⚡ Quick Actions</h3>
          <div class="quick-grid">
            <a href="?p=marks" class="qa">✍️<span>Marks</span></a>
            <a href="?p=attendance" class="qa">📅<span>Attendance</span></a>
            <a href="?p=students" class="qa">👨‍🎓<span>Students</span></a>
            <a href="?p=results" class="qa">📈<span>Results</span></a>
            <a href="?p=timetable" class="qa">🕐<span>Timetable</span></a>
            <a href="?p=analytics" class="qa">📉<span>Analytics</span></a>
          </div>
        </div>
      </div>

      <div class="grid-2">
        <div class="card">
          <h3>📚 My Classes</h3>
          <?php if(!$myClasses): ?><p style="color:#94a3b8">No classes yet</p>
          <?php else: ?>
            <table>
              <thead><tr><th>Class</th><th class="num">Students</th><th></th></tr></thead>
              <tbody>
              <?php foreach($myClasses as $c): ?>
                <tr>
                  <td><b><?= h($c['name'].' '.$c['section']) ?></b></td>
                  <td class="num"><b><?= $c['scount'] ?></b></td>
                  <td><a href="?p=attendance&class=<?= $c['id'] ?>" class="btn btn-sm btn-secondary">View →</a></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          <?php endif; ?>
        </div>

        <div class="card">
          <h3>📊 Class Performance</h3>
          <?php if(!$classPerf): ?><p style="color:#94a3b8">No data yet</p>
          <?php else: ?>
            <canvas id="classChart" height="120"></canvas>
          <?php endif; ?>
        </div>
      </div>

      <div class="card">
        <h3>📝 Recent Exams</h3>
        <?php if(!$recentExams): ?><p style="color:#94a3b8">No exams yet</p>
        <?php else: ?>
          <table>
            <thead><tr><th>Exam</th><th>Class</th><th>Date</th><th>Subjects</th><th>Status</th><th></th></tr></thead>
            <tbody>
            <?php foreach($recentExams as $e): ?>
              <tr>
                <td><b><?= h($e['name']) ?></b></td>
                <td><?= h($e['cname'].' '.$e['csec']) ?></td>
                <td><?= h($e['exam_date']) ?></td>
                <td><?= $e['subj_count'] ?></td>
                <td><span class="badge badge-<?= $e['status'] ?>"><?= h($e['status']) ?></span></td>
                <td><a href="?p=exam_manage&id=<?= $e['id'] ?>" class="btn btn-sm btn-secondary">Manage →</a></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>

      <script>
      window.addEventListener('DOMContentLoaded',()=>{
        const cd = <?= json_encode($classPerf) ?>;
        if (cd.length && document.getElementById('classChart')) {
          new Chart(document.getElementById('classChart'), {
            type: 'bar',
            data: {
              labels: cd.map(x=>x.cname+' '+x.csec),
              datasets: [{ label: 'Avg %',
                data: cd.map(x=>Math.round(x.avg_pct*10)/10),
                backgroundColor: '#10b981', borderRadius: 6 }]
            },
            options: { plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, max: 100 } } }
          });
        }
      });
      </script>

    <?php else: ?>
      <?php /* ============ 👨‍🎓 STUDENT DASHBOARD ============ */
        $myStudent = null;
        $st = $pdo->prepare("SELECT s.*, c.name cname, c.section csec
          FROM students s LEFT JOIN classes c ON c.id=s.class_id
          WHERE s.user_id = ? OR s.email = ? LIMIT 1");
        $st->execute([$user['id'], $user['username']]);
        $myStudent = $st->fetch();
        if(!$myStudent) {
          $st = $pdo->prepare("SELECT s.*, c.name cname, c.section csec
            FROM students s LEFT JOIN classes c ON c.id=s.class_id
            WHERE CONCAT(s.first_name,' ',s.last_name) = ? LIMIT 1");
          $st->execute([$user['full_name']]);
          $myStudent = $st->fetch();
        }
        if(!$myStudent) {
          $myStudent = $pdo->query("SELECT s.*, c.name cname, c.section csec
            FROM students s LEFT JOIN classes c ON c.id=s.class_id
            ORDER BY s.id LIMIT 1")->fetch();
        }
        $myHistory = $myStudent ? getStudentHistory($pdo, $myStudent['id']) : [];
        $attPct = $myStudent ? $pdo->query("SELECT SUM(status='present')*100/COUNT(*) p
          FROM attendance WHERE student_id={$myStudent['id']}")->fetchColumn() : 0;
        $feesDue = $myStudent ? ($pdo->query("SELECT SUM(amount) FROM fees
          WHERE student_id={$myStudent['id']} AND status!='paid'")->fetchColumn() ?: 0) : 0;
        $recentResults = array_slice($myHistory, 0, 5);
      ?>

      <div class="page-head">
        <div style="display:flex;gap:16px;align-items:center">
          <?php if($myStudent && $myStudent['photo']): ?>
            <img src="<?= UPLOAD_URL.h($myStudent['photo']) ?>" class="photo-lg">
          <?php else: ?>
            <div class="avatar-lg"><?= $myStudent ? strtoupper(substr($myStudent['first_name'],0,1)) : '👤' ?></div>
          <?php endif; ?>
          <div>
            <h1>👋 Hello, <?= h($myStudent['first_name'] ?? $user['full_name']) ?>!</h1>
            <p>Student Dashboard · <?= h(($myStudent['cname'] ?? '').' '.($myStudent['csec'] ?? '')) ?></p>
          </div>
        </div>
        <?php if($myStudent): ?>
        <a href="?p=student&id=<?= $myStudent['id'] ?>" class="btn btn-secondary">👤 My Full Profile</a>
        <?php endif; ?>
      </div>

      <?php if(!$myStudent): ?>
        <div class="card">
          <div class="empty" style="text-align:center;padding:60px 20px;color:#64748b">
            ⚠️ Your account is not linked to any student record. Please contact your school administrator.
          </div>
        </div>
      <?php else: ?>

      <div class="stat-grid">
        <div class="stat">
          <div class="label">Exams Taken</div>
          <div class="value"><?= count($myHistory) ?></div>
          <div class="icon">📝</div>
        </div>
        <div class="stat">
          <div class="label">My Average</div>
          <div class="value">
            <?= $myHistory ? number_format(array_sum(array_column($myHistory,'average'))/count($myHistory), 1) : '-' ?>
          </div>
          <div class="icon">📊</div>
        </div>
        <div class="stat">
          <div class="label">Attendance</div>
          <div class="value"><?= number_format($attPct ?: 0, 0) ?>%</div>
          <div class="icon">📅</div>
        </div>
        <div class="stat">
          <div class="label">Best Rank</div>
          <div class="value">#<?= $myHistory ? min(array_column($myHistory,'rank_in_class')) : '-' ?></div>
          <div class="icon">🏆</div>
        </div>
      </div>

      <?php if($feesDue > 0): ?>
        <div class="flash flash-error">
          ⚠️ You have <b><?= money($feesDue) ?></b> in pending fees. Please contact the office.
        </div>
      <?php endif; ?>

      <div class="grid-2">
        <div class="card">
          <h3>📈 My Performance</h3>
          <?php if(!$myHistory): ?>
            <div class="empty">No exam results yet</div>
          <?php else: ?>
            <canvas id="myChart" height="130"></canvas>
          <?php endif; ?>
        </div>

        <div class="card">
          <h3>⚡ Quick Links</h3>
          <div class="quick-grid">
            <a href="?p=student&id=<?= $myStudent['id'] ?>" class="qa">👤<span>Profile</span></a>
            <a href="?p=results" class="qa">📊<span>Results</span></a>
            <a href="?p=leaderboard" class="qa">🏆<span>Ranking</span></a>
            <a href="?p=timetable&class=<?= $myStudent['class_id'] ?>" class="qa">🕐<span>Timetable</span></a>
          </div>
        </div>
      </div>

      <div class="card">
        <h3>📜 My Recent Exam Results</h3>
        <?php if(!$recentResults): ?>
          <div class="empty">No results yet</div>
        <?php else: ?>
          <table>
            <thead>
              <tr>
                <th>Exam</th>
                <th>Date</th>
                <th class="num">Average</th>
                <th>Grade</th>
                <th>Rank</th>
                <th>Status</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
            <?php foreach($recentResults as $r): ?>
              <tr>
                <td><b><?= h($r['ename']) ?></b></td>
                <td><?= h($r['exam_date']) ?></td>
                <td class="num"><b><?= number_format($r['average'],2) ?></b></td>
                <td><span class="grade-badge grade-<?= h($r['overall_grade']) ?>"><?= h($r['overall_grade']) ?></span></td>
                <td><b>#<?= $r['rank_in_class'] ?></b></td>
                <td><?= $r['is_pass'] ? '✅ Pass' : '❌ Fail' ?></td>
                <td><a href="?p=report&id=<?= $myStudent['id'] ?>&exam=<?= $r['exam_id'] ?>" class="btn btn-sm btn-secondary">📄 Report</a></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>

      <script>
      window.addEventListener('DOMContentLoaded',()=>{
        const mh = <?= json_encode(array_reverse($recentResults)) ?>;
        if (mh.length && document.getElementById('myChart')) {
          new Chart(document.getElementById('myChart'), {
            type: 'line',
            data: {
              labels: mh.map(x=>x.ename),
              datasets: [{
                label: 'My Average',
                data: mh.map(x=>Math.round(x.average*100)/100),
                borderColor: '#6366f1',
                backgroundColor: 'rgba(99,102,241,.12)',
                fill: true, tension: .4, borderWidth: 3, pointRadius: 6,
                pointBackgroundColor: '#6366f1', pointBorderColor: '#fff', pointBorderWidth: 2
              }]
            },
            options: { plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, max: 100 } } }
          });
        }
      });
      </script>

      <?php endif; ?>

    <?php endif; /* end role checks */ ?>

<?php /* ============================================================
   STUDENTS
============================================================ */ ?>
    <?php elseif($page==='students'): 
      $students=$pdo->query("SELECT s.*,c.name cname,c.section csec FROM students s LEFT JOIN classes c ON c.id=s.class_id ORDER BY s.admission_no")->fetchAll();
    ?>
      <div class="page-head">
        <div><h1>👨‍🎓 Students</h1><p><?= count($students) ?> students</p></div>
        <div style="display:flex;gap:10px">
          <input id="student-search" placeholder="🔍 Search..." style="width:240px">
          <a href="?p=students_import" class="btn btn-secondary">📥 Import CSV</a>
        </div>
      </div>
      <div class="card">
        <h3>➕ Add Student</h3>
        <form method="post" class="row-form" enctype="multipart/form-data">
          <input type="hidden" name="csrf" value="<?= csrf() ?>">
          <input name="adm" placeholder="Admission No" required>
          <input name="fn" placeholder="First Name" required>
          <input name="ln" placeholder="Last Name" required>
          <input name="email" type="email" placeholder="Email">
          <input name="dob" type="date">
          <select name="gender"><option value="M">Male</option><option value="F">Female</option></select>
          <select name="class"><option value="">-- Class --</option>
            <?php foreach($pdo->query("SELECT id,name,section FROM classes") as $c): ?>
              <option value="<?= $c['id'] ?>"><?= h($c['name'].' '.$c['section']) ?></option>
            <?php endforeach; ?>
          </select>
          <input name="guardian" placeholder="Guardian">
          <input name="phone" placeholder="Phone">
          <input type="file" name="photo" accept="image/*">
          <button>Add</button>
        </form>
      </div>
      <div class="card">
        <table>
          <thead><tr><th>Photo</th><th>Adm No</th><th>Name</th><th>Class</th><th>Guardian</th><th>Actions</th></tr></thead>
          <tbody id="students-tbody">
          <?php if(!$students): ?>
            <tr><td colspan="6" style="text-align:center;color:#94a3b8;padding:30px">No students</td></tr>
          <?php else: foreach($students as $s): ?>
            <tr>
              <td><?php if($s['photo']): ?><img src="<?= UPLOAD_URL.h($s['photo']) ?>" class="photo-thumb">
                  <?php else: ?><div class="avatar-sm"><?= strtoupper(substr($s['first_name'],0,1)) ?></div><?php endif; ?></td>
              <td><b><?= h($s['admission_no']) ?></b></td>
              <td><?= h($s['first_name'].' '.$s['last_name']) ?></td>
              <td><?= h($s['cname'].' '.$s['csec']) ?></td>
              <td><?= h($s['guardian_name']) ?></td>
              <td>
                <a href="?p=student&id=<?= $s['id'] ?>" class="btn btn-sm btn-secondary">👁</a>
                <a href="?p=student_edit&id=<?= $s['id'] ?>" class="btn btn-sm btn-secondary">✏️</a>
                <a class="del btn btn-sm btn-danger" href="?p=student_delete&id=<?= $s['id'] ?>">🗑</a>
              </td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>

<?php /* ============================================================
   STUDENTS IMPORT
============================================================ */ ?>
    <?php elseif($page==='students_import'): ?>
      <div class="page-head"><div><h1>📥 Import Students from CSV</h1><p>Upload CSV file</p></div></div>
      <div class="card">
        <h3>CSV Format</h3>
        <pre style="background:#f1f5f9;padding:16px;border-radius:8px;overflow-x:auto">admission_no,first_name,last_name,class_id,gender,guardian_name,guardian_phone
ST011,Amali,Hewage,5,F,Mrs. Nimal Hewage,0771111111
ST012,Ruwan,Wickrama,5,M,Mr. Sunil Wickrama,0772222222</pre>
        <form method="post" enctype="multipart/form-data" style="margin-top:16px">
          <input type="hidden" name="csrf" value="<?= csrf() ?>">
          <input type="file" name="csv" accept=".csv" required>
          <button>📥 Upload & Import</button>
          <a href="?p=students" class="btn btn-secondary">← Cancel</a>
        </form>
      </div>

<?php /* ============================================================
   STUDENT EDIT / PROFILE
============================================================ */ ?>
    <?php elseif($page==='student_edit'): 
      $id=(int)$_GET['id']; $s=$pdo->query("SELECT * FROM students WHERE id=$id")->fetch();
      if(!$s) echo "<h1>Not found</h1>"; else { ?>
      <div class="page-head"><div><h1>✏️ Edit Student</h1></div></div>
      <div class="card">
        <form method="post" class="row-form" enctype="multipart/form-data">
          <input type="hidden" name="csrf" value="<?= csrf() ?>">
          <input type="hidden" name="id" value="<?= $s['id'] ?>">
          <input name="adm" value="<?= h($s['admission_no']) ?>" required>
          <input name="fn" value="<?= h($s['first_name']) ?>" required>
          <input name="ln" value="<?= h($s['last_name']) ?>" required>
          <input name="email" type="email" value="<?= h($s['email']) ?>" placeholder="Email">
          <input name="dob" type="date" value="<?= h($s['dob']) ?>">
          <select name="gender">
            <option value="M" <?= $s['gender']==='M'?'selected':'' ?>>Male</option>
            <option value="F" <?= $s['gender']==='F'?'selected':'' ?>>Female</option>
          </select>
          <select name="class">
            <option value="">-- Class --</option>
            <?php foreach($pdo->query("SELECT id,name,section FROM classes") as $c): ?>
              <option value="<?= $c['id'] ?>" <?= $c['id']==$s['class_id']?'selected':'' ?>><?= h($c['name'].' '.$c['section']) ?></option>
            <?php endforeach; ?>
          </select>
          <input name="guardian" value="<?= h($s['guardian_name']) ?>" placeholder="Guardian">
          <input name="phone" value="<?= h($s['guardian_phone']) ?>" placeholder="Phone">
          <input type="file" name="photo" accept="image/*">
          <?php if($s['photo']): ?><img src="<?= UPLOAD_URL.h($s['photo']) ?>" class="photo-thumb"><?php endif; ?>
          <button>Save</button>
          <a href="?p=student&id=<?= $s['id'] ?>" class="btn btn-secondary">Cancel</a>
        </form>
      </div>
    <?php } ?>

    <?php elseif($page==='student'): 
      $id=(int)$_GET['id'];
      $s=$pdo->query("SELECT s.*,c.name cname,c.section csec FROM students s LEFT JOIN classes c ON c.id=s.class_id WHERE s.id=$id")->fetch();
      if(!$s) echo "<h1>Not found</h1>"; else {
        $history = getStudentHistory($pdo,$id);
        $attStats = $pdo->query("SELECT
          SUM(status='present') p, SUM(status='absent') a, SUM(status='late') l, COUNT(*) t
          FROM attendance WHERE student_id=$id")->fetch();
        $attPct = $attStats['t'] ? round(($attStats['p']/$attStats['t'])*100,1) : 0;
        $feesTotal = $pdo->query("SELECT SUM(amount) FROM fees WHERE student_id=$id")->fetchColumn() ?: 0;
        $feesPaid  = $pdo->query("SELECT SUM(amount) FROM fees WHERE student_id=$id AND status='paid'")->fetchColumn() ?: 0;
    ?>
      <div class="page-head">
        <div style="display:flex;gap:16px;align-items:center">
          <?php if($s['photo']): ?><img src="<?= UPLOAD_URL.h($s['photo']) ?>" class="photo-lg">
          <?php else: ?><div class="avatar-lg"><?= strtoupper(substr($s['first_name'],0,1)) ?></div><?php endif; ?>
          <div>
            <h1><?= h($s['first_name'].' '.$s['last_name']) ?></h1>
            <p><?= h($s['admission_no']) ?> · <?= h($s['cname'].' '.$s['csec']) ?></p>
          </div>
        </div>
        <div>
          <a href="?p=student_edit&id=<?= $id ?>" class="btn btn-secondary">✏️ Edit</a>
          <a href="?p=students" class="btn btn-secondary">← Back</a>
        </div>
      </div>
      <div class="stat-grid">
        <div class="stat"><div class="label">Exams</div><div class="value"><?= count($history) ?></div></div>
        <div class="stat"><div class="label">Attendance</div><div class="value"><?= $attPct ?>%</div></div>
        <div class="stat"><div class="label">Fees Paid</div><div class="value" style="font-size:20px"><?= money($feesPaid) ?></div></div>
        <div class="stat"><div class="label">Fees Due</div><div class="value" style="font-size:20px;color:#ef4444"><?= money($feesTotal-$feesPaid) ?></div></div>
      </div>
      <div class="card">
        <h3>📜 Exam History</h3>
        <?php if(!$history): ?><p style="color:#94a3b8">No results</p>
        <?php else: ?>
        <table>
          <thead><tr><th>Exam</th><th>Date</th><th>Total</th><th>Average</th><th>GPA</th><th>Grade</th><th>Rank</th><th></th></tr></thead>
          <tbody>
          <?php foreach($history as $r): ?>
            <tr>
              <td><b><?= h($r['ename']) ?></b></td>
              <td><?= h($r['exam_date']) ?></td>
              <td class="num"><?= number_format($r['total'],2) ?></td>
              <td class="num"><b><?= number_format($r['average'],2) ?></b></td>
              <td><?= number_format($r['gpa'],2) ?></td>
              <td><span class="grade-badge grade-<?= h($r['overall_grade']) ?>"><?= h($r['overall_grade']) ?></span></td>
              <td><b>#<?= $r['rank_in_class'] ?></b></td>
              <td><a href="?p=report&id=<?= $id ?>&exam=<?= $r['exam_id'] ?>" class="btn btn-sm btn-secondary">📄</a></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <?php endif; ?>
      </div>
    <?php } ?>

<?php /* ============================================================
   CLASSES, SUBJECTS
============================================================ */ ?>
    <?php elseif($page==='classes'): 
      $classes=$pdo->query("SELECT c.*,y.name yname,(SELECT COUNT(*) FROM students WHERE class_id=c.id) scount FROM classes c LEFT JOIN academic_years y ON y.id=c.academic_year_id ORDER BY c.name,c.section")->fetchAll();
    ?>
      <div class="page-head"><div><h1>📚 Classes</h1></div></div>
      <div class="card">
        <h3>➕ Add Class</h3>
        <form method="post" class="row-form">
          <input type="hidden" name="csrf" value="<?= csrf() ?>">
          <input name="name" placeholder="Grade" required>
          <input name="section" placeholder="Section" required>
          <select name="year"><option value="">-- Year --</option>
            <?php foreach($pdo->query("SELECT id,name FROM academic_years") as $y): ?>
              <option value="<?= $y['id'] ?>"><?= h($y['name']) ?></option>
            <?php endforeach; ?>
          </select>
          <button>Add</button>
        </form>
      </div>
      <div class="card">
        <table>
          <thead><tr><th>Class</th><th>Section</th><th>Year</th><th>Students</th><th></th></tr></thead>
          <tbody>
          <?php foreach($classes as $c): ?>
            <tr>
              <td><b><?= h($c['name']) ?></b></td>
              <td><?= h($c['section']) ?></td>
              <td><?= h($c['yname']) ?></td>
              <td><b><?= $c['scount'] ?></b></td>
              <td><a class="del btn btn-sm btn-danger" href="?p=class_delete&id=<?= $c['id'] ?>">🗑</a></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>

    <?php elseif($page==='subjects'): 
      $subs=$pdo->query("SELECT * FROM subjects ORDER BY code")->fetchAll();
    ?>
      <div class="page-head"><div><h1>📖 Subjects</h1></div></div>
      <div class="card">
        <form method="post" class="row-form">
          <input type="hidden" name="csrf" value="<?= csrf() ?>">
          <input name="code" placeholder="Code" required>
          <input name="name" placeholder="Name" required>
          <select name="core"><option value="1">Core</option><option value="0">Optional</option></select>
          <button>Add</button>
        </form>
      </div>
      <div class="card">
        <table>
          <thead><tr><th>Code</th><th>Name</th><th>Type</th><th></th></tr></thead>
          <tbody>
          <?php foreach($subs as $s): ?>
            <tr>
              <td><b><?= h($s['code']) ?></b></td>
              <td><?= h($s['name']) ?></td>
              <td><?= $s['is_core']?'<span class="badge badge-published">Core</span>':'<span class="badge badge-draft">Optional</span>' ?></td>
              <td><a class="del btn btn-sm btn-danger" href="?p=subject_delete&id=<?= $s['id'] ?>">🗑</a></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>

<?php /* ============================================================
   EXAMS
============================================================ */ ?>
    <?php elseif($page==='exams'): 
      $exams=$pdo->query("SELECT e.*,c.name cname,c.section csec,(SELECT COUNT(*) FROM exam_subjects WHERE exam_id=e.id) subj_count FROM exams e LEFT JOIN classes c ON c.id=e.class_id ORDER BY e.id DESC")->fetchAll();
    ?>
      <div class="page-head"><div><h1>📝 Exams</h1></div></div>
      <div class="card">
        <h3>➕ Create Exam</h3>
        <form method="post" action="?p=exams_save" class="row-form">
          <input type="hidden" name="csrf" value="<?= csrf() ?>">
          <input name="name" placeholder="Exam Name" required>
          <input name="date" type="date" required value="<?= date('Y-m-d') ?>">
          <select name="class" required><option value="">-- Class --</option>
            <?php foreach($pdo->query("SELECT id,name,section FROM classes") as $c): ?>
              <option value="<?= $c['id'] ?>"><?= h($c['name'].' '.$c['section']) ?></option>
            <?php endforeach; ?>
          </select>
          <button>Create</button>
        </form>
      </div>
      <div class="card">
        <table>
          <thead><tr><th>Exam</th><th>Date</th><th>Class</th><th>Subjects</th><th>Status</th><th></th></tr></thead>
          <tbody>
          <?php foreach($exams as $e): ?>
            <tr>
              <td><b><?= h($e['name']) ?></b></td>
              <td><?= h($e['exam_date']) ?></td>
              <td><?= h($e['cname'].' '.$e['csec']) ?></td>
              <td><?= $e['subj_count'] ?></td>
              <td><span class="badge badge-<?= $e['status'] ?>"><?= h($e['status']) ?></span></td>
              <td>
                <a href="?p=exam_manage&id=<?= $e['id'] ?>" class="btn btn-sm btn-secondary">⚙</a>
                <a href="?p=results&exam=<?= $e['id'] ?>" class="btn btn-sm btn-secondary">📊</a>
                <a class="del btn btn-sm btn-danger" href="?p=exam_delete&id=<?= $e['id'] ?>">🗑</a>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>

    <?php elseif($page==='exam_manage'): 
      $eid=(int)$_GET['id'];
      $exam=$pdo->query("SELECT e.*,c.name cname,c.section csec FROM exams e LEFT JOIN classes c ON c.id=e.class_id WHERE e.id=$eid")->fetch();
      if(!$exam) echo "<h1>Not found</h1>"; else {
        $subs=$pdo->query("SELECT es.*,s.name sname,s.code scode,(SELECT COUNT(*) FROM marks WHERE exam_subject_id=es.id AND marks_obtained IS NOT NULL) mark_count FROM exam_subjects es JOIN subjects s ON s.id=es.subject_id WHERE es.exam_id=$eid")->fetchAll();
    ?>
      <div class="page-head">
        <div><h1>⚙ <?= h($exam['name']) ?></h1><p><?= h($exam['cname'].' '.$exam['csec']) ?> · <?= h($exam['exam_date']) ?></p></div>
        <div>
          <?php if($exam['status']!=='published'): ?>
            <a href="?p=exam_publish&id=<?= $eid ?>&status=published" class="btn">✅ Publish & Notify Parents</a>
          <?php else: ?>
            <a href="?p=exam_publish&id=<?= $eid ?>&status=ongoing" class="btn btn-secondary">↩ Unpublish</a>
          <?php endif; ?>
          <a href="?p=exams" class="btn btn-secondary">←</a>
        </div>
      </div>
      <div class="card">
        <form method="post" class="row-form">
          <input type="hidden" name="csrf" value="<?= csrf() ?>">
          <input type="hidden" name="exam_id" value="<?= $eid ?>">
          <select name="subject" required><option value="">-- Subject --</option>
            <?php foreach($pdo->query("SELECT id,code,name FROM subjects") as $s): ?>
              <option value="<?= $s['id'] ?>"><?= h($s['code'].' - '.$s['name']) ?></option>
            <?php endforeach; ?>
          </select>
          <input name="max" type="number" value="100" placeholder="Max">
          <input name="pass" type="number" value="35" placeholder="Pass">
          <button>Add</button>
        </form>
      </div>
      <div class="card">
        <table>
          <thead><tr><th>Code</th><th>Subject</th><th>Max</th><th>Pass</th><th>Marks</th><th></th></tr></thead>
          <tbody>
          <?php foreach($subs as $es): ?>
            <tr>
              <td><b><?= h($es['scode']) ?></b></td>
              <td><?= h($es['sname']) ?></td>
              <td><?= $es['max_marks'] ?></td>
              <td><?= $es['pass_marks'] ?></td>
              <td><?= $es['mark_count'] ?></td>
              <td><a href="?p=marks&es=<?= $es['id'] ?>" class="btn btn-sm">✍</a>
                  <a class="del btn btn-sm btn-danger" href="?p=es_delete&id=<?= $es['id'] ?>&exam=<?= $eid ?>">🗑</a></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php } ?>

<?php /* ============================================================
   MARKS ENTRY
============================================================ */ ?>
    <?php elseif($page==='marks'): 
      $es_id=(int)($_GET['es']??0);
      if(!$es_id): 
        $list=$pdo->query("SELECT es.id,s.name sname,s.code scode,e.name ename,e.status,c.name cname,c.section csec FROM exam_subjects es JOIN subjects s ON s.id=es.subject_id JOIN exams e ON e.id=es.exam_id LEFT JOIN classes c ON c.id=e.class_id ORDER BY e.id DESC,s.name")->fetchAll();
    ?>
      <div class="page-head"><div><h1>✍️ Marks Entry</h1></div></div>
      <div class="card">
        <table>
          <thead><tr><th>Exam</th><th>Class</th><th>Subject</th><th>Status</th><th></th></tr></thead>
          <tbody>
          <?php foreach($list as $r): ?>
            <tr>
              <td><b><?= h($r['ename']) ?></b></td>
              <td><?= h($r['cname'].' '.$r['csec']) ?></td>
              <td><?= h($r['scode'].' - '.$r['sname']) ?></td>
              <td><span class="badge badge-<?= $r['status'] ?>"><?= h($r['status']) ?></span></td>
              <td><a href="?p=marks&es=<?= $r['id'] ?>" class="btn btn-sm">Enter →</a></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php else: 
        $es=$pdo->query("SELECT es.*,s.name sname,s.code scode,e.class_id,e.name ename,e.id exam_id,c.name cname,c.section csec FROM exam_subjects es JOIN subjects s ON s.id=es.subject_id JOIN exams e ON e.id=es.exam_id LEFT JOIN classes c ON c.id=e.class_id WHERE es.id=$es_id")->fetch();
        if(!$es) echo "<h1>Not found</h1>"; else {
          $students=$pdo->query("SELECT * FROM students WHERE class_id=".(int)$es['class_id']." ORDER BY admission_no")->fetchAll();
          $marks=[]; foreach($pdo->query("SELECT student_id,marks_obtained FROM marks WHERE exam_subject_id=$es_id") as $m) $marks[$m['student_id']]=$m['marks_obtained'];
          $scales=getScales($pdo);
      ?>
        <div class="page-head">
          <div><h1>✍ <?= h($es['sname']) ?></h1><p><?= h($es['ename']) ?> · <?= h($es['cname'].' '.$es['csec']) ?> · Max <?= $es['max_marks'] ?></p></div>
          <a href="?p=exam_manage&id=<?= $es['exam_id'] ?>" class="btn btn-secondary">←</a>
        </div>
        <form method="post">
          <input type="hidden" name="csrf" value="<?= csrf() ?>">
          <input type="hidden" name="es_id" value="<?= $es_id ?>">
          <div class="card">
            <table>
              <thead><tr><th>#</th><th>Adm</th><th>Name</th><th>Marks</th><th>Grade</th></tr></thead>
              <tbody>
              <?php $i=0; foreach($students as $s): $i++;
                $m=$marks[$s['id']]??'';
                $g=($m!==''&&$m!==null)?calcGrade($m,$es['max_marks'],$scales):['grade'=>'-'];
              ?>
                <tr>
                  <td><?= $i ?></td>
                  <td><b><?= h($s['admission_no']) ?></b></td>
                  <td><?= h($s['first_name'].' '.$s['last_name']) ?></td>
                  <td><input type="number" step="0.01" min="0" max="<?= $es['max_marks'] ?>" name="marks[<?= $s['id'] ?>]" value="<?= h($m) ?>" class="mark-input" data-max="<?= $es['max_marks'] ?>" style="width:110px"></td>
                  <td class="grade-cell grade-<?= $g['grade'] ?>"><b><?= $g['grade'] ?></b></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <button style="padding:14px 40px">💾 Save & Calculate</button>
        </form>
      <?php } endif; ?>

<?php /* ============================================================
   RESULTS
============================================================ */ ?>
    <?php elseif($page==='results'): 
      $exam_id=(int)($_GET['exam']??0);
      $exams=$pdo->query("SELECT e.*,c.name cname,c.section csec FROM exams e LEFT JOIN classes c ON c.id=e.class_id ORDER BY e.id DESC")->fetchAll();
    ?>
      <div class="page-head"><div><h1>📈 Results</h1></div></div>
      <div class="card">
        <form method="get" class="row-form">
          <input type="hidden" name="p" value="results">
          <select name="exam" onchange="this.form.submit()" style="flex:1">
            <option value="">-- Select --</option>
            <?php foreach($exams as $e): ?>
              <option value="<?= $e['id'] ?>" <?= $e['id']==$exam_id?'selected':'' ?>><?= h($e['name'].' ('.$e['cname'].' '.$e['csec'].')') ?></option>
            <?php endforeach; ?>
          </select>
        </form>
      </div>
      <?php if($exam_id): 
        $stats=$pdo->query("SELECT AVG(average) avg,MAX(average) max,MIN(average) min,SUM(is_pass) passed,COUNT(*) total FROM results WHERE exam_id=$exam_id")->fetch();
        if($stats&&$stats['total']>0): ?>
          <div class="stat-grid">
            <div class="stat"><div class="label">Avg</div><div class="value"><?= number_format($stats['avg'],1) ?></div></div>
            <div class="stat"><div class="label">High</div><div class="value"><?= number_format($stats['max'],1) ?></div></div>
            <div class="stat"><div class="label">Low</div><div class="value"><?= number_format($stats['min'],1) ?></div></div>
            <div class="stat"><div class="label">Passed</div><div class="value"><?= $stats['passed'] ?>/<?= $stats['total'] ?></div></div>
          </div>
        <?php endif; ?>
        <div class="card">
          <table>
            <thead><tr><th>Rank</th><th>Adm</th><th>Name</th><th class="num">Total</th><th class="num">Avg</th><th>GPA</th><th>Grade</th><th></th></tr></thead>
            <tbody>
            <?php foreach($pdo->query("SELECT r.*,s.admission_no,s.first_name,s.last_name,s.id sid FROM results r JOIN students s ON s.id=r.student_id WHERE r.exam_id=$exam_id ORDER BY r.rank_in_class") as $r): ?>
              <tr>
                <td><b>#<?= $r['rank_in_class'] ?></b></td>
                <td><?= h($r['admission_no']) ?></td>
                <td><?= h($r['first_name'].' '.$r['last_name']) ?></td>
                <td class="num"><?= number_format($r['total'],2) ?></td>
                <td class="num"><b><?= number_format($r['average'],2) ?></b></td>
                <td><?= number_format($r['gpa'],2) ?></td>
                <td><span class="grade-badge grade-<?= h($r['overall_grade']) ?>"><?= h($r['overall_grade']) ?></span></td>
                <td><a href="?p=report&id=<?= $r['sid'] ?>&exam=<?= $exam_id ?>" class="btn btn-sm btn-secondary">📄</a></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>

<?php /* ============================================================
   LEADERBOARD
============================================================ */ ?>
    <?php elseif($page==='leaderboard'): 
      $top=$pdo->query("SELECT s.id,s.first_name,s.last_name,s.admission_no,c.name cname,c.section csec,AVG(r.average) avg_avg,COUNT(r.id) exams,MAX(r.rank_in_class) best_rank FROM students s LEFT JOIN results r ON r.student_id=s.id LEFT JOIN classes c ON c.id=s.class_id GROUP BY s.id HAVING exams>0 ORDER BY avg_avg DESC LIMIT 20")->fetchAll();
    ?>
      <div class="page-head"><div><h1>🏆 Leaderboard</h1></div></div>
      <div class="card">
        <table>
          <thead><tr><th>Rank</th><th>Student</th><th>Class</th><th class="num">Exams</th><th class="num">Avg</th><th></th></tr></thead>
          <tbody>
          <?php foreach($top as $i=>$t): ?>
            <tr>
              <td><?php if($i<3): ?><span style="font-size:20px"><?= ['🥇','🥈','🥉'][$i] ?></span><?php else: ?><b>#<?= $i+1 ?></b><?php endif; ?></td>
              <td><div class="user-cell"><div class="avatar-sm"><?= strtoupper(substr($t['first_name'],0,1)) ?></div><div><b><?= h($t['first_name'].' '.$t['last_name']) ?></b><div style="font-size:12px;color:#94a3b8"><?= h($t['admission_no']) ?></div></div></div></td>
              <td><?= h($t['cname'].' '.$t['csec']) ?></td>
              <td class="num"><?= $t['exams'] ?></td>
              <td class="num"><b><?= number_format($t['avg_avg'],2) ?></b></td>
              <td><a href="?p=student&id=<?= $t['id'] ?>" class="btn btn-sm btn-secondary">View →</a></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>

<?php /* ============================================================
   ATTENDANCE
============================================================ */ ?>
    <?php elseif($page==='attendance'): 
      $class_id=(int)($_GET['class']??0);
      $date=$_GET['date']??date('Y-m-d');
      $classes=$pdo->query("SELECT id,name,section FROM classes ORDER BY name,section")->fetchAll();
    ?>
      <div class="page-head"><div><h1>📅 Attendance</h1></div></div>
      <div class="card">
        <form method="get" class="row-form">
          <input type="hidden" name="p" value="attendance">
          <select name="class" onchange="this.form.submit()">
            <option value="">-- Select Class --</option>
            <?php foreach($classes as $c): ?>
              <option value="<?= $c['id'] ?>" <?= $c['id']==$class_id?'selected':'' ?>><?= h($c['name'].' '.$c['section']) ?></option>
            <?php endforeach; ?>
          </select>
          <input type="date" name="date" value="<?= h($date) ?>" onchange="this.form.submit()">
        </form>
      </div>
      <?php if($class_id):
        $students=$pdo->query("SELECT * FROM students WHERE class_id=$class_id ORDER BY admission_no")->fetchAll();
        $existing=[];
        foreach($pdo->query("SELECT student_id,status FROM attendance WHERE class_id=$class_id AND att_date='$date'") as $a) $existing[$a['student_id']]=$a['status'];
      ?>
        <form method="post">
          <input type="hidden" name="csrf" value="<?= csrf() ?>">
          <input type="hidden" name="class_id" value="<?= $class_id ?>">
          <input type="hidden" name="att_date" value="<?= h($date) ?>">
          <div class="card">
            <h3>Mark Attendance — <?= h($date) ?></h3>
            <div style="display:flex;gap:10px;margin-bottom:14px">
              <button type="button" onclick="markAll('present')" class="btn btn-secondary">✅ All Present</button>
              <button type="button" onclick="markAll('absent')" class="btn btn-secondary">❌ All Absent</button>
            </div>
            <table>
              <thead><tr><th>Adm</th><th>Name</th><th>Status</th></tr></thead>
              <tbody>
              <?php foreach($students as $s): $cur=$existing[$s['id']]??'present'; ?>
                <tr>
                  <td><b><?= h($s['admission_no']) ?></b></td>
                  <td><?= h($s['first_name'].' '.$s['last_name']) ?></td>
                  <td>
                    <?php foreach(['present'=>'✅','absent'=>'❌','late'=>'⏰','excused'=>'📝'] as $k=>$ic): ?>
                      <label style="margin-right:14px;cursor:pointer">
                        <input type="radio" name="status[<?= $s['id'] ?>]" value="<?= $k ?>" class="att-radio" <?= $cur===$k?'checked':'' ?>>
                        <?= $ic ?> <?= ucfirst($k) ?>
                      </label>
                    <?php endforeach; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <button style="padding:14px 40px">💾 Save Attendance</button>
        </form>
      <?php endif; ?>

<?php /* ============================================================
   TIMETABLE
============================================================ */ ?>
    <?php elseif($page==='timetable'): 
      $class_id=(int)($_GET['class']??0);
      $classes=$pdo->query("SELECT id,name,section FROM classes ORDER BY name,section")->fetchAll();
      $days=['Mon','Tue','Wed','Thu','Fri','Sat','Sun'];
    ?>
      <div class="page-head"><div><h1>🕐 Timetable</h1></div></div>
      <div class="card">
        <form method="get" class="row-form">
          <input type="hidden" name="p" value="timetable">
          <select name="class" onchange="this.form.submit()">
            <option value="">-- Select Class --</option>
            <?php foreach($classes as $c): ?>
              <option value="<?= $c['id'] ?>" <?= $c['id']==$class_id?'selected':'' ?>><?= h($c['name'].' '.$c['section']) ?></option>
            <?php endforeach; ?>
          </select>
        </form>
      </div>
      <?php if($class_id && $user['role'] !== 'student'): ?>
        <div class="card">
          <h3>➕ Add Slot</h3>
          <form method="post" class="row-form">
            <input type="hidden" name="csrf" value="<?= csrf() ?>">
            <input type="hidden" name="class_id" value="<?= $class_id ?>">
            <select name="day" required>
              <?php foreach($days as $i=>$d): ?><option value="<?= $i+1 ?>"><?= $d ?></option><?php endforeach; ?>
            </select>
            <select name="subject_id" required><option value="">-- Subject --</option>
              <?php foreach($pdo->query("SELECT id,code,name FROM subjects") as $s): ?>
                <option value="<?= $s['id'] ?>"><?= h($s['code'].' - '.$s['name']) ?></option>
              <?php endforeach; ?>
            </select>
            <input name="start" type="time" required>
            <input name="end" type="time" required>
            <input name="room" placeholder="Room">
            <button>Add</button>
          </form>
        </div>
      <?php endif; ?>
      <?php if($class_id): ?>
        <div class="card">
          <h3>Weekly Schedule</h3>
          <div class="tt-grid">
            <?php foreach($days as $i=>$d): $day=$i+1; ?>
              <div class="tt-day">
                <div class="tt-day-head"><?= $d ?></div>
                <?php $slots=$pdo->query("SELECT tt.*,s.code scode,s.name sname FROM timetable tt JOIN subjects s ON s.id=tt.subject_id WHERE tt.class_id=$class_id AND tt.day_of_week=$day ORDER BY tt.start_time"); ?>
                <?php foreach($slots as $sl): ?>
                  <div class="tt-slot">
                    <div class="tt-time"><?= substr($sl['start_time'],0,5) ?>-<?= substr($sl['end_time'],0,5) ?></div>
                    <div class="tt-subj"><b><?= h($sl['scode']) ?></b> <?= h($sl['sname']) ?></div>
                    <?php if($sl['room']): ?><div class="tt-room">📍 <?= h($sl['room']) ?></div><?php endif; ?>
                    <?php if($user['role'] !== 'student'): ?>
                      <a class="del tt-del" href="?p=tt_delete&id=<?= $sl['id'] ?>&class=<?= $class_id ?>">×</a>
                    <?php endif; ?>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endif; ?>

<?php /* ============================================================
   FEES
============================================================ */ ?>
    <?php elseif($page==='fees'): 
      $fees=$pdo->query("SELECT f.*,s.first_name,s.last_name,s.admission_no FROM fees f JOIN students s ON s.id=f.student_id ORDER BY f.id DESC")->fetchAll();
      $total=$pdo->query("SELECT SUM(amount) FROM fees")->fetchColumn()?:0;
      $paid=$pdo->query("SELECT SUM(amount) FROM fees WHERE status='paid'")->fetchColumn()?:0;
    ?>
      <div class="page-head"><div><h1>💰 Fees</h1></div></div>
      <div class="stat-grid">
        <div class="stat"><div class="label">Total</div><div class="value" style="font-size:22px"><?= money($total) ?></div></div>
        <div class="stat"><div class="label">Collected</div><div class="value" style="font-size:22px;color:#10b981"><?= money($paid) ?></div></div>
        <div class="stat"><div class="label">Pending</div><div class="value" style="font-size:22px;color:#ef4444"><?= money($total-$paid) ?></div></div>
      </div>
      <div class="card">
        <h3>➕ Add Fee Record</h3>
        <form method="post" class="row-form">
          <input type="hidden" name="csrf" value="<?= csrf() ?>">
          <select name="student_id" required><option value="">-- Student --</option>
            <?php foreach($pdo->query("SELECT id,admission_no,first_name,last_name FROM students ORDER BY admission_no") as $s): ?>
              <option value="<?= $s['id'] ?>"><?= h($s['admission_no'].' - '.$s['first_name'].' '.$s['last_name']) ?></option>
            <?php endforeach; ?>
          </select>
          <input name="amount" type="number" step="0.01" placeholder="Amount" required>
          <input name="description" placeholder="Description">
          <input name="due_date" type="date">
          <input name="paid_date" type="date">
          <select name="status"><option value="pending">Pending</option><option value="paid">Paid</option></select>
          <button>Add</button>
        </form>
      </div>
      <div class="card">
        <table>
          <thead><tr><th>Receipt</th><th>Student</th><th>Description</th><th class="num">Amount</th><th>Status</th><th>Due</th><th></th></tr></thead>
          <tbody>
          <?php foreach($fees as $f): ?>
            <tr>
              <td><?= h($f['receipt_no'] ?: '—') ?></td>
              <td><b><?= h($f['first_name'].' '.$f['last_name']) ?></b><div style="font-size:12px;color:#94a3b8"><?= h($f['admission_no']) ?></div></td>
              <td><?= h($f['description']) ?></td>
              <td class="num"><b><?= money($f['amount']) ?></b></td>
              <td><span class="badge badge-<?= $f['status']==='paid'?'published':($f['status']==='overdue'?'draft':'ongoing') ?>"><?= h($f['status']) ?></span></td>
              <td><?= h($f['due_date']) ?></td>
              <td>
                <?php if($f['status']!=='paid'): ?><a href="?p=fee_mark_paid&id=<?= $f['id'] ?>" class="btn btn-sm">💰 Paid</a><?php endif; ?>
                <a class="del btn btn-sm btn-danger" href="?p=fee_delete&id=<?= $f['id'] ?>">🗑</a>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>

<?php /* ============================================================
   ANALYTICS
============================================================ */ ?>
    <?php elseif($page==='analytics'): 
      $subjStats=$pdo->query("SELECT s.code scode,s.name sname, AVG(m.marks_obtained/es.max_marks*100) avg_pct,
        SUM(m.marks_obtained>=es.pass_marks) passed, COUNT(m.id) total
        FROM subjects s JOIN exam_subjects es ON es.subject_id=s.id
        LEFT JOIN marks m ON m.exam_subject_id=es.id
        WHERE m.marks_obtained IS NOT NULL GROUP BY s.id")->fetchAll();
      $classPerf=$pdo->query("SELECT c.name cname,c.section csec, AVG(r.average) avg_pct
        FROM classes c LEFT JOIN exams e ON e.class_id=c.id LEFT JOIN results r ON r.exam_id=e.id
        GROUP BY c.id HAVING avg_pct IS NOT NULL ORDER BY avg_pct DESC")->fetchAll();
      $weak=$pdo->query("SELECT s.id,s.first_name,s.last_name,s.admission_no,AVG(r.average) avg_avg,COUNT(*) c
        FROM students s JOIN results r ON r.student_id=s.id
        GROUP BY s.id HAVING avg_avg < 50 AND c >= 2 ORDER BY avg_avg")->fetchAll();
    ?>
      <div class="page-head"><div><h1>📉 Analytics</h1></div></div>
      <div class="grid-2">
        <div class="card">
          <h3>📚 Subject-wise Performance</h3>
          <canvas id="subjChart" height="150"></canvas>
        </div>
        <div class="card">
          <h3>🏫 Class-wise Average</h3>
          <canvas id="classChart" height="150"></canvas>
        </div>
      </div>
      <div class="card">
        <h3>⚠️ Weak Students (avg &lt; 50%, 2+ exams)</h3>
        <?php if(!$weak): ?><p style="color:#94a3b8">None</p>
        <?php else: ?>
        <table>
          <thead><tr><th>Adm</th><th>Name</th><th>Exams</th><th>Average</th><th></th></tr></thead>
          <tbody>
          <?php foreach($weak as $w): ?>
            <tr>
              <td><b><?= h($w['admission_no']) ?></b></td>
              <td><?= h($w['first_name'].' '.$w['last_name']) ?></td>
              <td><?= $w['c'] ?></td>
              <td class="num" style="color:#ef4444"><b><?= number_format($w['avg_avg'],2) ?></b></td>
              <td><a href="?p=student&id=<?= $w['id'] ?>" class="btn btn-sm btn-secondary">View →</a></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <?php endif; ?>
      </div>
      <script>
      window.addEventListener('DOMContentLoaded',()=>{
        const sd=<?= json_encode($subjStats) ?>;
        if (sd.length) {
          new Chart(document.getElementById('subjChart'),{type:'bar',
            data:{labels:sd.map(x=>x.scode),datasets:[{label:'Avg %',
              data:sd.map(x=>Math.round((x.avg_pct||0)*10)/10),backgroundColor:'#6366f1',borderRadius:6}]},
            options:{plugins:{legend:{display:false}},scales:{y:{beginAtZero:true,max:100}}}});
        }
        const cd=<?= json_encode($classPerf) ?>;
        if (cd.length) {
          new Chart(document.getElementById('classChart'),{type:'bar',
            data:{labels:cd.map(x=>x.cname+' '+x.csec),datasets:[{label:'Avg %',
              data:cd.map(x=>Math.round((x.avg_pct||0)*10)/10),backgroundColor:'#10b981',borderRadius:6}]},
            options:{plugins:{legend:{display:false}},scales:{y:{beginAtZero:true,max:100}}}});
        }
      });
      </script>

<?php /* ============================================================
   CERTIFICATES
============================================================ */ ?>
    <?php elseif($page==='certificates'): 
      $certs=$pdo->query("SELECT c.*,s.first_name,s.last_name,s.admission_no FROM certificates c JOIN students s ON s.id=c.student_id ORDER BY c.id DESC")->fetchAll();
    ?>
      <div class="page-head"><div><h1>🎓 Certificates</h1></div></div>
      <div class="card">
        <h3>➕ Issue New Certificate</h3>
        <form method="post" action="?p=cert_create" class="row-form">
          <input type="hidden" name="csrf" value="<?= csrf() ?>">
          <select name="student_id" required><option value="">-- Student --</option>
            <?php foreach($pdo->query("SELECT id,admission_no,first_name,last_name FROM students ORDER BY admission_no") as $s): ?>
              <option value="<?= $s['id'] ?>"><?= h($s['admission_no'].' - '.$s['first_name'].' '.$s['last_name']) ?></option>
            <?php endforeach; ?>
          </select>
          <select name="type"><option value="completion">Course Completion</option><option value="merit">Merit / Achievement</option></select>
          <input name="title" placeholder="Certificate Title" value="Certificate of Achievement" required>
          <button>🎓 Issue</button>
        </form>
      </div>
      <div class="card">
        <table>
          <thead><tr><th>Ref</th><th>Student</th><th>Title</th><th>Type</th><th>Issued</th><th></th></tr></thead>
          <tbody>
          <?php foreach($certs as $c): ?>
            <tr>
              <td><b><?= h($c['ref_no']) ?></b></td>
              <td><?= h($c['first_name'].' '.$c['last_name']) ?><div style="font-size:12px;color:#94a3b8"><?= h($c['admission_no']) ?></div></td>
              <td><?= h($c['title']) ?></td>
              <td><?= h($c['cert_type']) ?></td>
              <td><?= h($c['issued_date']) ?></td>
              <td><a href="?p=certificate&id=<?= $c['id'] ?>" class="btn btn-sm">👁 View</a></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>

<?php /* ============================================================
   BACKUP
============================================================ */ ?>
    <?php elseif($page==='backup'): ?>
      <div class="page-head"><div><h1>💾 Backup & Restore</h1></div></div>
      <div class="card">
        <h3>⬇️ Download Backup</h3>
        <p style="color:#64748b;margin-bottom:14px">Download a full SQL dump of all your data.</p>
        <a href="?p=backup_download" class="btn">💾 Download Backup (SQL)</a>
      </div>
      <div class="card">
        <h3>⬆️ Restore from Backup</h3>
        <p style="color:#ef4444;margin-bottom:14px">⚠️ This will overwrite existing data!</p>
        <form method="post" action="?p=backup_restore" enctype="multipart/form-data">
          <input type="hidden" name="csrf" value="<?= csrf() ?>">
          <input type="file" name="sqlfile" accept=".sql" required>
          <button onclick="return confirm('Restore will overwrite current data. Continue?')">⬆️ Restore</button>
        </form>
      </div>

<?php /* ============================================================
   SETTINGS
============================================================ */ ?>
    <?php elseif($page==='settings'): ?>
      <div class="page-head"><div><h1>⚙️ Settings</h1></div></div>
      <div class="card">
        <h3>🎨 Appearance & Language</h3>
        <form method="post" class="row-form">
          <input type="hidden" name="csrf" value="<?= csrf() ?>">
          <select name="theme">
            <option value="light" <?= $theme==='light'?'selected':'' ?>>☀️ Light</option>
            <option value="dark"  <?= $theme==='dark'?'selected':''  ?>>🌙 Dark</option>
          </select>
          <select name="language">
            <option value="en" <?= $lang==='en'?'selected':'' ?>>🇬🇧 English</option>
            <option value="si" <?= $lang==='si'?'selected':'' ?>>🇱🇰 සිංහල</option>
            <option value="ta" <?= $lang==='ta'?'selected':'' ?>>🇱🇰 தமிழ்</option>
          </select>
          <button>Save</button>
        </form>
      </div>
      <div class="card">
        <h3>🏫 School Information</h3>
        <form method="post" class="row-form">
          <input type="hidden" name="csrf" value="<?= csrf() ?>">
          <input name="school_name" value="<?= h($schoolName) ?>" placeholder="School Name" required>
          <input name="school_address" value="<?= h($schoolAddr) ?>" placeholder="Address">
          <input name="school_phone" value="<?= h(getSetting($pdo,'school_phone')) ?>" placeholder="Phone">
          <input name="school_email" value="<?= h(getSetting($pdo,'school_email')) ?>" placeholder="Email">
          <button>Save</button>
        </form>
      </div>

<?php /* ============================================================
   PROFILE
============================================================ */ ?>
    <?php elseif($page==='profile'): ?>
      <div class="page-head"><div><h1>👤 My Profile</h1></div></div>
      <div class="card">
        <form method="post" class="row-form">
          <input type="hidden" name="csrf" value="<?= csrf() ?>">
          <input name="full_name" value="<?= h($user['full_name']) ?>" placeholder="Full Name" required>
          <input name="current" type="password" placeholder="Current Password" required>
          <input name="new" type="password" placeholder="New Password" required>
          <input name="confirm" type="password" placeholder="Confirm New Password" required>
          <button>Save Changes</button>
        </form>
        <p style="color:#94a3b8;font-size:12px;margin-top:12px">Username: <b><?= h($user['username']) ?></b> · Role: <b><?= h($user['role']) ?></b></p>
      </div>

<?php /* ============================================================
   PARENT PORTAL
============================================================ */ ?>
    <?php elseif($page==='parent_dashboard'): 
      $email = $user['username'];
      $children = $pdo->query("SELECT * FROM students WHERE email='$email' OR guardian_phone IN (SELECT username FROM users WHERE id={$user['id']})")->fetchAll();
      if(!$children) $children = $pdo->query("SELECT * FROM students LIMIT 5")->fetchAll();
    ?>
      <div class="page-head"><div><h1>👶 My Children</h1></div></div>
      <?php if(!$children): ?>
        <div class="card"><p>No children linked. Contact school admin.</p></div>
      <?php else: ?>
      <?php foreach($children as $c): 
        $results=$pdo->query("SELECT r.*,e.name ename,e.exam_date FROM results r JOIN exams e ON e.id=r.exam_id WHERE r.student_id={$c['id']} ORDER BY e.exam_date DESC LIMIT 5")->fetchAll();
        $attPct=$pdo->query("SELECT SUM(status='present')*100/COUNT(*) p FROM attendance WHERE student_id={$c['id']}")->fetchColumn();
      ?>
        <div class="card">
          <div class="user-cell" style="margin-bottom:16px">
            <div class="avatar-lg"><?= strtoupper(substr($c['first_name'],0,1)) ?></div>
            <div>
              <h3><?= h($c['first_name'].' '.$c['last_name']) ?></h3>
              <p style="color:#94a3b8"><?= h($c['admission_no']) ?> · Attendance: <b><?= round($attPct ?: 0,1) ?>%</b></p>
            </div>
          </div>
          <table>
            <thead><tr><th>Exam</th><th>Date</th><th class="num">Average</th><th>Grade</th><th>Rank</th></tr></thead>
            <tbody>
            <?php foreach($results as $r): ?>
              <tr>
                <td><b><?= h($r['ename']) ?></b></td>
                <td><?= h($r['exam_date']) ?></td>
                <td class="num"><b><?= number_format($r['average'],2) ?></b></td>
                <td><span class="grade-badge grade-<?= h($r['overall_grade']) ?>"><?= h($r['overall_grade']) ?></span></td>
                <td>#<?= $r['rank_in_class'] ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endforeach; ?>
      <?php endif; ?>

<?php /* ============================================================
   GRADE SCALES
============================================================ */ ?>
    <?php elseif($page==='grades'): 
      $scales=$pdo->query("SELECT * FROM grade_scales ORDER BY min_marks DESC")->fetchAll();
    ?>
      <div class="page-head"><div><h1>🎯 Grade Scales</h1></div></div>
      <div class="card">
        <form method="post" class="row-form">
          <input type="hidden" name="csrf" value="<?= csrf() ?>">
          <input name="name" value="Default" required>
          <input name="grade" placeholder="A+/A/B" required maxlength="2">
          <input name="min" type="number" placeholder="Min%" required>
          <input name="max" type="number" placeholder="Max%" required>
          <input name="gpa" type="number" step="0.01" placeholder="GPA" required>
          <input name="remark" placeholder="Remark">
          <button>Add</button>
        </form>
      </div>
      <div class="card">
        <table>
          <thead><tr><th>Grade</th><th>Range</th><th>GPA</th><th>Remark</th><th></th></tr></thead>
          <tbody>
          <?php foreach($scales as $s): ?>
            <tr>
              <td><span class="grade-badge grade-<?= h($s['grade']) ?>"><?= h($s['grade']) ?></span></td>
              <td><?= $s['min_marks'] ?>-<?= $s['max_marks'] ?>%</td>
              <td><b><?= number_format($s['gpa_point'],2) ?></b></td>
              <td><?= h($s['remark']) ?></td>
              <td><a class="del btn btn-sm btn-danger" href="?p=grade_delete&id=<?= $s['id'] ?>">🗑</a></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>

<?php /* ============================================================
   USERS
============================================================ */ ?>
    <?php elseif($page==='users'): 
      $users=$pdo->query("SELECT * FROM users ORDER BY id")->fetchAll();
    ?>
      <div class="page-head"><div><h1>👥 Users</h1></div></div>
      <div class="card">
        <form method="post" class="row-form">
          <input type="hidden" name="csrf" value="<?= csrf() ?>">
          <input name="full_name" placeholder="Full Name" required>
          <input name="username" placeholder="Username" required>
          <input name="password" type="password" placeholder="Password" required>
          <select name="role"><option value="admin">Admin</option><option value="teacher">Teacher</option><option value="student">Student</option><option value="parent">Parent</option></select>
          <button>Add</button>
        </form>
      </div>
      <div class="card">
        <table>
          <thead><tr><th>Name</th><th>Username</th><th>Role</th><th>Status</th><th></th></tr></thead>
          <tbody>
          <?php foreach($users as $u): ?>
            <tr>
              <td><b><?= h($u['full_name'] ?: '—') ?></b></td>
              <td><?= h($u['username']) ?></td>
              <td><span class="badge badge-published"><?= h($u['role']) ?></span></td>
              <td><?= $u['is_active']?'<span class="badge badge-published">Active</span>':'<span class="badge badge-draft">Off</span>' ?></td>
              <td><?php if($u['id']!=$user['id']): ?><a href="?p=user_toggle&id=<?= $u['id'] ?>" class="btn btn-sm btn-secondary"><?= $u['is_active']?'Disable':'Enable' ?></a><?php endif; ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>

<?php /* ============================================================
   REPORT CARD
============================================================ */ ?>
    <?php elseif($page==='report'): 
      $id=(int)$_GET['id']; $exam_id=(int)$_GET['exam'];
      $s=$pdo->query("SELECT s.*,c.name cname,c.section csec FROM students s LEFT JOIN classes c ON c.id=s.class_id WHERE s.id=$id")->fetch();
      $r=$pdo->query("SELECT * FROM results WHERE student_id=$id AND exam_id=$exam_id")->fetch();
      $exam=$pdo->query("SELECT * FROM exams WHERE id=$exam_id")->fetch();
      $details=getStudentExamDetails($pdo,$id,$exam_id);
      if(!$s || !$exam) echo "<h1>Not found</h1>"; else { ?>
      <div class="page-head no-print">
        <div><h1>📄 Report Card</h1></div>
        <div><button onclick="window.print()">🖨 Print / PDF</button>
          <a href="?p=student&id=<?= $id ?>" class="btn btn-secondary">← Back</a></div>
      </div>
      <div class="card" id="report-card">
        <div style="text-align:center;border-bottom:2px solid #6366f1;padding-bottom:20px;margin-bottom:24px">
          <h2 style="font-size:24px;font-weight:800">🎓 <?= h($schoolName) ?></h2>
          <p style="color:#64748b;font-size:13px"><?= h($schoolAddr) ?> · <?= h(getSetting($pdo,'school_phone')) ?></p>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:24px;margin-bottom:24px">
          <div><div style="font-size:12px;color:#64748b;text-transform:uppercase;font-weight:600">Student</div>
            <div style="font-size:18px;font-weight:700"><?= h($s['first_name'].' '.$s['last_name']) ?></div>
            <div style="color:#64748b;font-size:13px"><?= h($s['admission_no']) ?> · <?= h($s['cname'].' '.$s['csec']) ?></div></div>
          <div style="text-align:right"><div style="font-size:12px;color:#64748b;text-transform:uppercase;font-weight:600">Exam</div>
            <div style="font-size:18px;font-weight:700"><?= h($exam['name']) ?></div>
            <div style="color:#64748b;font-size:13px"><?= h($exam['exam_date']) ?></div></div>
        </div>
        <table>
          <thead><tr><th>Subject</th><th class="num">Marks</th><th class="num">Max</th><th class="num">%</th><th>Grade</th><th>Status</th></tr></thead>
          <tbody>
          <?php $scales=getScales($pdo); foreach($details as $d): 
            $m=$d['marks_obtained']; $g=$m!==null?calcGrade($m,$d['max_marks'],$scales):['grade'=>'-']; ?>
            <tr>
              <td><b><?= h($d['scode']) ?></b> — <?= h($d['sname']) ?></td>
              <td class="num"><?= $m!==null?number_format($m,2):'—' ?></td>
              <td class="num"><?= $d['max_marks'] ?></td>
              <td class="num"><?= $m!==null?number_format(($m/$d['max_marks'])*100,1).'%':'—' ?></td>
              <td><span class="grade-badge grade-<?= h($g['grade']) ?>"><?= h($g['grade']) ?></span></td>
              <td><?= ($m!==null&&$m>=$d['pass_marks'])?'✅ Pass':($m!==null?'❌ Fail':'—') ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <?php if($r): ?>
        <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-top:24px;padding-top:24px;border-top:2px solid #e2e8f0">
          <div style="text-align:center"><div style="font-size:12px;color:#64748b">Total</div><div style="font-size:24px;font-weight:800"><?= number_format($r['total'],2) ?></div></div>
          <div style="text-align:center"><div style="font-size:12px;color:#64748b">Average</div><div style="font-size:24px;font-weight:800;color:#6366f1"><?= number_format($r['average'],2) ?></div></div>
          <div style="text-align:center"><div style="font-size:12px;color:#64748b">Grade</div><div style="font-size:24px;font-weight:800"><span class="grade-<?= $r['overall_grade'] ?>"><?= h($r['overall_grade']) ?></span></div></div>
          <div style="text-align:center"><div style="font-size:12px;color:#64748b">Rank</div><div style="font-size:24px;font-weight:800">#<?= $r['rank_in_class'] ?></div></div>
        </div>
        <?php endif; ?>
        <div style="margin-top:40px;text-align:center;color:#94a3b8;font-size:12px">Generated <?= date('Y-m-d H:i') ?></div>
      </div>
    <?php } ?>

    <?php endif; ?>

    </div>
  </div>
</div>

<?php endif; ?>

<script src="assets/app.js"></script>
<script>
if ('serviceWorker' in navigator) navigator.serviceWorker.register('sw.js');
</script>
</body>
</html>