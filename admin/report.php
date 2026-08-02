<?php
declare(strict_types=1);require_once __DIR__.'/_auth.php';require_once __DIR__.'/../vendor/autoload.php';
use EnglAI\Analytics\AnalyticsService;use EnglAI\Analytics\AuditService;use EnglAI\Mvp\ClassroomService;
require_admin();$cid=(int)($_GET['classroom_id']??0);$actor=(string)($_SESSION['admin_username']??'admin');
$classroom=(new ClassroomService(db()))->requireOwned($cid,$actor);
$data=(new AnalyticsService(db()))->classroom($cid);
$q=db()->prepare('SELECT original_name FROM classroom_lesson_plans WHERE classroom_id=? AND is_active=1 ORDER BY version DESC LIMIT 1');$q->execute([$cid]);$rpp=(string)($q->fetchColumn()?:'—');

// Fetch student roster
$q=db()->prepare("SELECT m.id,m.display_name,m.created_at,m.last_seen_at,(SELECT COUNT(*) FROM learning_attempts a WHERE a.member_id=m.id AND a.classroom_id=? AND a.status='completed') completed,(SELECT ROUND(AVG(a.score),1) FROM learning_attempts a WHERE a.member_id=m.id AND a.classroom_id=? AND a.status='completed') avg_score FROM classroom_members m WHERE m.classroom_id=? ORDER BY avg_score DESC,m.display_name ASC");$q->execute([$cid,$cid,$cid]);$students=$q->fetchAll();

(new AuditService(db()))->record($cid,$actor,'report.exported','print_report',null,[],['type'=>'classroom_print']);
function sc(mixed $v):string{return htmlspecialchars((string)($v??''),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function skillBar(float $pct):string{$c=$pct>=80?'#10b981':($pct>=60?'#3b82f6':($pct>=40?'#f59e0b':'#ef4444'));return "<div style='background:#e5e7eb;border-radius:4px;height:8px;width:100%;overflow:hidden'><div style='height:100%;width:".min(100,$pct)."%;background:$c;border-radius:4px'></div></div>";}
?><!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Classroom Report · EnglAI</title>
<style>
  @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;900&family=Orbitron:wght@700&display=swap');
  *{box-sizing:border-box;margin:0;padding:0}
  body{font-family:'Inter',sans-serif;font-size:12px;color:#1e293b;background:#fff;line-height:1.5}
  .page{max-width:960px;margin:0 auto;padding:32px 40px}
  .no-print{margin-bottom:20px}
  @media print{.no-print{display:none} body{font-size:11px} .page{padding:16px 20px}}
  /* Header */
  .report-header{background:linear-gradient(135deg,#1e1b4b,#3730a3,#4f46e5);color:#fff;border-radius:16px;padding:28px 32px;margin-bottom:28px;display:flex;justify-content:space-between;align-items:flex-start}
  .report-header h1{font-family:'Orbitron',sans-serif;font-size:18px;font-weight:700;letter-spacing:0.05em;margin-bottom:8px}
  .report-header .sub{opacity:0.8;font-size:11px;line-height:1.8}
  .report-header .badge{background:rgba(255,255,255,0.15);border:1px solid rgba(255,255,255,0.25);border-radius:8px;padding:4px 10px;font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:0.05em;color:#fff;display:inline-block;margin-top:8px}
  .logo{font-family:'Orbitron',sans-serif;font-size:22px;font-weight:700;opacity:0.9;letter-spacing:0.1em}
  /* Section */
  .section-title{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;color:#6366f1;margin-bottom:12px;padding-bottom:6px;border-bottom:2px solid #e0e7ff;display:flex;align-items:center;gap:6px}
  .section{margin-bottom:28px}
  /* KPI Cards */
  .kpi-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:28px}
  .kpi{background:linear-gradient(135deg,#f8faff,#eef2ff);border:1px solid #c7d2fe;border-radius:12px;padding:14px 16px;text-align:center}
  .kpi .val{font-family:'Orbitron',sans-serif;font-size:22px;font-weight:700;color:#4338ca;line-height:1}
  .kpi .label{font-size:10px;color:#64748b;font-weight:500;margin-top:4px;text-transform:uppercase;letter-spacing:0.04em}
  /* Tables */
  table{width:100%;border-collapse:collapse;font-size:11px}
  thead tr{background:linear-gradient(90deg,#4f46e5,#6366f1);color:#fff}
  thead th{padding:8px 12px;text-align:left;font-weight:600;font-size:10px;text-transform:uppercase;letter-spacing:0.05em}
  tbody tr:nth-child(odd){background:#f8faff}
  tbody tr:nth-child(even){background:#fff}
  tbody tr:hover{background:#eef2ff}
  tbody td{padding:8px 12px;border-bottom:1px solid #e0e7ff;vertical-align:middle}
  /* Skill colored rows */
  .skill-reading td:first-child{border-left:3px solid #3b82f6}
  .skill-listening td:first-child{border-left:3px solid #10b981}
  .skill-speaking td:first-child{border-left:3px solid #f59e0b}
  .skill-writing td:first-child{border-left:3px solid #ec4899}
  /* Badges */
  .badge{display:inline-block;padding:2px 8px;border-radius:6px;font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:0.04em}
  .badge-mastered{background:#d1fae5;color:#065f46}
  .badge-developing{background:#dbeafe;color:#1e40af}
  .badge-practice{background:#fee2e2;color:#991b1b}
  .badge-good{background:#d1fae5;color:#065f46}
  .badge-avg{background:#fef9c3;color:#92400e}
  .badge-low{background:#fee2e2;color:#991b1b}
  /* Score cell */
  .score-cell{font-weight:700;font-family:'Orbitron',sans-serif;font-size:11px}
  .score-high{color:#059669}.score-mid{color:#d97706}.score-low{color:#dc2626}
  /* Two column layout */
  .grid-2{display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:28px}
  .card{background:#f8faff;border:1px solid #e0e7ff;border-radius:12px;padding:16px}
  .card .section-title{margin-bottom:10px}
  /* Footer */
  .report-footer{margin-top:40px;padding-top:16px;border-top:1px solid #e0e7ff;font-size:10px;color:#94a3b8;display:flex;justify-content:space-between}
</style>
</head>
<body>
<div class="page">
  <div class="no-print" style="display:flex;gap:10px;align-items:center">
    <button onclick="window.print()" style="background:#4f46e5;color:#fff;border:none;border-radius:8px;padding:8px 20px;font-family:inherit;font-size:13px;font-weight:600;cursor:pointer">🖨️ Print / Save PDF</button>
    <a href="/admin/analytics.php?classroom_id=<?=$cid?>" style="color:#6366f1;font-size:12px;text-decoration:none">← Back to Analytics</a>
  </div>

  <!-- HEADER -->
  <div class="report-header">
    <div>
      <div style="font-size:10px;opacity:0.7;letter-spacing:0.08em;text-transform:uppercase;margin-bottom:4px">Official Report · EnglAI</div>
      <h1><?=sc($classroom['name'])?></h1>
      <div class="sub">
        Code: <b><?=sc($classroom['code'])?></b> &nbsp;·&nbsp;
        Level: <b><?=ucfirst($data['classroom_level'])?></b> &nbsp;·&nbsp;
        RPP: <b><?=sc($rpp)?></b>
      </div>
      <span class="badge">Generated: <?=date('d M Y, H:i')?></span>
    </div>
    <div class="logo" style="text-align:right">Engl<span style="color:#818cf8">AI</span></div>
  </div>

  <!-- KPI OVERVIEW -->
  <div class="section-title">📊 Overview Metrics</div>
  <div class="kpi-grid">
    <div class="kpi"><div class="val"><?=$data['students']?></div><div class="label">Total Students</div></div>
    <div class="kpi"><div class="val"><?=$data['active_students']?></div><div class="label">Active (30d)</div></div>
    <div class="kpi"><div class="val"><?=$data['completion_rate']?>%</div><div class="label">Completion Rate</div></div>
    <div class="kpi"><div class="val"><?=$data['self_learning_attempts']?></div><div class="label">Self Learning Attempts</div></div>
    <div class="kpi"><div class="val"><?=$data['self_learning_average']?>%</div><div class="label">Self Learning Avg</div></div>
    <div class="kpi"><div class="val"><?=$data['live_quiz_sessions']?></div><div class="label">Live Quiz Sessions</div></div>
    <div class="kpi"><div class="val"><?=$data['live_quiz_average']?>%</div><div class="label">Live Quiz Avg</div></div>
    <div class="kpi"><div class="val"><?=ucfirst($data['classroom_level'])?></div><div class="label">Class Level</div></div>
  </div>

  <!-- SKILL + COMPETENCY SIDE BY SIDE -->
  <div class="grid-2">
    <div class="card">
      <div class="section-title">🎯 Skill Performance</div>
      <table>
        <thead><tr><th>Skill</th><th>Attempts</th><th>Average</th><th>Progress</th></tr></thead>
        <tbody>
        <?php 
        $skillColors=['reading'=>'#3b82f6','listening'=>'#10b981','speaking'=>'#f59e0b','writing'=>'#ec4899'];
        foreach($data['skills'] as $s=>$v):
          $pct=(float)$v['average'];
          $sc=$pct>=80?'score-high':($pct>=50?'score-mid':'score-low');
        ?>
        <tr class="skill-<?=sc($s)?>">
          <td><b><?=ucfirst($s)?></b></td>
          <td><?=$v['attempts']?></td>
          <td class="score-cell <?=$sc?>"><?=$v['average']?>%</td>
          <td style="width:100px"><?=skillBar($pct)?></td>
        </tr>
        <?php endforeach;?>
        </tbody>
      </table>
    </div>
    <div class="card">
      <div class="section-title">🏅 Competency Analysis</div>
      <table>
        <thead><tr><th>Competency</th><th>Avg</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach($data['competencies'] as $r):
          $pct=(float)$r['average'];
          $bc=$r['status']==='Mastered'?'badge-mastered':($r['status']==='Developing'?'badge-developing':'badge-practice');
          $sc=$pct>=80?'score-high':($pct>=50?'score-mid':'score-low');
        ?>
        <tr>
          <td><?=sc($r['competency'])?></td>
          <td class="score-cell <?=$sc?>"><?=number_format($pct,1)?>%</td>
          <td><span class="badge <?=$bc?>"><?=$r['status']?></span></td>
        </tr>
        <?php endforeach;?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- STUDENT GRADEBOOK -->
  <div class="section">
    <div class="section-title">👤 Student Gradebook</div>
    <?php if(!$students):?>
    <p style="color:#94a3b8;font-style:italic">Belum ada siswa di classroom ini.</p>
    <?php else:?>
    <table>
      <thead><tr><th>#</th><th>Student Name</th><th>Joined</th><th>Last Active</th><th>Completed Exercises</th><th>Average Score</th><th>Grade</th></tr></thead>
      <tbody>
      <?php foreach($students as $i=>$st):
        $avg=(float)($st['avg_score']??0);
        $grade=$avg>=90?'A':($avg>=80?'B':($avg>=70?'C':($avg>=60?'D':'E')));
        $bc=$avg>=80?'badge-mastered':($avg>=60?'badge-developing':'badge-practice');
        $sc=$avg>=80?'score-high':($avg>=50?'score-mid':'score-low');
      ?>
      <tr>
        <td style="color:#94a3b8;font-weight:600"><?=$i+1?></td>
        <td><b><?=sc($st['display_name']?:'Joined Student')?></b></td>
        <td><?=sc(substr((string)$st['created_at'],0,10))?></td>
        <td><?=$st['last_seen_at']?sc(substr((string)$st['last_seen_at'],0,10)):'—'?></td>
        <td style="text-align:center"><b><?=(int)$st['completed']?></b></td>
        <td class="score-cell <?=$sc?>"><?=$st['avg_score']!==null?$st['avg_score'].'%':'—'?></td>
        <td><span class="badge <?=$bc?>"><?=$grade?></span></td>
      </tr>
      <?php endforeach;?>
      </tbody>
    </table>
    <?php endif;?>
  </div>

  <!-- QUESTION DIFFICULTY -->
  <?php if(!empty($data['questions'])):?>
  <div class="section">
    <div class="section-title">🔍 Question Difficulty Analysis</div>
    <p style="font-size:10px;color:#64748b;margin-bottom:10px">Needs Review: ≥5 attempts dan correct rate &lt;30%. Ini bukan pernyataan otomatis bahwa soal salah.</p>
    <table>
      <thead><tr><th>Question</th><th>Skill</th><th>Level</th><th>Attempts</th><th>Correct Rate</th><th>Avg Response</th><th>Status</th></tr></thead>
      <tbody>
      <?php foreach(array_slice($data['questions'],0,30) as $r):
        $pct=(float)$r['correct_rate'];
        $sc=$pct>=70?'score-high':($pct>=40?'score-mid':'score-low');
        $isReview=$r['review_status']==='Needs Review';
      ?>
      <?php
        $badgeCls = $isReview ? 'badge-practice' : 'badge-mastered';
        $pctClass = $pct>=70 ? 'score-high' : ($pct>=40 ? 'score-mid' : 'score-low');
      ?>
      <tr <?=$isReview ? 'style="background:#fff1f2"' : ''?>>
        <td style="max-width:280px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?=sc(mb_strimwidth($r['question'],0,80,'…'))?></td>
        <td><?=ucfirst($r['skill'])?></td>
        <td><?=ucfirst($r['difficulty']??'—')?></td>
        <td style="text-align:center"><?=(int)$r['attempts']?></td>
        <td class="score-cell <?=$pctClass?>"><?=number_format($pct,1)?>%</td>
        <td><?=(int)$r['average_response_ms']?> ms</td>
        <td><span class="badge <?=$badgeCls?>"><?=$r['review_status']?></span></td>
      </tr>
      <?php endforeach;?>
      </tbody>
    </table>
  </div>
  <?php endif;?>

  <!-- FOOTER -->
  <div class="report-footer">
    <div>EnglAI · Classroom Report · <?=sc($classroom['name'])?></div>
    <div>Generated: <?=date('d M Y H:i:s')?> · Exported by: <?=sc($actor)?></div>
  </div>
</div>
</body>
</html>
