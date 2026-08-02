<?php
declare(strict_types=1);require_once __DIR__.'/_auth.php';require_once __DIR__.'/../vendor/autoload.php';
use EnglAI\Analytics\ExportService;use EnglAI\Mvp\ClassroomService;
require_admin();
$cid   = (int)($_GET['classroom_id']??0);
$actor = (string)($_SESSION['admin_username']??'admin');
(new ClassroomService(db()))->requireOwned($cid,$actor);
$type    = (string)($_GET['type']??'classroom_csv');
$service = new ExportService(db());
if ($type === 'student_csv') {
    $mid  = (int)($_GET['member_id']??0);
    $csv  = $service->studentCsv($cid, $mid, $actor);
    $name = "englai-student-{$mid}-".date('Ymd').'.csv';
} else {
    $csv  = $service->classroomCsv($cid, $actor);
    $name = "englai-classroom-{$cid}-".date('Ymd').'.csv';
}
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="'.$name.'"');
header('X-Content-Type-Options: nosniff');
echo $csv;
