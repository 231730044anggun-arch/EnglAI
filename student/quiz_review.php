<?php
declare(strict_types=1);
require_once __DIR__.'/../config/koneksi.php';
require_once __DIR__.'/../vendor/autoload.php';
use EnglAI\Mvp\StudentSession;

$member  = StudentSession::requireMember(db());
$quizId  = (int)($_GET['id'] ?? 0);

$q = db()->prepare("
    SELECT p.id participant_id, p.display_name, p.avatar, p.total_score, p.correct_answers,
           p.completion_count, p.final_rank, p.achievement,
           q.*, c.name classroom_name
    FROM quiz_participants p
    JOIN quiz_sessions q ON q.id = p.quiz_session_id
    JOIN classrooms c ON c.id = q.classroom_id
    WHERE q.id=? AND p.member_id=? AND q.classroom_id=?
      AND q.state IN ('FINISHED','CLOSED') AND q.review_enabled=1
");
$q->execute([$quizId, (int)$member['id'], (int)$member['classroom_id']]);
$quiz = $q->fetch();
if (!$quiz) { http_response_code(404); exit('Review tidak tersedia.'); }

$q = db()->prepare('
    SELECT s.position, s.skill, s.question_type, s.question, s.content_json,
           s.answer, s.explanation,
           a.selected_answer, a.score, a.is_correct, a.response_ms,
           a.transcript, a.writing_submission, a.rubric_json, a.assessment_source,
           a.assessment_status
    FROM quiz_session_questions s
    LEFT JOIN quiz_answers a ON a.session_question_id = s.id AND a.participant_id = ?
    WHERE s.quiz_session_id = ?
    ORDER BY s.position
');
$q->execute([(int)$quiz['participant_id'], $quizId]);
$rows = $q->fetchAll();

$totalScore    = (int)$quiz['total_score'];
$correctCount  = (int)$quiz['correct_answers'];
$questionCount = (int)$quiz['question_count'];
$maxPossible   = $questionCount * 1000;
$percentage    = $maxPossible > 0 ? round($totalScore / $maxPossible * 100) : 0;

$skillIcons = ['reading' => '📖', 'listening' => '🎧', 'speaking' => '🎤', 'writing' => '✍️'];
$skillColors = [
    'reading'   => ['color' => '#60a5fa', 'bg' => 'rgba(96,165,250,.15)', 'border' => 'rgba(96,165,250,.35)'],
    'listening' => ['color' => '#a78bfa', 'bg' => 'rgba(167,139,250,.15)', 'border' => 'rgba(167,139,250,.35)'],
    'speaking'  => ['color' => '#34d399', 'bg' => 'rgba(52,211,153,.15)', 'border' => 'rgba(52,211,153,.35)'],
    'writing'   => ['color' => '#fb923c', 'bg' => 'rgba(251,146,60,.15)', 'border' => 'rgba(251,146,60,.35)'],
];
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Quiz Review · <?=htmlspecialchars($quiz['title'] ?: 'Live Quiz')?> · EnglAI</title>
<link rel="stylesheet" href="/assets/css/mvp.css">
<link rel="stylesheet" href="/assets/css/phase3.css">
<style>
/* ── Review layout ───────────────────────────────────────────── */
.review-shell{width:min(820px,calc(100% - 32px));margin:auto;padding:24px 0 72px}

/* Hero summary card */
.hero-summary{display:grid;grid-template-columns:1fr auto;align-items:center;gap:24px;padding:28px 32px}
.hero-summary .score-ring{position:relative;width:108px;height:108px;flex-shrink:0}
.hero-summary svg{transform:rotate(-90deg)}
.score-ring .ring-bg{stroke:rgba(255,255,255,.08)}
.score-ring .ring-fill{stroke:url(#scoreGrad);stroke-linecap:round;transition:stroke-dashoffset .8s cubic-bezier(.4,0,.2,1)}
.score-ring .ring-text{position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:2px}
.score-ring .pct{font-family:Orbitron,monospace;font-weight:800;font-size:1.3rem;line-height:1}
.score-ring .lbl{font-size:.62rem;color:var(--muted);letter-spacing:.06em;text-transform:uppercase}
.stat-row{display:flex;gap:28px;flex-wrap:wrap;margin-top:16px}
.stat-pill{display:flex;flex-direction:column;gap:3px}
.stat-pill .val{font-family:Orbitron,monospace;font-size:1.35rem;font-weight:800}
.stat-pill .key{font-size:.7rem;color:var(--muted);text-transform:uppercase;letter-spacing:.08em}
.achievement-chip{display:inline-flex;align-items:center;gap:8px;padding:7px 14px;border-radius:999px;background:linear-gradient(135deg,rgba(245,158,11,.2),rgba(249,115,22,.15));border:1px solid rgba(245,158,11,.4);color:#fcd34d;font-size:.78rem;font-weight:700;margin-top:12px}

/* Filter bar */
.filter-bar{display:flex;gap:8px;flex-wrap:wrap;margin:20px 0 4px}
.filter-btn{padding:6px 14px;border-radius:999px;border:1px solid var(--border);background:rgba(255,255,255,.05);color:var(--muted);font-size:.75rem;font-weight:600;cursor:pointer;transition:all .18s}
.filter-btn.active,.filter-btn:hover{color:#fff;border-color:rgba(167,139,250,.5);background:rgba(124,58,237,.18)}

/* Question cards */
.q-card{border-radius:18px;border:1px solid var(--border);background:var(--surface);backdrop-filter:blur(16px);padding:24px;margin-top:14px;transition:border-color .2s}
.q-card:hover{border-color:rgba(255,255,255,.2)}
.q-header{display:flex;align-items:center;gap:10px;margin-bottom:16px}
.q-num{width:30px;height:30px;border-radius:50%;background:rgba(255,255,255,.08);border:1px solid var(--border);display:grid;place-items:center;font-family:Orbitron,monospace;font-size:.72rem;font-weight:800;flex-shrink:0}
.skill-chip{display:inline-flex;align-items:center;gap:5px;padding:4px 11px;border-radius:999px;font-size:.7rem;font-weight:700;letter-spacing:.04em}
.score-badge{margin-left:auto;font-family:Orbitron,monospace;font-size:.8rem;font-weight:800;padding:4px 10px;border-radius:8px}
.score-badge.correct{color:#34d399;background:rgba(52,211,153,.12);border:1px solid rgba(52,211,153,.3)}
.score-badge.wrong{color:#f87171;background:rgba(248,113,113,.10);border:1px solid rgba(248,113,113,.25)}
.score-badge.neutral{color:#94a3b8;background:rgba(148,163,184,.08);border:1px solid rgba(148,163,184,.2)}
.q-text{font-size:1rem;font-weight:600;line-height:1.5;margin-bottom:18px;color:var(--text)}

/* Passage block */
.passage-block{background:rgba(0,0,0,.25);border-left:3px solid rgba(96,165,250,.5);border-radius:0 10px 10px 0;padding:14px 16px;margin-bottom:16px;font-size:.9rem;line-height:1.7;color:rgba(248,247,255,.82)}

/* Options grid */
.options-list{display:grid;gap:8px;margin-bottom:16px}
.opt-row{display:flex;align-items:center;gap:10px;padding:11px 14px;border-radius:11px;border:1px solid var(--border);background:rgba(255,255,255,.03);font-size:.88rem}
.opt-key{width:26px;height:26px;border-radius:50%;display:grid;place-items:center;font-weight:800;font-size:.75rem;flex-shrink:0;border:1px solid currentColor}
.opt-row.is-correct{background:rgba(52,211,153,.1);border-color:rgba(52,211,153,.4)}
.opt-row.is-correct .opt-key{color:#34d399}
.opt-row.is-wrong{background:rgba(248,113,113,.08);border-color:rgba(248,113,113,.3)}
.opt-row.is-wrong .opt-key{color:#f87171}
.opt-row.is-selected-correct .opt-key{background:rgba(52,211,153,.25)}
.opt-mark{margin-left:auto;font-size:.8rem}

/* Answer verdict */
.verdict{display:inline-flex;align-items:center;gap:7px;padding:8px 14px;border-radius:10px;font-size:.82rem;font-weight:700;margin-bottom:12px}
.verdict.correct{background:rgba(52,211,153,.12);border:1px solid rgba(52,211,153,.3);color:#6ee7b7}
.verdict.wrong{background:rgba(248,113,113,.10);border:1px solid rgba(248,113,113,.28);color:#fca5a5}
.verdict.timeout{background:rgba(251,191,36,.09);border:1px solid rgba(251,191,36,.28);color:#fcd34d}

/* Explanation */
.explanation{font-size:.84rem;color:var(--muted);padding:10px 14px;background:rgba(255,255,255,.04);border-radius:10px;margin-top:4px;line-height:1.6}

/* Transcript / Writing */
.response-box{background:rgba(0,0,0,.28);border:1px solid var(--border);border-radius:12px;padding:16px;font-size:.88rem;line-height:1.7;color:rgba(248,247,255,.85);white-space:pre-wrap;margin-top:8px}
.response-label{font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:var(--muted);margin-bottom:6px}

/* Rubric accordion */
.rubric-details{margin-top:14px}
.rubric-details summary{cursor:pointer;font-size:.8rem;font-weight:700;color:var(--muted);padding:8px 0;user-select:none;list-style:none;display:flex;align-items:center;gap:6px}
.rubric-details summary::before{content:"▸";font-size:.7rem;transition:transform .2s}
.rubric-details[open] summary::before{transform:rotate(90deg)}
.rubric-inner{display:grid;gap:8px;margin-top:10px}
.rubric-row{display:flex;justify-content:space-between;align-items:center;padding:8px 12px;border-radius:9px;background:rgba(255,255,255,.04);font-size:.82rem}
.rubric-score{font-family:Orbitron,monospace;font-size:.75rem;font-weight:800}
.rubric-bar-wrap{height:4px;background:rgba(255,255,255,.08);border-radius:999px;margin-top:3px;width:80px}
.rubric-bar{height:100%;border-radius:999px;background:linear-gradient(90deg,#7c3aed,#ec4899)}
.rubric-feedback{font-size:.8rem;color:var(--muted);line-height:1.6;margin-top:8px;padding:10px 12px;background:rgba(255,255,255,.03);border-radius:9px;border-left:2px solid rgba(167,139,250,.4)}

/* Script accordion */
.script-details{margin-top:10px}
.script-details summary{cursor:pointer;font-size:.78rem;font-weight:600;color:rgba(167,139,250,.8);list-style:none;display:flex;align-items:center;gap:5px;user-select:none}
.script-details summary::before{content:"▸";font-size:.65rem;transition:transform .2s}
.script-details[open] summary::before{transform:rotate(90deg)}
.script-text{margin-top:8px;font-size:.84rem;color:var(--muted);line-height:1.7;padding:10px 14px;background:rgba(0,0,0,.2);border-radius:9px}

/* Separator */
.section-sep{display:flex;align-items:center;gap:12px;margin:28px 0 4px;color:var(--muted);font-size:.72rem;text-transform:uppercase;letter-spacing:.12em;font-weight:700}
.section-sep::before,.section-sep::after{content:'';flex:1;height:1px;background:var(--border)}

@media(max-width:600px){
    .hero-summary{grid-template-columns:1fr;text-align:center}
    .score-ring{margin:0 auto}
    .stat-row{justify-content:center}
}
</style>
</head>
<body>
<div class="stars" aria-hidden="true"></div>
<header class="nav">
    <a class="brand" href="/student/dashboard.php"><span class="brand-mark">E</span>EnglAI</a>
    <a class="button secondary" href="/student/dashboard.php">← Classroom</a>
</header>

<main class="review-shell">

    <nav class="breadcrumb">
        <a href="/student/dashboard.php">Classroom</a>
        <span>›</span>
        <strong>Quiz Review</strong>
    </nav>

    <!-- ── Hero summary ────────────────────────────────────────── -->
    <section class="card hero-summary">
        <div>
            <span class="eyebrow">Personal Review · Private</span>
            <h1 style="font-size:clamp(1.1rem,3vw,1.6rem);margin:8px 0 4px"><?=htmlspecialchars($quiz['title'] ?: 'Live Quiz')?></h1>
            <p class="muted" style="font-size:.85rem;margin:0"><?=htmlspecialchars($quiz['classroom_name'])?> · <?=$questionCount?> soal</p>

            <?php if ($quiz['achievement']): ?>
                <div class="achievement-chip">🏆 <?=htmlspecialchars($quiz['achievement'])?></div>
            <?php endif; ?>

            <div class="stat-row">
                <div class="stat-pill">
                    <span class="val" style="color:#a78bfa"><?=number_format($totalScore)?></span>
                    <span class="key">Total Score</span>
                </div>
                <div class="stat-pill">
                    <span class="val" style="color:#34d399"><?=$correctCount?></span>
                    <span class="key">Correct</span>
                </div>
                <div class="stat-pill">
                    <span class="val" style="color:#f87171"><?=$questionCount - $correctCount?></span>
                    <span class="key">Wrong / Skipped</span>
                </div>
                <?php if ($quiz['final_rank']): ?>
                <div class="stat-pill">
                    <span class="val" style="color:#fcd34d">#<?=(int)$quiz['final_rank']?></span>
                    <span class="key">Rank</span>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Circular score ring -->
        <div class="score-ring">
            <?php
                $r = 44; $circ = round(2 * M_PI * $r, 2);
                $offset = round($circ * (1 - $percentage / 100), 2);
            ?>
            <svg width="108" height="108" viewBox="0 0 108 108">
                <defs>
                    <linearGradient id="scoreGrad" x1="0%" y1="0%" x2="100%" y2="0%">
                        <stop offset="0%" stop-color="#7c3aed"/>
                        <stop offset="100%" stop-color="#ec4899"/>
                    </linearGradient>
                </defs>
                <circle class="ring-bg" cx="54" cy="54" r="<?=$r?>" fill="none" stroke-width="9"/>
                <circle class="ring-fill" cx="54" cy="54" r="<?=$r?>" fill="none" stroke-width="9"
                    stroke-dasharray="<?=$circ?>"
                    stroke-dashoffset="<?=$offset?>"
                    id="scoreRing"/>
            </svg>
            <div class="ring-text">
                <span class="pct"><?=$percentage?>%</span>
                <span class="lbl">Score</span>
            </div>
        </div>
    </section>

    <!-- ── Filter bar ──────────────────────────────────────────── -->
    <div class="filter-bar" id="filterBar">
        <button class="filter-btn active" data-filter="all" onclick="filterCards(this,'all')">All (<?=count($rows)?>)</button>
        <?php
        $skillCounts = [];
        foreach ($rows as $r) { $skillCounts[$r['skill']] = ($skillCounts[$r['skill']] ?? 0) + 1; }
        foreach ($skillCounts as $sk => $cnt):
        ?>
        <button class="filter-btn" data-filter="<?=$sk?>" onclick="filterCards(this,'<?=$sk?>')"><?=($skillIcons[$sk] ?? '•')?> <?=ucfirst($sk)?> (<?=$cnt?>)</button>
        <?php endforeach; ?>
        <button class="filter-btn" data-filter="correct" onclick="filterCards(this,'correct')">✅ Correct</button>
        <button class="filter-btn" data-filter="wrong" onclick="filterCards(this,'wrong')">❌ Wrong / Skipped</button>
    </div>

    <!-- ── Question cards ──────────────────────────────────────── -->
    <?php foreach ($rows as $idx => $row):
        $content     = json_decode((string)$row['content_json'], true) ?: [];
        $rubric      = json_decode((string)($row['rubric_json'] ?? ''), true) ?: [];
        $skill       = (string)$row['skill'];
        $qtype       = (string)$row['question_type'];
        $isObjective = in_array($qtype, ['objective', 'listening_objective'], true);
        $num         = (int)$row['position'] + 1;
        $score       = (int)($row['score'] ?? 0);
        $isCorrect   = (bool)($row['is_correct'] ?? false);
        $answered    = $row['selected_answer'] !== null || $row['transcript'] !== null || $row['writing_submission'] !== null;
        $sc          = $skillColors[$skill] ?? $skillColors['reading'];

        $verdictClass = 'neutral';
        $verdictIcon  = '⏱';
        $verdictText  = 'Timeout / Not answered';
        if ($isObjective) {
            if (!$answered) { /* timeout */ }
            elseif ($isCorrect) { $verdictClass = 'correct'; $verdictIcon = '✓'; $verdictText = 'Correct!'; }
            else                { $verdictClass = 'wrong';   $verdictIcon = '✗'; $verdictText = 'Wrong'; }
        }

        $cardFilter = $skill;
        if ($answered && $isObjective && $isCorrect) $cardFilter .= ' correct';
        elseif ($isObjective && !$isCorrect) $cardFilter .= ' wrong';
    ?>
    <article class="q-card" data-skill="<?=$skill?>" data-result="<?=$isCorrect?'correct':'wrong'?>" data-filter="<?=$cardFilter?>">

        <!-- Header row -->
        <div class="q-header">
            <div class="q-num"><?=$num?></div>
            <div class="skill-chip" style="color:<?=$sc['color']?>;background:<?=$sc['bg']?>;border:1px solid <?=$sc['border']?>">
                <?=$skillIcons[$skill] ?? '•'?> <?=ucfirst($skill)?>
            </div>
            <?php if ($isObjective): ?>
                <div class="score-badge <?=$verdictClass?>"><?=$score?> / 1000</div>
            <?php elseif ($score > 0): ?>
                <div class="score-badge neutral"><?=$score?> / 1000</div>
            <?php else: ?>
                <div class="score-badge neutral">Pending</div>
            <?php endif; ?>
        </div>

        <!-- Passage (reading) -->
        <?php if (!empty($content['passage'])): ?>
            <div class="passage-block"><?=nl2br(htmlspecialchars((string)$content['passage']))?></div>
        <?php endif; ?>

        <!-- Question text -->
        <div class="q-text"><?=htmlspecialchars($row['question'])?></div>

        <!-- ── OBJECTIVE / LISTENING ─────────────────────────── -->
        <?php if ($isObjective):
            $opts    = $content['options'] ?? [];
            $correct = strtoupper(trim((string)$row['answer']));
            $chosen  = strtoupper(trim((string)($row['selected_answer'] ?? '')));
            $letters = ['A', 'B', 'C', 'D'];
        ?>

        <!-- Verdict -->
        <div class="verdict <?=$verdictClass?>">
            <span><?=$verdictIcon?></span>
            <?=$verdictText?>
            <?php if ($answered && $row['response_ms']): ?>
                <span style="margin-left:6px;opacity:.65;font-weight:400"><?=round((int)$row['response_ms'] / 1000, 1)?>s</span>
            <?php endif; ?>
        </div>

        <!-- Options list -->
        <?php if ($opts): ?>
        <div class="options-list">
            <?php foreach ($opts as $i => $opt):
                $letter  = $letters[$i] ?? chr(65 + $i);
                $isCorrOpt = $letter === $correct;
                $isChosen  = $letter === $chosen;
                $rowClass  = '';
                $mark      = '';
                if ($isCorrOpt && $isChosen)  { $rowClass = 'is-correct is-selected-correct'; $mark = '✓ Correct'; }
                elseif ($isCorrOpt)            { $rowClass = 'is-correct'; $mark = '✓ Answer'; }
                elseif ($isChosen)             { $rowClass = 'is-wrong';   $mark = '✗ Your pick'; }
            ?>
            <div class="opt-row <?=$rowClass?>">
                <div class="opt-key"><?=$letter?></div>
                <span><?=htmlspecialchars((string)$opt)?></span>
                <?php if ($mark): ?><span class="opt-mark"><?=$mark?></span><?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Explanation -->
        <?php if (!empty($row['explanation'])): ?>
            <div class="explanation">💡 <?=htmlspecialchars((string)$row['explanation'])?></div>
        <?php endif; ?>

        <!-- Listening script -->
        <?php if ($qtype === 'listening_objective' && !empty($content['script'])): ?>
        <details class="script-details">
            <summary>🎧 Show audio script</summary>
            <p class="script-text"><?=htmlspecialchars((string)$content['script'])?></p>
        </details>
        <?php endif; ?>

        <!-- ── SPEAKING ──────────────────────────────────────── -->
        <?php elseif ($qtype === 'speaking_response'): ?>

        <div class="response-label">🎤 Your transcript</div>
        <div class="response-box"><?php
            $tr = trim((string)($row['transcript'] ?? ''));
            echo $tr !== '' ? htmlspecialchars($tr) : '<span style="opacity:.4;font-style:italic">No transcript recorded.</span>';
        ?></div>

        <?php if (!empty($rubric)): ?>
        <details class="rubric-details">
            <summary>📊 AI Rubric Feedback</summary>
            <div class="rubric-inner">
            <?php
            $criteriaScores = [];
            foreach (['relevance','task_completion','grammar','vocabulary','completeness','clarity_based_on_transcription'] as $crit) {
                if (isset($rubric[$crit])) $criteriaScores[$crit] = $rubric[$crit];
            }
            foreach ($criteriaScores as $crit => $val):
                $pct = is_numeric($val) ? min(100, max(0, (int)round((float)$val * 100 / 5))) : 0;
                $label = ucwords(str_replace(['_', 'based on transcription'], [' ', ''], $crit));
            ?>
            <div class="rubric-row">
                <div>
                    <div><?=htmlspecialchars($label)?></div>
                    <div class="rubric-bar-wrap"><div class="rubric-bar" style="width:<?=$pct?>%"></div></div>
                </div>
                <span class="rubric-score" style="color:#a78bfa"><?=htmlspecialchars((string)$val)?> / 5</span>
            </div>
            <?php endforeach; ?>
            <?php if (!empty($rubric['suggested_revision'])): ?>
            <div class="rubric-feedback">💬 <?=nl2br(htmlspecialchars((string)$rubric['suggested_revision']))?></div>
            <?php endif; ?>
            <?php if (!empty($rubric['overall_comment'])): ?>
            <div class="rubric-feedback">📝 <?=nl2br(htmlspecialchars((string)$rubric['overall_comment']))?></div>
            <?php endif; ?>
            </div>
        </details>
        <?php endif; ?>

        <!-- ── WRITING ───────────────────────────────────────── -->
        <?php else: ?>

        <div class="response-label">✍️ Your writing</div>
        <div class="response-box"><?php
            $ws = trim((string)($row['writing_submission'] ?? ''));
            echo $ws !== '' ? nl2br(htmlspecialchars($ws)) : '<span style="opacity:.4;font-style:italic">No answer submitted.</span>';
        ?></div>

        <?php if (!empty($rubric)): ?>
        <details class="rubric-details">
            <summary>📊 AI Writing Feedback</summary>
            <div class="rubric-inner">
            <?php
            foreach (['task_completion','relevance','grammar','vocabulary','organization','coherence','mechanics'] as $crit) {
                if (!isset($rubric[$crit])) continue;
                $val  = $rubric[$crit];
                $pct  = is_numeric($val) ? min(100, max(0, (int)round((float)$val * 100 / 5))) : 0;
                $label = ucfirst(str_replace('_', ' ', $crit));
            ?>
            <div class="rubric-row">
                <div>
                    <div><?=htmlspecialchars($label)?></div>
                    <div class="rubric-bar-wrap"><div class="rubric-bar" style="width:<?=$pct?>%"></div></div>
                </div>
                <span class="rubric-score" style="color:#fb923c"><?=htmlspecialchars((string)$val)?> / 5</span>
            </div>
            <?php } ?>
            <?php if (!empty($rubric['suggested_revision'])): ?>
            <div class="rubric-feedback">💬 <?=nl2br(htmlspecialchars((string)$rubric['suggested_revision']))?></div>
            <?php endif; ?>
            <?php if (!empty($rubric['overall_comment'])): ?>
            <div class="rubric-feedback">📝 <?=nl2br(htmlspecialchars((string)$rubric['overall_comment']))?></div>
            <?php endif; ?>
            </div>
        </details>
        <?php endif; ?>

        <?php endif; ?>

    </article>
    <?php endforeach; ?>

    <p class="muted" style="text-align:center;margin-top:32px;font-size:.8rem">
        🔒 Hanya hasil pribadimu yang ditampilkan di halaman ini.
    </p>

</main>

<script src="/assets/js/visual-effects.js" defer></script>
<script>
// Animate score ring on load
document.addEventListener('DOMContentLoaded', () => {
    const ring = document.getElementById('scoreRing');
    if (ring) {
        const total = 2 * Math.PI * 44;
        const pct   = <?=$percentage?> / 100;
        ring.style.strokeDashoffset = total * (1 - pct);
    }
});

// Filter cards by skill / result
function filterCards(btn, filter) {
    document.querySelectorAll('#filterBar .filter-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    document.querySelectorAll('.q-card').forEach(card => {
        const skill  = card.dataset.skill;
        const result = card.dataset.result;
        if (filter === 'all') { card.style.display = ''; return; }
        if (filter === 'correct') { card.style.display = result === 'correct' ? '' : 'none'; return; }
        if (filter === 'wrong')   { card.style.display = result !== 'correct' ? '' : 'none'; return; }
        card.style.display = skill === filter ? '' : 'none';
    });
}
</script>
</body>
</html>
