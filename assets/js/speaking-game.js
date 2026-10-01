(()=>{"use strict";
if(!window.isSecureContext&&location.hostname!=="localhost"&&location.hostname!=="127.0.0.1"){
  location.href="http://localhost:8000"+location.pathname+location.search;
  return;
}
const root=document.getElementById("speaking-game");if(!root)return;
const host=root.querySelector("[data-speaking-host]"),csrf=root.dataset.csrf,sid=Number(root.dataset.sessionId);
let state=JSON.parse(root.querySelector("[data-speaking-state]").textContent),recorder,recognition,stream,tick,deadline=0,recording=false,consented=false,pending=null,uploading=false,activeTimerElement=null;
let shadowRate=1.0,shadowSpeaking=false,shadowWordElements=[],shadowFallbackInterval=null,shadowSimultaneous=false;
let currentPlayBtn=null,currentWave=null,audioLevelCtx=null,audioLevelAnalyser=null,audioLevelAnim=null;
const node=(tag,text="",cls="")=>{const n=document.createElement(tag);n.textContent=text;n.className=cls;return n};

function stopAudioLevelMeter(){
  if(audioLevelAnim){cancelAnimationFrame(audioLevelAnim);audioLevelAnim=null}
  if(audioLevelCtx){try{audioLevelCtx.close()}catch(_){}audioLevelCtx=null;audioLevelAnalyser=null}
}

const tracksOff=()=>{
  stopAudioLevelMeter();
  stream?.getTracks().forEach(t=>t.stop());
  stream=null;
};
const beep=()=>new Promise(done=>{try{const c=new(window.AudioContext||window.webkitAudioContext)(),o=c.createOscillator(),g=c.createGain();o.frequency.value=760;g.gain.value=.07;o.connect(g);g.connect(c.destination);o.start();o.stop(c.currentTime+.12);o.onended=()=>{c.close();done()}}catch(_){done()}});

function pickBestVoice(){
  if(!("speechSynthesis" in window))return null;
  const voices=speechSynthesis.getVoices();
  if(!voices||!voices.length)return null;
  const en=voices.filter(v=>v.lang&&v.lang.toLowerCase().startsWith("en"));
  if(!en.length)return null;
  en.sort((a,b)=>{
    const aDavid=/david/i.test(a.name);
    const bDavid=/david/i.test(b.name);
    if(aDavid&&!bDavid)return 1;
    if(!aDavid&&bDavid)return -1;
    const aNat=/natural|online|neural|premium/i.test(a.name);
    const bNat=/natural|online|neural|premium/i.test(b.name);
    if(aNat&&!bNat)return -1;
    if(!aNat&&bNat)return 1;
    const aG=/google/i.test(a.name);
    const bG=/google/i.test(b.name);
    if(aG&&!bG)return -1;
    if(!aG&&bG)return 1;
    const preferred=['Google US English','Microsoft Jenny Online (Natural) - English (United States)','Microsoft Aria Online (Natural) - English (United States)','Microsoft Zira - English (United States)','Microsoft Zira Desktop - English (United States)','Samantha','Daniel','Microsoft Guy Online (Natural)'];
    const aPref=preferred.indexOf(a.name);
    const bPref=preferred.indexOf(b.name);
    if(aPref!==-1&&bPref===-1)return -1;
    if(aPref===-1&&bPref!==-1)return 1;
    if(aPref!==-1&&bPref!==-1)return aPref-bPref;
    return 0;
  });
  return en[0];
}

let shadowLoop=false;

function playSingleWord(word,span){
  if(!("speechSynthesis" in window))return;
  stopShadowAudio();
  try{window.speechSynthesis.cancel()}catch(_){}
  if(span){
    span.classList.add("playing-word");
  }
  const cleanWord=word.replace(/[^a-zA-Z']/g,"");
  const u=new SpeechSynthesisUtterance(cleanWord);
  u.lang="en-US";
  u.rate=0.82;
  u.pitch=1.05;
  const v=pickBestVoice();
  if(v)u.voice=v;
  u.onend=()=>{if(span)span.classList.remove("playing-word")};
  u.onerror=()=>{if(span)span.classList.remove("playing-word")};
  window.speechSynthesis.speak(u);
}

function stopShadowAudio(playBtn,wave){
  const btn=playBtn||currentPlayBtn,wv=wave||currentWave;
  if(window.speechSynthesis){
    try{window.speechSynthesis.cancel()}catch(_){}
  }
  shadowSpeaking=false;
  if(shadowFallbackInterval){clearInterval(shadowFallbackInterval);shadowFallbackInterval=null}
  if(btn){
    btn.textContent="▶ Play Native Audio";
    btn.classList.remove("playing");
  }
  if(wv){
    wv.classList.remove("active");
  }
  resetKaraokeHighlight();
}

function resetKaraokeHighlight(){
  shadowWordElements.forEach(el=>{
    el.classList.remove("active","passed","playing-word");
  });
}

function highlightWord(idx){
  shadowWordElements.forEach((el,i)=>{
    if(i<idx){
      el.classList.remove("active");
      el.classList.add("passed");
    }else if(i===idx){
      el.classList.remove("passed");
      el.classList.add("active");
    }else{
      el.classList.remove("active","passed");
    }
  });
}

function playNativeAudio(text,playBtn,wave,isSecondPass=false){
  if(!isSecondPass){
    stopShadowAudio(playBtn,wave);
  }
  if(!("speechSynthesis" in window)){
    alert("Browser does not support Web Speech Synthesis for the audio model.");
    return;
  }
  resetKaraokeHighlight();
  shadowSpeaking=true;
  if(playBtn){
    playBtn.textContent="⏸ Pause";
    playBtn.classList.add("playing");
  }
  if(wave){
    wave.classList.add("active");
  }
  const utter=new SpeechSynthesisUtterance(text);
  utter.lang="en-US";
  utter.rate=shadowRate;
  utter.pitch=1.05;
  const bestVoice=pickBestVoice();
  if(bestVoice)utter.voice=bestVoice;
  const words=(text||"").trim().split(/\s+/).filter(Boolean);
  let boundaryFired=false;
  utter.onboundary=e=>{
    boundaryFired=true;
    if(shadowFallbackInterval){clearInterval(shadowFallbackInterval);shadowFallbackInterval=null}
    const charIndex=e.charIndex;
    let running=0;
    for(let i=0;i<words.length;i++){
      const wLen=words[i].length;
      if(charIndex>=running&&charIndex<=running+wLen+1){
        highlightWord(i);
        break;
      }
      running+=wLen+1;
    }
  };
  const estimatedMsPerWord=Math.max(200,Math.round(340/shadowRate));
  let fallbackIdx=0;
  shadowFallbackInterval=setInterval(()=>{
    if(boundaryFired)return;
    if(fallbackIdx<shadowWordElements.length){
      highlightWord(fallbackIdx);
      fallbackIdx++;
    }else{
      clearInterval(shadowFallbackInterval);
      shadowFallbackInterval=null;
    }
  },estimatedMsPerWord);
  utter.onend=()=>{
    if(shadowFallbackInterval){clearInterval(shadowFallbackInterval);shadowFallbackInterval=null}
    if(shadowLoop&&!isSecondPass){
      resetKaraokeHighlight();
      setTimeout(()=>{
        if(shadowSpeaking){
          playNativeAudio(text,playBtn,wave,true);
        }
      },600);
      return;
    }
    shadowSpeaking=false;
    if(playBtn){
      playBtn.textContent="▶ Play Native Audio";
      playBtn.classList.remove("playing");
    }
    if(wave){
      wave.classList.remove("active");
    }
    shadowWordElements.forEach(el=>{
      el.classList.remove("active");
      el.classList.add("passed");
    });
  };
  utter.onerror=()=>{
    stopShadowAudio(playBtn,wave);
  };
  window.speechSynthesis.speak(utter);
}

async function api(action,values={}){const f=new FormData();f.append("csrf_token",csrf);f.append("session_id",String(sid));f.append("task_id",String(state.task.id));f.append("action",action);for(const[k,v]of Object.entries(values))f.append(k,v);const r=await fetch("/student/speaking.php",{method:"POST",body:f,credentials:"same-origin"}),d=await r.json();if(!r.ok||!d.success)throw new Error(d.message||"Permintaan gagal.");return d}

function shell(){
  const t=state.task,w=node("div","","speaking-task"),h=node("header","","speaking-header"),meta=node("div","","task-meta"),timer=node("div","25","record-timer"),bar=node("div","","speaking-progress progress-track"),fill=node("div","","progress-fill");
  activeTimerElement=timer;
  const lvlName=state.level?state.level.charAt(0).toUpperCase()+state.level.slice(1):"Basic";
  const badgeText=`🎙️ Shadowing Practice · ${lvlName}`;
  meta.append(node("span",badgeText,"badge available"),node("span",`Task ${state.position+1} of 10`,"task-counter"));
  h.append(meta,timer);
  fill.style.width=`${state.position*10}%`;
  bar.append(fill);
  w.append(h,bar,node("p",t.instruction,"task-instruction"));

  const prompt=node("section","","speaking-prompt");

  // Shadowing Coach Panel for all Speaking levels
  if(t.prompt){
    const coach=node("div","","shadow-coach-panel");
    const coachHeader=node("div","","shadow-coach-header");
    coachHeader.append(node("span","🎙️ Native Audio Model","shadow-badge"),node("span","💡 Click any word to hear pronunciation • Listen & shadow aloud","shadow-tip"));
    const audioBar=node("div","","shadow-audio-bar");
    const audioLeft=node("div","","shadow-audio-left");
    const playBtn=node("button","▶ Play Native Audio","shadow-play-btn");
    playBtn.type="button";
    const wave=node("div","","shadow-wave");
    for(let i=0;i<5;i++)wave.append(document.createElement("span"));
    audioLeft.append(playBtn,wave);

    const loopBtn=node("button","🔁 Loop (2x)","shadow-loop-btn"+(shadowLoop?" active":""));
    loopBtn.type="button";
    loopBtn.title="Repeat audio twice for thorough shadowing practice";
    loopBtn.onclick=()=>{
      shadowLoop=!shadowLoop;
      loopBtn.classList.toggle("active",shadowLoop);
    };
    audioLeft.append(loopBtn);

    const speedWrap=node("div","","shadow-speed-wrap");
    speedWrap.append(node("span","Speed:","shadow-speed-label"));
    const pills=node("div","","shadow-speed-pills");
    const rates=[0.75,1.0,1.25];
    const rateButtons=[];
    rates.forEach(r=>{
      const btn=node("button",r===1.0?"1.0x (Normal)":r+"x","shadow-speed-btn"+(r===shadowRate?" active":""));
      btn.type="button";
      btn.dataset.rate=String(r);
      btn.onclick=()=>{
        shadowRate=r;
        rateButtons.forEach(b=>b.classList.toggle("active",b.dataset.rate===String(r)));
        if(shadowSpeaking){
          playNativeAudio(t.prompt,playBtn,wave);
        }
      };
      rateButtons.push(btn);
      pills.append(btn);
    });
    speedWrap.append(pills);
    audioBar.append(audioLeft,speedWrap);
    coach.append(coachHeader,audioBar);

    const simRow=node("label","","shadow-simultaneous-toggle");
    const simCheck=document.createElement("input");
    simCheck.type="checkbox";
    simCheck.checked=shadowSimultaneous;
    simCheck.onchange=()=>{shadowSimultaneous=simCheck.checked};
    simRow.append(simCheck,node("span","Play native audio automatically when recording starts (Simultaneous Shadowing)"));
    coach.append(simRow);

    currentPlayBtn=playBtn;
    currentWave=wave;
    w.append(coach);

    playBtn.onclick=()=>{
      if(shadowSpeaking){
        stopShadowAudio(playBtn,wave);
      }else{
        playNativeAudio(t.prompt,playBtn,wave);
      }
    };
  }

  // Target Copy with Karaoke Highlighting and Click-to-Pronounce
  const targetCopy=node("p","","target-copy target-copy-karaoke");
  shadowWordElements=[];
  const words=(t.prompt||"").trim().split(/\s+/).filter(Boolean);
  words.forEach((wrd,idx)=>{
    const span=node("span",wrd,"shadow-word");
    span.dataset.wordIndex=String(idx);
    span.title="Click to hear '"+wrd+"'";
    span.onclick=(e)=>{
      e.stopPropagation();
      playSingleWord(wrd,span);
    };
    targetCopy.append(span,document.createTextNode(" "));
    shadowWordElements.push(span);
  });
  prompt.append(targetCopy);

  if(t.guidance)prompt.append(node("p",`Key words: ${t.guidance}`,"guidance"));
  if(t.sentence_starter)prompt.append(node("p",`Sentence starter: ${t.sentence_starter}`,"guidance"));
  w.append(prompt);
  return{w,timer};
}

function consent(){
  const box=node("div","","consent-card"),check=document.createElement("input"),row=node("label","","consent-check"),button=node("button","Izinkan Mikrofon","button gold");
  check.type="checkbox";
  button.disabled=true;
  check.onchange=()=>button.disabled=!check.checked;
  button.onclick=async()=>{try{const s=await navigator.mediaDevices.getUserMedia({audio:true});s.getTracks().forEach(t=>t.stop());consented=true;render()}catch(_){button.textContent="Izin gagal — Coba Lagi"}};
  row.append(check,node("span","Saya memahami dan menyetujui penyimpanan rekaman untuk aktivitas ini."));
  box.append(node("h2","Akses Mikrofon"),node("p","Your voice recording will be securely stored in EnglAI for classroom learning and Teacher review. The audio recording will not be sent to external AI services. A de-identified transcript may be used for automated language feedback."),row,button);
  host.replaceChildren(box);
}

const style=document.createElement("style");
style.textContent="@keyframes pulse {0%{transform:scale(0.95);box-shadow:0 0 0 0 rgba(239,68,68,0.7);}70%{transform:scale(1);box-shadow:0 0 0 6px rgba(239,68,68,0);}100%{transform:scale(0.95);box-shadow:0 0 0 0 rgba(239,68,68,0);}}";
document.head.appendChild(style);

async function task(){
  const recordLabel="🗣️ Start Shadowing";
  const initialStatus="Click 'Start Shadowing' to record your voice shadowing the sentence above.";
  const{w,timer}=shell(),controls=node("div","","record-controls"),record=node("button",recordLabel,"button gold"),stop=node("button","Stop & Evaluate","button secondary"),status=node("p",initialStatus,"record-status"),liveBox=node("div","","live-transcript-box");
  liveBox.style.display="none";
  liveBox.style.marginTop="20px";
  liveBox.style.padding="16px";
  liveBox.style.background="rgba(255,255,255,0.02)";
  liveBox.style.border="1px dashed rgba(255,255,255,0.15)";
  liveBox.style.borderRadius="12px";
  record.disabled=stop.disabled=true;
  record.onclick=()=>{
    startRecording(record,stop,status,liveBox);
  };
  stop.onclick=stopRecording;
  controls.append(record,stop);
  w.append(controls,status,liveBox);
  host.replaceChildren(w);
  try{
    const d=await api("ready");
    state=d.state;
    deadline=Number(state.task_deadline_epoch_ms);
    record.disabled=state.attempt_used||!Number.isFinite(deadline)||Date.now()>=deadline;
    countdown(timer,record,status);
  }catch(e){status.textContent=e.message}
}

function showTimeoutPopup(onConfirm){
  const overlay=document.createElement("div");
  overlay.className="timeout-overlay";
  overlay.style.cssText="position:fixed;top:0;left:0;width:100vw;height:100vh;background:rgba(10,10,14,0.92);display:flex;flex-direction:column;justify-content:center;align-items:center;z-index:9999;backdrop-filter:blur(12px);";
  const card=document.createElement("div");
  card.style.cssText="background:linear-gradient(135deg, rgba(239,68,68,0.15), rgba(239,68,68,0.05));border:1px solid rgba(239,68,68,0.3);border-radius:24px;padding:40px;text-align:center;box-shadow:0 0 40px rgba(239,68,68,0.25);max-width:400px;width:90%;";
  const icon=document.createElement("div");
  icon.textContent="⏱️";
  icon.style.cssText="font-size:4.5rem;margin-bottom:20px;";
  const title=node("h2", "Time's Up!");
  title.style.cssText="color:#ef4444;font-size:2rem;margin:0 0 10px 0;";
  const desc=node("p", "");
  desc.style.cssText="color:#cbd5e1;margin:0;font-size:1.05rem;";
  card.append(icon,title,desc);
  overlay.append(card);
  document.body.appendChild(overlay);
  let secondsLeft=5;
  desc.textContent="Mengalihkan ke soal berikutnya dalam "+secondsLeft+" detik...";
  let finished=false;
  const proceed=()=>{
    if(finished)return;
    finished=true;
    clearInterval(countdownInterval);
    overlay.remove();
    if(onConfirm)onConfirm();
  };
  const countdownInterval=setInterval(()=>{
    secondsLeft--;
    if(secondsLeft<=0){
      proceed();
    }else{
      desc.textContent="Mengalihkan ke soal berikutnya dalam "+secondsLeft+" detik...";
    }
  },1000);
}

function countdown(timer,record,status){
  activeTimerElement=timer;
  clearInterval(tick);
  let handled=false;
  const update=async()=>{
    const raw=Math.ceil((deadline-Date.now())/1000),left=Number.isFinite(raw)?Math.max(0,Math.min(25,raw)):0;
    if(timer){
      timer.replaceChildren(document.createTextNode(String(left)));
      timer.classList.toggle("warning",left<=5);
    }
    if(left||handled)return;
    handled=true;
    clearInterval(tick);
    if(recording){stopRecording();return}
    record.disabled=true;
    status.textContent="Waktu habis. Tidak ada rekaman yang dikirim.";
    try{
      const d=await api("no_response");
      state=d.state;
      showTimeoutPopup(()=>{render();});
    }catch(e){status.textContent=e.message;}
  };
  update();
  tick=setInterval(update,250);
}

async function startRecording(record,stop,status,liveBox){
  if(recording||state.attempt_used||Date.now()>=deadline)return;
  record.disabled=true;
  if(shadowSimultaneous&&state.task?.prompt){
    playNativeAudio(state.task.prompt,currentPlayBtn,currentWave);
  }else{
    stopShadowAudio();
  }
  try{
    await beep();
    stream=await navigator.mediaDevices.getUserMedia({audio:true});
    const R=window.SpeechRecognition||window.webkitSpeechRecognition;
    if(!R)throw new Error("Browser belum mendukung transkripsi suara.");

    if(liveBox){
      liveBox.style.display="block";
      liveBox.replaceChildren();
      const header=node("div","","live-transcript-header");
      header.style.display="flex";
      header.style.alignItems="center";
      header.style.justifyContent="space-between";
      header.style.flexWrap="wrap";
      header.style.gap="10px";

      const micGroup=node("div");
      micGroup.style.display="flex";
      micGroup.style.alignItems="center";
      micGroup.style.gap="8px";

      const dot=node("span","","pulse-dot");
      dot.style.display="inline-block";
      dot.style.width="9px";
      dot.style.height="9px";
      dot.style.backgroundColor="#10b981";
      dot.style.borderRadius="50%";
      dot.style.animation="pulse 1.5s infinite";

      const label=node("small","Microphone active — Listening to your voice...","muted");
      micGroup.append(dot,label);

      const vu=node("div","","vu-meter");
      for(let i=0;i<5;i++){
        const bar=node("span","","vu-bar");
        vu.append(bar);
      }
      micGroup.append(vu);
      header.append(micGroup);

      const txt=node("p","Mendengarkan ucapan Anda... Silakan bersuara.","live-transcript-text");
      liveBox.append(header,txt);
    }

    try{
      audioLevelCtx=new(window.AudioContext||window.webkitAudioContext)();
      const src=audioLevelCtx.createMediaStreamSource(stream);
      audioLevelAnalyser=audioLevelCtx.createAnalyser();
      audioLevelAnalyser.fftSize=64;
      src.connect(audioLevelAnalyser);
      const pcmData=new Uint8Array(audioLevelAnalyser.frequencyBinCount);
      const vuBars=liveBox?.querySelectorAll(".vu-bar");
      const checkVolume=()=>{
        if(!recording){stopAudioLevelMeter();return}
        audioLevelAnalyser.getByteFrequencyData(pcmData);
        let sum=0;
        for(let i=0;i<pcmData.length;i++)sum+=pcmData[i];
        const avg=sum/pcmData.length;
        if(vuBars&&vuBars.length){
          vuBars.forEach((b,idx)=>{
            const sc=Math.max(0.2,Math.min(1.8,(avg/20)*(0.85+idx*0.25)));
            b.style.transform=`scaleY(${sc.toFixed(2)})`;
            b.style.backgroundColor=avg>8?"#10b981":"#64748b";
          });
        }
        audioLevelAnim=requestAnimationFrame(checkVolume);
      };
      audioLevelAnim=requestAnimationFrame(checkVolume);
    }catch(_){}

    let committedFinal="",sessionFinal="",sessionInterim="",confidence=[],chunks=[],intentional=false,activeRec=null;

    const renderLiveDisplay=()=>{
      const parts=[committedFinal,sessionFinal,sessionInterim].filter(Boolean);
      const full=parts.join(" ").trim().replace(/\s+/g," ");
      const liveDisplay=liveBox?.querySelector(".live-transcript-text");
      if(liveDisplay){
        liveDisplay.textContent=full||"Listening to your voice... Speak clearly into your mic.";
      }
      if(shadowWordElements.length&&full){
        const spokenTokens=full.toLowerCase().replace(/[^a-z0-9\s]/g,"").split(/\s+/).filter(Boolean);
        const spokenSet=new Set(spokenTokens);
        shadowWordElements.forEach(el=>{
          const wrd=(el.textContent||"").toLowerCase().replace(/[^a-z0-9]/g,"");
          if(wrd&&spokenSet.has(wrd)){
            el.classList.add("matched");
          }
        });
      }
    };

    function launchRecognitionSession(){
      if(!recording||intentional)return;
      try{
        const rec=new R();
        const promptTxt=state.task?.prompt||state.task?.target_text||"";
        const isIndo=/(boleh bahasa indonesia|bahasa indonesia|sebutkan|ciri fisik)/i.test(promptTxt);
        rec.lang=isIndo?"id-ID":"en-US";
        rec.interimResults=true;
        rec.continuous=true;
        rec.maxAlternatives=1;

        rec.onresult=e=>{
          sessionFinal="";
          sessionInterim="";
          for(let i=0;i<e.results.length;i++){
            const res=e.results[i];
            const val=res[0]?.transcript?.trim()||"";
            if(res.isFinal){
              sessionFinal+=(sessionFinal?" ":"")+val;
              if(res[0]?.confidence>0)confidence.push(res[0].confidence);
            }else{
              sessionInterim+=(sessionInterim?" ":"")+val;
            }
          }
          renderLiveDisplay();
        };

        rec.onerror=err=>{
          if(err.error==="no-speech")return;
          console.warn("Speech recognition notice:",err.error);
          const liveDisplay=liveBox?.querySelector(".live-transcript-text");
          if(liveDisplay){
            if(err.error==="not-allowed"){
              liveDisplay.textContent="⚠️ Izin mikrofon browser belum aktif. Harap izinkan akses mic di ikon gembok address bar.";
            }else if(err.error==="network"){
              liveDisplay.textContent="⚠️ Layanan speech recognition butuh internet. Rekaman audio Anda tetap disimpan.";
            }else if(err.error==="audio-capture"){
              liveDisplay.textContent="⚠️ Mikrofon sedang digunakan aplikasi lain atau tidak terdeteksi.";
            }else{
              liveDisplay.textContent="Status speech: "+err.error+". Tetap berbicara dekat ke mic.";
            }
          }
        };

        rec.onend=()=>{
          if(sessionFinal){
            committedFinal+=(committedFinal?" ":"")+sessionFinal;
            sessionFinal="";
          }
          sessionInterim="";
          if(recording&&!intentional){
            setTimeout(()=>{
              if(recording&&!intentional){
                launchRecognitionSession();
              }
            },60);
          }
        };

        rec.start();
        activeRec=rec;
      }catch(rxErr){
        console.warn("Speech recognition launch error:",rxErr);
        const liveDisplay=liveBox?.querySelector(".live-transcript-text");
        if(liveDisplay)liveDisplay.textContent="Catatan speech: "+rxErr.message+". Suara Anda tetap direkam.";
      }
    }

    recorder=new MediaRecorder(stream,{mimeType:MediaRecorder.isTypeSupported("audio/webm;codecs=opus")?"audio/webm;codecs=opus":"audio/webm"});
    recorder.ondataavailable=e=>{if(e.data.size)chunks.push(e.data)};
    recorder.onstop=async()=>{
      recording=false;
      intentional=true;
      if(activeRec){
        try{activeRec.stop()}catch(_){}
        activeRec=null;
      }
      stopAudioLevelMeter();
      stopShadowAudio();
      await new Promise(r=>setTimeout(r,400));
      tracksOff();
      stop.disabled=true;
      if(sessionFinal){
        committedFinal+=(committedFinal?" ":"")+sessionFinal;
        sessionFinal="";
      }
      const combined=((committedFinal?committedFinal+" ":"")+sessionInterim).trim().replace(/\s+/g," ");
      const transcript=combined;
      pending={
        blob:new Blob(chunks,{type:recorder.mimeType}),
        duration:Math.max(250,Math.min(25000,Date.now()-startedAt)),
        transcript,
        confidence:confidence.length?confidence.reduce((a,b)=>a+b,0)/confidence.length:0,
        key:crypto.randomUUID().replaceAll("-","").padEnd(64,"0")
      };
      recorder=null;
      preview(transcript?pending.confidence>=.35?"Transkrip berhasil dibuat.":"Transkrip berhasil dibuat, tetapi mungkin perlu diperiksa kembali.":"Transkrip belum berhasil dibuat. Rekaman akan ditandai untuk diperiksa oleh Teacher.");
    };

    recording=true;
    window.startedAt=Date.now();
    recorder.start(250);
    launchRecognitionSession();
    stop.disabled=false;
    status.textContent="Recording... Shadow the sentence above with clear pronunciation.";
    state.attempt_used=true;
    api("recording_start").then(d=>{
      if(d&&d.state){
        state=d.state;
        if(Number.isFinite(Number(state.task_deadline_epoch_ms))){
          deadline=Number(state.task_deadline_epoch_ms);
        }
      }
    }).catch(e=>console.warn("API recording_start notice:",e));
  }catch(e){
    recording=false;
    tracksOff();
    record.disabled=Date.now()>=deadline;
    status.textContent=e.message||"Mikrofon tidak dapat digunakan.";
  }
}

let startedAt=0;
Object.defineProperty(window,"startedAt",{configurable:true,get:()=>startedAt,set:value=>{startedAt=Number(value)||0}});

function stopRecording(){
  clearInterval(tick);
  if(recorder?.state==="recording")recorder.stop();
}

function preview(message){
  clearInterval(tick);
  const{w}=shell(),card=node("section","","transcript-preview"),save=node("button","Simpan & Lanjut","button gold"),status=node("p",message,"record-status");
  card.append(node("h2","Hasil transkrip sementara"));
  const transcriptText=pending?.transcript?`"${pending.transcript}"`:"(Tidak ada transkrip terdeteksi — rekaman suara tetap disimpan untuk penilaian dan Teacher Review)";
  const transcriptBox=node("p",transcriptText,"transcript-copy");
  card.append(transcriptBox,node("p","Transkrip ini digunakan untuk memastikan ucapan berhasil terdeteksi. Evaluasi dan koreksi lengkap ditampilkan setelah semua task selesai.","muted"),status,save);
  save.onclick=async()=>{
    if(uploading)return;
    uploading=true;
    save.disabled=true;
    status.textContent="Menyimpan rekaman…";
    try{
      const d=await api("upload",{
        duration_ms:String(pending.duration),
        idempotency_key:pending.key,
        raw_transcript:pending.transcript,
        transcript_confidence:String(pending.confidence),
        audio:pending.blob
      });
      state=d.state;
      pending=null;
      uploading=false;
      render();
    }catch(e){
      uploading=false;
      save.disabled=false;
      status.textContent=e.message+" Klik kembali untuk mengirim blob yang sama.";
    }
  };
  w.append(card);
  host.replaceChildren(w);
}

function result(){
  const w=node("div","","speaking-result");
  w.append(node("h1","Sesi Speaking Selesai"),node("p","Selesai 10/10","badge available"),node("h2","Review Speaking"));
  for(let idx=0;idx<state.recordings.length;idx++){
    const r=state.recordings[idx];
    const taskInfo=state.tasks?.[idx]||{};
    const c=node("article","","review-card");
    const label=node("h3",`Task ${idx+1}: ${taskInfo.title||"Speaking Task"}`);
    c.append(label);
    if(taskInfo.prompt){
      c.append(node("p",`Kalimat Target: "${taskInfo.prompt}"`,"guidance"));
      const nativeBar=node("div","","review-native-guide");
      nativeBar.append(node("span","Audio Model Native:","task-instruction"));
      const replayNativeBtn=node("button","▶ Dengarkan Suara Native","review-native-btn");
      replayNativeBtn.type="button";
      replayNativeBtn.onclick=()=>{
        if(!("speechSynthesis" in window))return;
        speechSynthesis.cancel();
        const u=new SpeechSynthesisUtterance(taskInfo.prompt);
        u.lang="en-US";
        u.rate=1.0;
        u.pitch=1.05;
        const v=pickBestVoice();
        if(v)u.voice=v;
        speechSynthesis.speak(u);
      };
      nativeBar.append(replayNativeBtn);
      c.append(nativeBar);
    }
    const myLabel=node("p","Rekaman Suara Anda:","task-instruction");
    const a=document.createElement("audio");
    a.controls=true;
    a.src=`/student/speaking_audio.php?id=${r.id}`;
    c.append(myLabel,a,node("p",r.final_transcript?`Transkrip: ${r.final_transcript}`:"Tidak ada transkrip — Teacher Review Required.","transcript"));
    if(r.assessment_json)c.append(node("p",`Assessment: ${JSON.parse(r.assessment_json).total_score}/100`,"badge available"));
    w.append(c);
  }
  host.replaceChildren(w);
}

function render(){
  clearInterval(tick);
  stopShadowAudio();
  if(state.status==="completed"){result();return}
  if(!consented){consent();return}
  task();
}

window.onbeforeunload=()=>{
  stopShadowAudio();
  tracksOff();
};

render();
})();
