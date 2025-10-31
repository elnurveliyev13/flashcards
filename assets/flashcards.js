/* global M */
(function(){
  function flashcardsInit(rootid, baseurl, cmid, instanceid, sesskey, globalMode){
    const root = document.getElementById(rootid);
    if(!root) return;
    const $ = s => root.querySelector(s);
    const $$ = s => Array.from(root.querySelectorAll(s));
    const uniq = a => [...new Set(a.filter(Boolean))];

    // Global mode detection: cmid = 0 OR globalMode = true
    const isGlobalMode = globalMode === true || cmid === 0;

    // Language detection (prefer Moodle, fallback to browser)
    const rawLang = (window.M && M.cfg && M.cfg.lang) || (navigator.language || 'en');
    const userLang = (rawLang || 'en').toLowerCase();
    const userLang2 = userLang.split(/[\-_]/)[0] || 'en';

    function languageName(code){
      const c = (code||'').toLowerCase();
      const map = {
        en:'English', no:'Norwegian', nb:'Norwegian', nn:'Norwegian (Nynorsk)',
        uk:'Ukrainian', ru:'Russian', pl:'Polish', de:'German', fr:'French', es:'Spanish'
      };
      return map[c] || c.toUpperCase();
    }

    // Prepare translation inputs visibility/labels
    try {
      const slotLocal = $("#slot_translation_local");
      const slotEn = $("#slot_translation_en");
      const tagLocal = $("#tag_trans_local");
      if(tagLocal){ tagLocal.textContent = `Translation (${languageName(userLang2)})`; }
      if(userLang2 === 'en'){
        if(slotLocal) slotLocal.classList.add('hidden');
        if(slotEn) slotEn.classList.remove('hidden');
      } else {
        if(slotLocal) slotLocal.classList.remove('hidden');
        if(slotEn) slotEn.classList.remove('hidden');
      }
    } catch(_e){}

    function pickTranslationFromPayload(p){
      const t = p && p.translations ? p.translations : null;
      if(t && typeof t === 'object'){
        return t[userLang2] || t[userLang] || t.en || '';
      }
      return p && (p.translation || p.back) || '';
    }

    function resolveAsset(u){ if(!u) return null; if(/^https?:|^blob:|^data:/.test(u)) return u; u=(u+'').replace(/^\/+/, ''); return baseurl + u; }
    async function api(action, params={}, method='GET', body){
      const opts = {method, headers:{'Content-Type':'application/json'}};
      if(body!==undefined && body!==null) opts.body = JSON.stringify(body);
      const url = new URL(M.cfg.wwwroot + '/mod/flashcards/ajax.php');
      // In global mode, pass cmid=0; in activity mode, pass actual cmid
      url.searchParams.set('cmid', isGlobalMode ? 0 : cmid);
      url.searchParams.set('action', action);
      url.searchParams.set('sesskey', sesskey);
      Object.entries(params||{}).forEach(([k,v])=>url.searchParams.set(k,v));
      const r = await fetch(url.toString(), opts);
      const j = await r.json();
      if(!j || j.ok!==true) throw new Error(j && j.error || 'Server error');
      return j.data;
    }
    async function syncFromServer(){
      try{
        // OPTIMIZED: Load only due cards initially (pagination + caching)
        const dueItems = await api('get_due_cards', {limit: 100});

        // Group cards by deckId (support multiple decks from server)
        const deckCards = {};
        (dueItems||[]).forEach(it => {
          const deckId = String(it.deckId || MY_DECK_ID); // Convert numeric deckId to string
          if(!deckCards[deckId]) deckCards[deckId] = [];

          const p = it.payload||{};
          deckCards[deckId].push({
            id: it.cardId,
            text: p.text||p.front||"",
            translation: pickTranslationFromPayload(p),
            translations: (p.translations && typeof p.translations==='object') ? p.translations : null,
            explanation: p.explanation||"",
            image: p.image||null,
            imageKey: p.imageKey||null,
            audio: p.audio||null,
            audioKey: p.audioKey||null,
            order: Array.isArray(p.order)?p.order:[],
            _progress: it.progress // Keep progress for later
          });
        });

        // Merge server cards into registry (preserving existing cards)
        Object.entries(deckCards).forEach(([deckId, cards]) => {
          // Create deck if doesn't exist
          if(!registry[deckId]) {
            registry[deckId] = {
              id: deckId,
              title: deckId === MY_DECK_ID ? "My cards" : `Deck ${deckId}`,
              cards: []
            };
          }

          // Merge cards (update existing, add new, but DON'T remove old)
          const existingCardIds = new Set(registry[deckId].cards.map(c => c.id));
          cards.forEach(card => {
            const existingIdx = registry[deckId].cards.findIndex(c => c.id === card.id);
            if(existingIdx >= 0) {
              // Update existing card (preserve order if not specified)
              const existing = registry[deckId].cards[existingIdx];
              registry[deckId].cards[existingIdx] = {
                ...existing,
                ...card,
                order: card.order.length > 0 ? card.order : existing.order
              };
            } else {
              // Add new card
              registry[deckId].cards.push(card);
            }
          });
        });
        saveRegistry();

        // Update progress from server (without destroying existing progress)
        if(!state.decks) state.decks = {};

        (dueItems||[]).forEach(it => {
          const deckId = String(it.deckId || MY_DECK_ID);
          if(!state.decks[deckId]) state.decks[deckId] = {};

          const prog = it.progress || {};
          state.decks[deckId][it.cardId] = {
            step: prog.step || 0,
            due: (prog.due || 0) * 1000,
            addedAt: (prog.addedAt || 0) * 1000,
            lastAt: (prog.lastAt || 0) * 1000,
            hidden: 0
          };
        });
        saveState();

        ensureAllProgress();
      }catch(e){ console.error('syncFromServer error:', e); }
    }
    function ensureAllProgress(){ Object.values(registry||{}).forEach(d=>ensureDeckProgress(d.id,d.cards)); }

    // Dates / SRS
    // TEST MODE: Using minutes instead of days for quick testing
    function today0(){return Date.now();} // Return current timestamp instead of midnight

    // Calculate due timestamp based on exponential intervals (2^n)
    // Step 0: card created (due=now)
    // Step 1-10: 1,2,4,8,16,32,64,128,256,512 days (in test: minutes)
    // Step 11+: completed (checkmark)
    function calculateDue(currentTime, step, easy){
      if(step <= 0) return currentTime;
      if(step > 10) return currentTime + (512 * 60 * 1000); // Far in future
      const daysInterval = Math.pow(2, step - 1);
      const actualInterval = easy ? daysInterval : Math.max(1, daysInterval / 2);
      return currentTime + (actualInterval * 60 * 1000); // Convert to milliseconds
    }

    // Storage
    const PROFILE_KEY="srs-profile";
    function storageKey(base){return `${base}:${localStorage.getItem(PROFILE_KEY)||"Guest"}`;}
    const STORAGE_STATE="srs-v6:state", STORAGE_REG="srs-v6:registry";
    const MY_DECK_ID="my-deck";
    const DEFAULT_ORDER=["audio","image","text","explanation","translation"];
    let state,registry,queue=[],current=-1,visibleSlots=1,currentItem=null;
    function loadState(){state=JSON.parse(localStorage.getItem(storageKey(STORAGE_STATE))||'{"active":{},"decks":{},"hidden":{}}'); registry=JSON.parse(localStorage.getItem(storageKey(STORAGE_REG))||'{}');}
    function saveState(){localStorage.setItem(storageKey(STORAGE_STATE),JSON.stringify(state));}
    function saveRegistry(){localStorage.setItem(storageKey(STORAGE_REG),JSON.stringify(registry));}
    function ensureDeckProgress(deckId,cards){ if(!state.decks[deckId]) state.decks[deckId]={}; const m=state.decks[deckId]; (cards||[]).forEach(c=>{ if(!m[c.id]) m[c.id]={step:0,due:today0(),addedAt:today0(),lastAt:null}; }); }
    function refreshSelect(){const s=$("#deckSelect"); s.innerHTML=""; Object.values(registry||{}).forEach(d=>{const o=document.createElement("option");o.value=d.id;o.textContent=`${d.title} - ${d.cards.length} kort`; s.appendChild(o);});}
    function updateBadge(){const act=Object.keys(state?.active||{}).filter(id=>state.active[id]); const lbl=$('#badge'); if(!lbl) return; const base=(lbl.dataset&&lbl.dataset.label)||lbl.textContent; const actlbl=$('#btnActivate')? $('#btnActivate').textContent : 'Activate'; lbl.textContent = act.length?`${actlbl}: ${act.length}`:base; }

    // IndexedDB
    function openDB(){return new Promise((res,rej)=>{const r=indexedDB.open("srs-media",1); r.onupgradeneeded=e=>{e.target.result.createObjectStore("files")}; r.onsuccess=e=>res(e.target.result); r.onerror=()=>rej(r.error);});}
    async function idbPut(k,b){const db=await openDB(); return new Promise((res,rej)=>{const tx=db.transaction("files","readwrite"); tx.oncomplete=()=>res(); tx.onerror=()=>rej(tx.error); tx.objectStore("files").put(b,k);});}
    async function idbGet(k){const db=await openDB(); return new Promise((res,rej)=>{const tx=db.transaction("files","readonly"); const rq=tx.objectStore("files").get(k); rq.onsuccess=()=>res(rq.result||null); rq.onerror=()=>rej(rq.error);});}
    async function urlFor(k){const b=await idbGet(k); return b?URL.createObjectURL(b):null;}

    // UI helpers
    const slotContainer=$("#slotContainer"), emptyState=$("#emptyState");

    // New pill-based stage visualization
    // Stage 0-10: fill pill left-to-right, show number
    // Stage 11+: green pill with checkmark
    function setStage(step){
      if(step < 0) step = 0;
      const stageBadge = $("#stageBadge");
      const stageEmoji = $("#stageEmoji");
      const stageText = $("#stageText");

      if(!stageBadge) return;
      stageBadge.classList.remove("hidden");

      // Stage 11+: completed (green checkmark)
      if(step > 10){
        if(stageEmoji) stageEmoji.textContent = "\u2713";
        if(stageText) stageText.textContent = "";
        stageBadge.style.background = "linear-gradient(90deg, #4caf50 100%, #e0e0e0 0%)";
        stageBadge.style.color = "#fff";
        stageBadge.style.justifyContent = "center";
        return;
      }

      // Stage 0-10: show number and fill percentage
      const fillPercent = step === 0 ? 0 : (step / 10) * 100;
      if(stageEmoji) stageEmoji.textContent = "\u2713";
      if(stageText) stageText.textContent = String(step);
      stageBadge.style.background = `linear-gradient(90deg, #2196f3 ${fillPercent}%, #e0e0e0 ${fillPercent}%)`;
      stageBadge.style.color = step > 5 ? "#fff" : "#333"; // White text when >50% filled
      stageBadge.style.justifyContent = "center";
    }
    function setDue(n){ const el=$("#due"); if(!el) return; el.textContent = String(n); }

    let audioURL=null; const player=new Audio();
    let lastImageKey=null,lastAudioKey=null; let lastImageUrl=null,lastAudioUrl=null; let rec=null,recChunks=[],camStream=null;
    let editingCardId=null; // Track which card is being edited to prevent cross-contamination
    // Top-right action icons helpers
    const btnEdit = $("#btnEdit"), btnDel=$("#btnDel"), btnPlayBtn=$("#btnPlay"), btnPlaySlowBtn=$("#btnPlaySlow"), btnUpdate=$("#btnUpdate");
    function setIconVisibility(hasCard){
      // Edit/Delete should remain visible even if no card (like original), but disabled.
      if(btnEdit){ btnEdit.classList.remove('hidden'); btnEdit.disabled = !hasCard; }
      if(btnDel){ btnDel.classList.remove('hidden'); btnDel.disabled = !hasCard; }
    }
    function hidePlayIcons(){ if(btnPlayBtn) btnPlayBtn.classList.add('hidden'); if(btnPlaySlowBtn) btnPlaySlowBtn.classList.add('hidden'); }
    function attachAudio(url){ audioURL=url; if(btnPlayBtn) btnPlayBtn.classList.remove("hidden"); if(btnPlaySlowBtn) btnPlaySlowBtn.classList.remove("hidden"); if(btnPlayBtn){ btnPlayBtn.onclick=()=>{ if(!audioURL)return; player.src=audioURL; player.playbackRate=1; player.currentTime=0; player.play().catch(()=>{}); }; } if(btnPlaySlowBtn){ btnPlaySlowBtn.onclick=()=>{ if(!audioURL)return; player.src=audioURL; player.playbackRate=0.67; player.currentTime=0; player.play().catch(()=>{}); }; }
    }
        function openEditor(){
      const grid = root.querySelector(".grid");
      const wrap = $("#cardCreationFormWrap");
      if(grid) grid.classList.add("edit-mode");
      if(wrap) wrap.classList.remove("card-form-collapsed");
    }
    function closeEditor(){
      const grid = root.querySelector(".grid");
      const wrap = $("#cardCreationFormWrap");
      if(grid) grid.classList.remove("edit-mode");
      if(wrap) wrap.classList.add("card-form-collapsed");
      editingCardId=null;
      const _btnUpdate = $("#btnUpdate");
      if(_btnUpdate) _btnUpdate.disabled = true;
    }function normalizeLessonCard(c){ if(c.front && !c.text) c.text=c.front; if(c.back&&!c.translation) c.translation=c.back; if(!Array.isArray(c.order)||!c.order.length){ c.order=[]; if(c.audio||c.audioKey) c.order.push("audio"); if(c.image||c.imageKey) c.order.push("image"); if(c.text) c.order.push("text"); if(c.explanation) c.order.push("explanation"); if(c.translation) c.order.push("translation"); } c.order=uniq(c.order); return c; }
    async function buildSlot(kind, card){ const el=document.createElement("div"); el.className="slot"; if(kind==="text" && card.text){ el.innerHTML=`<div class="front">${card.text}</div>`; return el; } if(kind==="explanation" && card.explanation){ el.innerHTML=`<div class="back">${card.explanation}</div>`; return el; } if(kind==="translation" && card.translation){ el.innerHTML=`<div class="back">${card.translation}</div>`; return el; } if(kind==="image" && (card.image||card.imageKey)){ const url=card.imageKey?await urlFor(card.imageKey):resolveAsset(card.image); if(url){ const img=document.createElement("img"); img.src=url; img.className="media"; el.appendChild(img); return el; } } if(kind==="audio" && (card.audio||card.audioKey)){ const url=card.audioKey?await urlFor(card.audioKey):resolveAsset(card.audio); if(url){ attachAudio(url); el.innerHTML=`<div class="pill">Audio</div>`; el.dataset.autoplay=url; return el; } } return null; }
    async function renderCard(card, count){ slotContainer.innerHTML=""; hidePlayIcons(); audioURL=null; const allSlots=[]; for(const kind of card.order){ const el=await buildSlot(kind,card); if(el) allSlots.push(el); } const items=allSlots.slice(0,count); if(!items.length){ const d=document.createElement("div"); d.className="front"; d.textContent="-"; items.push(d); } items.forEach(x=>slotContainer.appendChild(x)); if(count===1 && items[0] && items[0].dataset && items[0].dataset.autoplay){ player.src=items[0].dataset.autoplay; player.playbackRate=1; player.currentTime=0; player.play().catch(()=>{}); } card._availableSlots=allSlots.length; }

    function buildQueue(){
      const now=today0();
      queue=[]; current=-1; currentItem=null;
      const act=Object.keys(state?.active||{}).filter(id=>state.active[id]);

      console.log(`[buildQueue] Active decks:`, act);
      console.log(`[buildQueue] Current time:`, now, new Date(now));

      act.forEach(id=>{
        const d=registry[id];
        if(!d) {
          console.warn(`[buildQueue] Deck ${id} is active but not found in registry`);
          return;
        }
        ensureDeckProgress(id,d.cards);

        console.log(`[buildQueue] Processing deck ${id}: ${d.cards?.length || 0} cards`);

        (d.cards||[]).forEach(c=>{
          if(state.hidden && state.hidden[id] && state.hidden[id][c.id]) return;
          const nc=normalizeLessonCard(Object.assign({}, c));
          const r=state.decks[id][c.id];

          const isDue = r.due <= now;
          if(!isDue) {
            console.log(`[buildQueue] Card ${c.id} NOT due: due=${r.due} (${new Date(r.due)}), now=${now}`);
          } else {
            console.log(`[buildQueue] Card ${c.id} IS due: due=${r.due} (${new Date(r.due)})`);
            queue.push({deckId:id,card:nc,rec:r,index:queue.length});
          }
        });
      });

      console.log(`[buildQueue] Total due cards: ${queue.length}`);
      setDue(queue.length);

      if(queue.length===0){
        slotContainer.innerHTML="";
        const sb=$("#stageBadge"); if(sb) sb.classList.add("hidden");
        emptyState.classList.remove("hidden");
        const br=$("#btnRevealNext"); if(br){ br.disabled=true; br.classList.remove("primary"); }
        setIconVisibility(false); hidePlayIcons();
        return;
      }
      emptyState.classList.add("hidden");
      current=0; visibleSlots=1; currentItem=queue[current];
      showCurrent();
    }
    function updateRevealButton(){ const more = currentItem && currentItem.card._availableSlots && visibleSlots < currentItem.card._availableSlots; const br=$("#btnRevealNext"); if(br){ br.disabled=!more; br.classList.toggle("primary",!!more); } }
    async function showCurrent(){ if(!currentItem){ updateRevealButton(); setIconVisibility(false); hidePlayIcons(); return; } setStage(currentItem.rec.step||0); await renderCard(currentItem.card, visibleSlots); setIconVisibility(true); updateRevealButton(); }

    async function syncProgressToServer(deckId, cardId, rec){
      try{
        const payload = {records:[{deckId, cardId, step:rec.step, due:Math.floor(rec.due/1000), addedAt:Math.floor(rec.addedAt/1000), lastAt:Math.floor(rec.lastAt/1000), hidden:rec.hidden||0}]};
        await api('save', {}, 'POST', payload);
      }catch(e){ console.error('Failed to sync progress:', e); }
    }
    // Easy: advance stage, full interval (2^n)
    function rateEasy(){
      if(!currentItem) return;
      const it = currentItem;
      const now = today0();

      // Advance to next stage (max 11, where 11 = checkmark)
      it.rec.step = Math.min(11, it.rec.step + 1);
      it.rec.lastAt = now;
      it.rec.due = calculateDue(now, it.rec.step, true); // true = easy (full interval)

      saveState();
      syncProgressToServer(it.deckId, it.card.id, it.rec);

      // Remove from queue
      queue.splice(current, 1);
      if(queue.length === 0){
        buildQueue();
      } else {
        currentItem = queue[Math.min(current, queue.length - 1)];
        visibleSlots = 1;
        showCurrent();
        setDue(queue.length);
      }
    }

    // Normal: advance stage, half interval (2^n / 2)
    function rateNormal(){
      if(!currentItem) return;
      const it = currentItem;
      const now = today0();

      // Advance to next stage (max 11)
      it.rec.step = Math.min(11, it.rec.step + 1);
      it.rec.lastAt = now;
      it.rec.due = calculateDue(now, it.rec.step, false); // false = normal (half interval)

      saveState();
      syncProgressToServer(it.deckId, it.card.id, it.rec);

      // Remove from queue
      queue.splice(current, 1);
      if(queue.length === 0){
        buildQueue();
      } else {
        currentItem = queue[Math.min(current, queue.length - 1)];
        visibleSlots = 1;
        showCurrent();
        setDue(queue.length);
      }
    }

    // Hard: stage stays same, card reappears tomorrow (1 minute in test mode)
    function rateHard(){
      if(!currentItem) return;
      const it = currentItem;
      const now = today0();

      // Step doesn't change
      it.rec.lastAt = now;
      it.rec.due = now + (1 * 60 * 1000); // +1 minute

      saveState();
      syncProgressToServer(it.deckId, it.card.id, it.rec);

      // Move to end of queue
      queue.push(it);
      queue.splice(current, 1);
      currentItem = queue[Math.min(current, queue.length - 1)];
      visibleSlots = 1;
      showCurrent();
      setDue(queue.length);
    }

    $("#btnRevealNext").addEventListener("click",()=>{ if(!currentItem)return; visibleSlots=Math.min(currentItem.card.order.length, visibleSlots+1); showCurrent(); });
    $("#btnEasy").addEventListener("click",rateEasy);
    $("#btnNormal").addEventListener("click",rateNormal);
    $("#btnHard").addEventListener("click",rateHard);

    // Fallback handlers for bottom action bar (work even if flashcards-ux.js is not loaded)
    const _btnEasyBottom = $("#btnEasyBottom");
    const _btnNormalBottom = $("#btnNormalBottom");
    const _btnHardBottom = $("#btnHardBottom");
    if(_btnEasyBottom && !_btnEasyBottom.dataset.bound){ _btnEasyBottom.dataset.bound = '1'; _btnEasyBottom.addEventListener('click', e=>{ e.preventDefault(); rateEasy(); }); }
    if(_btnNormalBottom && !_btnNormalBottom.dataset.bound){ _btnNormalBottom.dataset.bound = '1'; _btnNormalBottom.addEventListener('click', e=>{ e.preventDefault(); rateNormal(); }); }
    if(_btnHardBottom && !_btnHardBottom.dataset.bound){ _btnHardBottom.dataset.bound = '1'; _btnHardBottom.addEventListener('click', e=>{ e.preventDefault(); rateHard(); }); }

    // Keep bottom action bar aligned to content width (avoids overflow on Android themes)
    function syncBottomBarWidth(){
      const bar = $("#bottomActions");
      const wrap = root.querySelector('.wrap');
      if(!bar || !wrap) return;
      const rect = wrap.getBoundingClientRect();
      // Align to the visible content area
      bar.style.width = rect.width + 'px';
      bar.style.left = rect.left + 'px';
      bar.style.right = 'auto';
      bar.style.transform = 'none';
    }
    // Initial and reactive alignment
    try{
      syncBottomBarWidth();
      window.addEventListener('resize', syncBottomBarWidth, {passive:true});
      window.addEventListener('orientationchange', syncBottomBarWidth);
      window.addEventListener('scroll', syncBottomBarWidth, {passive:true});
      // Re-align after fonts/UI settle
      setTimeout(syncBottomBarWidth, 200);
      setTimeout(syncBottomBarWidth, 600);
    }catch(_e){}

    var _btnReset=$("#btnReset"); if(_btnReset){ _btnReset.addEventListener("click",()=>{ if(confirm("Reset?")){ state={active:{},decks:{},hidden:{}}; saveState(); buildQueue(); updateBadge(); } }); }
    var _btnExport=$("#btnExport"); if(_btnExport){ _btnExport.addEventListener("click",()=>{ const blob=new Blob([JSON.stringify({state,registry},null,2)],{type:"application/json"}); const a=document.createElement("a"); a.href=URL.createObjectURL(blob); a.download="srs_export.json"; a.click(); }); }
    var _btnImport=$("#btnImport"); var _fileImport=$("#fileImport"); if(_btnImport && _fileImport){ _btnImport.addEventListener("click",()=>_fileImport.click()); _fileImport.addEventListener("change",async e=>{const f=e.target.files?.[0]; if(!f)return; try{ const d=JSON.parse(await f.text()); if(d.state)state=d.state; if(d.registry)registry=d.registry; saveState(); saveRegistry(); refreshSelect(); updateBadge(); buildQueue();
      closeEditor();
      $("#status").textContent="OK"; setTimeout(()=>$("#status").textContent="",1200); }catch{ $("#status").textContent="Bad deck"; setTimeout(()=>$("#status").textContent="",1500); }}); }
    $("#packFile").addEventListener("change",async e=>{const f=e.target.files?.[0]; if(!f)return; try{ const p=JSON.parse(await f.text()); if(!p.id)p.id="pack-"+Date.now().toString(36); p.cards=(p.cards||[]).map(x=>normalizeLessonCard(x)); registry[p.id]=p; saveRegistry(); refreshSelect(); if(!state.active) state.active={}; state.active[p.id]=true; saveState(); updateBadge(); buildQueue(); $("#deckSelect").value=p.id; }catch{ $("#status").textContent="Bad deck"; setTimeout(()=>$("#status").textContent="",1500); }});
    $("#btnActivate").addEventListener("click",()=>{ const id=$("#deckSelect").value; if(!id)return; if(!state.active) state.active={}; state.active[id]=true; saveState(); updateBadge(); buildQueue(); });

    $("#btnEdit").addEventListener("click",()=>{
      if(!currentItem) return;
      const c=currentItem.card;

      // Set editing card ID to prevent media from being attached to other cards
      editingCardId = c.id;

      // Enable Update button (both buttons always visible now)
      if(btnUpdate) btnUpdate.disabled = false;

      // Reset media variables when starting to edit
      lastImageKey = c.imageKey || null;
      lastAudioKey = c.audioKey || null;
      lastImageUrl = c.image || null;
      lastAudioUrl = c.audio || null;

      $("#uFront").value=c.text||"";
      $("#uExplanation").value=c.explanation||"";
      const __tr = c.translations || {};
      const __tl = $("#uTransLocal"); if(__tl) __tl.value = (userLang2 !== 'en') ? (__tr[userLang2] || c.translation || "") : "";
      const __te = $("#uTransEn"); if(__te) __te.value = (__tr.en || (userLang2 === 'en' ? (c.translation || "") : ""));

      (async()=>{
        $("#imgPrev").classList.add("hidden");
        $("#audPrev").classList.add("hidden");

        if(c.imageKey){
          const u=await urlFor(c.imageKey);
          if(u){$("#imgPrev").src=u; $("#imgPrev").classList.remove("hidden");}
        } else if(c.image){
          $("#imgPrev").src=resolveAsset(c.image);
          $("#imgPrev").classList.remove("hidden");
        }

        if(c.audioKey){
          const u=await urlFor(c.audioKey);
          if(u){$("#audPrev").src=u; $("#audPrev").classList.remove("hidden");}
        } else if(c.audio){
          $("#audPrev").src=resolveAsset(c.audio);
          $("#audPrev").classList.remove("hidden");
        }
      })();

      orderChosen=[...(c.order||[])];
      updateOrderPreview();
      const grid = root.querySelector(".grid");
      if (grid) grid.scrollIntoView({behavior:"smooth"}); openEditor();
    const _btnAddNew = $("#btnAddNew");
    if(_btnAddNew && !_btnAddNew.dataset.bound){
      _btnAddNew.dataset.bound = "1";
      _btnAddNew.addEventListener("click", ()=>{
        editingCardId=null;
        resetForm();
        const _up=$("#btnUpdate"); if(_up) _up.disabled=true;
        openEditor();
      });
    }
    });
    $("#btnDel").addEventListener("click",async()=>{ if(!currentItem) return; const {deckId,card}=currentItem; if(!confirm("Delete this card?")) return; try { await api('delete_card', {deckid:deckId, cardid:card.id}, 'POST'); } catch(e) { /* May fail for shared cards, fallback to local removal */ } const arr=registry[deckId]?.cards||[]; const ix=arr.findIndex(x=>x.id===card.id); if(ix>=0){arr.splice(ix,1); saveRegistry();} if(state.decks[deckId]) delete state.decks[deckId][card.id]; if(state.hidden && state.hidden[deckId]) delete state.hidden[deckId][card.id]; saveState(); buildQueue(); });

    // removed duplicate var line
    $("#btnChooseImg").addEventListener("click",()=>$("#uImage").click());
    $("#btnChooseAud").addEventListener("click",()=>$("#uAudio").click());
    async function uploadMedia(file,type,cardId){ const fd=new FormData(); fd.append('file',file,file.name||('blob.'+(type==='audio'?'webm':'jpg'))); fd.append('type',type); const url=new URL(M.cfg.wwwroot + '/mod/flashcards/ajax.php'); url.searchParams.set('cmid',cmid); url.searchParams.set('action','upload_media'); url.searchParams.set('sesskey',sesskey); if(cardId) url.searchParams.set('cardid',cardId); const r= await fetch(url.toString(),{method:'POST',body:fd}); const j=await r.json(); if(j && j.ok && j.data && j.data.url) return j.data.url; throw new Error('upload failed'); }
    $("#uImage").addEventListener("change", async e=>{const f=e.target.files?.[0]; if(!f)return; $("#imgName").textContent=f.name; lastImageKey="my-"+Date.now().toString(36)+"-img"; await idbPut(lastImageKey,f); lastImageUrl=null; $("#imgPrev").src=URL.createObjectURL(f); $("#imgPrev").classList.remove("hidden");});
    $("#uAudio").addEventListener("change", async e=>{const f=e.target.files?.[0]; if(!f)return; $("#audName").textContent=f.name; lastAudioKey="my-"+Date.now().toString(36)+"-aud"; await idbPut(lastAudioKey,f); lastAudioUrl=null; $("#audPrev").src=URL.createObjectURL(f); $("#audPrev").classList.remove("hidden");});
    $("#btnClearImg").addEventListener("click",()=>{ lastImageKey=null; lastImageUrl=null; $("#uImage").value=""; const img=$("#imgPrev"); if(img){ img.classList.add("hidden"); img.removeAttribute('src'); } $("#imgName").textContent=""; });
    $("#btnClearAud").addEventListener("click",()=>{ lastAudioKey=null; lastAudioUrl=null; $("#uAudio").value=""; const a=$("#audPrev"); if(a){ try{a.pause();}catch(e){} a.removeAttribute('src'); a.load(); a.classList.add("hidden"); } $("#audName").textContent=""; });
    $("#btnOpenCam").addEventListener("click",async()=>{try{camStream=await navigator.mediaDevices.getUserMedia({video:{facingMode:"environment"},audio:false});const v=$("#cam");v.srcObject=camStream;v.classList.remove("hidden");$("#btnShot").classList.remove("hidden");$("#btnCloseCam").classList.remove("hidden");}catch{}});
    $("#btnCloseCam").addEventListener("click",()=>{if(camStream){camStream.getTracks().forEach(t=>t.stop());camStream=null;}$("#cam").classList.add("hidden");$("#btnShot").classList.add("hidden");$("#btnCloseCam").classList.add("hidden");});
    $("#btnShot").addEventListener("click",()=>{const v=$("#cam");if(!v.srcObject)return;const c=document.createElement("canvas");c.width=v.videoWidth;c.height=v.videoHeight;c.getContext("2d").drawImage(v,0,0,c.width,c.height);c.toBlob(async b=>{lastImageKey="my-"+Date.now().toString(36)+"-img";await idbPut(lastImageKey,b); lastImageUrl=null; $("#imgPrev").src=URL.createObjectURL(b);$("#imgPrev").classList.remove("hidden");},"image/jpeg",0.92);});
    $("#btnRec").addEventListener("click",async()=>{try{const stream=await navigator.mediaDevices.getUserMedia({audio:true,video:false});recChunks=[];const mime=MediaRecorder.isTypeSupported("audio/webm")?"audio/webm":"audio/mp4";rec=new MediaRecorder(stream,{mimeType:mime});rec.ondataavailable=e=>{if(e.data.size>0)recChunks.push(e.data);};rec.onstop=async()=>{const blob=new Blob(recChunks,{type:mime});lastAudioKey="my-"+Date.now().toString(36)+"-aud";await idbPut(lastAudioKey,blob); lastAudioUrl=null; $("#audPrev").src=URL.createObjectURL(blob);$("#audPrev").classList.remove("hidden");stream.getTracks().forEach(t=>t.stop());};rec.start();$("#btnRec").classList.add("hidden");$("#btnStop").classList.remove("hidden");}catch{}});
    $("#btnStop").addEventListener("click",()=>{if(rec){rec.stop();rec=null;}$("#btnStop").classList.add("hidden");$("#btnRec").classList.remove("hidden");});

    let orderChosen=[];
    function updateOrderPreview(){ const chipsMap={ audio: $("#chip_audio")? $("#chip_audio").textContent:'audio', image: $("#chip_image")? $("#chip_image").textContent:'image', text: $("#chip_text")? $("#chip_text").textContent:'text', explanation: $("#chip_explanation")? $("#chip_explanation").textContent:'explanation', translation: $("#chip_translation")? $("#chip_translation").textContent:'translation' }; $$("#orderChips .chip").forEach(ch=>{ ch.classList.toggle("active", orderChosen.includes(ch.dataset.kind)); }); const pretty=(orderChosen.length?orderChosen:DEFAULT_ORDER).map(k=>chipsMap[k]).join(' -> '); $("#orderPreview").textContent=pretty; }
    $("#orderChips").addEventListener("click",e=>{const btn=e.target.closest(".chip"); if(!btn)return; const k=btn.dataset.kind; const i=orderChosen.indexOf(k); if(i===-1) orderChosen.push(k); else orderChosen.splice(i,1); updateOrderPreview();});
    function resetForm(){
      $("#uFront").value="";
      $("#uExplanation").value="";
      const _tl=$("#uTransLocal"); if(_tl) _tl.value="";
      const _te=$("#uTransEn"); if(_te) _te.value="";
      $("#uImage").value="";
      $("#uAudio").value="";
      $("#imgPrev").classList.add("hidden");
      $("#audPrev").classList.add("hidden");
      $("#imgName").textContent="";
      $("#audName").textContent="";
      lastImageKey=null;
      lastAudioKey=null;
      lastImageUrl=null;
      lastAudioUrl=null;
      editingCardId=null; // Clear editing card ID when resetting form
      orderChosen=[];
      updateOrderPreview();

      // Disable Update button when no card is being edited
      const btnUpdate = $("#btnUpdate");
      if(btnUpdate) btnUpdate.disabled = true;
    }
    $("#btnFormReset").addEventListener("click", resetForm);
        const btnCancel = $("#btnCancelEdit");
    if(btnCancel && !btnCancel.dataset.bound){
      btnCancel.dataset.bound = "1";
      btnCancel.addEventListener("click", e=>{ e.preventDefault(); closeEditor(); });
    }
    // Bind global "+" (Create new card) button at init
    (function(){
      const addBtn = $("#btnAddNew");
      if(addBtn && !addBtn.dataset.bound){
        addBtn.dataset.bound = "1";
        addBtn.addEventListener("click", e => {
          e.preventDefault();
          editingCardId = null;
          resetForm();
          const _up=$("#btnUpdate"); if(_up) _up.disabled=true;
          openEditor();
        });
      }
    })();
    // Fallback toggle for the collapsible card creation form (when UX script isn't active)
    const _btnToggleForm = $("#btnToggleForm");
    const _cardCreationFormWrap = $("#cardCreationFormWrap");
    if(_btnToggleForm && _cardCreationFormWrap && !_btnToggleForm.dataset.bound){
      _btnToggleForm.dataset.bound = '1';
      _btnToggleForm.addEventListener('click', e => {
        e.preventDefault();
        _cardCreationFormWrap.classList.toggle('card-form-collapsed');
      });
    }
    // Shared function for both Add and Update buttons
    async function saveCard(isUpdate){
      const text=$("#uFront").value.trim(), expl=$("#uExplanation").value.trim();
      const trLocalEl=$("#uTransLocal"), trEnEl=$("#uTransEn");
      const trLocal = trLocalEl ? trLocalEl.value.trim() : "";
      const trEn = trEnEl ? trEnEl.value.trim() : "";
      if(!text && !expl && !tr && !lastImageKey && !lastAudioKey && $("#imgPrev").classList.contains("hidden") && $("#audPrev").classList.contains("hidden")){
        $("#status").textContent="Empty"; setTimeout(()=>$("#status").textContent="",1000); return;
      }

      // If editing existing card, use that ID; otherwise create new
      const id = editingCardId || ("my-"+Date.now().toString(36));

      // Upload media files to server NOW (with correct cardId)
      if(lastImageKey && !lastImageUrl){
        try{
          const blob = await idbGet(lastImageKey);
          if(blob){
            lastImageUrl = await uploadMedia(new File([blob], 'image.jpg', {type: blob.type || 'image/jpeg'}), 'image', id);
          }
        }catch(e){ console.error('Image upload failed:', e); }
      }

      if(lastAudioKey && !lastAudioUrl){
        try{
          const blob = await idbGet(lastAudioKey);
          if(blob){
            lastAudioUrl = await uploadMedia(new File([blob], 'audio.webm', {type: blob.type || 'audio/webm'}), 'audio', id);
          }
        }catch(e){ console.error('Audio upload failed:', e); }
      }

      const translations={};
      if(userLang2 !== 'en' && trLocal){ translations[userLang2]=trLocal; }
      if(trEn){ translations['en']=trEn; }
      const translationDisplay = (userLang2 !== 'en' ? (translations[userLang2] || translations['en'] || "") : (translations['en'] || ""));
      const payload={id,text,explanation:expl,translation:translationDisplay,translations,order:(orderChosen.length?orderChosen:[...DEFAULT_ORDER])};
      if(lastImageUrl) payload.image=lastImageUrl; else if(lastImageKey) payload.imageKey=lastImageKey;
      if(lastAudioUrl) payload.audio=lastAudioUrl; else if(lastAudioKey) payload.audioKey=lastAudioKey;

      // Save to server and get back the real deckId
      let serverDeckId = MY_DECK_ID;
      try{
        const result = await api('upsert_card', {}, 'POST', {deckId:null,cardId:id,scope:'private',payload});
        if(result && result.ok) {
          if(result.deckId) {
            serverDeckId = String(result.deckId); // Use server's deckId
          }
        } else if(result && !result.ok) {
          // Server rejected (e.g., grace period, no access)
          const msg = result.error || 'Access denied. You cannot create cards during grace period or after access expires.';
          $("#status").textContent = msg;
          setTimeout(()=>$("#status").textContent="", 3000);
          console.error('upsert_card rejected:', msg);
          return; // STOP - don't save to localStorage
        }
      }catch(e){
        console.error('upsert_card error:', e);
        // Check if it's an access error
        if(e.message && (e.message.includes('access') || e.message.includes('grace') || e.message.includes('blocked'))) {
          $("#status").textContent = "Access denied. Cannot create cards.";
          setTimeout(()=>$("#status").textContent="", 3000);
          return; // STOP - don't save to localStorage
        }
        // For other errors (network, etc), continue with local save
      }

      // Also keep local for offline use (use server's deckId if available)
      const localDeckId = serverDeckId;
      if(!registry[localDeckId]) registry[localDeckId]={id:localDeckId,title:"My cards",cards:[]};

      // If editing, update existing card; otherwise add new
      if(editingCardId){
        const existingIdx = registry[localDeckId].cards.findIndex(c => c.id === editingCardId);
        if(existingIdx >= 0){
          registry[localDeckId].cards[existingIdx] = {id,...payload};
        } else {
          registry[localDeckId].cards.push({id,...payload});
        }
      } else {
        registry[localDeckId].cards.push({id,...payload});
      }

      saveRegistry();

      if(!state.decks[localDeckId]) state.decks[localDeckId]={};
      if(!state.decks[localDeckId][id]){
        state.decks[localDeckId][id]={step:0,due:today0(),addedAt:today0(),lastAt:null};
      }
      state.active[localDeckId]=true;
      saveState();

      resetForm();
      // Don't sync from server - we already have the latest data locally
      // await syncFromServer();
      refreshSelect();
      updateBadge();
      buildQueue();
      closeEditor();
      $("#status").textContent= isUpdate ? "Updated" : "Added";
      setTimeout(()=>$("#status").textContent="",1200);
    }

    $("#btnAdd").addEventListener("click", ()=> saveCard(false));
    if(btnUpdate) btnUpdate.addEventListener("click", ()=> saveCard(true));

    const listPlayer = new Audio();
    async function audioURLFromCard(c){ if(c.audioKey) return await urlFor(c.audioKey); return resolveAsset(c.audio) || null; }
    function fmtDateTime(ts){ return ts? new Date(ts).toLocaleString() : '-'; }

    // Format stage as inline pill (for cards list table)
    function formatStageBadge(step){
      if(step < 0) step = 0;
      if(step > 10){
        // Completed: green checkmark
        return '<span class="badge" style="background:#4caf50;color:#fff;padding:4px 8px;border-radius:12px;">&#10003;</span>';
      }
      // Stage 0-10: number with fill
      const fillPercent = step === 0 ? 0 : (step / 10) * 100;
      const textColor = step > 5 ? '#fff' : '#333';
      const bg = `linear-gradient(90deg, #2196f3 ${fillPercent}%, #e0e0e0 ${fillPercent}%)`;
      return `<span class="badge" style="background:${bg};color:${textColor};padding:4px 8px;border-radius:12px;">${step}</span>`;
    }
    // Pagination state for Cards List
    let listCurrentPage = 1;
    const LIST_PAGE_SIZE = 50; // Cards per page

    function buildListRows(){
      const tbody=$("#listTable tbody");
      tbody.innerHTML="";
      const rows=[];
      Object.values(registry||{}).forEach(d=>{
        ensureDeckProgress(d.id,d.cards);
        (d.cards||[]).forEach(c=>{
          const rec=state.decks[d.id][c.id];
          rows.push({deckTitle:d.title, deckId:d.id, id:c.id, card:c, front:c.text||c.front||"", stage:rec.step||0, added:rec.addedAt||null, due:rec.due||null});
        });
      });

      // Apply search filter
      const q=$("#listSearch").value.toLowerCase();
      let filtered=rows.filter(r=>!q || r.front.toLowerCase().includes(q) || (r.deckTitle||"").toLowerCase().includes(q));

      // Apply due date filter
      const dueFilter=$("#listFilterDue").value;
      const now=today0();
      if(dueFilter==='due'){
        filtered=filtered.filter(r=>r.due<=now);
      } else if(dueFilter==='future'){
        filtered=filtered.filter(r=>r.due>now);
      }

      // Sort by due date
      filtered.sort((a,b)=> (a.due||0)-(b.due||0) );

      // Update count
      $("#listCount").textContent=filtered.length;

      // Calculate pagination
      const totalPages=Math.max(1, Math.ceil(filtered.length / LIST_PAGE_SIZE));
      if(listCurrentPage > totalPages) listCurrentPage = totalPages;
      if(listCurrentPage < 1) listCurrentPage = 1;

      const startIdx=(listCurrentPage - 1) * LIST_PAGE_SIZE;
      const endIdx=Math.min(startIdx + LIST_PAGE_SIZE, filtered.length);
      const paginated=filtered.slice(startIdx, endIdx);

      // Show/hide pagination controls
      if(filtered.length > LIST_PAGE_SIZE){
        $("#listPagination").style.display="flex";
        $("#pageInfo").textContent=`Page ${listCurrentPage} of ${totalPages}`;
        $("#btnPagePrev").disabled = listCurrentPage <= 1;
        $("#btnPageNext").disabled = listCurrentPage >= totalPages;
      } else {
        $("#listPagination").style.display="none";
      }

      // Render paginated rows
      paginated.forEach(async r=>{
        const tr=document.createElement("tr");
        tr.innerHTML=`<td>${r.front||"-"}</td><td>${r.deckTitle||"-"}</td><td>${formatStageBadge(r.stage)}</td><td>${fmtDateTime(r.added)}</td><td>${fmtDateTime(r.due)}</td><td class="row playcell" style="gap:6px"></td>`;
        const cell=tr.lastElementChild;
        const url = await audioURLFromCard(r.card);
        if(url){
          const b1=document.createElement("button");
          b1.className="iconbtn";
          b1.textContent="\uD83D\uDD0A";
          b1.title="Play";
          b1.onclick=()=>{listPlayer.src=url; listPlayer.playbackRate=1; listPlayer.currentTime=0; listPlayer.play().catch(()=>{});};
          const b2=document.createElement("button");
          b2.className="iconbtn";
          b2.textContent="\uD83D\uDC22";
          b2.title="Play 0.67x";
          b2.onclick=()=>{listPlayer.src=url; listPlayer.playbackRate=0.67; listPlayer.currentTime=0; listPlayer.play().catch(()=>{});};
          cell.appendChild(b1);
          cell.appendChild(b2);
        }
        const edit=document.createElement("button");
        edit.className="iconbtn";
        edit.textContent="\u270E";
        edit.title="Edit";
        edit.onclick=()=>{
          const pack=registry[r.deckId];
          const card=pack.cards.find(x=>x.id===r.id);
          if(card){
            // Set up for editing this card
            currentItem={deckId:r.deckId,card:normalizeLessonCard({...card}),rec:state.decks[r.deckId][r.id],index:0};
            visibleSlots=1;
            showCurrent();
            $("#listModal").style.display="none";
            // Trigger edit button to populate form
            $("#btnEdit").click();
          }
        };
        cell.appendChild(edit);
        const del=document.createElement("button");
        del.className="iconbtn";
        del.textContent="\u00D7";
        del.title="Delete";
        del.onclick=async()=>{
          if(!confirm("Delete this card?")) return;
          try {
            await api('delete_card', {deckid:r.deckId, cardid:r.id}, 'POST');
          } catch(e) { }
          const arr=registry[r.deckId]?.cards||[];
          const ix=arr.findIndex(x=>x.id===r.id);
          if(ix>=0){arr.splice(ix,1); saveRegistry();}
          if(state.decks[r.deckId]) delete state.decks[r.deckId][r.id];
          if(state.hidden && state.hidden[r.deckId]) delete state.hidden[r.deckId][r.id];
          saveState();
          buildQueue();
          buildListRows();
        };
        cell.appendChild(del);
        tbody.appendChild(tr);
      });
    }
    $("#btnList").addEventListener("click",()=>{
      listCurrentPage = 1; // Reset to page 1 when opening modal
      $("#listModal").style.display="flex";
      buildListRows();
    });
    $("#btnCloseList").addEventListener("click",()=>{ $("#listModal").style.display="none"; });
    $("#listSearch").addEventListener("input", ()=>{
      listCurrentPage = 1; // Reset to page 1 when searching
      buildListRows();
    });
    $("#listFilterDue").addEventListener("change", ()=>{
      listCurrentPage = 1; // Reset to page 1 when filtering
      buildListRows();
    });
    $("#btnPagePrev").addEventListener("click", ()=>{
      if(listCurrentPage > 1) {
        listCurrentPage--;
        buildListRows();
      }
    });
    $("#btnPageNext").addEventListener("click", ()=>{
      listCurrentPage++;
      buildListRows();
    });

    document.addEventListener("keydown",e=>{ if($("#listModal").style.display==="flex") return; const tag=(e.target.tagName||"").toLowerCase(); if(tag==="input"||tag==="textarea"||e.target.isContentEditable) return; if(e.code==="Space"){ e.preventDefault(); const br=$("#btnRevealNext"); if(br && !br.disabled) br.click(); } if(e.key==="e"||e.key==="E") rateEasy(); if(e.key==="n"||e.key==="N") rateNormal(); if(e.key==="h"||e.key==="H") rateHard(); });

    try { if('serviceWorker' in navigator){ navigator.serviceWorker.register(baseurl + 'sw.js'); } } catch(e){}
    try { if (!document.querySelector('link[rel="manifest"]')) { const l=document.createElement('link'); l.rel='manifest'; l.href=baseurl + 'manifest.webmanifest'; document.head.appendChild(l);} } catch(e){}

    // Add iOS-specific meta tags for PWA
    try {
      if (!document.querySelector('meta[name="apple-mobile-web-app-capable"]')) {
        const metaCapable = document.createElement('meta');
        metaCapable.name = 'apple-mobile-web-app-capable';
        metaCapable.content = 'yes';
        document.head.appendChild(metaCapable);
      }
      if (!document.querySelector('meta[name="apple-mobile-web-app-status-bar-style"]')) {
        const metaStatus = document.createElement('meta');
        metaStatus.name = 'apple-mobile-web-app-status-bar-style';
        metaStatus.content = 'black-translucent';
        document.head.appendChild(metaStatus);
      }
      if (!document.querySelector('meta[name="apple-mobile-web-app-title"]')) {
        const metaTitle = document.createElement('meta');
        metaTitle.name = 'apple-mobile-web-app-title';
        metaTitle.content = 'SRS Cards';
        document.head.appendChild(metaTitle);
      }
      if (!document.querySelector('link[rel="apple-touch-icon"]')) {
        const linkIcon = document.createElement('link');
        linkIcon.rel = 'apple-touch-icon';
        linkIcon.href = baseurl + 'icons/icon-192.png';
        document.head.appendChild(linkIcon);
      }
      console.log('[PWA] iOS meta tags added');
    } catch(e) {
      console.log('[PWA] Error adding iOS meta tags:', e);
    }

    // PWA Install Prompt
    let deferredInstallPrompt = null;
    const btnInstallApp = $("#btnInstallApp");

    // Debug: log button state on init
    console.log('[PWA] Install button in DOM:', !!btnInstallApp);
    console.log('[PWA] Service Worker support:', 'serviceWorker' in navigator);
    console.log('[PWA] User agent:', navigator.userAgent);

    window.addEventListener('beforeinstallprompt', (e) => {
      // Prevent Chrome 67 and earlier from automatically showing the prompt
      e.preventDefault();
      // Stash the event so it can be triggered later
      deferredInstallPrompt = e;
      // Show install button
      if(btnInstallApp) {
        btnInstallApp.classList.remove('hidden');
        console.log('[PWA] Install prompt available - button shown');
      } else {
        console.error('[PWA] Button not found in DOM!');
      }
    });

    if(btnInstallApp) {
      btnInstallApp.addEventListener('click', async () => {
        if(!deferredInstallPrompt) {
          console.log('[PWA] No install prompt available');
          return;
        }
        // Show the install prompt
        deferredInstallPrompt.prompt();
        // Wait for the user to respond to the prompt
        const { outcome } = await deferredInstallPrompt.userChoice;
        console.log(`[PWA] User choice: ${outcome}`);
        if(outcome === 'accepted') {
          console.log('[PWA] User accepted the install prompt');
        }
        // Clear the deferred prompt
        deferredInstallPrompt = null;
        // Hide install button
        btnInstallApp.classList.add('hidden');
      });
    }

    // Hide install button if already installed
    window.addEventListener('appinstalled', () => {
      console.log('[PWA] App successfully installed');
      if(btnInstallApp) btnInstallApp.classList.add('hidden');
      deferredInstallPrompt = null;
    });

    // iOS Install Hint (since iOS doesn't support beforeinstallprompt)
    const iosInstallHint = $("#iosInstallHint");
    const iosHintClose = $("#iosHintClose");
    const isIOS = /iPad|iPhone|iPod/.test(navigator.userAgent) && !window.MSStream;
    const isInStandaloneMode = ('standalone' in window.navigator) && window.navigator.standalone;
    const hintDismissedKey = 'ios-install-hint-dismissed';

    console.log('[PWA] iOS device:', isIOS);
    console.log('[PWA] Standalone mode:', isInStandaloneMode);

    // Check if user previously dismissed the hint
    const isHintDismissed = localStorage.getItem(hintDismissedKey) === 'true';

    if(iosInstallHint && isIOS && !isInStandaloneMode && !isHintDismissed) {
      // Show iOS install hint for iOS users who haven't installed the app yet
      iosInstallHint.classList.remove('hidden');
      console.log('[PWA] iOS install hint shown');
    } else if(iosInstallHint) {
      console.log('[PWA] iOS hint hidden (not iOS, already installed, or dismissed by user)');
    }

    // Handle close button click
    if(iosHintClose) {
      iosHintClose.addEventListener('click', () => {
        if(iosInstallHint) {
          iosInstallHint.classList.add('hidden');
          localStorage.setItem(hintDismissedKey, 'true');
          console.log('[PWA] iOS hint dismissed by user');
        }
      });
    }

    if(!localStorage.getItem(PROFILE_KEY)) localStorage.setItem(PROFILE_KEY,"Guest");
    (function(){ const sel=$("#profileSel"); const list=JSON.parse(localStorage.getItem("srs-profiles")||'["Guest"]'); const cur=localStorage.getItem(PROFILE_KEY)||"Guest"; if(!list.includes(cur)) list.push(cur); localStorage.setItem("srs-profiles",JSON.stringify([...new Set(list)])); sel.innerHTML=""; JSON.parse(localStorage.getItem("srs-profiles")).forEach(p=>{const o=document.createElement("option");o.value=p;o.textContent=p;sel.appendChild(o);}); sel.value=cur; })();
    $("#profileSel").addEventListener("change",e=>{ localStorage.setItem(PROFILE_KEY,e.target.value); loadState(); refreshSelect(); updateBadge(); buildQueue(); });
    $("#btnAddProfile").addEventListener("click",()=>{ const name=prompt("Profile name:",""); if(!name)return; const list=JSON.parse(localStorage.getItem("srs-profiles")||'["Guest"]'); list.push(name); localStorage.setItem("srs-profiles",JSON.stringify([...new Set(list)])); localStorage.setItem(PROFILE_KEY,name); (function(){ const sel=$("#profileSel"); sel.innerHTML=""; JSON.parse(localStorage.getItem("srs-profiles")).forEach(p=>{const o=document.createElement("option");o.value=p;o.textContent=p;sel.appendChild(o);}); sel.value=name; })(); loadState(); refreshSelect(); updateBadge(); buildQueue(); });

    // Initialize form: disable Update button by default
    if(btnUpdate) btnUpdate.disabled = true;

    // Auto-clear cache on plugin version update
    const CACHE_VERSION = "2025103002"; // Must match version.php
    const currentCacheVersion = localStorage.getItem("flashcards-cache-version");
    if (currentCacheVersion !== CACHE_VERSION) {
      console.log(`[Flashcards] Cache version mismatch: ${currentCacheVersion} -> ${CACHE_VERSION}. Clearing cache...`);
      // Clear all flashcards-related localStorage keys
      const keysToRemove = [];
      for (let i = 0; i < localStorage.length; i++) {
        const key = localStorage.key(i);
        if (key && (key.startsWith('srs-v6:') || key === 'srs-profile' || key === 'srs-profiles')) {
          keysToRemove.push(key);
        }
      }
      keysToRemove.forEach(key => localStorage.removeItem(key));

      // Clear IndexedDB media cache
      try {
        indexedDB.deleteDatabase("srs-media");
      } catch(e) {
        console.warn('[Flashcards] Failed to clear IndexedDB:', e);
      }

      // Set new version
      localStorage.setItem("flashcards-cache-version", CACHE_VERSION);
      console.log('[Flashcards] Cache cleared successfully');
    }

    // Check access permissions and hide card creation form if needed
    if (window.flashcardsAccessInfo) {
      const access = window.flashcardsAccessInfo;
      console.log('[Flashcards] Access info:', access);

      if (!access.can_create) {
        // Hide card creation form during grace period or when access expired
        const formEl = $("#cardCreationForm");
        if (formEl) {
          formEl.style.display = 'none';
          console.log('[Flashcards] Card creation form hidden (can_create=false)');
        }
      }
    }

    loadState(); (async()=>{
      await syncFromServer();

      // Debug: log what we got from server
      console.log('[Flashcards] Registry after sync:', Object.keys(registry || {}).map(id => ({
        id,
        title: registry[id].title,
        cardCount: registry[id].cards?.length || 0
      })));

      // Auto-activate ALL decks that have cards (from server or local)
      if(!state.active) state.active={};

      // Activate all decks in registry that have cards
      Object.keys(registry || {}).forEach(deckId => {
        if(registry[deckId].cards && registry[deckId].cards.length > 0) {
          state.active[deckId] = true;
          console.log(`[Flashcards] Auto-activated deck: ${deckId} (${registry[deckId].cards.length} cards)`);
        }
      });

      saveState();
      refreshSelect();
      updateBadge();
      buildQueue();

      console.log('[Flashcards] Active decks:', Object.keys(state.active || {}).filter(id => state.active[id]));
    })();
    (function(){ const m={ audio: $("#chip_audio")? $("#chip_audio").textContent:'audio', image: $("#chip_image")? $("#chip_image").textContent:'image', text: $("#chip_text")? $("#chip_text").textContent:'text', explanation: $("#chip_explanation")? $("#chip_explanation").textContent:'explanation', translation: $("#chip_translation")? $("#chip_translation").textContent:'translation' }; $("#orderPreview").textContent=DEFAULT_ORDER.map(k=>m[k]).join(' -> '); })();
  }
  window.flashcardsInit = flashcardsInit;
})();
