<?php
declare(strict_types=1);
require_once __DIR__.'/../config/koneksi.php';
require_once __DIR__.'/../vendor/autoload.php';
use EnglAI\Mvp\ClassroomService;
if(session_status()!==PHP_SESSION_ACTIVE)session_start();
$code=strtoupper(trim((string)($_GET['code']??$_SESSION['pending_classroom_code']??'')));
$classroom=preg_match('/^ENG-[A-Z0-9]{4,8}$/',$code)?(new ClassroomService(db()))->findActiveByCode($code):false;
if(!$classroom){header('Location: /index.php?error='.rawurlencode('Classroom ID tidak ditemukan atau classroom tidak aktif.'));exit;}
$_SESSION['pending_classroom_code']=$code;
$logged=(($_SESSION['user_role']??'')==='student');
function e(string $value):string{return htmlspecialchars($value,ENT_QUOTES,'UTF-8');}
if($logged){header('Location: /student/setup_profile.php?code='.rawurlencode($code));exit;}
?>
<!doctype html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Classroom ditemukan · EnglAI</title><link rel="stylesheet" href="/assets/css/mvp.css"></head><body><div class="stars" aria-hidden="true"></div><header class="nav"><a class="brand" href="/">EnglAI</a><a class="button secondary" href="/">← Kembali</a></header><main class="game-shell"><section class="card"><span class="eyebrow">CLASSROOM FOUND</span><h1><?=e((string)$classroom['name'])?></h1><p class="muted">Classroom ID: <?=e($code)?></p><h2>Masuk sebagai Student</h2><p class="muted">Gunakan akun Student agar progress, histori, dan achievement tersimpan lintas perangkat.</p><a class="button primary wide" href="/auth/student_login.php?classroom_code=<?=rawurlencode($code)?>">Student Login</a><a class="button secondary wide" href="/auth/register.php?role=student&amp;classroom_code=<?=rawurlencode($code)?>">Create Student Account</a></section></main></body></html>
