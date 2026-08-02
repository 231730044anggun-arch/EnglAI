<?php
declare(strict_types=1);require_once __DIR__.'/_auth.php';require_once __DIR__.'/../vendor/autoload.php';
use EnglAI\Analytics\AnalyticsService;use EnglAI\Analytics\ExportService;use EnglAI\Analytics\AuditService;use EnglAI\Mvp\ClassroomService;
require_admin();
$cid  = (int)($_GET['classroom_id']??0);
$actor= (string)($_SESSION['admin_username']??'admin');
$classroom = (new ClassroomService(db()))->requireOwned($cid,$actor);
$data = (new AnalyticsService(db()))->classroom($cid);

// Student gradebook
$q = db()->prepare("SELECT m.id,m.display_name,m.user_id,m.created_at,m.last_seen_at,u.name as user_name,u.email as user_email,
    (SELECT COUNT(*) FROM learning_attempts a WHERE a.member_id=m.id AND a.classroom_id=? AND a.status='completed') completed,
    (SELECT ROUND(AVG(a.score),1) FROM learning_attempts a WHERE a.member_id=m.id AND a.classroom_id=? AND a.status='completed') avg_score
  FROM classroom_members m
  LEFT JOIN users u ON u.id = m.user_id
  WHERE m.classroom_id=? ORDER BY avg_score DESC,m.display_name ASC");
$q->execute([$cid,$cid,$cid]);
$students = $q->fetchAll();

(new AuditService(db()))->record($cid,$actor,'report.exported','export_job',null,[],['type'=>'classroom_html']);
function he(mixed $v):string{return htmlspecialchars((string)($v??''),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function gradeFrom(float $avg):string{return $avg>=90?'A':($avg>=80?'B':($avg>=70?'C':($avg>=60?'D':'E')));}
?><!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Export Data · <?=he($classroom['name'])?> · EnglAI</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
  *{box-sizing:border-box;margin:0;padding:0}
  body{font-family:'Inter',sans-serif;font-size:13px;color:#0f172a;background:#f1f5f9;line-height:1.5}
  .shell{max-width:1100px;margin:0 auto;padding:28px 24px}
  /* Top bar */
  .topbar{display:flex;align-items:center;justify-content:space-between;margin-bottom:24px;flex-wrap:wrap;gap:12px}
  .topbar h1{font-size:18px;font-weight:700;color:#1e293b}
  .topbar .sub{font-size:12px;color:#64748b;margin-top:2px}
  .btn-row{display:flex;gap:10px;flex-wrap:wrap}
  .btn{display:inline-flex;align-items:center;gap:6px;padding:8px 18px;border-radius:8px;font-size:12px;font-weight:600;cursor:pointer;border:none;text-decoration:none;transition:.15s}
  .btn-primary{background:#4f46e5;color:#fff}.btn-primary:hover{background:#4338ca}
  .btn-ghost{background:#fff;color:#4f46e5;border:1px solid #c7d2fe}.btn-ghost:hover{background:#eef2ff}
  .btn-back{background:#fff;color:#64748b;border:1px solid #e2e8f0}.btn-back:hover{background:#f8faff}
  /* Tabs */
  .tabs{display:flex;gap:4px;border-bottom:2px solid #e2e8f0;margin-bottom:20px;flex-wrap:wrap}
  .tab{padding:8px 18px;border-radius:8px 8px 0 0;font-size:12px;font-weight:600;cursor:pointer;border:none;background:transparent;color:#64748b;transition:.15s}
  .tab.active{background:#4f46e5;color:#fff;margin-bottom:-2px;border-bottom:2px solid #4f46e5}
  .tab:not(.active):hover{background:#f1f5f9;color:#4f46e5}
  .tab-panel{display:none}.tab-panel.active{display:block}
  /* Table */
  .tbl-wrap{overflow-x:auto;border-radius:12px;border:1px solid #e2e8f0;background:#fff}
  table{width:100%;border-collapse:collapse;font-size:12px}
  thead tr{background:linear-gradient(90deg,#4f46e5,#6366f1)}
  thead th{padding:10px 14px;text-align:left;color:#fff;font-weight:600;font-size:11px;text-transform:uppercase;letter-spacing:.04em;white-space:nowrap}
  tbody tr:nth-child(odd){background:#f8faff}
  tbody tr:nth-child(even){background:#fff}
  tbody tr:hover{background:#eef2ff;transition:.1s}
  tbody td{padding:9px 14px;border-bottom:1px solid #f1f5f9;vertical-align:middle}
  tfoot tr{background:#f8faff;font-weight:600}
  tfoot td{padding:9px 14px;border-top:2px solid #e0e7ff;color:#4338ca}
  /* Skill accent */
  .skill-reading td:first-child{border-left:3px solid #3b82f6}
  .skill-listening td:first-child{border-left:3px solid #10b981}
  .skill-speaking td:first-child{border-left:3px solid #f59e0b}
  .skill-writing td:first-child{border-left:3px solid #ec4899}
  /* Badges */
  .badge{display:inline-block;padding:2px 8px;border-radius:6px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.04em}
  .bg-green{background:#d1fae5;color:#065f46}.bg-blue{background:#dbeafe;color:#1e40af}
  .bg-yellow{background:#fef9c3;color:#92400e}.bg-red{background:#fee2e2;color:#991b1b}
  /* Progress bar */
  .bar-wrap{width:90px;background:#e2e8f0;border-radius:4px;height:7px;overflow:hidden;display:inline-block;vertical-align:middle}
  .bar-fill{height:100%;border-radius:4px}
  /* Score color */
  .s-high{color:#059669;font-weight:700}.s-mid{color:#d97706;font-weight:700}.s-low{color:#dc2626;font-weight:700}
  /* KPI strip */
  .kpi-row{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:12px;margin-bottom:20px}
  .kpi{background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:12px 16px}
  .kpi-val{font-size:22px;font-weight:700;color:#4338ca;line-height:1}
  .kpi-lbl{font-size:10px;color:#64748b;text-transform:uppercase;letter-spacing:.04em;margin-top:3px}
  /* Search filter */
  .tbl-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;flex-wrap:wrap;gap:8px}
  .tbl-header h3{font-size:14px;font-weight:700;color:#1e293b}
  .search-input{padding:6px 12px;border:1px solid #e2e8f0;border-radius:8px;font-size:12px;font-family:inherit;outline:none;width:220px}
  .search-input:focus{border-color:#6366f1;box-shadow:0 0 0 3px #eef2ff}
  /* Print */
  @media print{.no-print{display:none!important} body{background:#fff} .tbl-wrap{border:1px solid #ccc}}
</style>
</head>
<body>
<div class="shell">
  <!-- Topbar -->
  <div class="topbar no-print">
    <div>
      <h1>📊 <?=he($classroom['name'])?> — Data Export</h1>
      <div class="sub">Classroom Code: <?=he($classroom['code'])?> · Exported by: <?=he($actor)?> · <?=date('d M Y, H:i')?></div>
    </div>
    <div class="btn-row">
      <a class="btn btn-back" href="/admin/analytics.php?classroom_id=<?=$cid?>">← Analytics</a>
      <a class="btn btn-ghost" href="/admin/report.php?classroom_id=<?=$cid?>">🖨️ Print Report</a>
      <button class="btn btn-primary" onclick="downloadActiveTabCSV()">⬇️ Download CSV</button>
    </div>
  </div>

  <!-- Overview KPIs -->
  <div class="kpi-row">
    <div class="kpi"><div class="kpi-val"><?=$data['students']?></div><div class="kpi-lbl">Students</div></div>
    <div class="kpi"><div class="kpi-val"><?=$data['active_students']?></div><div class="kpi-lbl">Active (30d)</div></div>
    <div class="kpi"><div class="kpi-val"><?=$data['completion_rate']?>%</div><div class="kpi-lbl">Completion</div></div>
    <div class="kpi"><div class="kpi-val"><?=$data['self_learning_attempts']?></div><div class="kpi-lbl">SL Attempts</div></div>
    <div class="kpi"><div class="kpi-val"><?=$data['self_learning_average']?>%</div><div class="kpi-lbl">SL Average</div></div>
    <div class="kpi"><div class="kpi-val"><?=$data['live_quiz_sessions']?></div><div class="kpi-lbl">Quiz Sessions</div></div>
    <div class="kpi"><div class="kpi-val"><?=$data['live_quiz_average']?>%</div><div class="kpi-lbl">Quiz Average</div></div>
  </div>

  <!-- Tabs -->
  <div class="tabs no-print">
    <button class="tab active" data-tab="gradebook">👤 Student Gradebook</button>
    <button class="tab" data-tab="skills">🎯 Skill Performance</button>
    <button class="tab" data-tab="competency">🏅 Competency</button>
    <button class="tab" data-tab="questions">🔍 Question Difficulty</button>
  </div>

  <!-- TAB: GRADEBOOK -->
  <div class="tab-panel active" id="tab-gradebook">
    <div class="tbl-header">
      <h3>Student Gradebook</h3>
      <input class="search-input no-print" type="search" placeholder="🔍 Filter nama..." oninput="filterTable('tbl-students',this.value)">
    </div>
    <div class="tbl-wrap">
      <table id="tbl-students">
        <thead><tr>
          <th>#</th><th>Student Name</th><th>Joined</th><th>Last Active</th>
          <th>Exercises Done</th><th>Average Score</th><th>Grade</th>
        </tr></thead>
        <tbody>
        <?php foreach($students as $i=>$st):
          $avg = (float)($st['avg_score']??0);
          $grade = gradeFrom($avg);
          $bc = $avg>=80?'bg-green':($avg>=60?'bg-blue':($avg>=40?'bg-yellow':'bg-red'));
          $sc2 = $avg>=80?'s-high':($avg>=60?'s-mid':'s-low');
        ?>
        <tr>
          <td style="color:#94a3b8;font-weight:600"><?=$i+1?></td>
          <?php $displayName = $st['display_name'] ?: $st['user_name'] ?: $st['user_email'] ?: ('Student #' . ($st['user_id'] ?: $st['id'])); ?>
          <td><b><?=he($displayName)?></b></td>
          <td><?=he(substr((string)$st['created_at'],0,10))?></td>
          <td><?=$st['last_seen_at']?he(substr((string)$st['last_seen_at'],0,10)):'—'?></td>
          <td><?=(int)$st['completed']?> exercises</td>
          <td class="<?=$sc2?>"><?=$st['avg_score']!==null?$st['avg_score'].'%':'—'?></td>
          <td><span class="badge <?=$bc?>"><?=$grade?></span></td>
        </tr>
        <?php endforeach; if(!$students):?>
        <tr><td colspan="7" style="text-align:center;color:#94a3b8;padding:20px">Belum ada siswa</td></tr>
        <?php endif;?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- TAB: SKILLS -->
  <div class="tab-panel" id="tab-skills">
    <div class="tbl-header"><h3>Skill Performance Breakdown</h3></div>
    <div class="tbl-wrap">
      <table>
        <thead><tr><th>Skill</th><th>Attempts</th><th>Avg Score</th><th>Completion</th><th>Progress</th></tr></thead>
        <tbody>
        <?php foreach($data['skills'] as $s=>$v):
          $pct = (float)$v['average'];
          $sc2 = $pct>=80?'s-high':($pct>=50?'s-mid':'s-low');
          $barColor = $pct>=80?'#10b981':($pct>=60?'#3b82f6':($pct>=40?'#f59e0b':'#ef4444'));
        ?>
        <tr class="skill-<?=he($s)?>">
          <td><b><?=ucfirst($s)?></b></td>
          <td><?=(int)$v['attempts']?></td>
          <td class="<?=$sc2?>"><?=$v['average']?>%</td>
          <td><?=$v['completion']?>%</td>
          <td>
            <div class="bar-wrap"><div class="bar-fill" style="width:<?=min(100,$pct)?>%;background:<?=$barColor?>"></div></div>
          </td>
        </tr>
        <?php endforeach;?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- TAB: COMPETENCY -->
  <div class="tab-panel" id="tab-competency">
    <div class="tbl-header"><h3>Competency Mastery Analysis</h3></div>
    <div class="tbl-wrap">
      <table>
        <thead><tr><th>Competency</th><th>Activities</th><th>Students</th><th>Attempts</th><th>Avg Score</th><th>Progress</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach($data['competencies'] as $r):
          $pct = (float)$r['average'];
          $sc2 = $pct>=80?'s-high':($pct>=50?'s-mid':'s-low');
          $bc = $r['status']==='Mastered'?'bg-green':($r['status']==='Developing'?'bg-blue':'bg-red');
          $barColor = $pct>=80?'#10b981':($pct>=60?'#3b82f6':($pct>=40?'#f59e0b':'#ef4444'));
        ?>
        <tr>
          <td><b><?=he($r['competency'])?></b></td>
          <td><?=(int)$r['items']?></td>
          <td><?=(int)$r['students']?></td>
          <td><?=(int)$r['attempts']?></td>
          <td class="<?=$sc2?>"><?=number_format($pct,1)?>%</td>
          <td><div class="bar-wrap"><div class="bar-fill" style="width:<?=min(100,$pct)?>%;background:<?=$barColor?>"></div></div></td>
          <td><span class="badge <?=$bc?>"><?=$r['status']?></span></td>
        </tr>
        <?php endforeach; if(!$data['competencies']):?>
        <tr><td colspan="7" style="text-align:center;color:#94a3b8;padding:20px">Belum ada data kompetensi</td></tr>
        <?php endif;?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- TAB: QUESTION DIFFICULTY -->
  <div class="tab-panel" id="tab-questions">
    <div class="tbl-header">
      <h3>Question Difficulty Analysis</h3>
      <input class="search-input no-print" type="search" placeholder="🔍 Filter soal..." oninput="filterTable('tbl-questions',this.value)">
    </div>
    <p style="font-size:11px;color:#64748b;margin-bottom:10px">
      <span class="badge bg-red">Needs Review</span> = minimal 5 attempts dan correct rate &lt;30%. Bukan berarti soal otomatis salah.
    </p>
    <div class="tbl-wrap">
      <table id="tbl-questions">
        <thead><tr><th>#</th><th>Question</th><th>Skill</th><th>Level</th><th>Attempts</th><th>Correct Rate</th><th>Avg Response</th><th>Status</th></tr></thead>
        <tbody>
        <?php
        $qs = $data['questions'] ?? [];
        foreach(array_slice($qs,0,100) as $qi=>$r):
          $pct = (float)$r['correct_rate'];
          $isReview = $r['review_status']==='Needs Review';
          $sc2 = $pct>=70?'s-high':($pct>=40?'s-mid':'s-low');
          $bc = $isReview?'bg-red':'bg-green';
          $barColor = $pct>=70?'#10b981':($pct>=40?'#f59e0b':'#ef4444');
          $rowStyle = $isReview ? 'background:#fff1f2' : '';
        ?>
        <tr style="<?=$rowStyle?>">
          <td style="color:#94a3b8;font-weight:600"><?=$qi+1?></td>
          <td style="max-width:320px;white-space:normal"><?=he(mb_strimwidth($r['question'],0,120,'…'))?></td>
          <td><?=ucfirst($r['skill'])?></td>
          <td><?=ucfirst($r['difficulty']??'—')?></td>
          <td><?=(int)$r['attempts']?></td>
          <td>
            <span class="<?=$sc2?>"><?=number_format($pct,1)?>%</span>
            <div class="bar-wrap" style="margin-left:6px"><div class="bar-fill" style="width:<?=min(100,$pct)?>%;background:<?=$barColor?>"></div></div>
          </td>
          <td><?=(int)$r['average_response_ms']?> ms</td>
          <td><span class="badge <?=$bc?>"><?=$r['review_status']?></span></td>
        </tr>
        <?php endforeach; if(!$qs):?>
        <tr><td colspan="8" style="text-align:center;color:#94a3b8;padding:20px">Belum ada soal yang dijawab</td></tr>
        <?php endif;?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<script>
// Tab switching
document.querySelectorAll('.tab').forEach(t => {
  t.addEventListener('click', () => {
    document.querySelectorAll('.tab,.tab-panel').forEach(el => el.classList.remove('active'));
    t.classList.add('active');
    document.getElementById('tab-' + t.dataset.tab).classList.add('active');
  });
});
// Live filter
function filterTable(tableId, query) {
  const q = query.toLowerCase();
  document.querySelectorAll('#' + tableId + ' tbody tr').forEach(row => {
    row.style.display = row.textContent.toLowerCase().includes(q) ? '' : 'none';
  });
}
// Export active tab to CSV
function downloadActiveTabCSV() {
  const activeTabBtn = document.querySelector('.tab.active');
  const activeTabName = activeTabBtn ? activeTabBtn.getAttribute('data-tab') : 'export';
  
  const activePanel = document.querySelector('.tab-panel.active');
  if (!activePanel) return;
  const table = activePanel.querySelector('table');
  if (!table) return;

  const rows = [];
  
  // Headers
  const headerCols = table.querySelectorAll('thead th');
  const headers = [];
  headerCols.forEach(col => {
    let text = col.innerText.trim();
    if (text === 'Average Score') text = 'Average Score (%)';
    if (text === 'Avg Score') text = 'Avg Score (%)';
    if (text === 'Completion') text = 'Completion (%)';
    if (text === 'Correct Rate') text = 'Correct Rate (%)';
    if (text === 'Avg Response') text = 'Avg Response (ms)';
    headers.push('"' + text.replace(/"/g, '""') + '"');
  });
  rows.push(headers.join(','));

  // Body rows
  const bodyRows = table.querySelectorAll('tbody tr');
  bodyRows.forEach(row => {
    if (row.style.display === 'none') return;
    if (row.querySelector('td[colspan]')) return; // empty row

    const rowData = [];
    const cols = row.querySelectorAll('td');
    cols.forEach(col => {
      let val = col.innerText.trim();
      val = val.replace(/\s*exercises\s*$/i, '');
      if (val.endsWith('%')) {
        val = val.slice(0, -1);
      }
      val = val.replace(/\s*ms\s*$/i, '');
      if (val === '—') {
        val = '';
      }
      val = val.replace(/"/g, '""');
      if (/^[=+\-@]/.test(val)) {
        val = "'" + val;
      }
      rowData.push('"' + val + '"');
    });
    
    if (rowData.length > 0) {
      rows.push(rowData.join(','));
    }
  });

  const csvContent = "\uFEFF" + rows.join("\n");
  const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
  const url = URL.createObjectURL(blob);
  const link = document.createElement("a");
  link.setAttribute("href", url);
  
  const className = "<?= he($classroom['name']) ?>".toLowerCase().replace(/[^a-z0-9]+/g, '-');
  link.setAttribute("download", `englai-${activeTabName}-${className}-${new Date().toISOString().slice(0,10)}.csv`);
  document.body.appendChild(link);
  link.click();
  document.body.removeChild(link);
}
</script>
</body>
</html>
