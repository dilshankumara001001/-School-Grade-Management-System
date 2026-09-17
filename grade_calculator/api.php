<?php
require 'config.php';
header('Content-Type: application/json');
if(empty($_SESSION['user'])) { echo json_encode(['error'=>'unauth']); exit; }
$action = $_GET['a'] ?? '';

switch($action) {
  case 'grade':
    echo json_encode(calcGrade((float)($_GET['m']??0),(float)($_GET['max']??100),getScales($pdo)));
    break;
  case 'search_students':
    $q='%'.($_GET['q']??'').'%';
    $st=$pdo->prepare("SELECT id,admission_no,first_name,last_name FROM students WHERE first_name LIKE ? OR last_name LIKE ? OR admission_no LIKE ? LIMIT 10");
    $st->execute([$q,$q,$q]); echo json_encode($st->fetchAll()); break;
  case 'exam_stats':
    $st=$pdo->prepare("SELECT AVG(average) avg,MAX(average) max,MIN(average) min,SUM(is_pass) passed,COUNT(*) total FROM results WHERE exam_id=?");
    $st->execute([(int)$_GET['exam']]); echo json_encode($st->fetch()); break;
  case 'attendance_pct':
    $st=$pdo->prepare("SELECT SUM(status='present')*100/COUNT(*) pct FROM attendance WHERE student_id=?");
    $st->execute([(int)$_GET['sid']]); echo json_encode($st->fetch()); break;
  default: http_response_code(404); echo json_encode(['error'=>'unknown']);
}