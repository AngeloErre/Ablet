// Voce e avatar: motore del browser oppure audio temporaneo tramite server. Nessuna archiviazione audio permanente in ABLET.
// Il riconoscimento del browser può inviare l'audio ai server del produttore del browser: l'interfaccia lo dichiara.
import { h, api, errText } from './app.js';
const NS = 'http://www.w3.org/2000/svg';
const Rec = window.SpeechRecognition || window.webkitSpeechRecognition;

export const voiceSupport = { input: !!Rec, output: 'speechSynthesis' in window };
export const VOICE_NOTICE = 'Le funzioni vocali dipendono dal motore configurato. Nella modalità browser, il browser può inviare l\'audio ai server del suo produttore. In modalità browser ABLET riceve il testo confermato. In modalità server l’audio attraversa temporaneamente ABLET ed è inviato al provider vocale scelto; non viene archiviato stabilmente da ABLET. Si applicano le condizioni del provider.';
export const REC_ERR = {
  'not-allowed': 'Il microfono non è autorizzato. Puoi scrivere.', 'service-not-allowed': 'Il riconoscimento vocale non è consentito su questo dispositivo. Puoi scrivere.',
  network: 'La connessione è lenta: la voce non funziona. Puoi scrivere.', 'no-speech': 'Non ti ho sentito. Tocca il microfono e riprova.',
  'audio-capture': 'Non trovo un microfono. Puoi scrivere.', 'language-not-supported': 'Questa lingua non è disponibile per la voce. Puoi scrivere.',
};
const FATAL = ['not-allowed', 'service-not-allowed', 'audio-capture', 'language-not-supported', 'network'];

/** Divide il testo in frasi brevi: la sintesi del browser è più affidabile e l'evidenziazione resta allineata. */
function sentences(text) {
  const out = [];
  const re = /[^.!?…]+[.!?…]*\s*/g;
  let m;
  while ((m = re.exec(text)) !== null) { if (m[0].trim()) out.push({ text: m[0], start: m.index }); }
  const bounded=[];
  for(const part of (out.length?out:[{text,start:0}])){
    let rest=part.text,offset=part.start;
    while(rest.length>180){let cut=rest.lastIndexOf(' ',180);if(cut<60)cut=180;if(/[\uD800-\uDBFF]/.test(rest[cut-1]))cut--;bounded.push({text:rest.slice(0,cut),start:offset});offset+=cut;rest=rest.slice(cut);}
    if(rest.trim())bounded.push({text:rest,start:offset});
  }
  return bounded;

}

// Diagnostica effimera: nessun testo, credenziale o audio viene registrato o inviato.
const ttsEvents=[];
export const voiceDiagnostics=()=>ttsEvents.map(e=>({...e}));
const ttsEvent=(event,data={})=>{ttsEvents.push({time:new Date().toISOString(),event,...data});if(ttsEvents.length>60)ttsEvents.shift();};

// Un solo proprietario della sintesi per pagina: impedisce sovrapposizioni tra anteprime e pannelli.
let speechOwner = null;
addEventListener('pagehide', () => speechOwner?.stopSpeaking());
addEventListener('ablet:voice-stop', () => speechOwner?.stopSpeaking());
export const SPEECH_ERR = {
  'not-allowed': 'Il browser ha bloccato la lettura. Tocca Riascolta o Prova la voce per avviarla.',
  'audio-busy': 'L’audio del dispositivo è occupato. Ferma le altre riproduzioni e riprova.',
  'audio-hardware': 'L’uscita audio non è disponibile. Controlla volume e dispositivo audio.',
  'voice-unavailable': 'La voce scelta non è disponibile. Seleziona la voce predefinita del dispositivo.',
  'language-unavailable': 'Non è disponibile una voce per questa lingua sul dispositivo.',
  network: 'La voce selezionata richiede una connessione. Verifica la rete o scegli una voce locale.',
};

export function createVoice(cfg, onSpeaking = () => {}) {
  if (cfg.engine === 'server') return createServerVoice(cfg, onSpeaking);
  let silenceTimer=null;
  const clearSilence=()=>{clearTimeout(silenceTimer);silenceTimer=null;};
  let rec = null, listening = false, token = 0, timer = null, activeUtterance = null, speaking = false;
  const synth = voiceSupport.output ? window.speechSynthesis : null;
  let voicesCleanup=()=>{};
  const pick = () => { const vs = synth.getVoices(); return vs.find(v => v.name === cfg.voice_name) || vs.find(v => v.localService && v.lang?.toLowerCase().replace('_','-').startsWith((cfg.language || 'it').slice(0,2))) || vs.find(v => v.lang === cfg.language) || vs.find(v => v.lang?.startsWith((cfg.language || 'it').slice(0, 2))) || null; };
  const notify = value => { speaking = value; onSpeaking(value); };
  const api = {
    canListen: voiceSupport.input && !!cfg.input_enabled, canSpeak: voiceSupport.output && !!cfg.output_enabled,
    get listening() { return listening; },
    get speaking() { return speaking; },
    // Nessuna frase muta in coda: poteva interferire con il primo messaggio su mobile.
    unlock() { if (synth) { try { synth.resume(); synth.getVoices(); } catch { /* speak segnala l'errore */ } } },
    listen(opts) {
      if (!api.canListen) { opts.onError?.('Questo browser non riconosce la voce o la dettatura è disattivata. Puoi scrivere.', true); opts.onEnd?.(); return; }
      api.stopSpeaking(); api.abortListening();
      try {
        const current = new Rec(); rec = current;
        const arm=()=>{clearSilence();if(opts.silenceMs)silenceTimer=setTimeout(()=>{if(rec!==current)return;opts.onSilence?.();api.stopListening();},opts.silenceMs);};
        current.onstart=arm;current.onspeechstart=()=>clearSilence();current.onspeechend=arm;
        current.lang = cfg.language || 'it-IT'; current.interimResults = true; current.continuous = false; current.maxAlternatives = 1;
        current.onresult = e => { if (rec !== current) return; let t = '', fin = true; for (const r of e.results) { t += r[0].transcript; fin = r.isFinal; } if(t.trim())arm();opts.onText?.(t.trim(), fin); };
        current.onerror = e => { if (rec === current && e.error !== 'aborted') opts.onError?.(REC_ERR[e.error] || 'La voce non ha funzionato. Riprova o scrivi.', FATAL.includes(e.error)); };
        current.onend = () => { if (rec !== current) return; clearSilence();rec = null; listening = false; opts.onEnd?.(); };
        current.start(); listening = true;
      } catch { rec = null; listening = false; opts.onError?.('La voce non è disponibile adesso. Puoi scrivere.', true); opts.onEnd?.(); }
    },
    stopListening() { clearSilence();try { rec?.stop(); } catch { /* già fermo */ } listening = false; },
    abortListening() { clearSilence();const previous = rec; rec = null; try { previous?.abort(); } catch { /* già fermo */ } listening = false; },
    speak(text, opts = {}) {
      if (speaking) return false; // doppio tocco: non cancella e non accoda una seconda lettura
      if (!api.canSpeak || !String(text || '').trim()) {
        opts.onError?.('La lettura ad alta voce non è disponibile o è disattivata.'); return false;
      }
      api.abortListening();
      if (speechOwner && speechOwner !== api) speechOwner.stopSpeaking();
      // Elimina soltanto la coda gestita dalla sintesi prima del nuovo testo.
      try { synth.cancel(); synth.resume(); } catch { opts.onError?.('Non riesco ad avviare l’audio. Riprova con il pulsante di ascolto.'); return false; }
      speechOwner = api;
      const my = ++token;
      ttsEvent('queued',{characters:String(text).length,voices:synth.getVoices().length,activated:typeof navigator!=='undefined'?navigator.userActivation?.hasBeenActive:null});
      text = String(text).replace(/[*_#`•→|]/g, ' ');
      const parts = sentences(text);
      notify(true); // include l'attesa di avvio: pulsanti già disabilitati
      const release = () => {
        clearTimeout(timer);voicesCleanup(); activeUtterance = null;
        if (speechOwner === api) speechOwner = null;
        notify(false); opts.onWord?.(-1, -1);
      };
      const fail = (message,code='synthesis-failed') => {
        if (my !== token) return;
        ttsEvent('error',{code,message});token++; try { synth.cancel(); } catch { /* stato ripristinato comunque */ }
        release(); opts.onError?.(message);
      };
      const next = i => {
        if (my !== token) return;
        clearTimeout(timer);
        if (i >= parts.length) { ttsEvent('completed');release(); opts.onEnd?.(); return; }
        const p = parts[i], u = new SpeechSynthesisUtterance(p.text), v = pick();
        ttsEvent('part',{index:i,characters:p.text.length,voice:v?.name||'default',lang:v?.lang||cfg.language||'it-IT',local:v?.localService??null});
        activeUtterance = u; // riferimento forte fino a fine riproduzione
        if (v) u.voice = v;
        u.lang = v?.lang || cfg.language || 'it-IT'; u.volume = 1;
        u.rate = Math.max(.5, Math.min(1.6, Number(cfg.rate) || 1));
        u.pitch = Math.max(.5, Math.min(1.6, Number(cfg.pitch) || 1));
        let ended = false;
        u.onstart = () => {
          if (my !== token || ended || activeUtterance !== u) return;
          ttsEvent('started',{index:i});clearTimeout(timer);
          timer = setTimeout(() => fail('La lettura si è bloccata. Tocca Riascolta per riprovare.'), Math.max(20000, (p.text.length / (8 * u.rate) + 15) * 1000));
        };
        u.onend = () => { if (my !== token || ended) return; ended = true;ttsEvent('ended',{index:i}); next(i + 1); };
        u.onerror = e => { if (my !== token || ended || activeUtterance !== u) return; fail(SPEECH_ERR[e.error] || 'La voce non è stata riprodotta. Controlla volume e uscita audio, poi riprova.',e.error); };
        u.onboundary = e => {
          if (my !== token || ended || activeUtterance !== u || (e.name && e.name !== 'word')) return;
          const s = p.start + (e.charIndex || 0), m = /^[^\s]+/.exec(text.slice(s));
          opts.onWord?.(s, s + (m ? m[0].length : 1)); onSpeaking(true, 'word');
        };
        // Se il browser non avvia l'audio, mostra un errore invece di simulare una fine riuscita.
        timer = setTimeout(() => fail('Il browser non ha avviato la voce. Tocca Riascolta o Prova la voce e controlla il volume.'), 8000);
        try { synth.speak(u); if (synth.paused) synth.resume(); }
        catch { fail('Non riesco a riprodurre la voce. Controlla il dispositivo audio e riprova.'); }
      };
      if(synth.getVoices().length)next(0);
      else{
        ttsEvent('waiting-voices');
        const ready=()=>{if(my!==token||!synth.getVoices().length)return;voicesCleanup();next(0);};
        voicesCleanup=()=>{clearTimeout(timer);synth.removeEventListener?.('voiceschanged',ready);voicesCleanup=()=>{};};
        synth.addEventListener?.('voiceschanged',ready);
        timer=setTimeout(()=>{if(my!==token)return;if(synth.getVoices().length){ready();return;}fail('Chrome non ha reso disponibili le voci del dispositivo. Apri la diagnosi TTS e verifica che sia installata una voce italiana.','voices-unavailable');},2000);
        ready();
      }
      return speaking;
    },
    stopSpeaking() {
      token++; clearTimeout(timer);voicesCleanup(); activeUtterance = null;
      // Un pannello inattivo non deve fermare la voce appartenente a un altro pannello.
      if (speechOwner === api) { speechOwner = null; try { synth?.cancel(); } catch { /* già fermo */ } }
      if (speaking) notify(false);
    },
  };
  return api;
}

/** Misura locale dell'energia audio: nessun invio se non è stato rilevato parlato.
 * La soglia distingue il silenzio dai suoni, non identifica la voce umana. */
function monitorSilence(stream,ms,onSilence){
  const Context=window.AudioContext||window.webkitAudioContext;
  if(!Context)throw new Error('AudioContext unavailable');
  const ctx=new Context(), source=ctx.createMediaStreamSource(stream), analyser=ctx.createAnalyser();
  analyser.fftSize=1024;source.connect(analyser);ctx.resume?.().catch?.(()=>{});
  const samples=new Uint8Array(analyser.fftSize);let quiet=0, voiced=0, heard=false, ended=false;
  const interval=setInterval(()=>{
    analyser.getByteTimeDomainData(samples);let power=0;for(const value of samples)power+=((value-128)/128)**2;
    const sound=Math.sqrt(power/samples.length)>.025;
    voiced=sound?voiced+100:0;if(voiced>=200)heard=true;
    quiet=sound?0:quiet+100;
    if(quiet>=ms&&!ended){ended=true;onSilence(heard);}
  },100);
  return ()=>{ended=true;clearInterval(interval);source.disconnect();analyser.disconnect();ctx.close?.().catch?.(()=>{});};
}

/** Alternativa alle API vocali del dispositivo: audio temporaneo tramite il server ABLET. */
function createServerVoice(cfg, onSpeaking) {
  const audio = typeof Audio !== 'undefined' ? new Audio() : null;
  let speaking=false, listening=false, generation=0, listenGeneration=0, recorder=null, stream=null, timer=null, blobUrl=null;
  let silenceCleanup=()=>{};
  const stopTracks=()=>{silenceCleanup();silenceCleanup=()=>{};stream?.getTracks().forEach(track=>track.stop());stream=null;};
  const freeAudio=()=>{if(audio){audio.pause();audio.removeAttribute('src');audio.load();}if(blobUrl){URL.revokeObjectURL(blobUrl);blobUrl=null;}};
  const state=on=>{speaking=on;onSpeaking(on);};
  const payload=()=>({context:cfg.api_context||{},...(cfg.preview?{preview:cfg.preview}:{})});
  const apiVoice={
    canSpeak:!!audio&&!!cfg.output_enabled,
    canListen:!!navigator.mediaDevices?.getUserMedia&&typeof MediaRecorder!=='undefined'&&!!cfg.input_enabled,
    get speaking(){return speaking;},get listening(){return listening;},
    unlock(){ /* Il comando di ascolto resta disponibile se il dispositivo blocca l'avvio automatico. */ },
    speak(text,opts={}){
      if(speaking)return false;
      if(!apiVoice.canSpeak){opts.onError?.('Uscita audio disattivata o non disponibile.');return false;}
      apiVoice.abortListening();if(speechOwner&&speechOwner!==apiVoice)speechOwner.stopSpeaking();speechOwner=apiVoice;
      const mine=++generation;state(true);
      const end=()=>{if(mine!==generation)return;generation++;clearTimeout(timer);freeAudio();if(speechOwner===apiVoice)speechOwner=null;state(false);opts.onEnd?.();};
      const fail=message=>{if(mine!==generation)return;apiVoice.stopSpeaking();opts.onError?.(message);};
      api('POST','/api/voice/speech',{...payload(),text:String(text).slice(0,2000)}).then(async result=>{
        if(mine!==generation)return;
        const raw=atob(result.audio),bytes=new Uint8Array(raw.length);for(let i=0;i<raw.length;i++)bytes[i]=raw.charCodeAt(i);
        blobUrl=URL.createObjectURL(new Blob([bytes],{type:result.mime}));audio.src=blobUrl;
        audio.onended=end;audio.onerror=()=>fail('Il browser non riesce a riprodurre l’audio ricevuto. Controlla volume e uscita audio.');
        timer=setTimeout(()=>fail('La riproduzione si è bloccata. Puoi riprovare con Riascolta.'),120000);
        try{await audio.play();}catch{fail('Audio pronto, ma il browser ha bloccato l’ascolto. Tocca Riascolta e verifica volume e uscita audio.');}
      }).catch(error=>fail(errText(error)));
      return true;
    },
    stopSpeaking(){generation++;clearTimeout(timer);freeAudio();if(speechOwner===apiVoice)speechOwner=null;if(speaking)state(false);},
    listen(opts){
      if(!apiVoice.canListen){opts.onError?.('La registrazione non è disponibile: usa HTTPS e un browser esterno aggiornato.',true);opts.onEnd?.();return;}
      apiVoice.stopSpeaking();apiVoice.abortListening();const mine=++listenGeneration;listening=true;
      navigator.mediaDevices.getUserMedia({audio:true}).then(current=>{
        if(mine!==listenGeneration){current.getTracks().forEach(track=>track.stop());return;}
        stream=current;
        const type=['audio/webm;codecs=opus','audio/mp4','audio/ogg;codecs=opus'].find(type=>MediaRecorder.isTypeSupported(type));
        recorder=new MediaRecorder(stream,type?{mimeType:type}:{});const parts=[];let bytes=0;
        recorder.ondataavailable=e=>{if(e.data.size){parts.push(e.data);bytes+=e.data.size;if(bytes>3000000&&recorder?.state==='recording')recorder.stop();}};
        recorder.onerror=()=>{if(mine!==listenGeneration)return;apiVoice.abortListening();opts.onError?.('Registrazione non riuscita. Controlla il microfono.',false);opts.onEnd?.();};
        recorder.onstop=async()=>{
          clearTimeout(timer);stopTracks();if(mine!==listenGeneration)return;listening=false;
          if(!bytes||bytes>3000000){opts.onError?.('Audio vuoto o troppo lungo: riprova con una frase breve.',false);opts.onEnd?.();return;}
          opts.onProcessing?.();
          try{
            const blob=new Blob(parts,{type:recorder.mimeType||type||'audio/webm'});
            const encoded=await new Promise((resolve,reject)=>{const reader=new FileReader();reader.onload=()=>resolve(String(reader.result).split(',')[1]);reader.onerror=reject;reader.readAsDataURL(blob);});
            if(mine!==listenGeneration)return;
            const result=await api('POST','/api/voice/transcribe',{...payload(),audio:encoded,mime:blob.type});
            if(mine!==listenGeneration)return;
            opts.onText?.(result.text,true);opts.onEnd?.();
          }catch(error){if(mine!==listenGeneration)return;opts.onError?.(errText(error),false);opts.onEnd?.();}
        };
        recorder.start(250);timer=setTimeout(()=>apiVoice.stopListening(),45000);
        if(opts.silenceMs){
          try{silenceCleanup=monitorSilence(stream,opts.silenceMs,heard=>{
            if(mine!==listenGeneration)return;opts.onSilence?.();
            if(heard)apiVoice.stopListening();else{apiVoice.abortListening();opts.onEnd?.();}
          });}catch{apiVoice.abortListening();opts.onError?.('Il controllo del silenzio non è disponibile. Puoi scrivere o cambiare motore vocale.',false);opts.onEnd?.();}
        }
      }).catch(()=>{if(mine!==listenGeneration)return;listening=false;opts.onError?.('Microfono non autorizzato o non disponibile. Controlla le autorizzazioni del sito.',true);opts.onEnd?.();});
    },
    stopListening(){if(recorder?.state==='recording')recorder.stop();else{listenGeneration++;listening=false;stopTracks();}},
    abortListening(){listenGeneration++;clearTimeout(timer);if(recorder?.state==='recording')recorder.stop();recorder=null;listening=false;stopTracks();},
  };
  return apiVoice;
}

/**
 * Avatar SVG volutamente non realistico (non simula una persona). Stati: idle, listening, thinking, speaking, sleeping.
 * La bocca si apre a ogni parola letta (evento della sintesi) oppure con un'animazione regolare se il browser non segnala le parole.
 */
export function avatar(cfg) {
  const e = (t, a) => { const x = document.createElementNS(NS, t); for (const k in a) x.setAttribute(k, a[k]); return x; };
  const c = cfg.color || '#2f6f6a';
  const svg = e('svg', { viewBox: '0 0 200 200', class: 'avatar st-idle', role: 'img', 'aria-label': `${cfg.name || 'Ablet'}, assistente di intelligenza artificiale` });
  svg.append(e('circle', { class: 'halo', cx: 100, cy: 100, r: 96, fill: c, opacity: .16 }), e('circle', { class: 'ring', cx: 100, cy: 100, r: 92, fill: 'none', stroke: c, 'stroke-width': 4, opacity: 0 }));
  const face = e('g', { class: 'face' });
  if (cfg.style === 'robot') {
    face.append(e('line', { x1: 100, y1: 22, x2: 100, y2: 42, stroke: c, 'stroke-width': 6, 'stroke-linecap': 'round' }), e('circle', { class: 'antenna', cx: 100, cy: 20, r: 8, fill: c }),
      e('rect', { x: 38, y: 42, width: 124, height: 118, rx: 30, fill: c }), e('rect', { x: 52, y: 58, width: 96, height: 62, rx: 18, fill: '#fff', opacity: .16 }));
  } else if (cfg.style === 'astratto') {
    face.append(e('path', { d: 'M100 24 C150 24 178 60 174 102 C170 148 136 176 96 172 C56 168 24 136 30 92 C35 54 62 24 100 24Z', fill: c }));
  } else {
    face.append(e('circle', { cx: 100, cy: 104, r: 72, fill: c }), e('circle', { cx: 52, cy: 58, r: 16, fill: c }), e('circle', { cx: 148, cy: 58, r: 16, fill: c }),
      e('circle', { cx: 64, cy: 124, r: 10, fill: '#ff9e8a', opacity: .55 }), e('circle', { cx: 136, cy: 124, r: 10, fill: '#ff9e8a', opacity: .55 }));
  }
  const eyes = e('g', { class: 'eyes' });
  for (const x of [76, 124]) {
    const g = e('g', { class: 'eye' });
    g.append(e('ellipse', { cx: x, cy: 96, rx: 13, ry: 15, fill: '#fff' }), e('circle', { class: 'pupil', cx: x, cy: 98, r: 7, fill: '#1c2530' }), e('circle', { cx: x + 3, cy: 93, r: 2.4, fill: '#fff' }));
    eyes.append(g);
  }
  const lids = e('g', { class: 'lids' });
  for (const x of [76, 124]) lids.append(e('path', { d: `M${x - 14} 96 Q${x} 104 ${x + 14} 96`, stroke: '#1c2530', 'stroke-width': 4, fill: 'none', 'stroke-linecap': 'round' }));
  const mouth = e('ellipse', { class: 'mouth', cx: 100, cy: 136, rx: 18, ry: 4, fill: '#1c2530' });
  const dots = e('g', { class: 'dots' });
  [80, 100, 120].forEach((x, i) => dots.append(e('circle', { cx: x, cy: 180, r: 5, fill: c, class: 'd' + i })));
  const zz = e('text', { class: 'zz', x: 150, y: 50, 'font-size': 26, 'font-weight': 700, fill: c }); zz.textContent = 'z z';
  svg.append(face, eyes, lids, mouth, dots, zz);

  let level = 0, raf = 0, last = 0;
  const calm = () => document.documentElement.classList.contains('calm') || matchMedia('(prefers-reduced-motion: reduce)').matches;
  const draw = () => {
    level = Math.max(0, level - (performance.now() - last) / 260); last = performance.now();
    mouth.setAttribute('ry', String(4 + level * 14)); mouth.setAttribute('rx', String(18 - level * 4));
    if (level > 0) raf = requestAnimationFrame(draw); else raf = 0;
  };
  const api = {
    el: svg,
    setState(s) { svg.setAttribute('class', 'avatar st-' + s); if (s !== 'speaking') { level = 0; mouth.setAttribute('ry', '4'); mouth.setAttribute('rx', '18'); } },
    /** Impulso della bocca in corrispondenza di una parola. */
    pulse() { if (calm()) return; svg.classList.add('words'); level = 1; last = performance.now(); if (!raf) raf = requestAnimationFrame(draw); },
    setSpeaking(on) { api.setState(on ? 'speaking' : 'idle'); },
  };
  return api;
}

export const voiceNames = () => voiceSupport.output ? speechSynthesis.getVoices().map(v => ({ name: v.name, lang: v.lang, local: v.localService })) : [];
export { h };
