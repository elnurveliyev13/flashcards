from pathlib import Path
path = Path("assets/flashcards.js")
text = path.read_text()
start = text.find('    function compareTexts')
end = text.find('    async function buildSlot')
if start == -1 or end == -1:
    raise SystemExit('markers not found')
new_block = '''    // Compare dictation answers with move-aware logic
    function compareTexts(userInput, correctText){
      const userNorm = (userInput || '').trim();
      const correctNorm = (correctText || '').trim();

      if(userNorm === correctNorm){
        return {
          isCorrect: true,
          userTokens: tokenizeText(userInput),
          originalTokens: tokenizeText(correctText),
          matches: [],
          extras: [],
          missing: [],
          movePlan: { moveBlocks: [], rewriteGroups: [], tokenMeta: [], gapMeta: {}, gapsNeeded: new Set(), missingByPosition: {} },
          errorCount: 0
        };
      }

      const userTokens = tokenizeText(userNorm);
      const originalTokens = tokenizeText(correctNorm);

      const similarity = buildSimilarityMatrix(userTokens, originalTokens);
      const assignment = solveMaxAssignment(similarity);
      const MIN_ACCEPTABLE = 0.35;

      const matches = [];
      const matchedUser = new Set();
      const matchedOrig = new Set();

      assignment.forEach((item, idx) => {
        if(!item) return;
        const { row, col, weight } = item;
        if(row >= userTokens.length || col >= originalTokens.length) return;
        if(weight < MIN_ACCEPTABLE) return;
        matches.push({
          id: idx,
          userIndex: row,
          origIndex: col,
          userToken: userTokens[row],
          origToken: originalTokens[col],
          score: weight,
          exact: userTokens[row].raw === originalTokens[col].raw
        });
        matchedUser.add(row);
        matchedOrig.add(col);
      });

      const extras = [];
      userTokens.forEach((token, index)=>{
        if(!matchedUser.has(index)){
          extras.push({ userIndex:index, token });
        }
      });

      const missing = [];
      originalTokens.forEach((token, index)=>{
        if(!matchedOrig.has(index)){
          missing.push({ origIndex:index, token });
        }
      });

      const lisIndices = longestIncreasingSubsequence(matches.map(m=>m.origIndex));
      const lisSet = new Set(lisIndices.map(idx=> matches[idx]?.id).filter(Boolean));
      const movePlan = buildMovePlan(matches, lisSet, userTokens, originalTokens);
      const missingByPosition = buildMissingByPosition(missing, matches, userTokens.length);
      movePlan.missingByPosition = missingByPosition;

      const spellingIssues = matches.filter(m => m.userToken.raw !== m.origToken.raw).length;
      const orderIssues = matches.filter(m => !lisSet.has(m.id)).length;
      const moveIssues = movePlan.rewriteGroups.length + movePlan.moveBlocks.filter(b=>!b.resolvedByRewrite).length;
      const errorCount = extras.length + missing.length + spellingIssues + orderIssues + moveIssues;

      return {
        isCorrect: false,
        userTokens,
        originalTokens,
        matches,
        extras,
        missing,
        movePlan,
        errorCount
      };
    }

    const MIN_SIMILARITY_SCORE = 0.35;
    const STEM_SIMILARITY_SCORE = 0.85;

    function tokenizeText(text){
      const tokens = [];
      if(!text){
        return tokens;
      }
      const pattern = /[\p{L}\p{M}]+|\d+|[^\s\p{L}\p{M}\d]/gu;
      let match;
      let idx = 0;
      while((match = pattern.exec(text)) !== null){
        const raw = match[0];
        tokens.push({
          raw,
          norm: raw.toLowerCase(),
          type: /^[\p{L}\p{M}\d]+$/u.test(raw) ? 'word' : 'punct',
          index: idx++
        });
      }
      return tokens;
    }

    function normalizePunctuation(char){
      const map = {
        '—': '-',
        '–': '-',
        '−': '-',
        '…': '...',
        '“': '"',
        '”': '"',
        '«': '"',
        '»': '"',
        "'": "'"
      };
      return map[char] || char;
    }

    function simpleStem(value){
      if(!value) return '';
      const normalized = value.toLowerCase().replace(/^[^\p{L}\p{M}]+|[^\p{L}\p{M}]+$/gu, '');
      return normalized.replace(/(ene|ane|ene|het|ene|ers|er|en|et|e)$/u, '');
    }

    function levenshtein(a,b){
      if(a === b) return 0;
      if(!a.length) return b.length;
      if(!b.length) return a.length;
      const dp = Array.from({length: a.length + 1}, (_, i) => i);
      for(let j = 1; j <= b.length; j++){
        let prev = j - 1;
        dp[0] = j;
        for(let i = 1; i <= a.length; i++){
          const temp = dp[i];
          if(a[i-1] === b[j-1]){
            dp[i] = prev;
          } else {
            dp[i] = 1 + Math.min(prev, dp[i-1], dp[i]);
          }
          prev = temp;
        }
      }
      return dp[a.length];
    }

    function tokenSimilarity(a, b){
      if(!a || !b) return 0;
      if(a.type === 'punct' && b.type === 'punct'){
        const normA = normalizePunctuation(a.raw);
        const normB = normalizePunctuation(b.raw);
        if(normA === normB) return 0.8;
        return 0.2;
      }
      if(a.type !== b.type){
        return 0.05;
      }
      if(a.norm === b.norm){
        return 1;
      }
      const stemA = simpleStem(a.norm);
      const stemB = simpleStem(b.norm);
      if(stemA && stemA === stemB){
        return STEM_SIMILARITY_SCORE;
      }
      const distance = levenshtein(a.norm, b.norm);
      const maxLen = Math.max(a.norm.length, b.norm.length) || 1;
      const closeness = Math.max(0, 1 - (distance / maxLen));
      if(closeness >= 0.8) return 0.6 + (closeness * 0.25);
      if(closeness >= 0.6) return 0.5 + (closeness * 0.2);
      return closeness * 0.5;
    }

    function buildSimilarityMatrix(userTokens, originalTokens){
      const matrix = [];
      for(let i = 0; i < userTokens.length; i++){
        matrix[i] = [];
        for(let j = 0; j < originalTokens.length; j++){
          matrix[i][j] = tokenSimilarity(userTokens[i], originalTokens[j]);
        }
      }
      return matrix;
    }

    function solveMaxAssignment(weights){
      const rows = weights.length;
      const cols = weights[0] ? weights[0].length : 0;
      const n = Math.max(rows, cols);
      if(!n) return [];
      let maxWeight = 0;
      for(let i = 0; i < rows; i++){
        for(let j = 0; j < cols; j++){
          maxWeight = Math.max(maxWeight, weights[i][j]);
        }
      }
      const big = maxWeight + 1;
      const cost = Array.from({length: n}, (_, i)=>{
        const row = [];
        for(let j = 0; j < n; j++){
          if(i < rows && j < cols){
            row.push(big - weights[i][j]);
          } else {
            row.push(big);
          }
        }
        return row;
      });
      const u = Array(n + 1).fill(0);
      const v = Array(n + 1).fill(0);
      const p = Array(n + 1).fill(0);
      const way = Array(n + 1).fill(0);
      for(let i = 1; i <= n; i++){
        p[0] = i;
        let j0 = 0;
        const minv = Array(n + 1).fill(Infinity);
        const used = Array(n + 1).fill(false);
        do{
          used[j0] = true;
          const i0 = p[j0];
          let delta = Infinity;
          let j1 = 0;
          for(let j = 1; j <= n; j++){
            if(used[j]) continue;
            const cur = cost[i0 - 1][j - 1] - u[i0] - v[j];
            if(cur < minv[j]){
              minv[j] = cur;
              way[j] = j0;
            }
            if(minv[j] < delta){
              delta = minv[j];
              j1 = j;
            }
          }
          for(let j = 0; j <= n; j++){
            if(used[j]){
              u[p[j]] += delta;
              v[j] -= delta;
            } else {
              minv[j] -= delta;
            }
          }
          j0 = j1;
        } while(p[j0] !== 0);
        do{
          const j1 = way[j0];
          p[j0] = p[j1];
          j0 = j1;
        } while(j0 !== 0);
      }
      const result = [];
      for(let j = 1; j <= n; j++){
        if(p[j] && p[j] - 1 < rows && j - 1 < cols){
          result.push({ row: p[j] - 1, col: j - 1, weight: weights[p[j] - 1][j - 1] });
        }
      }
      return result;
    }

    function longestIncreasingSubsequence(arr){
      const tails = [];
      const prev = Array(arr.length).fill(-1);
      const idxs = [];
      for(let i = 0; i < arr.length; i++){
        let l = 0, r = tails.length;
        while(l < r){
          const m = (l + r) >> 1;
          if(arr[tails[m]] < arr[i]) l = m + 1; else r = m;
        }
        if(l === tails.length){
          tails.push(i);
        } else {
          tails[l] = i;
        }
        prev[i] = l > 0 ? tails[l - 1] : -1;
        idxs[l] = i;
      }
      let k = tails.length ? tails[tails.length - 1] : -1;
      const result = [];
      while(k !== -1){
        result.push(k);
        k = prev[k];
      }
      return result.reverse();
    }

    function buildMovePlan(matches, lisSet, userTokens, originalTokens){
      const orderedMatches = [...matches].sort((a,b)=>a.userIndex - b.userIndex);
      const lisMatches = orderedMatches.filter(m=>lisSet.has(m.id));
      const lisOrigSorted = lisMatches.map(m=>m.origIndex).sort((a,b)=>a-b);
      const lisOrigToStudent = new Map();
      lisMatches.forEach(m=>{
        lisOrigToStudent.set(m.origIndex, m.userIndex);
      });

      const findNeighbors = (value)=>{
        let prev = -1;
        let next = null;
        for(const idx of lisOrigSorted){
          if(idx < value){
            prev = idx;
          } else if(idx > value){
            next = idx;
            break;
          }
        }
        return { prev, next };
      };

      const metaByUser = Array(userTokens.length).fill(null);
      const moveBlocks = [];
      const gapMeta = {};
      let current = null;

      orderedMatches.forEach((m)=>{
        const inLis = lisSet.has(m.id);
        const { prev, next } = findNeighbors(m.origIndex);
        const gapKey = `${prev}-${next === null ? 'END' : next}`;
        gapMeta[gapKey] = { before: prev, after: next };
        const hasError = m.userToken.raw !== m.origToken.raw;
        metaByUser[m.userIndex] = {
          match: m,
          inLis,
          targetGapKey: gapKey,
          targetGap: { before: prev, after: next },
          hasError,
          needsMove: !inLis
        };
        if(!inLis){
          const canJoin = current && current.targetGapKey === gapKey && m.userIndex === current.end + 1 && m.origIndex > current.lastOrigIndex;
          if(canJoin){
            current.tokens.push(m.userIndex);
            current.end = m.userIndex;
            current.lastOrigIndex = m.origIndex;
            current.hasError = current.hasError || hasError;
          } else {
            if(current){
              moveBlocks.push(current);
            }
            current = {
              id: `move-${moveBlocks.length + 1}`,
              tokens: [m.userIndex],
              start: m.userIndex,
              end: m.userIndex,
              lastOrigIndex: m.origIndex,
              targetGapKey: gapKey,
              targetGap: { before: prev, after: next },
              hasError,
              resolvedByRewrite: false
            };
          }
        } else if(current){
          moveBlocks.push(current);
          current = null;
        }
      });
      if(current){
        moveBlocks.push(current);
      }

      const gapGroups = {};
      moveBlocks.forEach(block=>{
        if(!gapGroups[block.targetGapKey]){
          gapGroups[block.targetGapKey] = [];
        }
        gapGroups[block.targetGapKey].push(block);
      });

      const rewriteGroups = [];
      Object.keys(gapGroups).forEach(key=>{
        const group = gapGroups[key];
        if(group.length <= 1){
          return;
        }
        const target = group[0].targetGap;
        const prevLisIndex = target.before !== -1 && lisOrigToStudent.has(target.before) ? lisOrigToStudent.get(target.before) : -1;
        const nextLisIndex = target.after !== null && lisOrigToStudent.has(target.after) ? lisOrigToStudent.get(target.after) : userTokens.length;
        const minBlockStart = Math.min(...group.map(g=>g.start));
        const maxBlockEnd = Math.max(...group.map(g=>g.end));
        let rewriteStart = Math.min(minBlockStart, prevLisIndex + 1 || 0);
        let rewriteEnd = Math.max(maxBlockEnd, nextLisIndex);
        if(rewriteEnd === userTokens.length - 1 && userTokens[rewriteEnd] && userTokens[rewriteEnd].type === 'punct'){
          rewriteEnd -= 1;
        }
        const origIndexesInRange = orderedMatches
          .filter(m=>m.userIndex >= rewriteStart && m.userIndex <= rewriteEnd)
          .map(m=>m.origIndex);
        const maxOrig = Math.max(...origIndexesInRange, target.after !== null ? target.after - 1 : originalTokens.length - 1);
        const correctTokens = originalTokens.filter(t=> t.index > target.before && t.index <= maxOrig);
        const correctText = correctTokens.map(t=>t.raw).join(' ');
        const rewriteId = `rewrite-${rewriteGroups.length + 1}`;
        rewriteGroups.push({
          id: rewriteId,
          start: rewriteStart,
          end: rewriteEnd,
          targetGapKey: key,
          targetGap: target,
          correctText
        });
        group.forEach(block=>{ block.resolvedByRewrite = true; });
        for(let i = rewriteStart; i <= rewriteEnd; i++){
          if(!metaByUser[i]){
            metaByUser[i] = {};
          }
          metaByUser[i].rewriteGroupId = rewriteId;
          metaByUser[i].targetGapKey = key;
          metaByUser[i].targetGap = target;
        }
      });

      moveBlocks.forEach(block=>{
        if(block.resolvedByRewrite) return;
        block.tokens.forEach(idx=>{
          if(!metaByUser[idx]) metaByUser[idx] = {};
          metaByUser[idx].moveBlockId = block.id;
          metaByUser[idx].targetGapKey = block.targetGapKey;
          metaByUser[idx].targetGap = block.targetGap;
        });
      });

      const gapsNeeded = new Set();
      moveBlocks.forEach(block=>{ gapsNeeded.add(block.targetGapKey); });
      rewriteGroups.forEach(group=> gapsNeeded.add(group.targetGapKey));

      return { moveBlocks, rewriteGroups, tokenMeta: metaByUser, gapMeta, gapsNeeded };
    }

    function buildMissingByPosition(missing, matches, userLength){
      const map = {};
      if(!missing || !missing.length){
        return map;
      }
      const matchedByOrig = new Map();
      matches.forEach(m=>{
        matchedByOrig.set(m.origIndex, m.userIndex);
      });
      const sortedMissing = [...missing].sort((a,b)=>a.origIndex - b.origIndex);
      sortedMissing.forEach(item=>{
        let prevOrig = -1;
        let prevUser = -1;
        let nextOrig = null;
        matchedByOrig.forEach((userIdx, origIdx)=>{
          if(origIdx < item.origIndex && origIdx > prevOrig){
            prevOrig = origIdx;
            prevUser = userIdx;
          }
          if(origIdx > item.origIndex){
            if(nextOrig === null || origIdx < nextOrig){
              nextOrig = origIdx;
            }
          }
        });
        const gapKey = `${prevOrig}-${nextOrig === null ? 'END' : nextOrig}`;
        const pos = Math.max(0, prevUser + 1);
        if(!map[pos]){
          map[pos] = [];
        }
        map[pos].push({ token: item.token, gapKey });
      });
      if(map[userLength]){
        map[userLength] = map[userLength].sort((a,b)=>a.token.index - b.token.index);
      }
      return map;
    }

    function renderComparisonResult(resultEl, comparison){
      resultEl.innerHTML = '';
      const wrapper = document.createElement('div');
      wrapper.className = 'dictation-comparison';

      if(comparison.isCorrect){
        const success = document.createElement('div');
        success.className = 'dictation-success-msg';
        success.textContent = `✓ ${aiStrings.dictationPerfect || 'Отлично! Всё правильно!'}`;
        wrapper.appendChild(success);
        resultEl.appendChild(wrapper);
        return;
      }

      const errorCount = comparison.errorCount || 0;
      const errorWord = errorCount === 1 ? (aiStrings.dictationError || 'ошибка') :
                        errorCount < 5 ? (aiStrings.dictationErrors2 || 'ошибки') :
                        (aiStrings.dictationErrors5 || 'ошибок');
      const summary = document.createElement('div');
      summary.className = 'dictation-errors-summary';
      summary.textContent = `📝 ${errorCount} ${errorWord}`;
      wrapper.appendChild(summary);

      const visual = document.createElement('div');
      visual.className = 'dictation-visual';

      const userRow = buildUserLine(comparison);
      const correctRow = buildCorrectLine(comparison);
      visual.appendChild(userRow);
      visual.appendChild(correctRow);

      wrapper.appendChild(visual);
      resultEl.appendChild(wrapper);

      requestAnimationFrame(()=> drawDictationArrows(visual));
    }

    function buildUserLine(comparison){
      const row = document.createElement('div');
      row.className = 'dictation-comparison-row';
      const label = document.createElement('div');
      label.className = 'dictation-comparison-label';
      label.textContent = aiStrings.dictationYourAnswer || 'Ваш ответ:';
      const line = document.createElement('div');
      line.className = 'dictation-line dictation-line-user';

      const missingByPos = comparison.movePlan.missingByPosition || {};
      const meta = comparison.movePlan.tokenMeta || [];
      let idx = 0;
      while(idx < comparison.userTokens.length){
        if(missingByPos[idx]){
          missingByPos[idx].forEach(miss=>{
            const missSpan = document.createElement('span');
            missSpan.className = 'dictation-missing-token';
            missSpan.dataset.gapAnchor = miss.gapKey;
            const caret = document.createElement('span');
            caret.className = 'dictation-missing-caret';
            caret.textContent = '⋎';
            const word = document.createElement('span');
            word.className = 'dictation-missing-word';
            word.textContent = miss.token.raw;
            missSpan.appendChild(word);
            missSpan.appendChild(caret);
            line.appendChild(missSpan);
          });
        }
        const token = comparison.userTokens[idx];
        const metaInfo = meta[idx] || {};
        if(metaInfo.rewriteGroupId){
          const group = comparison.movePlan.rewriteGroups.find(r=>r.id === metaInfo.rewriteGroupId);
          const start = group ? group.start : idx;
          const end = group ? group.end : idx;
          const segmentTokens = comparison.userTokens.slice(start, end + 1).map(t=>t.raw).join(' ');
          const rewriteEl = document.createElement('span');
          rewriteEl.className = 'dictation-rewrite-block';
          if(group){
            rewriteEl.dataset.targetGap = group.targetGapKey;
          }
          const corrected = document.createElement('span');
          corrected.className = 'dictation-rewrite-correct';
          corrected.textContent = group ? group.correctText : '';
          const original = document.createElement('span');
          original.className = 'dictation-rewrite-original';
          original.textContent = segmentTokens;
          rewriteEl.appendChild(corrected);
          rewriteEl.appendChild(original);
          line.appendChild(rewriteEl);
          idx = end + 1;
          continue;
        }
        if(metaInfo.moveBlockId){
          const block = comparison.movePlan.moveBlocks.find(b=>b.id === metaInfo.moveBlockId);
          const blockEl = document.createElement('span');
          blockEl.className = 'dictation-move-block';
          blockEl.dataset.targetGap = metaInfo.targetGapKey;
          block.tokens.forEach(tIdx=>{
            const tMeta = meta[tIdx] || {};
            const t = comparison.userTokens[tIdx];
            const span = createTokenSpan(t, tMeta);
            span.classList.add('dictation-token-move');
            blockEl.appendChild(span);
          });
          line.appendChild(blockEl);
          idx = block.end + 1;
          continue;
        }
        const span = createTokenSpan(token, metaInfo);
        line.appendChild(span);
        idx++;
      }
      if(missingByPos[comparison.userTokens.length]){
        missingByPos[comparison.userTokens.length].forEach(miss=>{
          const missSpan = document.createElement('span');
          missSpan.className = 'dictation-missing-token';
          missSpan.dataset.gapAnchor = miss.gapKey;
          const caret = document.createElement('span');
          caret.className = 'dictation-missing-caret';
          caret.textContent = '⋎';
          const word = document.createElement('span');
          word.className = 'dictation-missing-word';
          word.textContent = miss.token.raw;
          missSpan.appendChild(word);
          missSpan.appendChild(caret);
          line.appendChild(missSpan);
        });
      }
      row.appendChild(label);
      row.appendChild(line);
      return row;
    }

    function buildCorrectLine(comparison){
      const row = document.createElement('div');
      row.className = 'dictation-comparison-row';
      const label = document.createElement('div');
      label.className = 'dictation-comparison-label';
      label.textContent = aiStrings.dictationShouldBe || 'Должно быть:';
      const line = document.createElement('div');
      line.className = 'dictation-line dictation-line-correct';

      const gapsNeeded = comparison.movePlan.gapsNeeded || new Set();
      const gapMeta = comparison.movePlan.gapMeta || {};
      const gapsByBefore = new Map();
      gapsNeeded.forEach(key=>{
        const meta = gapMeta[key];
        if(!meta) return;
        const before = meta.before === undefined ? -1 : meta.before;
        if(!gapsByBefore.has(before)){
          gapsByBefore.set(before, []);
        }
        gapsByBefore.get(before).push(key);
      });

      const insertAnchors = (beforeIdx)=>{
        const list = gapsByBefore.get(beforeIdx) || [];
        list.forEach(key=>{
          const anchor = document.createElement('span');
          anchor.className = 'dictation-gap-anchor';
          anchor.dataset.gapAnchor = key;
          const mark = document.createElement('span');
          mark.className = 'dictation-gap-mark';
          anchor.appendChild(mark);
          line.appendChild(anchor);
        });
      };

      insertAnchors(-1);
      comparison.originalTokens.forEach((token, idx)=>{
        const span = document.createElement('span');
        span.className = 'dictation-token dictation-token-ok';
        span.textContent = token.raw;
        line.appendChild(span);
        insertAnchors(idx);
      });

      row.appendChild(label);
      row.appendChild(line);
      return row;
    }

    function createTokenSpan(token, meta){
      const span = document.createElement('span');
      span.className = 'dictation-token';
      span.textContent = token.raw;
      if(meta && meta.hasError){
        span.classList.add('dictation-token-error');
        const fix = document.createElement('span');
        fix.className = 'dictation-token-correction';
        fix.textContent = meta.match ? meta.match.origToken.raw : '';
        span.appendChild(fix);
      }
      if(meta && meta.match){
        if(meta.inLis){
          span.classList.add(meta.hasError ? 'dictation-token-warn' : 'dictation-token-ok');
        }
      }
      if(meta && meta.needsMove){
        span.classList.add('dictation-token-move');
      }
      if(!meta || (!meta.match && !meta.hasError && !meta.moveBlockId && !meta.rewriteGroupId)){
        span.classList.add('dictation-token-extra');
      }
      return span;
    }

    function drawDictationArrows(container){
      const svgNS = 'http://www.w3.org/2000/svg';
      const old = container.querySelector('.dictation-arrow-layer');
      if(old){
        old.remove();
      }
      const blocks = container.querySelectorAll('[data-target-gap]');
      const anchors = container.querySelectorAll('[data-gap-anchor]');
      if(!blocks.length || !anchors.length){
        return;
      }
      const svg = document.createElementNS(svgNS, 'svg');
      svg.classList.add('dictation-arrow-layer');
      svg.setAttribute('width', container.offsetWidth);
      svg.setAttribute('height', container.offsetHeight);
      svg.setAttribute('viewBox', `0 0 ${container.offsetWidth} ${container.offsetHeight}`);
      const defs = document.createElementNS(svgNS, 'defs');
      const marker = document.createElementNS(svgNS, 'marker');
      marker.setAttribute('id', 'dictation-arrowhead');
      marker.setAttribute('markerWidth', '10');
      marker.setAttribute('markerHeight', '7');
      marker.setAttribute('refX', '10');
      marker.setAttribute('refY', '3.5');
      marker.setAttribute('orient', 'auto');
      const path = document.createElementNS(svgNS, 'path');
      path.setAttribute('d', 'M0,0 L10,3.5 L0,7 Z');
      path.setAttribute('fill', '#60a5fa');
      marker.appendChild(path);
      defs.appendChild(marker);
      svg.appendChild(defs);

      const containerRect = container.getBoundingClientRect();
      blocks.forEach(block=>{
        const gapKey = block.dataset.targetGap;
        const target = container.querySelector(`[data-gap-anchor="${gapKey}"]`);
        if(!target){
          return;
        }
        const blockRect = block.getBoundingClientRect();
        const targetRect = target.getBoundingClientRect();
        const startX = blockRect.left + (blockRect.width / 2) - containerRect.left;
        const startY = blockRect.top - containerRect.top;
        const endX = targetRect.left + (targetRect.width / 2) - containerRect.left;
        const endY = targetRect.top - containerRect.top + targetRect.height * 0.2;
        const controlY = Math.min(startY, endY) - 28;
        const p = document.createElementNS(svgNS, 'path');
        p.setAttribute('d', `M ${startX} ${startY} C ${startX} ${controlY}, ${endX} ${controlY}, ${endX} ${endY}`);
        p.setAttribute('fill', 'none');
        p.setAttribute('stroke', '#60a5fa');
        p.setAttribute('stroke-width', '2');
        p.setAttribute('marker-end', 'url(#dictation-arrowhead)');
        svg.appendChild(p);
      });
      container.appendChild(svg);
    }

    function escapeHtml(str){
      const div = document.createElement('div');
      div.textContent = str;
      return div.innerHTML;
    }
'''
new_text = text[:start] + new_block + text[end:]
path.write_text(new_text)
