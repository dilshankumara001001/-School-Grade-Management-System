<?php
// Access directly: /grade_calculator/backup.php
require 'config.php';
if(empty($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') die('Forbidden');
$sql = createBackup($pdo);
header('Content-Type: application/sql');
header('Content-Disposition: attachment; filename="gradecalc_backup_'.date('Ymd_His').'.sql"');
echo $sql;