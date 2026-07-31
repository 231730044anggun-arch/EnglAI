const fs=require('fs');
const vm=require('vm');

let failures=[];
function check(condition,message){if(!condition)failures.push(message)}

const source=fs.readFileSync('assets/js/game-core.js','utf8');
vm.runInThisContext(source);
const core=globalThis.EnglAIGameCore;

check(core.quizScore({correct:false,difficulty:'hard',timeLeft:30,streak:5})===0,'Wrong answer must score 0.');
check(core.quizScore({correct:true,difficulty:'easy',timeLeft:30,timeMax:30,streak:1,timerEnabled:true})===150,'Easy correct answer formula mismatch.');
check(core.quizScore({correct:true,difficulty:'hard',timeLeft:0,timeMax:30,streak:3,timerEnabled:true})===250,'Hard streak formula mismatch.');

const guard=core.createRoundGuard();
check(guard.resolve()===true&&guard.resolve()===false,'Double submission must be rejected.');
guard.reset();
check(guard.resolve()===true&&guard.resolve()===false,'Timer and answer must not resolve one round twice.');

check(core.speakingScore('', 'target', 1)===0,'Empty transcript must score 0.');
check(core.speakingScore('HELLO, WORLD!', 'hello world', 1)===100,'Speaking normalization must ignore punctuation and case.');

const names=core.uniqueTeamNames(['Team','team','<img src=x onerror=alert(1)>']);
check(new Set(names).size===3,'Duplicate team names must become unique.');
check(names[2]==='<img src=x onerror=alert(1)>','HTML-like team name must remain literal data.');

const ranked=core.sortLeaderboard([
  {name:'Zulu',score:100,correct:2,averageResponseMs:1000},
  {name:'Alpha',score:100,correct:2,averageResponseMs:1000},
  {name:'Winner',score:100,correct:3,averageResponseMs:2000}
]);
check(ranked.map(x=>x.name).join(',')==='Winner,Alpha,Zulu','Leaderboard tie-break must be correct, response time, then name.');

const index=fs.readFileSync('public_demo.php','utf8');
check(!/\b(innerHTML|insertAdjacentHTML|outerHTML|document\.write)\b/.test(index),'Untrusted DOM rendering must not use HTML injection APIs.');
check(index.includes("const pts = 0;")&&index.includes("'Dilewati: 0 poin'"),'Skip must award and display 0 points.');
for(const marker of ['textContent','createElement','createTextNode','replaceChildren']){
  check(index.includes(marker),`Safe DOM marker ${marker} is missing.`);
}
for(const payloadTarget of ['q.q','option','q.exp','transcript','t.nm']){
  check(index.includes(payloadTarget),`Expected rendered data path ${payloadTarget} is missing.`);
}

if(failures.length){
  process.stderr.write('JavaScript tests failed:\n- '+failures.join('\n- ')+'\n');
  process.exit(1);
}
process.stdout.write('JavaScript scoring and DOM security tests OK.\n');
