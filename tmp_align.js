function tokenize(t){
  const r=[];
  const p=/[\p{L}\p{M}]+|\d+|[^\s\p{L}\p{M}\d]/gu;
  let m,i=0;
  while((m=p.exec(t))!==null){
    const raw=m[0];
    r.push({raw,norm:raw.toLowerCase(),type:/^[\p{L}\p{M}\d]+$/u.test(raw)?'word':'punct',index:i++});
  }
  return r;
}
function normP(c){
  const map={'—':'-','–':'-','−':'-','…':'...','“':'"','”':'"','«':'"','»':'"',"'":"'"};
  return map[c]||c;
}
function stem(v){
  if(!v) return '';
  const n=v.toLowerCase().replace(/^[^\p{L}\p{M}]+|[^\p{L}\p{M}]+$/gu,'');
  return n.replace(/(ene|ane|ene|het|ene|ers|er|en|et|e)$/u,'');
}
function lev(a,b){
  if(a===b) return 0;
  if(!a.length) return b.length;
  if(!b.length) return a.length;
  const dp=Array.from({length:a.length+1},(_,i)=>i);
  for(let j=1;j<=b.length;j++){
    let prev=j-1; dp[0]=j;
    for(let i=1;i<=a.length;i++){
      const tmp=dp[i];
      if(a[i-1]===b[j-1]) dp[i]=prev; else dp[i]=1+Math.min(prev, dp[i-1], dp[i]);
      prev=tmp;
    }
  }
  return dp[a.length];
}
const MIN=0.35;
function sim(a,b){
  if(!a||!b) return 0;
  if(a.type==='punct' && b.type==='punct'){
    const na=normP(a.raw), nb=normP(b.raw);
    return na===nb ? 0.8 : 0.2;
  }
  if(a.type!==b.type) return 0.05;
  if(a.norm===b.norm) return 1;
  const sa=stem(a.norm), sb=stem(b.norm);
  if(sa && sa===sb) return 0.85;
  const d=lev(a.norm,b.norm);
  const max=Math.max(a.norm.length,b.norm.length)||1;
  const c=Math.max(0, 1 - (d/max));
  if(c>=0.8) return 0.6 + c*0.25;
  if(c>=0.6) return 0.5 + c*0.2;
  return c*0.5;
}
function buildSim(u,o){
  const n=Math.max(u.length,o.length,1);
  const k=0.2;
  return u.map((ut,i)=> o.map((ot,j)=> Math.max(0, sim(ut,ot) - k*Math.abs(i-j)/n)) );
}
function alignMonotonic(sim){
  const m=sim.length;
  const n=m?sim[0].length:0;
  const dp=Array.from({length:m+1},()=>Array(n+1).fill(0));
  const tr=Array.from({length:m+1},()=>Array(n+1).fill(''));
  for(let i=1;i<=m;i++){
    for(let j=1;j<=n;j++){
      const s=sim[i-1][j-1];
      const match=s>=MIN ? dp[i-1][j-1] + s : -Infinity;
      const up=dp[i-1][j];
      const left=dp[i][j-1];
      let best=match, dir='D';
      if(up>best){best=up; dir='U';}
      if(left>best){best=left; dir='L';}
      if(match===best && dir!=='D') dir='D';
      dp[i][j]=best; tr[i][j]=dir;
    }
  }
  const res=[];
  let i=m,j=n;
  while(i>0 && j>0){
    const d=tr[i][j];
    if(d==='D'){
      const s=sim[i-1][j-1];
      if(s>=MIN) res.push({row:i-1,col:j-1,weight:s});
      i--; j--;
    } else if(d==='U') i--; else j--;
  }
  return res.reverse();
}
function run(user, correct){
  const u=tokenize(user);
  const o=tokenize(correct);
  const sim=buildSim(u,o);
  const m=alignMonotonic(sim);
  console.log(user);
  console.log(m.map(x=>${u[x.row].raw}->));
}
const correct='Det var veldig hyggelig å se deg i dag .';
run('var Det veldig hyggelig å se deg i dag .', correct);
run('Det veldig var hyggelig å se deg i dag .', correct);
run('var veldig hyggelig å se deg i dag Det .', correct);

