<?php
session_start();
date_default_timezone_set('Asia/Colombo');

define('DB_HOST','localhost'); define('DB_NAME','grade_calc');
define('DB_USER','root');      define('DB_PASS','');
define('UPLOAD_DIR', __DIR__.'/uploads/');
define('UPLOAD_URL', 'uploads/');

try {
  $pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4",
    DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
     PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
} catch (Exception $e) { die("DB Error: ".$e->getMessage()); }

if (!is_dir(UPLOAD_DIR)) @mkdir(UPLOAD_DIR, 0777, true);

// ==================== HELPERS ====================
function auth() { if (empty($_SESSION['user'])) { header('Location: index.php?p=login'); exit; } return $_SESSION['user']; }
function role(...$roles) { $u=auth(); if(!in_array($u['role'],$roles)) { http_response_code(403); die('Forbidden'); } return $u; }
function h($s) { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
function csrf() { if(empty($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(16)); return $_SESSION['csrf']; }
function check_csrf() { if(($_POST['csrf']??'')!==($_SESSION['csrf']??'')) die('CSRF failed'); }
function redirect($url) { header("Location: $url"); exit; }
function flash($msg,$type='success') { $_SESSION['flash']=['msg'=>$msg,'type'=>$type]; }
function getFlash() { if(!empty($_SESSION['flash'])) { $f=$_SESSION['flash']; unset($_SESSION['flash']); return $f; } return null; }
function money($n) { return 'Rs. '.number_format($n,2); }

// ==================== SETTINGS ====================
function getSetting($pdo,$key,$default='') {
  static $cache=[];
  if(isset($cache[$key])) return $cache[$key];
  $st=$pdo->prepare("SELECT value FROM settings WHERE key_name=?"); $st->execute([$key]);
  $v=$st->fetchColumn(); $cache[$key]=$v!==false?$v:$default; return $cache[$key];
}
function setSetting($pdo,$key,$value) {
  $pdo->prepare("INSERT INTO settings (key_name,value) VALUES (?,?)
    ON DUPLICATE KEY UPDATE value=VALUES(value)")->execute([$key,$value]);
}

// ==================== TRANSLATIONS ====================
function t($key,$pdo=null) {
  static $lang=null, $dict=null;
  if($dict===null) {
    if($pdo) $lang=getSetting($pdo,'language','en');
    $dict=[
      'en'=>['dashboard'=>'Dashboard','students'=>'Students','classes'=>'Classes','subjects'=>'Subjects',
        'exams'=>'Exams','marks'=>'Marks Entry','results'=>'Results','leaderboard'=>'Leaderboard',
        'attendance'=>'Attendance','timetable'=>'Timetable','fees'=>'Fees','settings'=>'Settings',
        'logout'=>'Logout','login'=>'Sign In','welcome'=>'Welcome','save'=>'Save','cancel'=>'Cancel',
        'delete'=>'Delete','edit'=>'Edit','add'=>'Add','search'=>'Search','print'=>'Print',
        'report'=>'Report Card','parents'=>'Parent Portal','profile'=>'My Profile','analytics'=>'Analytics',
        'backup'=>'Backup','certificates'=>'Certificates','language'=>'Language','theme'=>'Theme'],
      'si'=>['dashboard'=>'පාලක පුවරුව','students'=>'සිසුන්','classes'=>'පන්ති','subjects'=>'විෂයයන්',
        'exams'=>'විභාග','marks'=>'ලකුණු','results'=>'ප්‍රතිඵල','leaderboard'=>'ප්‍රමුඛයන්',
        'attendance'=>'පැමිණීම','timetable'=>'කාලසටහන','fees'=>'ගාස්තු','settings'=>'සැකසුම්',
        'logout'=>'පිටවීම','login'=>'පිවිසෙන්න','welcome'=>'සාදරයෙන් පිළිගනිමු','save'=>'සුරකින්න',
        'cancel'=>'අවලංගු','delete'=>'මකන්න','edit'=>'සංස්කරණය','add'=>'එක් කරන්න',
        'search'=>'සොයන්න','print'=>'මුද්‍රණය','report'=>'වාර්තා පත්‍රිකාව','parents'=>'දෙමාපිය',
        'profile'=>'මගේ පැතිකඩ','analytics'=>'විශ්ලේෂණ','backup'=>'උපස්ථ','certificates'=>'සහතික',
        'language'=>'භාෂාව','theme'=>'තේමාව'],
      'ta'=>['dashboard'=>'கட்டுப்பாட்டு பலகை','students'=>'மாணவர்கள்','classes'=>'வகுப்புகள்',
        'subjects'=>'பாடங்கள்','exams'=>'தேர்வுகள்','marks'=>'மதிப்பெண்கள்','results'=>'முடிவுகள்',
        'leaderboard'=>'தரவரிசை','attendance'=>'வருகை','timetable'=>'நேர அட்டவணை','fees'=>'கட்டணம்',
        'settings'=>'அமைப்புகள்','logout'=>'வெளியேறு','login'=>'உள்நுழை','save'=>'சேமி',
        'cancel'=>'ரத்து','delete'=>'நீக்கு','edit'=>'திருத்து','add'=>'சேர்','search'=>'தேடு',
        'print'=>'அச்சிடு','report'=>'அறிக்கை','parents'=>'பெற்றோர்','profile'=>'என் சுயவிவரம்',
        'analytics'=>'பகுப்பாய்வு','backup'=>'காப்பு','certificates'=>'சான்றிதழ்',
        'language'=>'மொழி','theme'=>'தீம்']
    ];
    if($lang===null) $lang='en';
    return $dict[$lang][$key] ?? $dict['en'][$key] ?? $key;
  }
  return $dict[$lang ?? 'en'][$key] ?? $key;
}

// ==================== GRADE ENGINE ====================
function calcGrade($mark,$max,$scales) {
  $pct = $max>0 ? ($mark/$max)*100 : 0;
  foreach($scales as $s) if($pct>=$s['min_marks'] && $pct<=$s['max_marks']) return $s;
  return ['grade'=>'F','gpa_point'=>0,'remark'=>'Fail'];
}
function getScales($pdo) { return $pdo->query("SELECT * FROM grade_scales ORDER BY min_marks DESC")->fetchAll(); }

function recomputeExamResults($pdo,$exam_id) {
  $scales = getScales($pdo);
  $exam = $pdo->query("SELECT class_id FROM exams WHERE id=".(int)$exam_id)->fetch();
  if(!$exam) return;
  $students = $pdo->query("SELECT id FROM students WHERE class_id=".(int)$exam['class_id'])->fetchAll(PDO::FETCH_COLUMN);
  $rows = [];
  foreach($students as $sid) {
    $q = $pdo->prepare("SELECT m.marks_obtained,es.max_marks,es.pass_marks
      FROM exam_subjects es LEFT JOIN marks m ON m.exam_subject_id=es.id AND m.student_id=?
      WHERE es.exam_id=?");
    $q->execute([$sid,$exam_id]); $subs=$q->fetchAll();
    $total=0;$gpaSum=0;$count=0;$allPass=true;
    foreach($subs as $s) {
      if($s['marks_obtained']===null) continue;
      $total+=$s['marks_obtained'];
      $g=calcGrade($s['marks_obtained'],$s['max_marks'],$scales);
      $gpaSum+=$g['gpa_point']; $count++;
      if($s['marks_obtained']<$s['pass_marks']) $allPass=false;
    }
    $avg=$count?$total/$count:0; $gpa=$count?$gpaSum/$count:0;
    $overall=calcGrade($avg,100,$scales)['grade'];
    $rows[]=['sid'=>$sid,'total'=>$total,'avg'=>$avg,'gpa'=>$gpa,'pass'=>$allPass,'overall'=>$overall];
  }
  usort($rows, fn($a,$b)=>$b['avg']<=>$a['avg']);
  $pdo->prepare("DELETE FROM results WHERE exam_id=?")->execute([$exam_id]);
  $ins=$pdo->prepare("INSERT INTO results (exam_id,student_id,total,average,gpa,overall_grade,rank_in_class,is_pass) VALUES (?,?,?,?,?,?,?,?)");
  foreach($rows as $i=>$r) $ins->execute([$exam_id,$r['sid'],$r['total'],$r['avg'],$r['gpa'],$r['overall'],$i+1,$r['pass']?1:0]);
}
function getStudentHistory($pdo,$sid) {
  $st=$pdo->prepare("SELECT r.*,e.name ename,e.exam_date,c.name cname,c.section csec
    FROM results r JOIN exams e ON e.id=r.exam_id LEFT JOIN classes c ON c.id=e.class_id
    WHERE r.student_id=? ORDER BY e.exam_date DESC");
  $st->execute([$sid]); return $st->fetchAll();
}
function getStudentExamDetails($pdo,$sid,$exam_id) {
  $st=$pdo->prepare("SELECT es.id es_id,s.code scode,s.name sname,es.max_marks,es.pass_marks,m.marks_obtained
    FROM exam_subjects es JOIN subjects s ON s.id=es.subject_id
    LEFT JOIN marks m ON m.exam_subject_id=es.id AND m.student_id=?
    WHERE es.exam_id=? ORDER BY s.name");
  $st->execute([$sid,$exam_id]); return $st->fetchAll();
}

// ==================== FILE UPLOAD ====================
function uploadPhoto($file,$prefix='stu') {
  if(empty($file['tmp_name'])) return null;
  $ext = strtolower(pathinfo($file['name'],PATHINFO_EXTENSION));
  if(!in_array($ext,['jpg','jpeg','png','gif','webp'])) return null;
  if($file['size'] > 3*1024*1024) return null;
  $name = $prefix.'_'.time().'_'.bin2hex(random_bytes(4)).'.'.$ext;
  if(move_uploaded_file($file['tmp_name'], UPLOAD_DIR.$name)) return $name;
  return null;
}

// ==================== EMAIL (log-based, no SMTP required) ====================
function sendEmail($pdo,$to,$subject,$body) {
  try {
    $pdo->prepare("INSERT INTO email_log (to_email,subject,body,status) VALUES (?,?,?,'sent')")
      ->execute([$to,$subject,$body]);
    // Optional: real mail() call
    // @mail($to,$subject,$body,"From: ".getSetting($pdo,'school_email'));
    return true;
  } catch(Exception $e) { return false; }
}

// ==================== BACKUP ====================
function createBackup($pdo) {
  $tables = ['users','academic_years','classes','subjects','students','exams','exam_subjects','marks','grade_scales','results','attendance','timetable','fees','settings','schools','certificates'];
  $sql = "-- GradeCalc Backup ".date('Y-m-d H:i:s')."\n\nSET FOREIGN_KEY_CHECKS=0;\n\n";
  foreach($tables as $t) {
    try {
      $create = $pdo->query("SHOW CREATE TABLE `$t`")->fetch();
      $sql .= "DROP TABLE IF EXISTS `$t`;\n".$create['Create Table'].";\n\n";
      $rows = $pdo->query("SELECT * FROM `$t`")->fetchAll();
      if($rows) {
        $cols = array_keys($rows[0]);
        $sql .= "INSERT INTO `$t` (`".implode('`,`',$cols)."`) VALUES\n";
        $vals = [];
        foreach($rows as $r) {
          $esc = array_map(fn($v)=>$v===null?'NULL':$pdo->quote($v), array_values($r));
          $vals[] = '('.implode(',',$esc).')';
        }
        $sql .= implode(",\n",$vals).";\n\n";
      }
    } catch(Exception $e) {}
  }
  return $sql;
}

// ==================== LOAD THEME/LANG ====================
$THEME = getSetting($pdo,'theme','light');
$LANG  = getSetting($pdo,'language','en');