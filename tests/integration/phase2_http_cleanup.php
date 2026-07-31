<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/config/koneksi.php';
$threshold=(int)($argv[1]??0);
if($threshold===0){echo (int)db()->query('SELECT COALESCE(MAX(id),0) FROM classroom_members')->fetchColumn();exit;}
$stmt=db()->prepare("DELETE FROM classroom_members WHERE id>? AND display_name IS NULL");$stmt->execute([$threshold]);
