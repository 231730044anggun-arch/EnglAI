(function(global){
  'use strict';

  function createRoundGuard(){
    let resolved=false;
    return {
      reset(){resolved=false},
      resolve(){if(resolved)return false;resolved=true;return true},
      isResolved(){return resolved}
    };
  }

  function quizScore({correct,difficulty,timeLeft=0,timeMax=30,streak=0,timerEnabled=true}){
    if(!correct)return 0;
    const base={easy:100,medium:150,hard:200}[difficulty]||100;
    const speed=timerEnabled?Math.round((Math.max(0,Math.min(timeLeft,timeMax))/timeMax)*50):0;
    const streakBonus=Math.max(0,(Math.min(streak,5)-1)*25);
    return base+speed+streakBonus;
  }

  function normalizeSpeech(value){
    return String(value??'')
      .normalize('NFKC')
      .toLowerCase()
      .replace(/[^\p{L}\p{N}\s]/gu,' ')
      .replace(/\s+/g,' ')
      .trim();
  }

  function levenshtein(a,b){
    const matrix=Array.from({length:b.length+1},()=>Array(a.length+1).fill(0));
    for(let i=0;i<=b.length;i++)matrix[i][0]=i;
    for(let j=0;j<=a.length;j++)matrix[0][j]=j;
    for(let i=1;i<=b.length;i++)for(let j=1;j<=a.length;j++){
      matrix[i][j]=b[i-1]===a[j-1]?matrix[i-1][j-1]:Math.min(matrix[i-1][j-1],matrix[i][j-1],matrix[i-1][j])+1;
    }
    return matrix[b.length][a.length];
  }

  function speakingScore(transcript,target,confidence=1){
    const actual=normalizeSpeech(transcript);
    const expected=normalizeSpeech(target);
    if(!actual||!expected)return 0;
    const similarity=1-(levenshtein(actual,expected)/Math.max(actual.length,expected.length));
    return Math.max(0,Math.min(100,Math.round(similarity*100*Math.max(0,Math.min(confidence,1)))));
  }

  function uniqueTeamNames(names){
    const counts=new Map();
    return names.map((raw,index)=>{
      const base=String(raw??'').trim()||`Kelompok ${String.fromCharCode(65+index)}`;
      const key=base.toLocaleLowerCase();
      const count=(counts.get(key)||0)+1;
      counts.set(key,count);
      return count===1?base:`${base} (${count})`;
    });
  }

  function sortLeaderboard(rows){
    return rows.map((row,index)=>({...row,_index:index})).sort((a,b)=>
      (b.score-a.score)||
      ((b.correct||0)-(a.correct||0))||
      ((a.averageResponseMs??Number.MAX_SAFE_INTEGER)-(b.averageResponseMs??Number.MAX_SAFE_INTEGER))||
      a.name.localeCompare(b.name)||
      (a._index-b._index)
    );
  }

  global.EnglAIGameCore={createRoundGuard,quizScore,normalizeSpeech,speakingScore,uniqueTeamNames,sortLeaderboard};
})(typeof window!=='undefined'?window:globalThis);
