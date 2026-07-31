<?php
declare(strict_types=1);
require_once __DIR__ . '/config/koneksi.php';
apply_security_headers(true);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>EnglAI – Interactive English Learning Game</title>
<link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@400;700;900&family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<style>
/* ─── ROOT ─────────────────────────────────── */
:root{
  --bg0:#07071a;--bg1:#10103a;
  --glass:rgba(255,255,255,.06);--border:rgba(255,255,255,.12);
  --accent:#7c3aed;--accent2:#6366f1;--gold:#f59e0b;
  --ok:#10b981;--err:#ef4444;--warn:#f97316;
  --txt:rgba(255,255,255,.95);--muted:rgba(255,255,255,.55);
  --circ:188.5;
}
*{margin:0;padding:0;box-sizing:border-box}
html,body{height:100%;overflow:hidden;font-family:'Poppins',sans-serif;background:var(--bg0);color:var(--txt)}
/* ─── STARFIELD ─────────────────────────────── */
#stars{position:fixed;inset:0;pointer-events:none;z-index:0}
.s{position:absolute;border-radius:50%;background:#fff;animation:tw var(--d) ease-in-out infinite alternate}
@keyframes tw{0%{opacity:.1;transform:scale(1)}100%{opacity:1;transform:scale(1.8)}}
/* ─── AURORA ────────────────────────────────── */
.aurora{position:fixed;inset:0;pointer-events:none;z-index:0;overflow:hidden}
.aurora::before,.aurora::after{content:'';position:absolute;width:70vw;height:70vw;border-radius:50%;filter:blur(80px);opacity:.12;animation:drift 18s ease-in-out infinite alternate}
.aurora::before{background:radial-gradient(#7c3aed,#6366f1);top:-20%;left:-15%;animation-delay:0s}
.aurora::after{background:radial-gradient(#ec4899,#f59e0b);bottom:-20%;right:-15%;animation-delay:-9s}
@keyframes drift{0%{transform:translate(0,0) scale(1)}100%{transform:translate(5%,8%) scale(1.15)}}
/* ─── SCREENS ───────────────────────────────── */
.screen{position:fixed;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;
  z-index:10;opacity:0;pointer-events:none;transition:opacity .5s ease;overflow-y:auto;padding:20px}
.screen.on{opacity:1;pointer-events:all}
/* ─── SPLASH ────────────────────────────────── */
#splash{background:radial-gradient(ellipse at 50% 30%,#1a1050 0%,var(--bg0) 65%)}
.logo-wrap{text-align:center;animation:floatY 4s ease-in-out infinite}
@keyframes floatY{0%,100%{transform:translateY(0)}50%{transform:translateY(-18px)}}
.logo-ico{font-size:5rem;display:block;margin-bottom:12px;
  filter:drop-shadow(0 0 20px rgba(124,58,237,.8));animation:spin 20s linear infinite}
@keyframes spin{to{transform:rotate(360deg)}}
.logo-txt{font-family:'Orbitron',monospace;font-size:clamp(3rem,9vw,6.5rem);font-weight:900;letter-spacing:10px;
  background:linear-gradient(135deg,#818cf8,#a78bfa,#ec4899,#f59e0b);
  -webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text;
  filter:drop-shadow(0 0 30px rgba(124,58,237,.6))}
.logo-sub{font-size:clamp(.75rem,1.8vw,1rem);color:var(--muted);letter-spacing:4px;text-transform:uppercase;margin-top:6px}
.logo-tag{font-size:clamp(.9rem,2vw,1.2rem);color:rgba(255,255,255,.75);margin-top:18px;font-weight:300}
.unit-pills{display:flex;gap:12px;flex-wrap:wrap;justify-content:center;margin-top:24px}
.pill{padding:6px 18px;border-radius:24px;font-size:.8rem;
  background:rgba(124,58,237,.2);border:1px solid rgba(124,58,237,.45);color:rgba(255,255,255,.85)}
.splash-btns{display:flex;gap:14px;flex-wrap:wrap;justify-content:center;margin-top:38px}
.splash-credit{margin-top:28px;font-size:.75rem;color:rgba(255,255,255,.25)}
/* ─── BUTTONS ───────────────────────────────── */
.btn{display:inline-flex;align-items:center;gap:8px;padding:13px 30px;border:none;border-radius:50px;
  font-family:'Poppins',sans-serif;font-size:.95rem;font-weight:600;cursor:pointer;
  transition:all .25s ease;letter-spacing:.8px;text-decoration:none}
.btn:hover:not(:disabled){transform:translateY(-3px)}
.btn:disabled{opacity:.4;cursor:not-allowed;transform:none!important}
.btn-primary{background:linear-gradient(135deg,#7c3aed,#6366f1);color:#fff;box-shadow:0 4px 22px rgba(99,102,241,.45)}
.btn-primary:hover:not(:disabled){box-shadow:0 8px 32px rgba(99,102,241,.65)}
.btn-gold{background:linear-gradient(135deg,#f59e0b,#f97316);color:#fff;box-shadow:0 4px 22px rgba(245,158,11,.4)}
.btn-gold:hover:not(:disabled){box-shadow:0 8px 32px rgba(245,158,11,.65)}
.btn-ok{background:linear-gradient(135deg,#10b981,#059669);color:#fff;box-shadow:0 4px 18px rgba(16,185,129,.4)}
.btn-ghost{background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.2);color:#fff;backdrop-filter:blur(10px)}
.btn-ghost:hover:not(:disabled){background:rgba(255,255,255,.17)}
.btn-sm{padding:8px 18px;font-size:.82rem}
.btn-lg{padding:17px 44px;font-size:1.08rem}
/* ─── GLASS CARD ─────────────────────────────── */
.gc{background:var(--glass);border:1px solid var(--border);border-radius:20px;backdrop-filter:blur(18px);-webkit-backdrop-filter:blur(18px)}
/* ─── SETUP SCREEN ───────────────────────────── */
#setup{justify-content:flex-start;padding:0}
.setup-inner{width:100%;max-width:700px;margin:0 auto;padding:24px 20px 60px}
.setup-h{font-family:'Orbitron',monospace;font-size:1.7rem;font-weight:700;text-align:center;margin-bottom:24px;
  background:linear-gradient(135deg,#818cf8,#a78bfa);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text}
.sec{margin-bottom:22px}
.sec-lbl{display:block;font-size:.8rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:2px;margin-bottom:9px}
.fi{position:relative}
.fi input{width:100%;padding:13px 46px 13px 17px;background:rgba(255,255,255,.07);border:1px solid rgba(255,255,255,.15);
  border-radius:12px;color:#fff;font-family:'Poppins',sans-serif;font-size:.93rem;outline:none;transition:all .3s}
.fi input:focus{border-color:rgba(124,58,237,.7);background:rgba(124,58,237,.12);box-shadow:0 0 22px rgba(124,58,237,.25)}
.fi input::placeholder{color:rgba(255,255,255,.28)}
.fi-ico{position:absolute;right:14px;top:50%;transform:translateY(-50%);font-size:1.1rem;pointer-events:none;color:rgba(255,255,255,.38)}
.teams-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.team-wrap{position:relative}
.tdot{position:absolute;left:14px;top:50%;transform:translateY(-50%);width:11px;height:11px;border-radius:50%}
.team-wrap .fi input{padding-left:36px!important}
.unit-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:11px}
.uc{cursor:pointer;padding:16px;border-radius:14px;border:2px solid rgba(255,255,255,.1);
  background:rgba(255,255,255,.04);transition:all .3s;text-align:center}
.uc:hover{border-color:rgba(124,58,237,.5);background:rgba(124,58,237,.1)}
.uc.sel{border-color:#7c3aed;background:rgba(124,58,237,.22);box-shadow:0 0 22px rgba(124,58,237,.3)}
.uc-ico{font-size:2.2rem;margin-bottom:7px}
.uc-ttl{font-size:.82rem;font-weight:700}
.uc-dsc{font-size:.72rem;color:var(--muted);margin-top:3px}
.ns{display:flex;align-items:center;gap:14px}
.nb{width:38px;height:38px;border-radius:50%;border:1px solid rgba(255,255,255,.2);background:rgba(255,255,255,.08);
  color:#fff;font-size:1.1rem;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:all .25s}
.nb:hover{background:rgba(124,58,237,.35);border-color:#7c3aed}
.nd{font-family:'Orbitron',monospace;font-size:1.5rem;font-weight:700;color:#7c3aed;min-width:36px;text-align:center}
.tog-row{display:flex;align-items:center;justify-content:space-between;padding:9px 0}
.tog-lbl{font-size:.88rem;color:rgba(255,255,255,.8)}
.tog{position:relative;width:44px;height:24px;flex-shrink:0}
.tog input{opacity:0;width:0;height:0}
.ts{position:absolute;cursor:pointer;inset:0;background:rgba(255,255,255,.15);border-radius:24px;transition:.3s}
.ts::before{content:'';position:absolute;height:16px;width:16px;left:4px;bottom:4px;background:#fff;border-radius:50%;transition:.3s}
.tog input:checked+.ts{background:#7c3aed}
.tog input:checked+.ts::before{transform:translateX(20px)}
.div{height:1px;background:rgba(255,255,255,.08);margin:8px 0}
/* ─── GAME SCREEN ────────────────────────────── */
#game{padding:0;align-items:stretch;justify-content:flex-start;overflow:hidden}
.gc-wrap{display:flex;flex-direction:column;height:100vh;width:100%;max-width:920px;margin:0 auto;padding:12px;gap:10px}
.prog-wrap{height:4px;background:rgba(255,255,255,.07);border-radius:2px;overflow:hidden;flex-shrink:0}
.prog-fill{height:100%;background:linear-gradient(90deg,#7c3aed,#6366f1,#ec4899);border-radius:2px;transition:width .6s ease}
.scoreboard{display:flex;gap:7px;overflow-x:auto;flex-shrink:0;padding-bottom:4px}
.scoreboard::-webkit-scrollbar{height:3px}
.scoreboard::-webkit-scrollbar-thumb{background:rgba(255,255,255,.25);border-radius:3px}
.tsc{flex:1;min-width:95px;padding:9px 11px;border-radius:12px;border:2px solid transparent;
  background:rgba(255,255,255,.05);text-align:center;transition:all .35s;flex-shrink:0}
.tsc.cur{box-shadow:0 0 20px currentColor;border-color:currentColor}
.tsc-nm{font-size:.65rem;font-weight:700;opacity:.8;text-transform:uppercase;letter-spacing:.8px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.tsc-sc{font-family:'Orbitron',monospace;font-size:1.15rem;font-weight:700}
.tsc-st{font-size:.62rem;opacity:.65;min-height:14px}
.sbar{display:flex;align-items:center;justify-content:space-between;flex-shrink:0;padding:2px 4px}
.turn-lbl{font-size:.7rem;color:var(--muted);text-transform:uppercase;letter-spacing:2px}
.turn-nm{font-size:1rem;font-weight:700;margin-top:1px;transition:color .4s}
.rnd-lbl{font-size:.68rem;color:var(--muted);text-transform:uppercase;text-align:right}
.rnd-nm{font-family:'Orbitron',monospace;font-size:1rem;font-weight:700;color:var(--gold);text-align:right}
.q-area{flex:1;display:flex;flex-direction:column;gap:10px;overflow-y:auto;min-height:0}
.q-area::-webkit-scrollbar{width:4px}
.q-area::-webkit-scrollbar-thumb{background:rgba(255,255,255,.2);border-radius:4px}
.q-meta{display:flex;align-items:center;gap:9px;flex-wrap:wrap;flex-shrink:0}
.badge{padding:4px 12px;border-radius:20px;font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.8px}
.bg-gram{background:rgba(99,102,241,.3);color:#a5b4fc;border:1px solid rgba(99,102,241,.5)}
.bg-voc{background:rgba(16,185,129,.3);color:#6ee7b7;border:1px solid rgba(16,185,129,.5)}
.bg-fct{background:rgba(245,158,11,.3);color:#fcd34d;border:1px solid rgba(245,158,11,.5)}
.bg-rd{background:rgba(239,68,68,.3);color:#fca5a5;border:1px solid rgba(239,68,68,.5)}
.bg-pron{background:rgba(236,72,153,.3);color:#f9a8d4;border:1px solid rgba(236,72,153,.5)}
.bg-unit{background:rgba(255,255,255,.09);border:1px solid rgba(255,255,255,.18);color:rgba(255,255,255,.75)}
.dif-easy{background:rgba(16,185,129,.2);color:#6ee7b7}
.dif-med{background:rgba(245,158,11,.2);color:#fcd34d}
.dif-hard{background:rgba(239,68,68,.2);color:#fca5a5}
.qcard{padding:22px 24px;border-radius:18px}
.qtxt{font-size:clamp(.95rem,2.5vw,1.18rem);font-weight:500;line-height:1.65;color:rgba(255,255,255,.95)}
.speak-phrase{font-size:1.35rem;font-weight:600;line-height:1.6;color:#c7d2fe;margin:20px 0;padding:20px;background:rgba(124,58,237,.1);border-radius:16px;border:2px dashed rgba(124,58,237,.4);text-align:center}
.speak-btn{width:100%;max-width:420px;margin:30px auto 0;display:flex;align-items:center;justify-content:center;gap:12px;font-size:1.25rem;padding:22px 40px}
.opts{display:grid;grid-template-columns:1fr 1fr;gap:9px}
@media(max-width:480px){.opts{grid-template-columns:1fr}}
.opt{padding:13px 16px;border-radius:12px;border:1px solid rgba(255,255,255,.14);background:rgba(255,255,255,.05);
  color:rgba(255,255,255,.88);cursor:pointer;text-align:left;font-family:'Poppins',sans-serif;
  font-size:.88rem;transition:all .2s;display:flex;align-items:center;gap:11px;width:100%}
.opt:hover:not(:disabled){border-color:rgba(124,58,237,.65);background:rgba(124,58,237,.16);transform:translateX(4px)}
.opt.correct{border-color:#10b981!important;background:rgba(16,185,129,.25)!important;color:#6ee7b7!important;box-shadow:0 0 20px rgba(16,185,129,.4)!important}
.opt.wrong{border-color:#ef4444!important;background:rgba(239,68,68,.25)!important;color:#fca5a5!important}
.opt:disabled{cursor:not-allowed}
.opt-lt{width:27px;height:27px;border-radius:50%;background:rgba(255,255,255,.1);display:flex;
  align-items:center;justify-content:center;font-weight:700;font-size:.78rem;flex-shrink:0}
.exp-box{padding:13px 17px;border-radius:12px;background:rgba(124,58,237,.1);
  border:1px solid rgba(124,58,237,.3);font-size:.82rem;color:rgba(255,255,255,.8);
  line-height:1.55;display:none}
.exp-box.vis{display:block;animation:fadeIn .4s ease}
.load-q{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:16px;padding:40px;text-align:center;flex:1}
.spin-ring{width:58px;height:58px;border:3px solid rgba(124,58,237,.2);border-top-color:#7c3aed;border-radius:50%;animation:rotate 1s linear infinite}
@keyframes rotate{to{transform:rotate(360deg)}}
.load-txt{color:var(--muted);font-size:.88rem}
/* ─── TIMER ─────────────────────────────────── */
.timer-wrap{position:relative;width:66px;height:66px;flex-shrink:0}
.timer-svg{transform:rotate(-90deg)}
.tc-bg{fill:none;stroke:rgba(255,255,255,.09);stroke-width:4}
.tc-fg{fill:none;stroke:#7c3aed;stroke-width:4;stroke-linecap:round;
  stroke-dasharray:188.5;stroke-dashoffset:0;transition:stroke-dashoffset 1s linear,stroke .5s ease}
.tn{position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);
  font-family:'Orbitron',monospace;font-size:1.05rem;font-weight:700;color:#fff;transition:color .4s}
/* ─── POWER-UPS ──────────────────────────────── */
.pu-bar{display:flex;gap:8px;justify-content:center;flex-shrink:0;flex-wrap:wrap}
.pu{padding:6px 14px;border-radius:20px;border:1px solid rgba(255,255,255,.18);
  background:rgba(255,255,255,.07);color:#fff;font-size:.78rem;font-weight:600;
  cursor:pointer;display:flex;align-items:center;gap:6px;transition:all .2s;font-family:'Poppins',sans-serif}
.pu:hover:not(:disabled){background:rgba(255,255,255,.15);border-color:rgba(255,255,255,.35)}
.pu:disabled{opacity:.3;cursor:not-allowed}
/* ─── FEEDBACK OVERLAY ───────────────────────── */
#fbk{position:fixed;inset:0;display:flex;align-items:center;justify-content:center;
  z-index:200;opacity:0;pointer-events:none;transition:opacity .3s;background:rgba(0,0,0,.35);backdrop-filter:blur(4px)}
#fbk.show{opacity:1;pointer-events:all}
.fbk-box{text-align:center;padding:38px 44px;border-radius:26px;max-width:400px;width:90%}
.fbk-box.ok{background:radial-gradient(circle,rgba(16,185,129,.5),rgba(0,0,0,.9));border:2px solid rgba(16,185,129,.65);box-shadow:0 0 60px rgba(16,185,129,.5)}
.fbk-box.no{background:radial-gradient(circle,rgba(239,68,68,.5),rgba(0,0,0,.9));border:2px solid rgba(239,68,68,.65);box-shadow:0 0 60px rgba(239,68,68,.5)}
.fbk-ico{font-size:4rem;margin-bottom:12px;animation:popIn .4s cubic-bezier(.17,.67,.35,1.4)}
@keyframes popIn{0%{transform:scale(0)}80%{transform:scale(1.2)}100%{transform:scale(1)}}
.fbk-ttl{font-family:'Orbitron',monospace;font-size:1.4rem;font-weight:700}
.fbk-pts{font-size:.95rem;color:rgba(255,255,255,.8);margin-top:8px}
.fbk-next{font-size:.75rem;color:rgba(255,255,255,.45);margin-top:6px}
/* ─── RESULTS SCREEN ─────────────────────────── */
#results{background:radial-gradient(ellipse at 50% 25%,#1a1050 0%,var(--bg0) 60%);text-align:center}
.res-inner{width:100%;max-width:600px}
.crown{font-size:4.5rem;animation:bounceAlt 1s ease-in-out infinite alternate}
@keyframes bounceAlt{0%{transform:translateY(0) scale(1)}100%{transform:translateY(-14px) scale(1.08)}}
.win-ttl{font-family:'Orbitron',monospace;font-size:clamp(1.4rem,4vw,2.3rem);font-weight:900;
  background:linear-gradient(135deg,#f59e0b,#f97316);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text;margin:8px 0}
.win-nm{font-size:clamp(1.2rem,3vw,1.8rem);font-weight:700;margin-bottom:4px}
.win-sc{font-family:'Orbitron',monospace;font-size:.95rem;color:var(--muted)}
.lb{display:flex;flex-direction:column;gap:9px;margin:24px 0}
.lb-row{display:flex;align-items:center;padding:13px 18px;border-radius:13px;gap:13px}
.lb-rnk{font-family:'Orbitron',monospace;font-size:1.1rem;font-weight:700;width:30px;text-align:center}
.lb-rnk.g{color:#f59e0b}.lb-rnk.s{color:#94a3b8}.lb-rnk.b{color:#b45309}
.lb-dot{width:13px;height:13px;border-radius:50%;flex-shrink:0}
.lb-nm{flex:1;font-weight:600;text-align:left;font-size:.9rem}
.lb-rt{text-align:right}
.lb-sc{font-family:'Orbitron',monospace;font-size:.95rem;font-weight:700}
.lb-cr{font-size:.7rem;color:var(--muted)}
/* ─── MUSIC BTN ──────────────────────────────── */
#mbtn{position:fixed;top:14px;right:14px;z-index:300;width:40px;height:40px;border-radius:50%;
  background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.2);color:#fff;font-size:1rem;
  cursor:pointer;display:flex;align-items:center;justify-content:center;transition:all .3s;backdrop-filter:blur(10px)}
#mbtn:hover{background:rgba(255,255,255,.2);transform:scale(1.12)}
/* ─── CONFETTI ───────────────────────────────── */
#cnf{position:fixed;inset:0;pointer-events:none;z-index:400;overflow:hidden}
.cp{position:absolute;top:-20px;animation:fall linear forwards}
@keyframes fall{0%{transform:translateY(-20px) rotate(0deg);opacity:1}100%{transform:translateY(105vh) rotate(720deg);opacity:0}}
/* ─── ANIMATIONS ─────────────────────────────── */
@keyframes fadeIn{from{opacity:0;transform:translateY(12px)}to{opacity:1;transform:translateY(0)}}
@keyframes shake{0%,100%{transform:translateX(0)}20%{transform:translateX(-10px)}40%{transform:translateX(10px)}60%{transform:translateX(-7px)}80%{transform:translateX(7px)}}
.sl{animation:fadeIn .45s ease-out}
.shk{animation:shake .45s ease-out}
/* ─── SCROLLBAR ──────────────────────────────── */
::-webkit-scrollbar{width:5px;height:5px}
::-webkit-scrollbar-thumb{background:rgba(255,255,255,.2);border-radius:4px}
/* ─── RESPONSIVE ─────────────────────────────── */
@media(max-width:500px){
  .teams-grid{grid-template-columns:1fr}
  .unit-grid{grid-template-columns:1fr 1fr}
  .setup-inner{padding:20px 14px 80px}
  .modal-content{padding:20px;max-height:90vh}
}
/* ─── MODAL ──────────────────────────────────── */
.modal-overlay{position:fixed;inset:0;background:rgba(7,7,26,.85);backdrop-filter:blur(8px);-webkit-backdrop-filter:blur(8px);display:flex;align-items:center;justify-content:center;z-index:500;opacity:0;pointer-events:none;transition:opacity .3s ease;padding:20px}
.modal-overlay.open{opacity:1;pointer-events:all}
.modal-content{width:100%;max-width:520px;position:relative;padding:32px;max-height:85vh;overflow-y:auto;box-shadow:0 20px 50px rgba(0,0,0,.6),0 0 30px rgba(124,58,237,.25);border:1px solid rgba(255,255,255,.15);border-radius:24px;text-align:left}
.modal-close{position:absolute;top:16px;right:20px;background:none;border:none;color:var(--muted);font-size:1.8rem;cursor:pointer;transition:color .2s;line-height:1}
.modal-close:hover{color:var(--txt)}
.modal-header{display:flex;align-items:center;gap:12px;margin-bottom:20px;border-bottom:1px solid rgba(255,255,255,.1);padding-bottom:12px}
.modal-icon{font-size:1.8rem}
.modal-title{font-family:'Orbitron',monospace;font-size:1.3rem;font-weight:700;background:linear-gradient(135deg,#a78bfa,#f59e0b);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text}
.modal-body{display:flex;flex-direction:column;gap:18px}
.modal-section h3{font-size:.95rem;font-weight:700;color:var(--accent2);margin-bottom:8px;text-transform:uppercase;letter-spacing:1px}
.modal-section ul{list-style:none;padding-left:0}
.modal-section li{font-size:.85rem;color:rgba(255,255,255,.85);margin-bottom:5px;position:relative;padding-left:14px;line-height:1.4}
.modal-section li::before{content:"•";position:absolute;left:0;color:var(--gold)}
.modal-footer{margin-top:24px;display:flex;justify-content:flex-end}
</style>
<script src="assets/js/game-core.js"></script>
</head>
<body>
<div class="aurora"></div>
<div id="stars"></div>
<button id="mbtn" onclick="toggleMusic()" title="Toggle Music">🎵</button>
<div id="cnf"></div>

<!-- Modal Cara Bermain -->
<div class="modal-overlay" id="howToPlayModal" onclick="if(event.target === this) closeHowToPlay()">
  <div class="modal-content gc sl">
    <button class="modal-close" onclick="closeHowToPlay()" title="Close">&times;</button>
    <div class="modal-header">
      <span class="modal-icon">📖</span>
      <h2 class="modal-title">CARA BERMAIN EnglAI</h2>
    </div>
    <div class="modal-body">
      <div class="modal-section">
        <h3>🎮 Quiz Mode</h3>
        <ul>
          <li>Kelompok bergantian menjawab pertanyaan pilihan ganda.</li>
          <li>Soal dibuat secara otomatis oleh AI Gemini; fallback lokal tersedia.</li>
        </ul>
      </div>
      <div class="modal-section">
        <h3>🗣️ Speaking Mode</h3>
        <ul>
          <li>Kelompok bergantian mengucapkan kalimat dari Unit 1-3.</li>
          <li>Gunakan mic untuk merekam suara dengan browser Speech Recognition.</li>
          <li>Feedback menunjukkan kecocokan transkripsi, bukan diagnosis pronunciation.</li>
        </ul>
      </div>
      <div class="modal-section">
        <h3>🏆 Poin & Penentuan Juara</h3>
        <ul>
          <li>Juara ditentukan dari total skor tertinggi di akhir permainan!</li>
        </ul>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-primary btn-sm" onclick="closeHowToPlay()">Mengerti</button>
    </div>
  </div>
</div>
<div id="fbk">
  <div class="fbk-box" id="fbkBox">
    <div class="fbk-ico" id="fbkIco"></div>
    <div class="fbk-ttl" id="fbkTtl"></div>
    <div class="fbk-pts" id="fbkPts"></div>
    <div class="fbk-next" id="fbkNxt"></div>
  </div>
</div>
<div class="screen on" id="splash">
  <div class="logo-wrap">
    <span class="logo-ico">🌿</span>
    <div class="logo-txt">EnglAI</div>
    <div class="logo-sub">AI-Powered English Learning Game</div>
    <div class="logo-tag">Belajar Bahasa Inggris dengan Seru &amp; Kompetitif! 🚀</div>
    <div class="unit-pills">
      <span class="pill">🐒 Unit 1 · Bekantan</span>
      <span class="pill">🦧 Unit 2 · Orangutan &amp; Gorilla</span>
      <span class="pill">🦅 Unit 3 · Indonesian Birds</span>
    </div>
    <div class="splash-btns">
      <button class="btn btn-primary btn-lg" onclick="goSetup()">🎮 Mulai Quiz Game</button>
      <button class="btn btn-gold btn-lg" onclick="startSpeakingMode()">🗣️ Latihan Pronunciation</button>
      <button class="btn btn-ghost" onclick="howToPlay()">📖 Cara Bermain</button>
    </div>
    <p class="splash-credit"><h1>SMP Plus Assa'adah · Bahasa Inggris Kelas IX · 2026</h1></p>
  </div>
</div>
<div class="screen" id="setup">
  <div class="setup-inner">
    <button class="btn btn-ghost btn-sm" onclick="go('splash')" style="margin-bottom:14px">← Kembali</button>
    <h2 class="setup-h">⚙️ Pengaturan Game</h2>
    <div class="sec">
      <span class="sec-lbl">👥 Jumlah Kelompok</span>
      <div class="ns"><button class="nb" onclick="chgTeam(-1)">−</button><span class="nd" id="tcnt">4</span><button class="nb" onclick="chgTeam(1)">+</button></div>
    </div>
    <div class="sec">
      <span class="sec-lbl">✏️ Nama Kelompok</span>
      <div class="teams-grid" id="tgrid"></div>
    </div>
    <div class="div"></div>
    <div class="sec">
      <span class="sec-lbl">📚 Pilih Unit Materi</span>
      <div class="unit-grid">
        <div class="uc sel" data-u="1" onclick="togUnit(1)"><div class="uc-ico">🐒</div><div class="uc-ttl">Unit 1</div><div class="uc-dsc">Bekantan</div></div>
        <div class="uc sel" data-u="2" onclick="togUnit(2)"><div class="uc-ico">🦧</div><div class="uc-ttl">Unit 2</div><div class="uc-dsc">Orangutan &amp; Gorilla</div></div>
        <div class="uc sel" data-u="3" onclick="togUnit(3)"><div class="uc-ico">🦅</div><div class="uc-ttl">Unit 3</div><div class="uc-dsc">Indonesian Birds</div></div>
      </div>
    </div>
    <div class="sec">
      <span class="sec-lbl">🎯 Total Pertanyaan / Latihan</span>
      <div class="ns"><button class="nb" onclick="chgRnd(-5)">−</button><span class="nd" id="rcnt">20</span><button class="nb" onclick="chgRnd(5)">+</button></div>
    </div>
    <div class="sec gc" style="padding:14px 20px">
      <div class="tog-row"><span class="tog-lbl">🎵 Musik Background</span><label class="tog"><input type="checkbox" id="tgMusic" checked><span class="ts"></span></label></div>
      <div class="tog-row"><span class="tog-lbl">⚡ Power-ups (hanya Quiz)</span><label class="tog"><input type="checkbox" id="tgPU" checked><span class="ts"></span></label></div>
      <div class="tog-row"><span class="tog-lbl">⏱️ Timer (30 detik - hanya Quiz)</span><label class="tog"><input type="checkbox" id="tgTimer" checked><span class="ts"></span></label></div>
    </div>
    <div style="text-align:center;margin-top:28px;margin-bottom:20px">
      <button class="btn btn-gold btn-lg" onclick="startGame()">🏆 Mulai Quiz Game!</button>
    </div>
  </div>
</div>
<div class="screen" id="game">
  <div class="gc-wrap">
    <div class="prog-wrap"><div class="prog-fill" id="prog" style="width:0%"></div></div>
    <div class="scoreboard" id="sb"></div>
    <div class="sbar">
      <div>
        <div class="turn-lbl">Giliran</div>
        <div class="turn-nm" id="turnNm">–</div>
      </div>
      <div style="display:flex;align-items:center;gap:14px">
        <div class="timer-wrap" id="timerEl">
          <svg class="timer-svg" width="66" height="66" viewBox="0 0 66 66">
            <circle class="tc-bg" cx="33" cy="33" r="30"/>
            <circle class="tc-fg" id="tcFg" cx="33" cy="33" r="30"/>
          </svg>
          <div class="tn" id="tnNum">30</div>
        </div>
        <div>
          <div class="rnd-lbl">Ronde</div>
          <div class="rnd-nm" id="rndDisp">1/20</div>
        </div>
      </div>
    </div>
    <div class="q-area" id="qarea">
      <div class="load-q"><div class="spin-ring"></div><div class="load-txt">Memuat game…</div></div>
    </div>
    <div class="pu-bar" id="puBar" style="display:none">
      <button class="pu" id="pu50" onclick="usePU('50')">⚡ 50/50 <span id="pu50c">(2)</span></button>
      <button class="pu" id="puT" onclick="usePU('t')">⏰ +15 detik <span id="puTc">(2)</span></button>
      <button class="pu" id="puSk" onclick="usePU('sk')">⏭️ Skip <span id="puSkc">(1)</span></button>
    </div>
  </div>
</div>
<div class="screen" id="results">
  <div class="res-inner">
    <div class="crown">👑</div>
    <div class="win-ttl">🎉 PEMENANG! 🎉</div>
    <div class="win-nm" id="winNm">–</div>
    <div class="win-sc" id="winSc">0 poin</div>
    <div class="lb" id="lbList"></div>
    <div style="display:flex;gap:13px;justify-content:center;flex-wrap:wrap;margin-top:10px">
      <button class="btn btn-gold btn-lg" onclick="goSetup()">🔄 Main Lagi</button>
      <button class="btn btn-ghost" onclick="go('splash')">🏠 Beranda</button>
    </div>
    <p style="margin-top:18px;font-size:.72rem;color:rgba(255,255,255,.22)">Powered by Google Gemini 2.5 Flash AI 🤖 • Modul Speaking Terpisah</p>
  </div>
</div>
<script>
/* ═══════════════════════════════════════
   CONSTANTS
═══════════════════════════════════════ */
const COLORS=['#ef4444','#3b82f6','#10b981','#f59e0b','#8b5cf6','#ec4899'];
const TMAX=30, CIRC=188.5;
const CAT_CLASS={'Grammar':'bg-gram','Vocabulary':'bg-voc','Facts':'bg-fct','Reading':'bg-rd','Reading Comprehension':'bg-rd','Pronunciation':'bg-pron'};
const DIF_CLASS={'easy':'dif-easy','medium':'dif-med','hard':'dif-hard'};
const ICONS={1:'🐒',2:'🦧',3:'🦅'};
function node(tag,className='',text=null){
  const element=document.createElement(tag);
  if(className)element.className=className;
  if(text!==null)element.textContent=String(text);
  return element;
}

/* ═══════════════════════════════════════
   MUSIC ENGINE
═══════════════════════════════════════ */
const MX={ 
  ctx:null,mg:null,on:false,bpm:140,
  init(){if(this.ctx)return;this.ctx=new(window.AudioContext||window.webkitAudioContext)();this.mg=this.ctx.createGain();this.mg.gain.value=.28;this.mg.connect(this.ctx.destination)},
  osc(type,freq,t,dur,vol=.15){if(!this.ctx)return;const o=this.ctx.createOscillator(),g=this.ctx.createGain();o.type=type;o.frequency.value=freq;g.gain.setValueAtTime(vol,t);g.gain.exponentialRampToValueAtTime(.001,t+dur);o.connect(g);g.connect(this.mg);o.start(t);o.stop(t+dur+.01)},
  noise(t,dur,vol=.08){if(!this.ctx)return;const buf=this.ctx.createBuffer(1,Math.ceil(this.ctx.sampleRate*dur),this.ctx.sampleRate),d=buf.getChannelData(0);for(let i=0;i<d.length;i++)d[i]=Math.random()*2-1;const s=this.ctx.createBufferSource(),g=this.ctx.createGain(),f=this.ctx.createBiquadFilter();s.buffer=buf;f.type='bandpass';f.frequency.value=2200;g.gain.setValueAtTime(vol,t);g.gain.exponentialRampToValueAtTime(.001,t+dur);s.connect(f);f.connect(g);g.connect(this.mg);s.start(t);s.stop(t+dur+.01)},
  kick(t){if(!this.ctx)return;const o=this.ctx.createOscillator(),g=this.ctx.createGain();o.type='sine';o.frequency.setValueAtTime(140,t);o.frequency.exponentialRampToValueAtTime(35,t+.18);g.gain.setValueAtTime(.55,t);g.gain.exponentialRampToValueAtTime(.001,t+.28);o.connect(g);g.connect(this.mg);o.start(t);o.stop(t+.3)},
  get bl(){return 60/this.bpm},
  melody:[[659,1],[784,1],[880,.5],[784,.5],[659,1],[587,.5],[659,.5],[523,1],[659,1],[784,1],[659,.5],[523,.5],[880,.5],[784,.5],[659,.5],[587,.5],[523,1],[587,.5],[659,.5],[784,2],[659,.5],[523,.5],[587,1],[440,.5],[523,.5],[659,.5],[784,.5],[880,.5],[784,.5],[659,.5],[523,.5],[587,1],[659,.5],[784,.5],[880,2],[659,.5],[784,.5],[880,1],[784,.5],[659,.5],[587,1],[523,2],[440,1],[523,1]],
  bass:[[110,2],[82,2],[73,2],[98,2],[110,2],[82,2],[73,2],[65,2],[110,2],[82,2],[73,2],[98,2],[82,2],[110,4],[55,2]],
  loop(t){if(!this.on)return;const bl=this.bl;let mt=t,bt=t;for(const[f,b]of this.melody){this.osc('square',f,mt,b*bl*.82,.1);mt+=b*bl}for(const[f,b]of this.bass){this.osc('sawtooth',f,bt,b*bl*.75,.18);bt+=b*bl}const total=mt-t;for(let i=0;i<Math.floor(total/bl);i++){const bt2=t+i*bl;if(i%4===0||i%4===2)this.kick(bt2);if(i%4===2)this.noise(bt2,.12,.14);this.noise(bt2,.04,.05);if(i%2===1)this.noise(bt2+bl*.5,.04,.04)}setTimeout(()=>{if(this.on)this.loop(t+total-.2);},(total-.35)*1000)},
  start(){this.init();if(this.on)return;this.on=true;if(this.ctx.state==='suspended')this.ctx.resume();this.loop(this.ctx.currentTime+.1)},
  stop(){this.on=false;if(this.mg){this.mg.gain.setTargetAtTime(0,this.ctx.currentTime,.1);setTimeout(()=>{if(this.mg)this.mg.gain.value=.28},600)}},
  sfxOk(){this.init();if(this.ctx.state==='suspended')this.ctx.resume();const t=this.ctx.currentTime;[523,659,784,1047].forEach((f,i)=>this.osc('square',f,t+i*.09,.14,.18))},
  sfxNo(){this.init();if(this.ctx.state==='suspended')this.ctx.resume();const t=this.ctx.currentTime;this.osc('sawtooth',220,t,.12,.3);this.osc('sawtooth',160,t+.12,.18,.3)},
  sfxTick(){this.init();if(this.ctx.state==='suspended')this.ctx.resume();this.osc('sine',900,this.ctx.currentTime,.03,.12)},
  sfxWin(){this.init();if(this.ctx.state==='suspended')this.ctx.resume();const t=this.ctx.currentTime;[523,659,784,1047,1319].forEach((f,i)=>{this.osc('square',f,t+i*.11,.18,.2);this.osc('square',f*1.5,t+i*.11,.14,.08)})}
};

/* ═══════════════════════════════════════
   STARS
═══════════════════════════════════════ */
(function(){
  const c=document.getElementById('stars');
  for(let i=0;i<90;i++){
    const s=document.createElement('div');s.className='s';
    s.style.cssText=`left:${Math.random()*100}%;top:${Math.random()*100}%;width:${Math.random()*2+1}px;height:${s.style.width};--d:${Math.random()*3+1.5}s;animation-delay:${Math.random()*4}s`;
    c.appendChild(s);
  }
})();

/* ═══════════════════════════════════════
   GAME STATE
═══════════════════════════════════════ */
let G={
  teams:[],scores:{},streaks:{},correct:{},
  tidx:0,rnd:0,total:20,units:[1,2,3],
  pu:{},timer:true,puOn:true,musicOn:true,
  active:false,curQ:null,tmrId:null,left:TMAX,
  done:false,hist:[], mode:'quiz',guard:EnglAIGameCore.createRoundGuard()
};
let tcnt=4,rcnt=20;

/* ═══════════════════════════════════════
   SCREEN NAV
═══════════════════════════════════════ */
function go(id){document.querySelectorAll('.screen').forEach(s=>s.classList.remove('on'));document.getElementById(id).classList.add('on')}
function goSetup(){buildTeamGrid();go('setup')}

/* ═══════════════════════════════════════
   SETUP HELPERS
═══════════════════════════════════════ */
function chgTeam(d){tcnt=Math.max(2,Math.min(6,tcnt+d));document.getElementById('tcnt').textContent=tcnt;buildTeamGrid()}
function chgRnd(d){rcnt=Math.max(10,Math.min(50,rcnt+d));document.getElementById('rcnt').textContent=rcnt}
function buildTeamGrid(){
  const g=document.getElementById('tgrid');g.replaceChildren();
  const def=['Kelompok A','Kelompok B','Kelompok C','Kelompok D','Kelompok E','Kelompok F'];
  for(let i=0;i<tcnt;i++){
    const w=node('div','team-wrap');
    const dot=node('div','tdot');dot.style.background=COLORS[i];
    const field=node('div','fi');
    const input=node('input');input.type='text';input.id=`tn${i}`;input.value=def[i];input.placeholder=`Nama kelompok ${i+1}`;input.maxLength=18;
    field.appendChild(input);w.append(dot,field);
    g.appendChild(w);
  }
}
function togUnit(u){
  const el=document.querySelector(`[data-u="${u}"]`);
  const i=G.units.indexOf(u);
  if(i>-1){if(G.units.length<=1)return;G.units.splice(i,1);el.classList.remove('sel')}
  else{G.units.push(u);el.classList.add('sel')}
}
function howToPlay(){
  document.getElementById('howToPlayModal').classList.add('open');
}
function closeHowToPlay(){
  document.getElementById('howToPlayModal').classList.remove('open');
}

/* ═══════════════════════════════════════
   START GAME (Quiz)
═══════════════════════════════════════ */
function startGame(){
  G.mode = 'quiz';
  G.teams=[];G.scores={};G.streaks={};G.correct={};G.pu={};G.hist=[];
  const teamNames=EnglAIGameCore.uniqueTeamNames(Array.from({length:tcnt},(_,i)=>document.getElementById(`tn${i}`)?.value));
  for(let i=0;i<tcnt;i++){
    const nm=teamNames[i];
    G.teams.push({nm,cl:COLORS[i]});
    G.scores[nm]=0;G.streaks[nm]=0;G.correct[nm]=0;
    G.pu[nm]={'50':2,t:2,sk:1};
  }
  G.tidx=0;G.rnd=0;G.total=rcnt;G.active=true;
  G.timer=document.getElementById('tgTimer').checked;
  G.puOn=document.getElementById('tgPU').checked;
  G.musicOn=document.getElementById('tgMusic').checked;
  buildSB();
  if(G.musicOn){MX.start();document.getElementById('mbtn').textContent='🎵'}
  go('game');
  nextTurn();
}

/* ═══════════════════════════════════════
   START SPEAKING MODE 
═══════════════════════════════════════ */
function startSpeakingMode(){
  G.mode = 'speaking';
  G.teams=[];G.scores={};G.streaks={};G.correct={};G.pu={};G.hist=[];
  const teamNames=EnglAIGameCore.uniqueTeamNames(Array.from({length:tcnt},(_,i)=>document.getElementById(`tn${i}`)?.value));
  for(let i=0;i<tcnt;i++){
    const nm=teamNames[i];
    G.teams.push({nm,cl:COLORS[i]});
    G.scores[nm]=0;G.streaks[nm]=0;G.correct[nm]=0;
    G.pu[nm]={'50':2,t:2,sk:1};
  }
  G.tidx=0;G.rnd=0;G.total=rcnt;G.active=true;
  G.timer=false;           // no timer in speaking
  G.puOn=false;            // no power-ups in speaking
  G.musicOn=document.getElementById('tgMusic').checked;
  buildSB();
  if(G.musicOn){MX.start();document.getElementById('mbtn').textContent='🎵'}
  go('game');
  nextTurn();
}

/* ═══════════════════════════════════════
   SCOREBOARD
═══════════════════════════════════════ */
function buildSB(){
  const sb=document.getElementById('sb');sb.replaceChildren();
  G.teams.forEach((t,i)=>{
    const d=node('div','tsc'+(i===G.tidx?' cur':''));d.id=`tsc${i}`;d.style.color=t.cl;
    const name=node('div','tsc-nm',t.nm);
    const score=node('div','tsc-sc','0');score.id=`tss${i}`;
    const streak=node('div','tsc-st');streak.id=`tst${i}`;
    d.append(name,score,streak);
    sb.appendChild(d);
  });
}
function updSB(){
  G.teams.forEach((t,i)=>{
    document.getElementById(`tss${i}`).textContent=G.scores[t.nm].toLocaleString();
    const sk=G.streaks[t.nm];
    document.getElementById(`tst${i}`).textContent=sk>1?`🔥${sk}×`:'';
    document.getElementById(`tsc${i}`).classList.toggle('cur',i===G.tidx);
  });
}

/* ═══════════════════════════════════════
   FETCH QUESTION / SPEAKING TASK
═══════════════════════════════════════ */
async function fetchGeneratedTask(mode){
  const u=G.units[Math.floor(Math.random()*G.units.length)];
  const prog=G.rnd/G.total;
  const dif=prog<.3?'easy':prog<.7?'medium':'hard';
  const res=await fetch('api/generate_question.php',{
    method:'POST',headers:{'Content-Type':'application/json'},
    body:JSON.stringify({mode,difficulty:dif,unit:u})
  });
  const payload=await res.json();
  if(!res.ok||!payload.success) throw new Error(payload.message||'Materi tidak dapat dibuat.');
  if(payload.source==='fallback'&&payload.warning){
    console.warn(payload.warning);
  }
  return payload.data;
}
async function fetchQ(){return fetchGeneratedTask('quiz')}
async function fetchSpeaking(){return fetchGeneratedTask('speaking')}

/* ═══════════════════════════════════════
   NEXT TURN 
═══════════════════════════════════════ */
async function nextTurn(){
  if(G.rnd>=G.total){endGame();return}
  G.rnd++;G.done=false;G.guard.reset();clrTimer();
  const team=G.teams[G.tidx];
  document.getElementById('turnNm').textContent=team.nm;
  document.getElementById('turnNm').style.color=team.cl;
  document.getElementById('rndDisp').textContent=`${G.rnd}/${G.total}`;
  document.getElementById('prog').style.width=((G.rnd-1)/G.total*100)+'%';
  updSB();
  
  if(G.mode==='quiz' && G.puOn){
    document.getElementById('puBar').style.display='flex';
    updPU();
  } else {
    document.getElementById('puBar').style.display='none';
  }
  
  showLoad();
  let task;
  try{
    if(G.mode==='quiz') {
      task = await fetchQ(); 
    } else {
      task = await fetchSpeaking();
    }
  } catch(error){
    console.error('AI and backend fallback unavailable.', error);
    G.rnd--;
    const wrapper=node('div','load-q');
    const text=node('div','load-txt','Materi belum dapat dibuat.');
    const retry=node('button','btn btn-primary','Coba Lagi');retry.addEventListener('click',nextTurn);
    text.appendChild(retry);wrapper.appendChild(text);document.getElementById('qarea').replaceChildren(wrapper);
    return;
  }
  
  G.curQ=task;
  G.hist.push(G.mode==='quiz'? {q:task.q} : {phrase:task.phrase});
  showTask(task);
  
  if(G.mode==='quiz' && G.timer) startTimer();
}

/* ═══════════════════════════════════════
   SHOW LOAD & TASK
═══════════════════════════════════════ */
function showLoad(){
  const team=G.teams[G.tidx];
  const text=G.mode==='quiz'?`AI menyiapkan pertanyaan untuk ${team.nm}…`:`Menyiapkan latihan speaking untuk ${team.nm}…`;
  const wrapper=node('div','load-q');
  wrapper.append(node('div','spin-ring'),node('div','load-txt',text));
  document.getElementById('qarea').replaceChildren(wrapper);
}

function showTask(task){
  if(G.mode==='quiz'){
    showQ(task);
  } else {
    showSpeakingTask(task);
  }
}

function showQ(q){ 
  const cc=CAT_CLASS[q.cat]||'bg-gram';
  const dc=DIF_CLASS[q.dif]||'dif-med';
  const ltrs=['A','B','C','D'];
  const meta=node('div','q-meta sl');
  meta.append(node('span',`badge ${cc}`,q.cat),node('span','badge bg-unit',`${ICONS[q.u]||'📚'} Unit ${q.u}`),node('span',`badge ${dc}`,q.dif));
  const card=node('div','gc qcard sl');card.style.animationDelay='.05s';card.appendChild(node('div','qtxt',q.q));
  const options=node('div','opts sl');options.id='optsWrap';options.style.animationDelay='.1s';
  q.op.forEach((option,i)=>{
    const button=node('button','opt sl');button.dataset.l=ltrs[i];button.style.animationDelay=`${.05+i*.07}s`;button.addEventListener('click',()=>pick(ltrs[i]));
    button.append(node('span','opt-lt',ltrs[i]),node('span','',String(option).replace(/^[A-D]\.\s*/,'')));
    options.appendChild(button);
  });
  const explanation=node('div','exp-box');explanation.id='expBox';
  explanation.append(node('b','','Penjelasan: '),document.createTextNode(q.exp||''));
  document.getElementById('qarea').replaceChildren(meta,card,options,explanation);
}

function showSpeakingTask(task){
  const dc=DIF_CLASS[task.dif]||'dif-med';
  const meta=node('div','q-meta sl');
  meta.append(node('span','badge bg-pron','🗣️ Speaking'),node('span','badge bg-unit',`${ICONS[task.u]||'📚'} Unit ${task.u}`),node('span',`badge ${dc}`,task.dif));
  const card=node('div','gc qcard sl');card.style.animationDelay='.05s';
  const tips=node('div');tips.style.fontSize='.9rem';tips.style.color='var(--muted)';tips.append(node('b','','Tips: '),document.createTextNode(task.tips||'Ucapkan dengan jelas'));
  card.append(node('div','qtxt','Ucapkan kalimat berikut dengan jelas:'),node('div','speak-phrase',task.phrase),tips);
  const controls=node('div');controls.style.textAlign='center';controls.style.marginTop='30px';
  const button=node('button','btn btn-primary speak-btn','🗣️ Mulai Berbicara — dinilai dari kecocokan transkripsi');button.addEventListener('click',startPronunciation);controls.appendChild(button);
  const result=node('div');result.id='speakResult';result.style.display='none';result.style.marginTop='20px';
  document.getElementById('qarea').replaceChildren(meta,card,controls,result);
}

/* ═══════════════════════════════════════
   PICK ANSWER (Quiz only)
═══════════════════════════════════════ */
function pick(l){ 
  if(!G.guard.resolve())return;G.done=true;clrTimer();
  const q=G.curQ,ok=l===q.ans;
  const tm=G.teams[G.tidx].nm;
  document.querySelectorAll('.opt').forEach((b,i)=>{
    b.disabled=true;
    const bl=['A','B','C','D'][i];
    if(bl===q.ans)b.classList.add('correct');
    else if(bl===l&&!ok)b.classList.add('wrong');
  });
  document.getElementById('expBox')?.classList.add('vis');
  let pts=0,msg='';
  if(ok){
    G.streaks[tm]++;
    pts=EnglAIGameCore.quizScore({correct:true,difficulty:q.dif,timeLeft:G.left,timeMax:TMAX,streak:G.streaks[tm],timerEnabled:G.timer});
    G.scores[tm]+=pts;G.correct[tm]++;
    msg=`+${pts} poin`;
    MX.sfxOk();confetti();
  } else {
    G.streaks[tm]=0;
    msg=`Jawaban benar: ${q.ans}`;
    MX.sfxNo();
    document.getElementById('optsWrap')?.classList.add('shk');
  }
  updSB();
  showFbk(ok,tm,pts,msg,q.ans);
  setTimeout(()=>{hideFbk();G.tidx=(G.tidx+1)%G.teams.length;nextTurn()},2600);
}

/* ═══════════════════════════════════════
   SPEAKING PRONUNCIATION MODULE
═══════════════════════════════════════ */
function startPronunciation(){
  if(!G.guard.resolve()) return;
  G.done = true;
  const resultDiv = document.getElementById('speakResult');
  resultDiv.style.display = 'block';
  const listening=node('div','load-q');listening.style.padding='30px';
  const spinner=node('div','spin-ring');spinner.style.width='48px';spinner.style.height='48px';
  listening.append(spinner,node('div','load-txt','🎙️ Sedang mendengarkan. Ucapkan kalimat dengan jelas.'));
  resultDiv.replaceChildren(listening);

  const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
  if(!SpeechRecognition){
    const warning=node('div','gc','Browser Anda tidak mendukung Speech Recognition. Gunakan Chrome/Edge.');
    warning.style.padding='20px';warning.style.color='#ef4444';resultDiv.replaceChildren(warning);
    setTimeout(()=>{G.tidx=(G.tidx+1)%G.teams.length;nextTurn()},2000);
    return;
  }

  const recognition = new SpeechRecognition();
  recognition.lang = 'en-US';
  recognition.interimResults = false;
  recognition.maxAlternatives = 1;

  recognition.onresult = (event) => {
    const transcript = event.results[0][0].transcript;
    const confidence = event.results[0][0].confidence || 0.8;
    evaluatePronunciation(transcript, G.curQ.phrase, confidence);
  };

  recognition.onerror = (event) => {
    const warning=node('div','gc',`Error mic: ${event.error}. Coba lagi.`);
    warning.style.padding='20px';warning.style.color='#ef4444';resultDiv.replaceChildren(warning);
    setTimeout(()=>{G.tidx=(G.tidx+1)%G.teams.length;nextTurn()},1800);
  };

  recognition.onend = () => {
    if(!G.done) recognition.start(); 
  };

  recognition.start();
}

function levenshtein(a, b) {
  const matrix = Array.from({length: b.length + 1}, () => Array(a.length + 1).fill(0));
  for (let i = 0; i <= b.length; i++) matrix[i][0] = i;
  for (let j = 0; j <= a.length; j++) matrix[0][j] = j;
  for (let i = 1; i <= b.length; i++) {
    for (let j = 1; j <= a.length; j++) {
      if (b.charAt(i - 1) === a.charAt(j - 1)) {
        matrix[i][j] = matrix[i - 1][j - 1];
      } else {
        matrix[i][j] = Math.min(matrix[i - 1][j - 1] + 1, matrix[i][j - 1] + 1, matrix[i - 1][j] + 1);
      }
    }
  }
  return matrix[b.length][a.length];
}

function evaluatePronunciation(transcript, target, confidence){
  const score = EnglAIGameCore.speakingScore(transcript,target,confidence);

  const tm = G.teams[G.tidx].nm;
  const pts = Math.max(0, Math.round(score * 1.8));
  G.scores[tm] += pts;
  if(score>=70){
    G.streaks[tm]++;
    G.correct[tm]++;
  }else{
    G.streaks[tm]=0;
  }

  const resultDiv = document.getElementById('speakResult');
  const card=node('div','gc');card.style.padding='22px';
  const label=node('div','','Kamu mengucapkan:');label.style.fontSize='1.1rem';label.style.marginBottom='12px';
  const spoken=node('div','',`"${transcript}"`);spoken.style.background='rgba(255,255,255,.1)';spoken.style.padding='14px';spoken.style.borderRadius='12px';spoken.style.fontSize='1.15rem';
  const scoreNode=node('div','',`Skor kecocokan transkripsi: ${score}/100`);scoreNode.style.margin='18px 0';scoreNode.style.fontSize='2.4rem';scoreNode.style.fontWeight='700';scoreNode.style.color=score>=75?'#10b981':'#f59e0b';
  const points=node('div','',`+${pts} poin untuk ${tm}`);points.style.color='#10b981';points.style.fontWeight='600';
  card.append(label,spoken,scoreNode,points);
  if(G.curQ.tips){const tips=node('div','',`Tips: ${G.curQ.tips}`);tips.style.marginTop='14px';tips.style.fontSize='.9rem';card.appendChild(tips)}
  resultDiv.replaceChildren(card);

  updSB();
  MX.sfxOk();
  if(score>=70) confetti();
  showFbk(true, tm, pts, `Transcription similarity: ${score}`, '');
  setTimeout(()=>{hideFbk();G.tidx=(G.tidx+1)%G.teams.length;nextTurn()},2800);
}

/* ═══════════════════════════════════════
   TIMER SYSTEM (DIKEMBALIKAN & DIPERBAIKI)
═══════════════════════════════════════ */
function startTimer(){
  clrTimer();
  G.left = TMAX;
  if(!G.timer){
    document.getElementById('timerEl').style.display='none';
    return;
  }
  document.getElementById('timerEl').style.display='block';
  updTimerUI();
  G.tmrId = setInterval(updTimer, 1000);
}

function clrTimer(){
  if(G.tmrId) clearInterval(G.tmrId);
}

function updTimer(){
  G.left--;
  updTimerUI();
  if(G.left <= 0){
    clrTimer();
    timeOut();
  }
}

function updTimerUI(){
  const tn = document.getElementById('tnNum');
  const fg = document.getElementById('tcFg');
  if(!tn || !fg) return;
  
  tn.textContent = G.left;
  // Kalkulasi stroke dashoffset berdasarkan 188.5
  const offset = CIRC - (G.left / TMAX) * CIRC;
  fg.style.strokeDashoffset = offset;

  if(G.left <= 5){
    fg.style.stroke = '#ef4444';
    tn.style.color = '#ef4444';
    MX.sfxTick();
  } else if(G.left <= 15){
    fg.style.stroke = '#f59e0b';
    tn.style.color = '#f59e0b';
  } else {
    fg.style.stroke = '#7c3aed';
    tn.style.color = '#fff';
  }
}

function timeOut(){
  if(!G.guard.resolve()) return;
  G.done = true;
  const q = G.curQ;
  const tm = G.teams[G.tidx].nm;
  
  G.streaks[tm] = 0; 

  document.querySelectorAll('.opt').forEach((b,i)=>{
    b.disabled = true;
    const bl = ['A','B','C','D'][i];
    if(bl === q.ans) b.classList.add('correct');
  });
  document.getElementById('expBox')?.classList.add('vis');

  MX.sfxNo();
  document.getElementById('optsWrap')?.classList.add('shk');
  updSB();
  
  showFbk(false, tm, 0, `Waktu Habis! Jawaban: ${q.ans}`, q.ans);
  setTimeout(()=>{hideFbk();G.tidx=(G.tidx+1)%G.teams.length;nextTurn()}, 2600);
}

/* ═══════════════════════════════════════
   POWER-UPS, FEEDBACK, CONFETTI, MUSIC
═══════════════════════════════════════ */
function updPU(){ 
  if(!G.puOn || G.mode !== 'quiz') return;
  const team = G.teams[G.tidx].nm;
  const p = G.pu[team];
  
  document.getElementById('pu50c').textContent = `(${p['50']})`;
  document.getElementById('pu50').disabled = p['50'] <= 0 || G.done;
  
  document.getElementById('puTc').textContent = `(${p.t})`;
  document.getElementById('puT').disabled = p.t <= 0 || G.done;
  
  document.getElementById('puSkc').textContent = `(${p.sk})`;
  document.getElementById('puSk').disabled = p.sk <= 0 || G.done;
}

function usePU(type){ 
  if(G.done||G.guard.isResolved()) return;
  const team = G.teams[G.tidx].nm;
  if(G.pu[team][type] <= 0) return;
  
  G.pu[team][type]--;
  updPU();

  if(type === '50'){
    const q = G.curQ;
    const wrongIdx = [];
    ['A','B','C','D'].forEach((l,i) => { if(l !== q.ans) wrongIdx.push(i); });
    wrongIdx.sort(() => Math.random() - 0.5);
    const hide = wrongIdx.slice(0, 2);
    
    const opts = document.querySelectorAll('.opt');
    hide.forEach(i => {
      opts[i].style.visibility = 'hidden';
      opts[i].disabled = true;
    });
    MX.sfxTick();
  } 
  else if(type === 't'){
    G.left += 15;
    if(G.left > TMAX) G.left = TMAX; 
    updTimerUI();
    MX.sfxTick();
  } 
  else if(type === 'sk'){
    if(!G.guard.resolve())return;
    G.done = true;
    clrTimer();
    const pts = 0;
    updSB();
    MX.sfxNo();
    showFbk(false, team, pts, 'Dilewati: 0 poin', G.curQ.ans);
    setTimeout(()=>{hideFbk();G.tidx=(G.tidx+1)%G.teams.length;nextTurn()}, 2000);
  }
}

function showFbk(ok, nm, pts, msg, correctAns){ 
  const fbk = document.getElementById('fbk');
  const box = document.getElementById('fbkBox');
  fbk.classList.add('show');
  
  box.className = 'fbk-box ' + (ok ? 'ok' : 'no');
  document.getElementById('fbkIco').textContent = ok ? '✅' : '❌';
  document.getElementById('fbkTtl').textContent = ok ? 'BENAR!' : 'SALAH!';
  document.getElementById('fbkPts').textContent = msg;
  document.getElementById('fbkNxt').textContent = 'Memuat giliran selanjutnya...';
}

function hideFbk(){ 
  document.getElementById('fbk').classList.remove('show');
}

function confetti(){ 
  const c = document.getElementById('cnf');
  const colors = ['#7c3aed', '#10b981', '#f59e0b', '#ec4899', '#3b82f6'];
  for(let i=0; i<40; i++){
    const el = document.createElement('div');
    el.className = 'cp';
    el.style.left = Math.random() * 100 + 'vw';
    el.style.backgroundColor = colors[Math.floor(Math.random()*colors.length)];
    el.style.width = el.style.height = (Math.random()*8+5) + 'px';
    el.style.animationDuration = (Math.random()*1.5 + 1.5) + 's';
    el.style.animationDelay = (Math.random()*.5) + 's';
    c.appendChild(el);
    setTimeout(()=>el.remove(), 3500);
  }
}

function toggleMusic(){ 
  const btn = document.getElementById('mbtn');
  if(MX.on){
    MX.stop();
    btn.textContent = '🔇';
  } else {
    MX.start();
    btn.textContent = '🎵';
  }
}

/* ═══════════════════════════════════════
   END GAME
═══════════════════════════════════════ */
function endGame(){
  clrTimer();G.active=false;
  if(MX.on)MX.stop();
  setTimeout(()=>MX.sfxWin(),300);
  const sorted=EnglAIGameCore.sortLeaderboard(G.teams.map(t=>({name:t.nm,score:G.scores[t.nm],correct:G.correct[t.nm],cl:t.cl}))).map(row=>({nm:row.name,cl:row.cl}));
  const winner=sorted[0];
  document.getElementById('winNm').textContent=winner.nm;
  document.getElementById('winNm').style.color=winner.cl;
  document.getElementById('winSc').textContent=`${G.scores[winner.nm].toLocaleString()} poin`;
  const lb=document.getElementById('lbList');lb.replaceChildren();
  sorted.forEach((t,i)=>{
    const rnkCls=['g','s','b'][i]||'';
    const rnkIco=['🥇','🥈','🥉'][i]||(i+1)+'';
    const row=node('div','lb-row gc sl');row.style.animationDelay=i*.08+'s';
    const rank=node('div',`lb-rnk ${rnkCls}`,rnkIco);
    const dot=node('div','lb-dot');dot.style.background=t.cl;
    const name=node('div','lb-nm',t.nm);
    const right=node('div','lb-rt');
    const score=node('div','lb-sc',G.scores[t.nm].toLocaleString());score.style.color=t.cl;
    right.append(score,node('div','lb-cr',`${G.correct[t.nm]} benar / latihan`));
    row.append(rank,dot,name,right);
    lb.appendChild(row);
  });
  confetti();setTimeout(confetti,700);
  go('results');
}

/* ═══════════════════════════════════════
   INIT
═══════════════════════════════════════ */
buildTeamGrid();
G.units=[1,2,3];
document.getElementById('mbtn').style.opacity='1';
</script>
</body>
</html>
