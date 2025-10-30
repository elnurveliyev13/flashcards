// This AMD module adapts the existing PWA logic to run natively inside Moodle.
// We scope DOM queries to a container and prefix relative asset URLs with a base path.

define(['core/str'], function(str) {
  'use strict';

  function init(rootid, baseurl, cmid, instanceid, sesskey) {
    const root = document.getElementById(rootid);
    if (!root) return;

    // Scoped selectors
    const $ = s => root.querySelector(s);
    const $$ = s => Array.from(root.querySelectorAll(s));
    const uniq = a => [...new Set(a.filter(Boolean))];
    const fmtDateTime = ts => ts ? new Date(ts).toLocaleString() : "-";

    // Resolve relative asset to plugin base URL
    function resolveAsset(u) {
      if (!u) return null;
      if (/^https?:|^blob:|^data:/.test(u)) return u;
      u = (u + '').replace(/^\/+/, '');
      return baseurl + u;
    }

    /* ===== i18n (copied) ===== */
    const I18N={
      en:{appTitle:"SRS Cards",intervals:"Intervals: 1,3,7,15,31,62,125,251",
          export:"Export",import:"Import",reset:"Reset progress",profile:"Profile:",activate:"Activate lesson",
          choose:"Choose lesson",loadPack:"Load deck",due:n=>`Due: ${n}`,list:"Cards list",
          addOwn:"Add your card",front:"Front text",back:"Translation / note",image:"Image",audio:"Audio (normal)",
          chooseFile:"Choose file",showMore:"Show more",easy:"Easy",normal:"Normal",hard:"Hard",
          order:"Order (click in sequence)",empty:"Nothing due today",
          titles:{camera:"Camera",take:"Take photo",closeCam:"Close camera",play:"Play",slow:"Play 0.67×",edit:"Edit",del:"Delete/Hide",record:"Record",stop:"Stop"},
          listCols:{front:"Front",deck:"Deck",stage:"Stage",added:"Added",due:"Next due",play:"Play"},resetForm:"Reset form",addToMine:'Add to "Mine"',
          chips:{text:"Text",translation:"Translation",image:"Image",audio:"Audio"},searchPh:"Search..."},
      no:{appTitle:"SRS-kort",intervals:"Intervaller: 1,3,7,15,31,62,125,251",
          export:"Eksporter",import:"Importer",reset:"Tilbakestill",profile:"Profil:",activate:"Aktiver leksjon",
          choose:"Velg leksjon",loadPack:"Last opp kortstokk",due:n=>`Til repetisjon: ${n}`,list:"Kortliste",
          addOwn:"Legg til eget kort",front:"Tekst (forside)",back:"Oversettelse / notat",image:"Bilde",audio:"Lyd (vanlig)",
          chooseFile:"Velg fil",showMore:"Vis mer",easy:"Lett",normal:"Middels",hard:"Vanskelig",
          order:"Rekkefølge (klikk i ønsket rekkefølge)",empty:"Ingen kort i dag",
          titles:{camera:"Kamera",take:"Ta bilde",closeCam:"Lukk kamera",play:"Spill av",slow:"Sakte 0.67×",edit:"Rediger",del:"Slett/Skjul",record:"Ta opp",stop:"Stopp"},
          listCols:{front:"Forside",deck:"Leksjon",stage:"Trinn",added:"Lagt til",due:"Neste",play:"Spill"},resetForm:"Nullstill",addToMine:'Legg til i "Mine"',
          chips:{text:"Tekst",translation:"Overs.",image:"Bilde",audio:"Lyd"},searchPh:"Søk..."},
    };
    let LANG=localStorage.getItem("srs-lang")||"en";
    // Load Moodle strings and map them into existing I18N structure to avoid large refactors.
    function loadStrings() {
      const keys = [
        'export','import','reset','profile','activate','choose','loadpack','due','list','addown','front','back','image','audio','choosefile','showmore','easy','normal','hard','order','empty','resetform','addtomine','title_camera','title_take','title_closecam','title_play','title_slow','title_edit','title_del','title_record','title_stop','list_front','list_deck','list_stage','list_added','list_due','list_play','search_ph'
      ].map(k=>({key:k, component:'mod_flashcards'}));
      return str.get_strings(keys).then(values=>{
        const STR = {}; keys.forEach((k,i)=> STR[k.key]=values[i]);
        if (!I18N[LANG]) I18N[LANG] = {};
        const m = I18N[LANG];
        m.export=STR.export; m.import=STR.import; m.reset=STR.reset; m.profile=STR.profile; m.activate=STR.activate; m.choose=STR.choose; m.loadPack=STR.loadpack;
        m.list=STR.list; m.addOwn=STR.addown; m.front=STR.front; m.back=STR.back; m.image=STR.image; m.audio=STR.audio; m.chooseFile=STR.choosefile; m.showMore=STR.showmore; m.easy=STR.easy; m.normal=STR.normal; m.hard=STR.hard; m.order=STR.order; m.empty=STR.empty; m.resetForm=STR.resetform; m.addToMine=STR.addtomine;
        m.titles={ camera:STR.title_camera, take:STR.title_take, closeCam:STR.title_closecam, play:STR.title_play, slow:STR.title_slow, edit:STR.title_edit, del:STR.title_del, record:STR.title_record, stop:STR.title_stop };
        m.listCols={ front:STR.list_front, deck:STR.list_deck, stage:STR.list_stage, added:STR.list_added, due:STR.list_due, play:STR.list_play };
        m.chips={ text:STR.front, translation:STR.back, image:STR.image, audio:STR.audio };
        // Template with placeholder: replace {$a} later in applyI18N calls
        m.due = n => (STR.due||'Due: {$a}').replace('{$a}', n);
      });
    }
    function t(k,...a){const v=I18N[LANG] && I18N[LANG][k]; return typeof v==="function"?v(...a):v;}
    function applyI18N(){
      document.documentElement.lang=LANG; document.title=t("appTitle")||"SRS Cards";
      $("#t_appTitle").textContent=t("appTitle"); $("#t_intervals").textContent=t("intervals");
      $("#btnExport").textContent=t("export"); $("#btnImport").textContent=t("import"); $("#btnReset").textContent=t("reset");
      $("#btnList").textContent=t("list");
      $("#t_profile").textContent=t("profile"); $("#btnActivate").textContent=t("activate"); $("#badge").textContent=t("choose");
      $("#t_loadPack").textContent=t("loadPack"); $("#dueInfo").textContent=t("due",queue.length);
      $("#btnRevealNext").textContent=t("showMore"); $("#btnEasy").textContent=t("easy"); $("#btnNormal").textContent=t("normal"); $("#btnHard").textContent=t("hard");
      $("#t_addOwn").textContent=t("addOwn"); $("#t_frontText").textContent=t("front"); $("#t_backText").textContent=t("back");
      $("#t_pickPhoto").textContent=t("image"); $("#t_pickAudio").textContent=t("audio");
      $("#btnChooseImg").textContent=t("chooseFile"); $("#btnChooseAud").textContent=t("chooseFile");
      $("#t_order").textContent=t("order"); $("#emptyState").textContent=t("empty");
      $("#btnFormReset").textContent=t("resetForm"); $("#btnAdd").textContent=t("addToMine");
      // titles
      $("#btnOpenCam").title=t("titles").camera; $("#btnShot").title=t("titles").take; $("#btnCloseCam").title=t("titles").closeCam;
      $("#btnPlay").title=t("titles").play; $("#btnPlaySlow").title=t("titles").slow;
      $("#btnEdit").title=t("titles").edit; $("#btnDel").title=t("titles").del;
      $("#btnRec").title=t("titles").record; $("#btnStop").title=t("titles").stop;
      // list headers / placeholders
      $("#t_listTitle").textContent=t("list");
      $("#thFront").textContent=t("listCols").front; $("#thDeck").textContent=t("listCols").deck;
      $("#thStage").textContent=t("listCols").stage; $("#thAdded").textContent=t("listCols").added;
      $("#thDue").textContent=t("listCols").due; $("#thPlay").textContent=t("listCols").play;
      $("#listSearch").placeholder=t("searchPh");
    }
    $("#langSel").value=LANG; $("#langSel").addEventListener("change",()=>{LANG=$("#langSel").value;localStorage.setItem("srs-lang",LANG);applyI18N();});

    /* ===== profiles ===== */
    const PROFILE_KEY="srs-profile";
    function storageKey(base){return `${base}:${localStorage.getItem(PROFILE_KEY)||"Guest"}`;}
    function refreshProfiles(){
      const sel=$("#profileSel"); const list=JSON.parse(localStorage.getItem("srs-profiles")||'["Guest"]');
      const cur=localStorage.getItem(PROFILE_KEY)||"Guest"; if(!list.includes(cur)) list.push(cur);
      localStorage.setItem("srs-profiles",JSON.stringify([...new Set(list)]));
      sel.innerHTML=""; JSON.parse(localStorage.getItem("srs-profiles")).forEach(p=>{const o=document.createElement("option");o.value=p;o.textContent=p;sel.appendChild(o);}); sel.value=cur;
    }

    /* ===== SRS core ===== */
    const STORAGE_STATE="srs-v6:state", STORAGE_REG="srs-v6:registry";
    const INTERVALS=[1,3,7,15,31,62,125,251], MY_DECK_ID="my-deck";
    let state,registry,queue=[],current=-1,visibleSlots=1,currentItem=null;

    function today0(){const d=new Date();d.setHours(0,0,0,0);return +d;}
    function addDays(t,days){const d=new Date(t);d.setDate(d.getDate()+days);return +d;}
    function loadState(){state=JSON.parse(localStorage.getItem(storageKey(STORAGE_STATE))||'{"active":{},"decks":{},"hidden":{}}'); registry=JSON.parse(localStorage.getItem(storageKey(STORAGE_REG))||'{}');}
    function saveState(){localStorage.setItem(storageKey(STORAGE_STATE),JSON.stringify(state));}
    function saveRegistry(){localStorage.setItem(storageKey(STORAGE_REG),JSON.stringify(registry));}
    function ensureDeckProgress(deckId,cards){
      if(!state.decks[deckId]) state.decks[deckId]={};
      const m=state.decks[deckId];
      cards.forEach(c=>{ if(!m[c.id]) m[c.id]={step:0,due:today0(),addedAt:today0(),lastAt:null}; });
    }
    function refreshSelect(){const s=$("#deckSelect");s.innerHTML="";Object.values(registry).forEach(d=>{const o=document.createElement("option");o.value=d.id;o.textContent=`${d.title} - ${d.cards.length} kort`;s.appendChild(o);});}
    function updateBadge(){const act=Object.keys(state.active).filter(id=>state.active[id]);$("#badge").textContent=act.length?`${t("activate")}: ${act.length}`:t("choose");}

    /* ===== media (IndexedDB for "Mine") ===== */
    function openDB(){return new Promise((res,rej)=>{const r=indexedDB.open("srs-media",1);r.onupgradeneeded=e=>{e.target.result.createObjectStore("files")};r.onsuccess=e=>res(e.target.result);r.onerror=()=>rej(r.error);});}
    async function idbPut(k,b){const db=await openDB();return new Promise((res,rej)=>{const tx=db.transaction("files","readwrite");tx.oncomplete=()=>res();tx.onerror=()=>rej(tx.error);tx.objectStore("files").put(b,k);});}
    async function idbGet(k){const db=await openDB();return new Promise((res,rej)=>{const tx=db.transaction("files","readonly");const rq=tx.objectStore("files").get(k);rq.onsuccess=()=>res(rq.result||null);rq.onerror=()=>rej(rq.error);});}
    async function urlFor(k){const b=await idbGet(k);return b?URL.createObjectURL(b):null;}
    // ===== server sync helpers =====
    async function apiFetch(action, body) {
      try {
        const url = M.cfg.wwwroot + '/mod/flashcards/ajax.php?action=' + encodeURIComponent(action) + '&cmid=' + encodeURIComponent(cmid) + '&sesskey=' + encodeURIComponent(sesskey);
        const opt = { method: body? 'POST':'GET', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' } };
        if (body) opt.body = JSON.stringify(body);
        const res = await fetch(url, opt);
        if (!res.ok) throw new Error('Network');
        return await res.json();
      } catch(e) { return {ok:false}; }
    }
    async function pullServerProgress(){
      const resp = await apiFetch('fetch');
      if (!resp || !resp.ok) return;
      const data = resp.data || {};
      if (!state) loadState(); if (!state.decks) state.decks={};
      Object.keys(data).forEach(deckId=>{
        if(!state.decks[deckId]) state.decks[deckId]={};
        const m = state.decks[deckId];
        Object.keys(data[deckId]).forEach(cardId=>{
          const r = data[deckId][cardId];
          m[cardId] = { step: r.step||0, due: r.due||0, addedAt: r.addedAt||0, lastAt: r.lastAt||0 };
          if (r.hidden){ if(!state.hidden) state.hidden={}; if(!state.hidden[deckId]) state.hidden[deckId]={}; state.hidden[deckId][cardId]=true; }
        });
      });
      saveState();
    }

    /* ===== render card ===== */
    const slotContainer=$("#slotContainer"), emptyState=$("#emptyState");
    const STAGE_EMOJI=["🟢","🟡","🟠","🔵","⭐","⭐","⭐","⭐","⭐"];
    function setStage(step){if(step<0)step=0;if(step>8)step=8;$("#stageEmoji").textContent=STAGE_EMOJI[step];$("#stageText").textContent=step===0?"0":String(INTERVALS[Math.min(step,INTERVALS.length)-1]);$("#stageBadge").classList.remove("hidden");}
    let audioURL=null; const player=new Audio();
    function attachAudio(url){audioURL=url; $("#btnPlay").classList.remove("hidden"); $("#btnPlaySlow").classList.remove("hidden");
      $("#btnPlay").onclick=()=>{if(!audioURL)return; player.src=audioURL; player.playbackRate=1; player.currentTime=0; player.play().catch(()=>{});};
      $("#btnPlaySlow").onclick=()=>{if(!audioURL)return; player.src=audioURL; player.playbackRate=0.67; player.currentTime=0; player.play().catch(()=>{});};
    }
    function normalizeLessonCard(c){
      if(c.front && !c.text) c.text=c.front; if(c.back&&!c.translation) c.translation=c.back;
      if(!Array.isArray(c.order)||!c.order.length){ c.order=[]; if(c.audio||c.audioKey) c.order.push("audio"); if(c.text) c.order.push("text"); if(c.image||c.imageKey) c.order.push("image"); if(c.translation) c.order.push("translation"); }
      c.order=uniq(c.order); return c;
    }
    async function buildSlot(kind, card){
      const el=document.createElement("div"); el.className="slot";
      if(kind==="text" && card.text){ el.innerHTML=`<div class="front">${card.text}</div>`; return el; }
      if(kind==="translation" && card.translation){ el.innerHTML=`<div class="back">${card.translation}</div>`; return el; }
      if(kind==="image" && (card.image||card.imageKey)){
        const url=card.imageKey?await urlFor(card.imageKey):resolveAsset(card.image);
        if(url){ const img=document.createElement("img"); img.src=url; img.className="media"; el.appendChild(img); return el; }
      }
      if(kind==="audio" && (card.audio||card.audioKey)){
        const url=card.audioKey?await urlFor(card.audioKey):resolveAsset(card.audio);
        if(url){ attachAudio(url); el.innerHTML=`<div class="pill">▶ ${ (t("audio")||"Audio").split(" ")[0] }</div>`; el.dataset.autoplay=url; return el; }
      }
      return null;
    }
    async function renderCard(card, count){
      slotContainer.innerHTML=""; $("#btnPlay").classList.add("hidden"); $("#btnPlaySlow").classList.add("hidden"); audioURL=null;
      const items=[]; for(const kind of card.order.slice(0,count)){ const el=await buildSlot(kind,card); if(el) items.push(el); }
      if(!items.length){ const d=document.createElement("div"); d.className="front"; d.textContent="-"; items.push(d); }
      items.forEach(x=>slotContainer.appendChild(x));
      if(count===1 && items[0] && items[0].dataset && items[0].dataset.autoplay){ player.src=items[0].dataset.autoplay; player.playbackRate=1; player.currentTime=0; player.play().catch(()=>{}); }
    }

    /* ===== build queue ===== */
    function buildQueue(){
      const now=today0(); queue=[]; current=-1; currentItem=null;
      const act=Object.keys(state.active).filter(id=>state.active[id]);
      act.forEach(id=>{
        const d=registry[id]; if(!d) return;
        ensureDeckProgress(id,d.cards);
        d.cards.forEach(c=>{
          if(state.hidden && state.hidden[id] && state.hidden[id][c.id]) return;
          const nc=normalizeLessonCard(Object.assign({}, c)); const r=state.decks[id][c.id];
          if(r.due<=now) queue.push({deckId:id,card:nc,rec:r,index:queue.length});
        });
      });
      $("#dueInfo").textContent=t("due",queue.length);
      if(queue.length===0){ slotContainer.innerHTML=""; $("#counter").textContent="0/0"; $("#stageBadge").classList.add("hidden"); emptyState.classList.remove("hidden"); $("#btnRevealNext").disabled=true; $("#btnRevealNext").classList.remove("primary"); return; }
      emptyState.classList.add("hidden");
      current=0; visibleSlots=1; currentItem=queue[current]; showCurrent();
    }
    function updateRevealButton(){
      const more = currentItem && visibleSlots < currentItem.card.order.length;
      $("#btnRevealNext").disabled=!more;
      $("#btnRevealNext").classList.toggle("primary",!!more);
    }
    async function showCurrent(){
      if(!currentItem){ updateRevealButton(); return; }
      $("#counter").textContent=`${currentItem.index+1}/${queue.length}`;
      setStage(currentItem.rec.step||0);
      await renderCard(currentItem.card, visibleSlots);
      updateRevealButton();
    }

    /* ===== study actions (one review per day) ===== */
    function rateEasy(){
      if(!currentItem)return;
      const it=currentItem;
      it.rec.step=Math.min(8,it.rec.step+1);
      const idx=Math.min(it.rec.step,INTERVALS.length);
      it.rec.lastAt=today0();
      it.rec.due=addDays(it.rec.lastAt, idx?INTERVALS[idx-1]:0);
      saveState();
      queue.splice(current,1);
      if(queue.length===0){ buildQueue(); } else { currentItem=queue[Math.min(current,queue.length-1)]; visibleSlots=1; showCurrent(); $("#dueInfo").textContent=t("due",queue.length); }
    }
    function rateNormal(){
      if(!currentItem)return;
      const it=currentItem;
      it.rec.lastAt=today0();
      it.rec.due=addDays(it.rec.lastAt,1);
      saveState();
      queue.splice(current,1);
      if(queue.length===0){ buildQueue(); } else { currentItem=queue[Math.min(current,queue.length-1)]; visibleSlots=1; showCurrent(); $("#dueInfo").textContent=t("due",queue.length); }
    }
    function rateHard(){
      if(!currentItem)return;
      const it=currentItem;
      it.rec.lastAt=today0();
      it.rec.due=today0();
      saveState();
      queue.push(it); queue.splice(current,1);
      currentItem=queue[Math.min(current,queue.length-1)]; visibleSlots=1; showCurrent(); $("#dueInfo").textContent=t("due",queue.length);
    }
    $("#btnRevealNext").addEventListener("click",()=>{ if(!currentItem)return; visibleSlots=Math.min(currentItem.card.order.length, visibleSlots+1); showCurrent(); });
    function pushProgressFromState(deckId, cardId){
      try {
        const rec = (state && state.decks && state.decks[deckId]) ? state.decks[deckId][cardId] : null;
        if(!rec) return;
        apiFetch('save', {records:[{ deckId, cardId, step: rec.step||0, due: rec.due||0, addedAt: rec.addedAt||0, lastAt: rec.lastAt||0 }]});
      } catch(e) { /* ignore */ }
    }
    $("#btnEasy").addEventListener("click",()=>{ const d=currentItem&&currentItem.deckId; const c=currentItem&&currentItem.card&&currentItem.card.id; rateEasy(); if(d&&c) pushProgressFromState(d,c); });
    $("#btnNormal").addEventListener("click",()=>{ const d=currentItem&&currentItem.deckId; const c=currentItem&&currentItem.card&&currentItem.card.id; rateNormal(); if(d&&c) pushProgressFromState(d,c); });
    $("#btnHard").addEventListener("click",()=>{ const d=currentItem&&currentItem.deckId; const c=currentItem&&currentItem.card&&currentItem.card.id; rateHard(); if(d&&c) pushProgressFromState(d,c); });

    /* ===== system buttons ===== */
    $("#btnReset").addEventListener("click",()=>{if(confirm("Reset?")){state={active:{},decks:{},hidden:{}};saveState();buildQueue();updateBadge();}});
    $("#btnExport").addEventListener("click",()=>{const blob=new Blob([JSON.stringify({state,registry},null,2)],{type:"application/json"});const a=document.createElement("a");a.href=URL.createObjectURL(blob);a.download="srs_export.json";a.click();});
    $("#btnImport").addEventListener("click",()=>$("#fileImport").click());
    $("#fileImport").addEventListener("change",async e=>{const f=e.target.files?.[0];if(!f)return;try{const d=JSON.parse(await f.text());if(d.state)state=d.state;if(d.registry)registry=d.registry;saveState();saveRegistry();refreshSelect();updateBadge();buildQueue();$("#status").textContent="OK";setTimeout(()=>$("#status").textContent="",1200);}catch{$("#status").textContent="Bad deck";setTimeout(()=>$("#status").textContent="",1500);}});
    $("#packFile").addEventListener("change",async e=>{const f=e.target.files?.[0];if(!f)return;try{const p=JSON.parse(await f.text());if(!p.id)p.id="pack-"+Date.now().toString(36); p.cards=(p.cards||[]).map(x=>normalizeLessonCard(x)); registry[p.id]=p; saveRegistry(); refreshSelect(); state.active[p.id]=true; saveState(); updateBadge(); buildQueue(); $("#deckSelect").value=p.id;}catch{$("#status").textContent="Bad deck";setTimeout(()=>$("#status").textContent="",1500);}});
    $("#btnActivate").addEventListener("click",()=>{const id=$("#deckSelect").value;if(!id)return;state.active[id]=true;saveState();updateBadge();buildQueue();});

    /* ===== edit/delete ===== */
    $("#btnEdit").addEventListener("click",()=>{ if(!currentItem) return; const c=currentItem.card;
      $("#uFront").value=c.text||""; $("#uBack").value=c.translation||"";
      (async()=>{ $("#imgPrev").classList.add("hidden"); $("#audPrev").classList.add("hidden");
        if(c.imageKey){const u=await urlFor(c.imageKey); if(u){$("#imgPrev").src=u; $("#imgPrev").classList.remove("hidden");}}
        else if(c.image){ $("#imgPrev").src=resolveAsset(c.image); $("#imgPrev").classList.remove("hidden");}
        if(c.audioKey){const u=await urlFor(c.audioKey); if(u){$("#audPrev").src=u; $("#audPrev").classList.remove("hidden");}}
        else if(c.audio){ $("#audPrev").src=resolveAsset(c.audio); $("#audPrev").classList.remove("hidden");}
      })();
      orderChosen=[...(c.order||[])]; updateOrderPreview();
      const grid = root.querySelector(".grid"); if (grid) grid.scrollIntoView({behavior:"smooth"});
    });
    $("#btnDel").addEventListener("click",()=>{ if(!currentItem) return; const {deckId,card}=currentItem; if(deckId===MY_DECK_ID){ const arr=registry[deckId]?.cards||[]; const ix=arr.findIndex(x=>x.id===card.id); if(ix>=0){arr.splice(ix,1); saveRegistry();} if(state.decks[deckId]) delete state.decks[deckId][card.id]; saveState(); } else { if(!state.hidden) state.hidden={}; if(!state.hidden[deckId]) state.hidden[deckId]={}; state.hidden[deckId][card.id]=true; saveState(); } buildQueue();});

    /* ===== camera / mic ===== */
    let camStream=null, rec=null, recChunks=[], lastImageKey=null, lastAudioKey=null;
    $("#btnChooseImg").addEventListener("click",()=>$("#uImage").click());
    $("#btnChooseAud").addEventListener("click",()=>$("#uAudio").click());
    $("#uImage").addEventListener("change", async e=>{const f=e.target.files?.[0]; if(!f)return; $("#imgName").textContent=f.name; lastImageKey="my-"+Date.now().toString(36)+"-img"; await idbPut(lastImageKey,f); $("#imgPrev").src=URL.createObjectURL(f); $("#imgPrev").classList.remove("hidden");});
    $("#uAudio").addEventListener("change", async e=>{const f=e.target.files?.[0]; if(!f)return; $("#audName").textContent=f.name; lastAudioKey="my-"+Date.now().toString(36)+"-aud"; await idbPut(lastAudioKey,f); $("#audPrev").src=URL.createObjectURL(f); $("#audPrev").classList.remove("hidden");});
    $("#btnOpenCam").addEventListener("click",async()=>{try{camStream=await navigator.mediaDevices.getUserMedia({video:{facingMode:"environment"},audio:false});const v=$("#cam");v.srcObject=camStream;v.classList.remove("hidden");$("#btnShot").classList.remove("hidden");$("#btnCloseCam").classList.remove("hidden");}catch{}});
    $("#btnCloseCam").addEventListener("click",()=>{if(camStream){camStream.getTracks().forEach(t=>t.stop());camStream=null;}$("#cam").classList.add("hidden");$("#btnShot").classList.add("hidden");$("#btnCloseCam").classList.add("hidden");});
    $("#btnShot").addEventListener("click",()=>{const v=$("#cam");if(!v.srcObject)return;const c=document.createElement("canvas");c.width=v.videoWidth;c.height=v.videoHeight;c.getContext("2d").drawImage(v,0,0,c.width,c.height);c.toBlob(async b=>{lastImageKey="my-"+Date.now().toString(36)+"-img";await idbPut(lastImageKey,b);$("#imgPrev").src=URL.createObjectURL(b);$("#imgPrev").classList.remove("hidden");},"image/jpeg",0.92);});
    $("#btnRec").addEventListener("click",async()=>{try{const stream=await navigator.mediaDevices.getUserMedia({audio:true,video:false});recChunks=[];const mime=MediaRecorder.isTypeSupported("audio/webm")?"audio/webm":"audio/mp4";rec=new MediaRecorder(stream,{mimeType:mime});rec.ondataavailable=e=>{if(e.data.size>0)recChunks.push(e.data);};rec.onstop=async()=>{const blob=new Blob(recChunks,{type:mime});lastAudioKey="my-"+Date.now().toString(36)+"-aud";await idbPut(lastAudioKey,blob);$("#audPrev").src=URL.createObjectURL(blob);$("#audPrev").classList.remove("hidden");stream.getTracks().forEach(t=>t.stop());};rec.start();$("#btnRec").classList.add("hidden");$("#btnStop").classList.remove("hidden");}catch{}});
    $("#btnStop").addEventListener("click",()=>{if(rec){rec.stop();rec=null;}$("#btnStop").classList.add("hidden");$("#btnRec").classList.remove("hidden");});

    /* ===== order chips (click sequence) ===== */
    const defaultMyOrder=["text","translation","image","audio"];
    let orderChosen=[];
    function syncChips(){ $$("#orderChips .chip").forEach(ch=>{ ch.classList.toggle("active", orderChosen.includes(ch.dataset.kind)); }); }
    function updateOrderPreview(){ syncChips(); const m=I18N[LANG].chips || {text:'text',translation:'translation',image:'image',audio:'audio'}; const pretty = (orderChosen.length?orderChosen:defaultMyOrder).map(k=>m[k]).join(" → "); $("#orderPreview").textContent=pretty; }
    $("#orderChips").addEventListener("click",e=>{const btn=e.target.closest(".chip"); if(!btn)return; const k=btn.dataset.kind; const i=orderChosen.indexOf(k); if(i===-1) orderChosen.push(k); else orderChosen.splice(i,1); updateOrderPreview();});
    function resetForm(){ $("#uFront").value=""; $("#uBack").value=""; $("#uImage").value=""; $("#uAudio").value=""; $("#imgPrev").classList.add("hidden"); $("#audPrev").classList.add("hidden"); $("#imgName").textContent=""; $("#audName").textContent=""; lastImageKey=null; lastAudioKey=null; orderChosen=[]; updateOrderPreview(); }
    $("#btnFormReset").addEventListener("click", resetForm);
    $("#btnAdd").addEventListener("click",async()=>{
      const text=$("#uFront").value.trim(), tr=$("#uBack").value.trim();
      if(!text && !tr && !lastImageKey && !lastAudioKey && $("#imgPrev").classList.contains("hidden") && $("#audPrev").classList.contains("hidden")){ $("#status").textContent="Empty"; setTimeout(()=>$("#status").textContent="",1000); return; }
      const id="my-"+Date.now().toString(36);
      const card={id,text,translation:tr,order:(orderChosen.length?orderChosen:[...defaultMyOrder])};
      if(lastImageKey) card.imageKey=lastImageKey; if(lastAudioKey) card.audioKey=lastAudioKey;
      if(!registry[MY_DECK_ID]) registry[MY_DECK_ID]={id:MY_DECK_ID,title:"Mine / Min",cards:[]};
      registry[MY_DECK_ID].cards.push(card); saveRegistry();
      if(!state.decks[MY_DECK_ID]) state.decks[MY_DECK_ID]={};
      state.decks[MY_DECK_ID][id]={step:0,due:today0(),addedAt:today0(),lastAt:null};
      state.active[MY_DECK_ID]=true; saveState();
      resetForm(); refreshSelect(); updateBadge(); buildQueue(); $("#status").textContent="Added"; setTimeout(()=>$("#status").textContent="",1200);
    });

    /* ===== list modal (with play) ===== */
    const listPlayer = new Audio();
    async function audioURLFromCard(c){ if(c.audioKey) return await urlFor(c.audioKey); return resolveAsset(c.audio) || null; }
    function buildListRows(){
      const tbody=$("#listTable tbody"); tbody.innerHTML="";
      const rows=[];
      Object.values(registry).forEach(d=>{ ensureDeckProgress(d.id,d.cards); d.cards.forEach(c=>{ const rec=state.decks[d.id][c.id]; rows.push({deckTitle:d.title, deckId:d.id, id:c.id, card:c, front:c.text||c.front||"", stage:rec.step||0, added:rec.addedAt||null, due:rec.due||null}); }); });
      const q=$("#listSearch").value.toLowerCase();
      const filtered=rows.filter(r=>!q || r.front.toLowerCase().includes(q) || (r.deckTitle||"").toLowerCase().includes(q));
      $("#listCount").textContent=filtered.length;
      filtered.sort((a,b)=> (a.due||0)-(b.due||0) );
      filtered.forEach(async r=>{
        const tr=document.createElement("tr");
        tr.innerHTML=`<td>${r.front||"-"}</td>
          <td>${r.deckTitle||"-"}</td>
          <td><span class="badge">${r.stage}</span></td>
          <td>${fmtDateTime(r.added)}</td>
          <td>${fmtDateTime(r.due)}</td>
          <td class="row" style="gap:6px"></td>`;
        const cell=tr.lastElementChild;
        const url = await audioURLFromCard(r.card);
        if(url){
          const b1=document.createElement("button"); b1.className="iconbtn"; b1.textContent="▶"; b1.title=t("titles").play;
          b1.onclick=()=>{listPlayer.src=url; listPlayer.playbackRate=1; listPlayer.currentTime=0; listPlayer.play().catch(()=>{});};
          const b2=document.createElement("button"); b2.className="iconbtn"; b2.textContent="🐢"; b2.title=t("titles").slow;
          b2.onclick=()=>{listPlayer.src=url; listPlayer.playbackRate=0.67; listPlayer.currentTime=0; listPlayer.play().catch(()=>{});};
          cell.appendChild(b1); cell.appendChild(b2);
        }
        const edit=document.createElement("button"); edit.className="iconbtn"; edit.textContent="✎"; edit.title=t("titles").edit;
        edit.onclick=()=>{ const pack=registry[r.deckId]; const card=pack.cards.find(x=>x.id===r.id);
          if(card){ currentItem={deckId:r.deckId,card:normalizeLessonCard({...card}),rec:state.decks[r.deckId][r.id],index:0}; visibleSlots=1; showCurrent(); $("#listModal").style.display="none"; }};
        cell.appendChild(edit);
        tbody.appendChild(tr);
      });
    }
    $("#btnList").addEventListener("click",()=>{ $("#listModal").style.display="flex"; buildListRows(); });
    $("#btnCloseList").addEventListener("click",()=>{ $("#listModal").style.display="none"; });
    $("#listSearch").addEventListener("input", buildListRows);

    /* ===== hotkeys ===== */
    document.addEventListener("keydown",e=>{
      if($("#listModal").style.display==="flex") return;
      const tag=(e.target.tagName||"").toLowerCase(); if(tag==="input"||tag==="textarea"||e.target.isContentEditable) return;
      if(e.code==="Space"){ e.preventDefault(); if(!$("#btnRevealNext").disabled) $("#btnRevealNext").click(); }
      if(e.key==="e"||e.key==="E") rateEasy();
      if(e.key==="n"||e.key==="N") rateNormal();
      if(e.key==="h"||e.key==="H") rateHard();
    });

    /* ===== PWA Install Prompt ===== */
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
        console.log('[PWA] ✅ Install prompt available - button shown');
      } else {
        console.error('[PWA] ❌ Button not found in DOM!');
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
    const isIOS = /iPad|iPhone|iPod/.test(navigator.userAgent) && !window.MSStream;
    const isInStandaloneMode = ('standalone' in window.navigator) && window.navigator.standalone;

    console.log('[PWA] iOS device:', isIOS);
    console.log('[PWA] Standalone mode:', isInStandaloneMode);

    if(iosInstallHint && isIOS && !isInStandaloneMode) {
      // Show iOS install hint for iOS users who haven't installed the app yet
      iosInstallHint.classList.remove('hidden');
      console.log('[PWA] ✅ iOS install hint shown');
    } else if(iosInstallHint) {
      console.log('[PWA] iOS hint hidden (not iOS or already installed)');
    }

    /* ===== init ===== */
    (function(){
      // Ensure manifest link present in <head>.
      try { if (!document.querySelector('link[rel="manifest"]')) { const l=document.createElement('link'); l.rel='manifest'; l.href=baseurl + 'manifest.webmanifest'; document.head.appendChild(l);} } catch(e) {}
      if('serviceWorker' in navigator){ try { navigator.serviceWorker.register(baseurl + 'sw.js'); } catch(e) {} }

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
      loadStrings().then(()=>applyI18N());
      if(!localStorage.getItem(PROFILE_KEY)) localStorage.setItem(PROFILE_KEY,"Guest");
      refreshProfiles();
      $("#profileSel").addEventListener("change",e=>{ localStorage.setItem(PROFILE_KEY,e.target.value); loadState(); refreshSelect(); updateBadge(); buildQueue(); });
      $("#btnAddProfile").addEventListener("click",()=>{ const name=prompt("Profile name:",""); if(!name)return; const list=JSON.parse(localStorage.getItem("srs-profiles")||'["Guest"]'); list.push(name); localStorage.setItem("srs-profiles",JSON.stringify([...new Set(list)])); localStorage.setItem(PROFILE_KEY,name); refreshProfiles(); loadState(); refreshSelect(); updateBadge(); buildQueue(); });
      loadState(); refreshSelect(); updateBadge(); pullServerProgress().then(()=>{ buildQueue(); }); updateOrderPreview();
    })();
  }

  return { init };
});
