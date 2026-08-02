(function(){"use strict";
const body=document.body,quizId=Number(body.dataset.quizId),role=body.dataset.quizRole,csrf=body.dataset.csrf||"",pollInterval=Math.max(800,Math.min(5000,Number(body.dataset.pollInterval||1500)));let timerHandle=null,lastQuestion=null,busy=false,celebrated=false,replays=0;
function node(tag,text,className=""){const item=document.createElement(tag);item.textContent=text;if(className)item.className=className;return item}
function avatarNode(avatarStr, className = "avatar") {
    const box = node("div", "", className);
    const val = avatarStr || "a.jpg";
    if (val.match(/\.(jpg|jpeg|png|webp|gif)$/i)) {
        const img = node("img", "");
        img.src = "/assets/images/avatars/" + val;
        img.style.width = "100%";
        img.style.height = "100%";
        img.style.borderRadius = "50%";
        img.style.objectFit = "cover";
        img.style.verticalAlign = "middle";
        box.append(img);
    } else {
        box.textContent = val;
    }
    if (className === "avatar-mini") {
        box.style.display = "inline-block";
        box.style.width = "24px";
        box.style.height = "24px";
        box.style.marginRight = "6px";
        box.style.verticalAlign = "middle";
    }
    return box;
}
function replace(id,children){const host=document.getElementById(id);if(host)host.replaceChildren(...children)}
function leaderboard(rows,finalState){replace("leaderboard",rows.map((row,index)=>{const item=node("li","");const nameSpan=node("span","");nameSpan.append(avatarNode(row.avatar,"avatar-mini"),document.createTextNode(" "+row.display_name+(row.achievement?" · "+row.achievement:"")));item.append(node("b","#"+(row.final_rank||index+1)),nameSpan,node("b",row.total_score+" pts"));return item}));if(finalState){const order=[rows[1],rows[0],rows[2]],classes=["second","first","third"],ranks=["2","1","3"];replace("podium",order.map((row,index)=>{if(!row)return node("div","");const item=node("div","",`podium-place ${classes[index]}`);item.append(node("div",ranks[index],"podium-rank"),avatarNode(row.avatar,"avatar"),node("b",row.display_name),node("div",row.total_score+" pts","muted"),node("small",row.achievement||"","badge available"));return item}))}}
function showTimeoutPopup(onConfirm){
  const overlay=document.createElement("div");
  overlay.className="timeout-overlay";
  overlay.style.cssText="position:fixed;top:0;left:0;width:100vw;height:100vh;background:rgba(10,10,14,0.92);display:flex;flex-direction:column;justify-content:center;align-items:center;z-index:9999;backdrop-filter:blur(12px);";
  const card=document.createElement("div");
  card.style.cssText="background:linear-gradient(135deg, rgba(239,68,68,0.15), rgba(239,68,68,0.05));border:1px solid rgba(239,68,68,0.3);border-radius:24px;padding:40px;text-align:center;box-shadow:0 0 40px rgba(239,68,68,0.25);max-width:400px;width:90%;";
  const icon=document.createElement("div");
  icon.textContent="⏱️";
  icon.style.cssText="font-size:4.5rem;margin-bottom:20px;";
  const title=document.createElement("h2");
  title.textContent="Time's Up!";
  title.style.cssText="color:#ef4444;font-size:2rem;margin:0 0 10px 0;";
  const desc=document.createElement("p");
  desc.style.cssText="color:#cbd5e1;margin:0;";
  card.append(icon,title,desc);
  overlay.append(card);
  document.body.appendChild(overlay);
  
  let secondsLeft=5;
  desc.textContent="Mengalihkan ke soal berikutnya dalam "+secondsLeft+" detik...";
  
  const countdownInterval=setInterval(()=>{
    secondsLeft--;
    if(secondsLeft<=0){
      clearInterval(countdownInterval);
      overlay.remove();
      if(onConfirm)onConfirm();
    }else{
      desc.textContent="Mengalihkan ke soal berikutnya dalam "+secondsLeft+" detik...";
    }
  },1000);
}
function timer(data){clearInterval(timerHandle);const text=document.getElementById("timer-text"),circle=document.getElementById("timer-value");if(!text||!circle||!data.deadline_epoch_ms)return;const offset=Date.now()-data.server_epoch_ms,full=Math.max(1,data.question?.timer_seconds||20),circ=188.5;const tick=()=>{const left=Math.max(0,(data.deadline_epoch_ms-(Date.now()-offset))/1000);text.textContent=Math.ceil(left)+"s";text.setAttribute("aria-label",`${Math.ceil(left)} seconds remaining`);circle.style.strokeDasharray=String(circ);circle.style.strokeDashoffset=String(circ*(1-left/full));circle.style.stroke=left<=5?"#ef4444":"#7c3aed";if(left<=0&&!data.question?.submitted&&!document.querySelector(".timeout-overlay")){clearInterval(timerHandle);showTimeoutPopup();}};tick();timerHandle=setInterval(tick,250)}
function players(rows){replace("participants",rows.map(row=>{const item=node("div","", "player");item.append(avatarNode(row.avatar,"avatar"),node("b",row.display_name||"Joining…"),node("small","Connected","muted"));return item}))}
function renderTeacher(data){document.getElementById("state").textContent=data.state;document.getElementById("participant-count").textContent=String(data.participants.length);players(data.participants);leaderboard(data.leaderboard,["FINISHED","CLOSED"].includes(data.state));const start=document.getElementById("start"),close=document.getElementById("close"),nextBtn=document.getElementById("next-btn");if(start)start.hidden=data.state!=="LOBBY";if(nextBtn)nextBtn.hidden=data.state!=="ACTIVE";if(close)close.hidden=!["LOBBY","FINISHED"].includes(data.state);const lobbyStatus = document.getElementById("lobby-status"), lobbyEyebrow = document.getElementById("lobby-eyebrow");if(lobbyStatus){lobbyStatus.textContent = data.state === "LOBBY" ? "● Waiting" : "● Active";lobbyStatus.className = "status " + (data.state === "LOBBY" ? "waiting" : "active");}if(lobbyEyebrow){lobbyEyebrow.textContent = data.state === "LOBBY" ? "Lobby" : "Active Quiz";}const q=document.getElementById("teacher-question");if(q){if(data.question){q.replaceChildren(node("span",`${data.question.skill} · Question ${data.current_index+1}/${data.question_count}`,"eyebrow"),node("p",data.question.question,"question"),node("p",`${data.submitted_count}/${data.participants.length} submitted · ${data.pending_assessments} assessment pending`,"muted"));timer(data)}else q.replaceChildren(node("p",data.state==="EVALUATING"?`AI Evaluating · ${data.pending_assessments} pending`:data.state==="LOBBY"?"Waiting for Students…":data.state,"muted"))}const podium=document.getElementById("podium");if(podium)podium.hidden=!["FINISHED","CLOSED"].includes(data.state);if(data.state==="FINISHED"&&!celebrated){celebrated=true;window.EnglAIVisuals?.confetti()}}
function meta(question){const row=node("div","", "row");row.append(node("span",question.skill,"badge available"),node("span",question.difficulty,"badge dev"),node("span",question.question_type.replaceAll("_"," "),"badge"));return row}
function objective(question,game){const choices=node("div","", "choices");if(question.submitted)choices.append(node("p","Answer Submitted · Waiting for Other Players","muted"));else question.options.forEach((option,index)=>{const letter=String.fromCharCode(65+index),button=node("button","", "choice");button.type="button";button.append(node("b",letter),node("span",option));button.addEventListener("click",()=>submit({answer:letter},button));choices.append(button)});game.append(meta(question),node("p",question.question,"question"),choices)}
function pickBestVoice(lang){
  const voices=speechSynthesis.getVoices();
  if(!voices||voices.length===0)return null;
  const enVoices=voices.filter(v=>v.lang.toLowerCase().startsWith("en"));
  if(enVoices.length===0)return null;
  enVoices.sort((a,b)=>{
    const aNat=/natural|online|neural|premium/i.test(a.name);
    const bNat=/natural|online|neural|premium/i.test(b.name);
    if(aNat&&!bNat)return-1;
    if(!aNat&&bNat)return 1;
    const aG=/google/i.test(a.name);
    const bG=/google/i.test(b.name);
    if(aG&&!bG)return-1;
    if(!aG&&bG)return 1;
    const preferredNames=['Google US English','Google UK English Female','Microsoft Zira','Microsoft David','Samantha','Daniel'];
    const aPrefIndex=preferredNames.indexOf(a.name);
    const bPrefIndex=preferredNames.indexOf(b.name);
    if(aPrefIndex!==-1&&bPrefIndex===-1)return-1;
    if(aPrefIndex===-1&&bPrefIndex!==-1)return 1;
    if(aPrefIndex!==-1&&bPrefIndex!==-1)return aPrefIndex-bPrefIndex;
    const aUS=a.lang.toLowerCase()==='en-us';
    const bUS=b.lang.toLowerCase()==='en-us';
    if(aUS&&!bUS)return-1;
    if(!aUS&&bUS)return 1;
    return 0;
  });
  return enVoices[0];
}
let voicesReady=false;
if(speechSynthesis.onvoiceschanged!==undefined)speechSynthesis.onvoiceschanged=()=>{voicesReady=true;};
function listening(question,game){
  const content=question.content||{};
  const maxReplays=content.max_replays||2;
  const script=content.script||'';
  const lang=content.language||'en-US';
  const rate=Number(content.rate||0.9);
  const pitch=Number(content.pitch||1);

  // UI elements
  const wrap=node('div','','listening-wrap');
  wrap.style.cssText='display:flex;flex-direction:column;gap:14px';

  // Audio player card
  const playerCard=node('div','','audio-player-card');
  playerCard.style.cssText='background:rgba(124,58,237,.12);border:1px solid rgba(124,58,237,.3);border-radius:14px;padding:18px 20px;display:flex;align-items:center;gap:16px';

  const waveWrap=node('div','','audio-wave');
  waveWrap.id='audio-wave-anim';
  waveWrap.style.cssText='flex:0 0 auto;opacity:.4;transition:opacity .3s';
  for(let i=0;i<10;i++)waveWrap.append(node('span',''));

  const infoCol=node('div','');
  infoCol.style.cssText='flex:1;min-width:0';
  const audioLabel=node('p','🎧 Generated Listening Audio','');
  audioLabel.style.cssText='font-weight:700;margin:0 0 4px;font-size:.9rem';
  const audioSub=node('p',`Dengarkan baik-baik sebelum menjawab · ${maxReplays}× replay`,'muted');
  audioSub.style.cssText='font-size:.78rem;margin:0';
  infoCol.append(audioLabel,audioSub);

  const playBtn=node('button','▶ Play','button gold');
  playBtn.style.cssText='flex-shrink:0;min-width:90px';
  playerCard.append(waveWrap,infoCol,playBtn);
  wrap.append(playerCard);

  // Status text
  const status=node('p','Tekan Play untuk mendengarkan audio. Tombol jawaban tersedia setelah audio diputar.','muted');
  status.style.cssText='font-size:.82rem;text-align:center';
  wrap.append(status);

  // Question
  wrap.append(meta(question),node('p',question.question,'question'));

  // Choices (locked until audio plays)
  const choices=node('div','','choices');
  const choiceButtons=[];
  question.options.forEach((option,index)=>{
    const letter=String.fromCharCode(65+index);
    const button=node('button','','choice');
    button.type='button';button.disabled=true;
    button.style.opacity='0.45';
    button.append(node('b',letter),node('span',option));
    button.addEventListener('click',()=>submit({answer:letter},button));
    choices.append(button);
    choiceButtons.push(button);
  });
  if(question.submitted){choices.replaceChildren(node('p','Answer Submitted · Waiting for Other Players','muted'));}
  wrap.append(choices);
  game.append(wrap);

  // TTS play handler
  let played=0;
  let speaking=false;
  function playAudio(){
    if(played>=maxReplays||speaking)return;
    if(!('speechSynthesis' in window)){status.textContent='Browser TTS tidak didukung pada perangkat ini.';return;}
    speechSynthesis.cancel();
    const utter=new SpeechSynthesisUtterance(script);
    utter.lang=lang;
    utter.rate=Math.max(0.95, rate);
    utter.pitch=pitch;
    utter.volume=1;
    const voice=pickBestVoice(lang);
    if(voice)utter.voice=voice;
    utter.onstart=()=>{
      speaking=true;
      waveWrap.style.opacity='1';
      playBtn.textContent='🔊 Playing…';
      playBtn.disabled=true;
    };
    utter.onend=()=>{
      speaking=false;
      played++;
      waveWrap.style.opacity='0.4';
      const remaining=maxReplays-played;
      if(remaining>0){
        playBtn.textContent=`↺ Replay (${remaining} left)`;
        playBtn.disabled=false;
      }else{
        playBtn.textContent='▶ No replays left';
        playBtn.disabled=true;
      }
      // Unlock choices after first play
      if(played>=1&&!question.submitted){
        choiceButtons.forEach(b=>{b.disabled=false;b.style.opacity='1';});
        status.textContent='Audio selesai. Pilih jawaban Anda.';
      }
    };
    utter.onerror=()=>{
      speaking=false;
      waveWrap.style.opacity='0.4';
      playBtn.textContent='▶ Retry';
      playBtn.disabled=false;
      status.textContent='Gagal memutar audio. Coba lagi.';
    };
    // Wait for voices if not ready yet
    const doSpeak=()=>{speechSynthesis.speak(utter);};
    if(speechSynthesis.getVoices().length>0){doSpeak();}
    else{speechSynthesis.onvoiceschanged=()=>{doSpeak();speechSynthesis.onvoiceschanged=null;};}
  }
  playBtn.addEventListener('click',playAudio);
}

function speaking(question,game){
  const card=node("div","","speaking-card-wrapper");
  card.style.background="rgba(255,255,255,0.02)";
  card.style.border="1px solid rgba(255,255,255,0.08)";
  card.style.borderRadius="16px";
  card.style.padding="24px";
  card.style.marginTop="20px";
  
  card.append(node("div","Ucapkan kalimat berikut dengan jelas:","muted"),node("p",question.question,"question-text"));
  
  const qText=card.querySelector(".question-text")||card.lastChild;
  if(qText){
    qText.style.fontSize="1.45rem";
    qText.style.fontWeight="600";
    qText.style.margin="14px 0";
    qText.style.lineHeight="1.5";
    qText.style.color="#fff";
  }

  const tips=node("p","Tips: Speak clearly and pause naturally.","muted");
  tips.style.fontSize="0.85rem";
  card.append(tips);
  
  const area=document.createElement("textarea");
  area.placeholder="Transcript akan muncul di sini secara otomatis...";
  area.maxLength=12000;
  area.style.width="100%";
  area.style.height="80px";
  area.style.marginTop="12px";
  area.style.background="rgba(15,23,42,0.6)";
  area.style.color="#fff";
  area.style.border="1px solid rgba(255,255,255,0.1)";
  area.style.borderRadius="10px";
  area.style.padding="10px";
  
  const mic=node("button","🗣️ Mulai Berbicara — dinilai dari kecocokan transkripsi","button gold");
  mic.style.width="100%";
  mic.style.marginTop="16px";
  mic.style.fontSize="1.05rem";
  mic.style.padding="12px";
  
  const status=node("p","AI Speaking Feedback akan mengevaluasi kesesuaian ucapan Anda setelah disubmit.","muted");
  status.style.fontSize="0.8rem";
  status.style.marginTop="8px";
  
  let recognition=null;
  let recording=false;
  let finalText="";
  
  mic.addEventListener("click",()=>{
    const Recognition=window.SpeechRecognition||window.webkitSpeechRecognition;
    if(!Recognition){
      status.textContent="Speech Recognition tidak didukung di browser ini. Harap gunakan Chrome/Edge.";
      return;
    }
    
    if(!recording){
      recording=true;
      finalText="";
      area.value="";
      recognition=new Recognition();
      recognition.lang="en-US";
      recognition.interimResults=true;
      recognition.continuous=true;
      
      recognition.onstart=()=>{
        mic.textContent="🟥 Berhenti Berbicara";
        mic.className="button danger";
        mic.style.animation="pulse 1.5s infinite";
        status.textContent="Sedang mendengarkan... Ucapkan kalimat di atas.";
      };
      
      recognition.onresult=event=>{
        let interimText="";
        for(let i=event.resultIndex;i<event.results.length;i++){
          if(event.results[i].isFinal){
            finalText+=(finalText?" ":"")+event.results[i][0].transcript.trim();
          }else{
            interimText+=event.results[i][0].transcript;
          }
        }
        area.value=(finalText+(interimText?" "+interimText:"")).trim();
      };
      
      recognition.onerror=err=>{
        status.textContent=`Error mic: ${err.error}. Silakan coba lagi.`;
        stopMic();
      };
      
      recognition.onend=()=>{
        if(recording){
          try{recognition.start();}catch(_){}
        }
      };
      
      recognition.start();
    }else{
      stopMic();
    }
    
    function stopMic(){
      recording=false;
      if(recognition){
        try{recognition.stop();}catch(_){}
      }
      mic.textContent="🗣️ Mulai Berbicara — dinilai dari kecocokan transkripsi";
      mic.className="button gold";
      mic.style.animation="none";
      status.textContent="Transkripsi selesai. Silakan review lalu submit.";
    }
  });
  
  const send=node("button","Submit transcript","button gold");
  send.style.marginTop="12px";
  send.style.width="100%";
  send.addEventListener("click",()=>submit({transcript:area.value,submission_method:area.value?"manual_transcript":""},send));
  
  card.append(area,mic,status,send);
  game.append(meta(question),node("p",question.content.scenario||"","muted"),card);
}
function writing(question,game){const area=document.createElement("textarea");area.className="response-editor";area.placeholder="Write your response…";const key=`englai-live-${quizId}-${question.id}`,counter=node("p","0 words","muted"),limits=`${question.content.minimum_words||1}–${question.content.maximum_words||1000} words`;area.value=localStorage.getItem(key)||"";function count(){const words=area.value.trim()?area.value.trim().split(/\s+/).length:0;counter.textContent=`${words} words · required ${limits} · draft autosaved`;localStorage.setItem(key,area.value)}area.addEventListener("input",count);count();const send=node("button","Lock and submit","button gold");send.addEventListener("click",()=>submit({writing_submission:area.value},send));game.append(meta(question),node("p",question.content.context||"","muted"),node("p",question.question,"question"),area,counter,send)}
function renderStudent(data){const oldOverlay=document.querySelector(".timeout-overlay");if(oldOverlay)oldOverlay.remove();document.getElementById("state").textContent=data.state;leaderboard(data.leaderboard,["FINISHED","CLOSED"].includes(data.state));const game=document.getElementById("game");if(!game)return;if(data.state==="LOBBY"){game.replaceChildren(node("span",data.mode==="final_challenge"?"Final Challenge Lobby":"Live Quiz Lobby","eyebrow"),node("h1","Waiting for Teacher…"),node("p","Identity tersimpan; reconnect tidak membuat participant baru.","muted"));return}if(data.state==="EVALUATING"){game.replaceChildren(node("span","AI Evaluating","eyebrow"),node("h1","Finalizing scores…"),node("p",`${data.pending_assessments} assessment pending. Fallback aktif jika provider timeout.`,"muted"));return}if(["FINISHED","CLOSED"].includes(data.state)){const review=node("a","Open Personal Review","button secondary");review.href=`/student/quiz_review.php?id=${quizId}`;game.replaceChildren(node("span","Final Results","eyebrow"),node("h1",data.mode==="final_challenge"?"English Master Challenge Complete":"Quiz Finished!","gradient-text"),node("p","Final Leaderboard dan achievement telah disimpan.","muted"),review);document.getElementById("podium").hidden=false;if(!celebrated){celebrated=true;window.EnglAIVisuals?.confetti()}return}if(data.state==="ACTIVE"&&data.question){timer(data);if(lastQuestion===data.question.id)return;lastQuestion=data.question.id;replays=0;game.replaceChildren();if(data.question.submitted){game.append(meta(data.question),node("h2",data.question.submitted.assessment_status==="PENDING"?"AI Evaluating":"Answer Submitted"),node("p","Waiting for Other Players","muted"));return}if(data.question.question_type==="listening_objective")listening(data.question,game);else if(data.question.question_type==="speaking_response")speaking(data.question,game);else if(data.question.question_type==="writing_response")writing(data.question,game);else objective(data.question,game)}}
async function submit(payload,button){if(busy)return;busy=true;document.querySelectorAll("button.choice").forEach(item=>item.disabled=true);button.disabled=true;try{const params=new URLSearchParams({quiz_id:String(quizId),csrf_token:csrf});Object.entries(payload).forEach(([key,value])=>params.set(key,String(value)));const response=await fetch("/api/mvp/quiz_answer.php",{method:"POST",headers:{"Content-Type":"application/x-www-form-urlencoded"},body:params});const result=await response.json();if(!result.success)throw new Error(result.error||"Submission rejected.");const game=document.getElementById("game");game.replaceChildren(node("span","Submission Locked","eyebrow"),node("h2",result.data.assessment_status==="PENDING"?"AI Evaluating":"Answer Submitted ✓"),node("p","Waiting for Other Players or server deadline…","muted"));lastQuestion=null}catch(error){const status=document.getElementById("live-status");if(status)status.textContent=error.message;button.disabled=false}finally{busy=false}}
async function action(value){await fetch("/api/mvp/teacher_quiz_action.php",{method:"POST",headers:{"Content-Type":"application/x-www-form-urlencoded"},body:new URLSearchParams({quiz_id:String(quizId),action:value,csrf_token:csrf})});poll()}
async function poll(){try{const url=role==="teacher"?"/api/mvp/teacher_quiz_status.php?id=":"/api/mvp/student_quiz_status.php?id=";const response=await fetch(url+quizId);const result=await response.json();if(result.success)(role==="teacher"?renderTeacher:renderStudent)(result.data);else{const status=document.getElementById("live-status");if(status)status.textContent=result.error||"Status tidak tersedia."}}catch{}setTimeout(poll,pollInterval)}
document.addEventListener("DOMContentLoaded",()=>{document.querySelectorAll("[data-quiz-action]").forEach(button=>button.addEventListener("click",()=>action(button.dataset.quizAction)));poll()});
})();
