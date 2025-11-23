function tokenizeText(text){
  const tokens = [];
  if(!text) return tokens;
  const pattern = /[\p{L}\p{M}]+|\d+|[^\s\p{L}\p{M}\d]/gu;
  let m, idx=0;
  while((m=pattern.exec(text))!==null){
    const raw=m[0];
    tokens.push({raw,norm:raw.toLowerCase(),type:/^[\p{L}\p{M}\d]+$/u.test(raw)?'word':'punct',index:idx++});
  }
  return tokens;
}
function normalizePunctuation(char){
  const map={'—':'-','–':'-','−':'-','…':'...','“':'"','”':'"','«':'"','»':'"',"'":"'"};
  return map[char]||char;
}
function simpleStem(v){
  if(!v) return '';
  const n=v.toLowerCase().replace(/^[^\p{L}\p{M}]+|[^\p{L}\p{M}]+$/gu,'');
  return n.replace(/(ene|ane|ene|het|ene|ers|er|en|et|e)$/u,'');
}
function levenshtein(a,b){if(a===b)return 0; if(!a.length) return b.length; if(!b.length) return a.length; const dp=Array.from({length:a.length+1},(_,i)=>i); for(let j=1;j<=b.length;j++){let prev=j-1;dp[0]=j;for(let i=1;i<=a.length;i++){const tmp=dp[i]; if(a[i-1]===b[j-1]) dp[i]=prev; else dp[i]=1+Math.min(prev,dp[i-1],dp[i]); prev=tmp;} } return dp[a.length];}
const MIN_SIMILARITY_SCORE=0.35, STEM_SIMILARITY_SCORE=0.85;
function tokenSimilarity(a,b){
  if(!a||!b) return 0;
  if(a.type==='punct'&&b.type==='punct'){
    const na=normalizePunctuation(a.raw), nb=normalizePunctuation(b.raw);
    if(na===nb) return 0.8; return 0.2;
  }
  if(a.type!==b.type) return 0.05;
  if(a.norm===b.norm) return 1;
  const sa=simpleStem(a.norm), sb=simpleStem(b.norm);
  if(sa&&sa===sb) return STEM_SIMILARITY_SCORE;
  const dist=levenshtein(a.norm,b.norm);
  const maxLen=Math.max(a.norm.length,b.norm.length)||1;
  const closeness=Math.max(0,1-(dist/maxLen));
  if(closeness>=0.8) return 0.6+(closeness*0.25);
  if(closeness>=0.6) return 0.5+(closeness*0.2);
  return closeness*0.5;
}
function buildSimilarityMatrix(userTokens, originalTokens){
  const matrix=[]; const n=Math.max(userTokens.length, originalTokens.length,1); const posPenaltyFactor=0.2;
  for(let i=0;i<userTokens.length;i++){
    matrix[i]=[];
    for(let j=0;j<originalTokens.length;j++){
      const base=tokenSimilarity(userTokens[i],originalTokens[j]);
      const posPenalty=posPenaltyFactor*(Math.abs(i-j)/n);
      matrix[i][j]=Math.max(0, base-posPenalty);
    }
  }
  return matrix;
}
function solveMaxAssignment(weights){
  const rows=weights.length; const cols=weights[0]?weights[0].length:0; const n=Math.max(rows,cols); if(!n) return [];
  let maxWeight=0; for(let i=0;i<rows;i++) for(let j=0;j<cols;j++) maxWeight=Math.max(maxWeight, weights[i][j]);
  const big=maxWeight+1;
  const cost=Array.from({length:n},(_,i)=>Array.from({length:n},(_,j)=>{
    if(i<rows && j<cols) return big-weights[i][j]; else return big;
  }));
  const u=Array(n+1).fill(0), v=Array(n+1).fill(0), p=Array(n+1).fill(0), way=Array(n+1).fill(0);
  for(let i=1;i<=n;i++){
    p[0]=i; let j0=0; const minv=Array(n+1).fill(Infinity); const used=Array(n+1).fill(false);
    do{
      used[j0]=true; const i0=p[j0]; let delta=Infinity, j1=0;
      for(let j=1;j<=n;j++) if(!used[j]){
        const cur=cost[i0-1][j-1]-u[i0]-v[j];
        if(cur<minv[j]){minv[j]=cur; way[j]=j0;}
        if(minv[j]<delta){delta=minv[j]; j1=j;}
      }
      for(let j=0;j<=n;j++){
        if(used[j]){u[p[j]]+=delta; v[j]-=delta;} else {minv[j]-=delta;}
      }
      j0=j1;
    }while(p[j0]!=0);
    do{const j1=way[j0]; p[j0]=p[j1]; j0=j1;}while(j0!=0);
  }
  const res=[]; for(let j=1;j<=n;j++) if(p[j] && p[j]-1<rows && j-1<cols) res.push({row:p[j]-1, col:j-1, weight: weights[p[j]-1][j-1]});
  return res;
}
function longestIncreasingSubsequence(arr){
  const n=arr.length; const dp=Array(n).fill(1); const prev=Array(n).fill(-1); const firstIdx=Array(n).fill(0);
  for(let i=0;i<n;i++){
    firstIdx[i]=i;
    for(let j=0;j<i;j++){
      if(arr[j] < arr[i]){
        const cand = dp[j]+1;
        const betterLen = cand > dp[i];
        const sameLenEarlier = cand === dp[i] && firstIdx[j] < firstIdx[i];
        if(betterLen || sameLenEarlier){
          dp[i]=cand;
          prev[i]=j;
          firstIdx[i]=firstIdx[j];
        }
      }
    }
  }
  let best=0; for(let i=1;i<n;i++){ const better=dp[i]>dp[best]; const sameEarlier=dp[i]===dp[best] && firstIdx[i] < firstIdx[best]; if(better||sameEarlier) best=i; }
  const res=[]; let k=best; while(k!==-1){ res.push(k); k=prev[k]; } return res.reverse();
}
function compare(user, correct){
  const userTokens=tokenizeText(user.trim());
  const origTokens=tokenizeText(correct.trim());
  const sim=buildSimilarityMatrix(userTokens, origTokens);
  const assignment=solveMaxAssignment(sim);
  const matches=[]; const matchedUser=new Set(), matchedOrig=new Set();
  for(const item of assignment){
    const {row,col,weight}=item; if(row>=userTokens.length||col>=origTokens.length) continue; if(weight<0.35) continue;
    matches.push({id:matches.length, userIndex:row, origIndex:col, userToken:userTokens[row], origToken:origTokens[col], score:weight, exact:userTokens[row].raw===origTokens[col].raw});
    matchedUser.add(row); matchedOrig.add(col);
  }
  const ordered=[...matches].sort((a,b)=>a.userIndex-b.userIndex);
  const lisIdx=longestIncreasingSubsequence(ordered.map(m=>m.origIndex));
  const lisSet=new Set(lisIdx.map(idx=>ordered[idx].id));
  console.log('user:', userTokens.map(t=>t.raw).join(' '));
  console.log('orig:', origTokens.map(t=>t.raw).join(' '));
  console.log('matches (user->orig):', ordered.map(m=>m.userToken.raw+'->'+m.origIndex));
  console.log('lis keeps:', ordered.filter(m=>lisSet.has(m.id)).map(m=>m.userToken.raw));
  console.log('moves:', ordered.filter(m=>!lisSet.has(m.id)).map(m=>m.userToken.raw));
  console.log('----');
}
compare('var Det veldig hyggelig å se deg i dag .', 'Det var veldig hyggelig å se deg i dag .');
compare('Det veldig var hyggelig å se deg i dag .', 'Det var veldig hyggelig å se deg i dag .');
compare('var veldig hyggelig å se deg i dag Det .', 'Det var veldig hyggelig å se deg i dag .');

