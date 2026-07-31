<?php
declare(strict_types=1);
require_once __DIR__.'/_auth.php';require_once __DIR__.'/../vendor/autoload.php';
use EnglAI\Mvp\ClassroomService;use EnglAI\Security\Csrf;
require_admin();$teacher=(string)($_SESSION['admin_username']??env_value('ADMIN_USERNAME','admin'));$teacherId=(int)($_SESSION['user_id']??0);$error='';
if($_SERVER['REQUEST_METHOD']==='POST'){Csrf::requireValid($_POST['csrf_token']??null);try{$id=(new ClassroomService(db()))->create($teacher,(string)($_POST['name']??''));header('Location: classroom.php?id='.$id.'&message='.rawurlencode('Classroom berhasil dibuat.'));exit;}catch(Throwable $e){$error=$e->getMessage();}}
$stmt=db()->prepare("SELECT c.*,
(SELECT COUNT(*) FROM classroom_members m WHERE m.classroom_id=c.id) member_count,
(SELECT COUNT(*) FROM content_questions q WHERE q.classroom_id=c.id AND q.content_type='self_learning') self_count,
(SELECT COUNT(*) FROM content_questions q WHERE q.classroom_id=c.id AND q.content_type='live_quiz') live_count,
(SELECT original_name FROM classroom_lesson_plans lp WHERE lp.classroom_id=c.id AND lp.is_active=1 ORDER BY version DESC LIMIT 1) rpp_name
FROM classrooms c WHERE c.teacher_key=? OR (c.teacher_user_id IS NOT NULL AND c.teacher_user_id=?) ORDER BY c.created_at DESC");$stmt->execute([$teacher,$teacherId]);$classrooms=$stmt->fetchAll();
$metrics=db()->prepare("SELECT
(SELECT COUNT(*) FROM classroom_members m JOIN classrooms c ON c.id=m.classroom_id WHERE c.teacher_key=? OR c.teacher_user_id=?) students,
(SELECT COUNT(*) FROM student_learning_sessions s JOIN classrooms c ON c.id=s.classroom_id WHERE (c.teacher_key=? OR c.teacher_user_id=?) AND s.status='completed') learning_sessions,
(SELECT COUNT(*) FROM quiz_sessions q JOIN classrooms c ON c.id=q.classroom_id WHERE c.teacher_key=? OR c.teacher_user_id=?) quizzes");$metrics->execute([$teacher,$teacherId,$teacher,$teacherId,$teacher,$teacherId]);$summary=$metrics->fetch();
$recent=db()->prepare("(SELECT 'Self Learning selesai' label,s.completed_at event_at,c.name classroom FROM student_learning_sessions s JOIN classrooms c ON c.id=s.classroom_id WHERE (c.teacher_key=? OR c.teacher_user_id=?) AND s.completed_at IS NOT NULL)
UNION ALL (SELECT CONCAT('Live Quiz #',q.id,' · ',q.state),COALESCE(q.finished_at,q.created_at),c.name FROM quiz_sessions q JOIN classrooms c ON c.id=q.classroom_id WHERE c.teacher_key=? OR c.teacher_user_id=?)
ORDER BY event_at DESC LIMIT 6");$recent->execute([$teacher,$teacherId,$teacher,$teacherId]);$activities=$recent->fetchAll();
?>
<!doctype html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Teacher Dashboard · EnglAI</title><link rel="stylesheet" href="/assets/css/mvp.css"></head>
<body><div class="stars" aria-hidden="true"></div><header class="nav"><a class="brand" href="/admin/"><span class="brand-mark">E</span>EnglAI</a><div class="row"><span class="muted">Teacher · <?=htmlspecialchars($teacher)?></span><form method="post" action="/admin/logout.php"><?=Csrf::field()?><button class="button secondary">Logout</button></form></div></header>
<main class="shell"><nav class="breadcrumb" aria-label="Breadcrumb"><span>Teacher</span><span>›</span><strong>Dashboard</strong></nav>
<section class="card dashboard-hero"><span class="eyebrow">Teacher Workspace</span><h1>Selamat datang, <span class="gradient-text"><?=htmlspecialchars($teacher)?></span></h1><p class="muted">Kelola Classroom, RPP, content bank, dan Live Quiz dari satu tempat.</p><a class="button primary" href="#create-classroom">+ Create Classroom</a></section>
<?php if($error):?><div class="alert error" role="alert"><?=htmlspecialchars($error)?></div><?php endif;?>
<section class="grid four" aria-label="Teacher metrics">
<?php foreach([['🏫',count($classrooms),'Classroom'],['👥',(int)$summary['students'],'Student'],['📚',(int)$summary['learning_sessions'],'Self Learning'],['🏆',(int)$summary['quizzes'],'Live Quiz']] as $metric):?><article class="card metric"><div class="icon-box"><?=$metric[0]?></div><div><div class="stat"><?=$metric[1]?></div><span class="muted"><?=$metric[2]?></span></div></article><?php endforeach;?></section>
<div class="toolbar" style="margin-top:34px"><div><span class="eyebrow">Your Classrooms</span><h2>Classroom aktif</h2></div><a class="button primary" href="#create-classroom">+ Create Classroom</a></div>
<section class="grid"><?php foreach($classrooms as $c):$ready=(int)$c['self_count']>=20&&(int)$c['live_count']>=20;?>
<article class="card hover classroom-card"><div class="row" style="justify-content:space-between"><span class="code"><?=htmlspecialchars($c['code'])?></span><span class="badge <?=$ready?'available':'dev'?>"><?=$ready?'Content Ready':'Needs Generation'?></span></div><div><h3><?=htmlspecialchars($c['name'])?></h3><p class="muted"><?=htmlspecialchars($c['rpp_name']?:'Belum ada RPP')?></p></div>
<div class="classroom-meta"><div><b><?=(int)$c['member_count']?></b><small>Students</small></div><div><b><?=(int)$c['self_count']?></b><small>Self Learning</small></div><div><b><?=(int)$c['live_count']?></b><small>Live Quiz</small></div></div>
<div class="card-actions"><a class="button primary" href="/admin/classroom.php?id=<?=(int)$c['id']?>">Open Classroom</a><button type="button" class="button secondary" data-copy="<?=htmlspecialchars($c['code'])?>">Copy Classroom ID</button></div></article><?php endforeach;?>
<article class="card" id="create-classroom"><span class="eyebrow">Quick Action</span><h3>Create Classroom</h3><p class="muted">Classroom ID unik dibuat otomatis.</p><form method="post"><?=Csrf::field()?><label for="name">Nama Classroom</label><input id="name" name="name" maxlength="150" placeholder="English Grade 10A" required><button class="button gold wide">Create & Generate ID</button></form></article></section>
<section class="grid two" style="margin-top:22px"><article class="card"><h3>Recent Activity</h3><div class="activity-list"><?php if(!$activities):?><div class="empty">Belum ada aktivitas.</div><?php endif;foreach($activities as $a):?><div class="activity"><span><b><?=htmlspecialchars($a['label'])?></b><br><small class="muted"><?=htmlspecialchars($a['classroom'])?></small></span><small class="muted"><?=htmlspecialchars((string)$a['event_at'])?></small></div><?php endforeach;?></div></article>
<article class="card"><h3>Generation Status</h3><p class="muted">Classroom siap ketika kedua content bank memiliki minimal 20 pertanyaan.</p><?php $readyCount=count(array_filter($classrooms,fn($c)=>(int)$c['self_count']>=20&&(int)$c['live_count']>=20));$pct=count($classrooms)?round($readyCount/count($classrooms)*100):0;?><div class="generation-meter"><span style="width:<?=$pct?>%"></span></div><p style="margin-top:12px"><b><?=$readyCount?> / <?=count($classrooms)?></b> Classroom ready</p></article></section>
</main><script src="/assets/js/visual-effects.js" defer></script><script src="/assets/js/teacher.js" defer></script></body></html>
