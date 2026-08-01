<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/koneksi.php';require_once __DIR__ . '/../vendor/autoload.php';
use EnglAI\Mvp\ClassroomService;use EnglAI\Mvp\StudentSession;
if($_SERVER['REQUEST_METHOD']!=='POST'){header('Location: /');exit;}
$code=strtoupper(trim((string)($_POST['classroom_code']??'')));
if(!preg_match('/^ENG-[A-Z0-9]{4,8}$/',$code)){header('Location: /index.php?error='.rawurlencode('Format Classroom ID tidak valid.'));exit;}
$classroom=(new ClassroomService(db()))->findActiveByCode($code);
if(!$classroom){header('Location: /index.php?error='.rawurlencode('Classroom ID tidak ditemukan atau classroom tidak aktif.'));exit;}
$confirmed=(($_POST['confirmed']??'')==='1');
if(!$confirmed){$_SESSION['pending_classroom_code']=$code;header('Location: /student/continue.php?code='.rawurlencode($code));exit;}
$token=bin2hex(random_bytes(32));$userId=(($_SESSION['user_role']??'')==='student')?(int)$_SESSION['user_id']:null;$status=!empty($classroom['require_approval'])?'pending':'active';
if($userId){$existing=db()->prepare('SELECT * FROM classroom_members WHERE classroom_id=? AND user_id=?');$existing->execute([(int)$classroom['id'],$userId]);$member=$existing->fetch();if($member){if($member['membership_status']!=='active'){header('Location: /index.php?error='.rawurlencode('Membership menunggu persetujuan atau tidak aktif.'));exit;}StudentSession::establish((int)$member['id'],(int)$classroom['id'],(string)$member['session_token']);header('Location: /student/dashboard.php');exit;}}
$stmt=db()->prepare('INSERT INTO classroom_members(classroom_id,user_id,session_token,membership_status,last_seen_at,approved_at) VALUES(?,?,?,?,NOW(),IF(?="active",NOW(),NULL))');$stmt->execute([(int)$classroom['id'],$userId,$token,$status,$status]);
if($status==='pending'){header('Location: /student/account.php?message='.rawurlencode('Membership menunggu persetujuan Teacher.'));exit;}StudentSession::establish((int)db()->lastInsertId(),(int)$classroom['id'],$token);header('Location: /student/dashboard.php');exit;
