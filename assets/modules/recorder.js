(function(root){
  const namespace = root.ModFlashcards || (root.ModFlashcards = {});

  const workerSrc = `/**
 * This is a web worker responsible for recording/buffering the sound and
 * encoding it as wav.
 */

var recLength = 0;
var recBuffers = [];
var sampleRate;
var numChannels;

// Listen to incoming messages
this.onmessage = function(e) {
  switch(e.data.command){
    case 'init':
      init(e.data.config);
      break;
    case 'record':
      record(e.data.buffer);
      break;
    case 'export-wav':
      exportWAV();
      break;
    case 'clear':
      clear();
      break;
  }
};

function init(config) {
  sampleRate = config.sampleRate;
  numChannels = config.numChannels;
  initBuffers();
}

function record(inputBuffer) {
  for (var channel = 0; channel < numChannels; channel++){
    recBuffers[channel].push(inputBuffer[channel]);
  }
  recLength += inputBuffer[0].length;
}

function exportWAV() {
  var buffers = [];
  for (var channel = 0; channel < numChannels; channel++){
    buffers.push(mergeBuffers(recBuffers[channel], recLength));
  }
  var interleaved = (numChannels === 2)
    ? interleave(buffers[0], buffers[1])
    : buffers[0];
  var dataview = encodeWAV(interleaved);
  var audioBlob = new Blob([dataview], { type: 'audio/wav' });

  this.postMessage({
    command: 'wav-delivered',
    blob: audioBlob
  });
}

function clear() {
  recLength = 0;
  recBuffers = [];
  initBuffers();
}

function initBuffers() {
  for (var channel = 0; channel < numChannels; channel++){
    recBuffers[channel] = [];
  }
}

function mergeBuffers(recBuffers, recLength){
  var result = new Float32Array(recLength);
  var offset = 0;
  for (var i = 0; i < recBuffers.length; i++){
    result.set(recBuffers[i], offset);
    offset += recBuffers[i].length;
  }
  return result;
}

function interleave(inputL, inputR){
  var length = inputL.length + inputR.length;
  var result = new Float32Array(length);
  var index = 0, inputIndex = 0;
  while (index < length){
    result[index++] = inputL[inputIndex];
    result[index++] = inputR[inputIndex];
    inputIndex++;
  }
  return result;
}

function floatTo16BitPCM(output, offset, input) {
  for (var i = 0; i < input.length; i++, offset+=2){
    var s = Math.max(-1, Math.min(1, input[i]));
    output.setInt16(offset, s < 0 ? s * 0x8000 : s * 0x7FFF, true);
  }
}

function writeString(view, offset, string) {
  for (var i = 0; i < string.length; i++){
    view.setUint8(offset + i, string.charCodeAt(i));
  }
}

function encodeWAV(samples) {
  var buffer = new ArrayBuffer(44 + samples.length * 2);
  var view = new DataView(buffer);
  writeString(view, 0, 'RIFF');
  view.setUint32(4, 36 + samples.length * 2, true);
  writeString(view, 8, 'WAVE');
  writeString(view, 12, 'fmt ');
  view.setUint32(16, 16, true);
  view.setUint16(20, 1, true);
  view.setUint16(22, numChannels, true);
  view.setUint32(24, sampleRate, true);
  view.setUint32(28, sampleRate * 2 * numChannels, true);
  view.setUint16(32, numChannels * 2, true);
  view.setUint16(34, 16, true);
  writeString(view, 36, 'data');
  view.setUint32(40, samples.length * 2, true);
  floatTo16BitPCM(view, 44, samples);
  return view;
}`;

  class Emitter {
    constructor(){ this._events = Object.create(null); }
    on(evt, handler){ if(!evt || typeof handler !== 'function') return; (this._events[evt] ||= []).push(handler); }
    once(evt, handler){ if(!evt || typeof handler !== 'function') return; const wrap = payload => { this.off(evt, wrap); handler(payload); }; this.on(evt, wrap); }
    off(evt, handler){ const arr=this._events[evt]; if(!arr) return; const idx=arr.indexOf(handler); if(idx>-1) arr.splice(idx,1); }
    emit(evt, payload){ const arr=(this._events[evt]||[]).slice(); arr.forEach(fn=>{ try{ fn(payload); }catch(err){/* swallow */} }); }
  }

  class IOSRecorder extends Emitter {
    constructor(workerUrl, debugFn){
      super();
      this.workerUrl = workerUrl;
      this.debug = typeof debugFn === 'function' ? debugFn : function(){};
      this.config = {bufferLength:4096,numChannels:1};
      this.state = 'inactive';
      this.worker = null;
      this.workerPromise = null;
      this.stream = null;
      this.audioContext = null;
      this.scriptNode = null;
      this.sourceNode = null;
      this.userMediaPromise = null;
    }
    supported(){
      const AudioCtx = window.AudioContext || window.webkitAudioContext;
      return !!(AudioCtx && navigator.mediaDevices && navigator.mediaDevices.getUserMedia && window.Worker && window.Blob && window.URL);
    }
    async _ensureWorker(){
      if(this.worker) return this.worker;
      if(this.workerPromise) return this.workerPromise;
      this.workerPromise = (async()=>{
        let code = '';
        try{
          const resp = await fetch(this.workerUrl, {credentials:'same-origin'});
          if(resp && resp.ok){ code = await resp.text(); }
        }catch(err){ this.debug('worker fetch failed, using inline source: '+(err?.message||err)); }
        if(!code){ code = workerSrc; }
        const blob = new Blob([code], {type:'text/javascript'});
        const blobUrl = URL.createObjectURL(blob);
        const worker = new Worker(blobUrl);
        worker.onmessage = e => {
          const msg = e && e.data; if(!msg || !msg.command) return;
          this.emit(msg.command, msg);
        };
        worker.onerror = e => { this.emit('worker-error', e); };
        this.worker = worker;
        this.workerBlobUrl = blobUrl;
        return worker;
      })().catch(err=>{ this.workerPromise = null; throw err; });
      return this.workerPromise;
    }
    async _setupAudioProcessing(stream){
      const AudioCtx = window.AudioContext || window.webkitAudioContext;
      this.audioContext = new AudioCtx();
      try{ if(this.audioContext.state === 'suspended'){ await this.audioContext.resume(); } }catch(_e){}
      this.scriptNode = this.audioContext.createScriptProcessor(
        this.config.bufferLength,
        this.config.numChannels,
        this.config.numChannels
      );
      this.scriptNode.onaudioprocess = e => {
        if(this.state !== 'recording') return;
        try{
          const input = e.inputBuffer.getChannelData(0);
          const copy = new Float32Array(input.length);
          copy.set(input);
          this.worker.postMessage({command:'record', buffer:[copy]});
        }catch(err){ this.debug('chunk push failed: '+(err?.message||err)); }
      };
      this.sourceNode = this.audioContext.createMediaStreamSource(stream);
      this.sourceNode.connect(this.scriptNode);
      this.scriptNode.connect(this.audioContext.destination);
      this.worker.postMessage({command:'init', config:{sampleRate:this.audioContext.sampleRate, numChannels:this.config.numChannels}});
    }
    async grabMic(){
      if(!this.supported()) throw new Error('unsupported');
      if(this.userMediaPromise) return this.userMediaPromise;
      this.userMediaPromise = (async()=>{
        await this._ensureWorker();
        try{
          const stream = await navigator.mediaDevices.getUserMedia({audio:true});
          this.stream = stream;
          await this._setupAudioProcessing(stream);
          return stream;
        }catch(err){
          this.userMediaPromise = null;
          this.emit('blocked', err);
          throw err;
        }
      })();
      return this.userMediaPromise;
    }
    _setState(state){
      if(this.state === state) return;
      this.state = state;
      this.emit(state, {state});
    }
    async start(){
      await this.grabMic();
      this._setState('recording');
    }
    stop(){ this._setState('inactive'); }
    async exportWav(){
      await this._ensureWorker();
      this.stop();
      return new Promise((resolve,reject)=>{
        const onDelivered = data => {
          this.off('worker-error', onError);
          resolve(data && data.blob ? data.blob : null);
        };
        const onError = err => {
          this.off('wav-delivered', onDelivered);
          reject(err);
        };
        this.once('wav-delivered', onDelivered);
        this.once('worker-error', onError);
        try{ this.worker.postMessage({command:'export-wav'}); }
        catch(err){
          this.off('wav-delivered', onDelivered);
          this.off('worker-error', onError);
          reject(err);
        }
      });
    }
    async releaseMic(){
      this._setState('inactive');
      try{ if(this.worker){ this.worker.postMessage({command:'clear'}); } }catch(_e){}
      if(this.stream){ try{ this.stream.getTracks().forEach(t=>t.stop()); }catch(_e){} this.stream=null; }
      if(this.sourceNode){ try{ this.sourceNode.disconnect(); }catch(_e){} this.sourceNode=null; }
      if(this.scriptNode){ try{ this.scriptNode.disconnect(); }catch(_e){} this.scriptNode=null; }
      if(this.audioContext){ try{ await this.audioContext.close(); }catch(_e){} this.audioContext=null; }
      this.userMediaPromise = null;
    }
  }

  function createIOSRecorder(workerUrl, debugFn){
    return new IOSRecorder(workerUrl, debugFn);
  }

  namespace.Recorder = {
    workerSrc,
    createIOSRecorder
  };
})(window);
