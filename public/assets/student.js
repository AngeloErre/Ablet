// ABLET — interfaccia dello studente per tablet, «voce prima».
// Principi: un avatar che parla e ascolta; un solo gesto principale (il microfono); comandi grandi; testo sempre disponibile ma secondario.
// Ogni frase riconosciuta passa dalla conferma; il motore server elabora audio temporaneo secondo le autorizzazioni configurate.
import { api, h, clear, toast, uuid, errText, BASE, S, prefs, applyPrefs, logout, pwa } from './app.js';
import { createVoice, avatar, voiceSupport, VOICE_NOTICE } from './voice.js';

const PHASE = { created: 'Pronti?', forethought: 'Prepariamo il piano', performance: 'Al lavoro', self_reflection: 'Com\'è andata', paused: 'In pausa', safety_hold: 'In pausa', closed_complete: 'Finito!', closed_incomplete: 'Chiuso' };
const ACTIVE = ['forethought', 'performance', 'self_reflection'];
const NOTICE = {
  provider_rate_limited: 'Devo aspettare un po’. Il tuo lavoro è salvato. Puoi continuare con il tuo passo.',
  provider_timeout: 'La risposta sta tardando. Il tuo lavoro è salvato.',
  provider_service_unavailable: 'Il collegamento è temporaneamente occupato. Il tuo lavoro è salvato.',
  provider_configurazione: 'Serve l’aiuto del tuo insegnante per il collegamento. Il tuo lavoro è salvato.',
  provider_non_disponibile: 'Non riesco a collegarmi. Il tuo lavoro è salvato: riprova tra poco.',
  output_non_valido: 'Non ho trovato una risposta adatta. Riprova a dirmelo.',
  output_bloccato: 'Non ho trovato una risposta adatta. Riprova a dirmelo.',
  autorizzazione_mancante: 'Non posso risponderti finché non arriva un\'autorizzazione. Avvisa il tuo psicologo.',
};

let studyMode=null, homeData=null, runQueue=[], autoMicBlocked=false, listenEpoch=0, runEpoch=0;
let root, ses = null, look = null, voice = null, av = null;
let ui = 'idle';                 // idle | speaking | listening | confirm | thinking
let lastLocal = '', lastProposal = 0, lastSpoken = 0, countdown = null, micBroken = false, noSpeech = 0, busy = false, tick = null;
let stage = null;                // riferimenti agli elementi del palco
const NS = 'http://www.w3.org/2000/svg';
const ICONS = {
  mic: 'M12 3a3 3 0 0 1 3 3v6a3 3 0 0 1-6 0V6a3 3 0 0 1 3-3zM5 11a7 7 0 0 0 14 0M12 18v3M8 21h8',
  stop: 'M7 7h10v10H7z', pause: 'M8 5v14M16 5v14', play: 'M8 5l11 7-11 7z',
  replay: 'M4 12a8 8 0 1 0 2.3-5.6M4 4v5h5', keys: 'M3 7h18v10H3zM7 11h.01M11 11h.01M15 11h.01M8 14h8',
};
function icon(n, size = 34) {
  const svg = document.createElementNS(NS, 'svg');
  svg.setAttribute('viewBox', '0 0 24 24'); svg.setAttribute('width', size); svg.setAttribute('height', size); svg.setAttribute('aria-hidden', 'true');
  const p = document.createElementNS(NS, 'path');
  p.setAttribute('d', ICONS[n]); p.setAttribute('fill', n === 'play' || n === 'stop' ? 'currentColor' : 'none'); p.setAttribute('stroke', 'currentColor');
  p.setAttribute('stroke-width', '2.2'); p.setAttribute('stroke-linecap', 'round'); p.setAttribute('stroke-linejoin', 'round');
  svg.append(p); return svg;
}
const K = () => { const k=(prefs.kid ||= {subs:true,sound:true}); if(k.auto == null)k.auto=true;return k; };
const saveK = () => applyPrefs();

export function mount(el) { root = el; studyMode=null; runQueue=[]; home(); }

// ────────────────────────────────────────────── utilità
const say = (text, opts = {}) => (K().sound && voice?.canSpeak) ? voice.speak(text, { ...opts, onError: message => {
  av?.setState('idle'); if (stage) setUi('idle'); toast(message); opts.onError?.(message);
} }) : (opts.onEnd?.(), false);
const playButton = (attrs, ...children) => h('button', { ...attrs, 'data-voice-play': 'true', disabled: !!voice?.speaking }, ...children);
const stopAll = () => { listenEpoch++; runEpoch++; clearCountdown(); voice?.stopSpeaking(); voice?.abortListening(); };
function clearCountdown() { if (countdown) { clearInterval(countdown.t); countdown = null; } }
const fmt = (s) => { s = Math.max(0, Math.floor(s || 0)); return `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}`; };
const lastAgent = () => [...(ses?.messages || [])].reverse().find(m => m.role === 'agent');
/** È il turno dello studente? In pianificazione e riflessione l'agente attende sempre una risposta; durante il lavoro solo se fa una domanda (per non ascoltare mentre lo studente studia). */
const myTurn = (s, text) => ACTIVE.includes(s.state) && (!!s.proposal || s.state !== 'performance' || /\?\s*$/.test(text || ''));
const asks15 = (t) => /\b(da\s*)?1\s*(a|al)\s*5\b/i.test(t || '');
function head(title, extra = []) {
  return h('header', { class: 'khead' },
    title ? h('button', { class: 'kicon', 'aria-label': 'Torna ai compiti', onclick: () => { stopAll(); home(); } }, '←') : h('span', { class: 'kpill' }, 'ABLET'),
    h('div', { class: 't' }, title || ''), ...extra,
    h('button', { class: 'kicon', 'aria-label': 'Impostazioni', onclick: settingsSheet }, '⚙'));
}
function setVoice(cfg) {
  voice?.stopSpeaking();voice?.abortListening();
  voice = createVoice(cfg, (on, kind) => {
    if (kind === 'word') { av?.pulse(); return; }
    root?.querySelectorAll('[data-voice-play]').forEach(button => { button.disabled = on; button.setAttribute('aria-busy', String(on)); });
    av?.setState(on ? 'speaking' : 'idle');
    if (stage && on && ui !== 'thinking') setUi('speaking');
    if (stage && !on && ui === 'speaking') setUi('idle');
  });
}

// ────────────────────────────────────────────── schermata iniziale
async function home() {
  clearInterval(tick); stopAll(); ses = null; stage = null;
  let d;
  try { d = await api('GET', '/api/student/home'); } catch (e) { toast(errText(e)); return; }
  homeData=d;
  look = d.look;
  setVoice({ ...look.voice, input_enabled: look.voice.input_enabled, output_enabled: look.voice.output_enabled });
  const kid = h('div', { class: 'kid' });
  kid.style.setProperty('--k', look.avatar.color || '#2f6f6a');
  av = avatar({ ...look.avatar, style: look.avatar.enabled ? look.avatar.style : 'astratto' });
  if (!S.user) return;
  const name = (S.user.alias || '').split(' ')[0];
  const hello = `Ciao${name && !/^studente/i.test(name) ? ' ' + name : ''}! Che cosa facciamo oggi?`;
  const open = d.sessions.filter(s => !s.state.startsWith('closed'));
  const tile = (a) => {
    const resume = open.find(s => s.activity_id === a.id);
    const speakIt = (e) => { e.stopPropagation(); voice.unlock(); say(`${a.title}. ${a.assignment}`); };
    return h('div', { class: 'ktile' + (resume ? ' resume' : '') + (a.available || resume ? '' : ' locked'), role: 'button', tabindex: '0', 'aria-label': (resume ? 'Continua: ' : 'Inizia: ') + a.title,
      onclick: () => {if(a.available || resume){prepareQueue({activity:a.id});startActivity(a,resume);}}, onkeydown: (e) => { if ((e.key === 'Enter' || e.key === ' ') && (a.available || resume)) { e.preventDefault(); prepareQueue({activity:a.id});startActivity(a, resume); } } },
      playButton({ class: 'say', 'aria-label': 'Ascolta il compito', onclick: speakIt }, '🔊'),
      h('div', { class: 'em', 'aria-hidden': 'true' }, a.mode === 'free' ? '💡' : (a.agent_enabled ? '📚' : '🧭')), h('h3', {}, a.title), h('p', {}, a.assignment),
      h('div', { class: 'foot' }, a.expected_minutes ? h('span', { class: 'kpill' }, `⏱ ${a.expected_minutes} min`) : null, a.agent_enabled ? null : h('span', { class: 'kpill' }, 'da solo'),
        a.available || resume ? h('span', { class: 'go' }, resume ? 'Continua ▶' : 'Inizia ▶') : h('span', { class: 'kpill' }, a.reason === 'autorizzazioni' ? '🔒 manca un\'autorizzazione' : '🔒 non ancora pronto')));
  };
  clear(root).append(kid);
  if(!studyMode){
    kid.append(head(''),h('main',{class:'khome'},h('h1',{},'Come vuoi lavorare oggi?'),h('p',{},'Puoi fermarti dopo un’attività oppure proseguire con le successive. Puoi sempre fare una pausa.'),h('div',{class:'ktiles'},
      h('button',{class:'ktile',onclick:()=>{voice.unlock();studyMode='single';home();}},h('h2',{},'Un’attività alla volta'),h('p',{},'Alla fine torni alla scelta delle attività.')),
      h('button',{class:'ktile',onclick:()=>{voice.unlock();studyMode='sequence';home();}},h('h2',{},'Tutte in sequenza'),h('p',{},'Dopo ogni attività completata passiamo alla successiva assegnata.')))));
    return;
  }
  const inst = h('span', { class: 'kinstall' });
  const paintInstall = () => inst.replaceChildren(installButton() || '');
  paintInstall(); removeEventListener('ablet:installabile', onInstallable); onInstallable = paintInstall; addEventListener('ablet:installabile', onInstallable);
  keepAwake(false);
  kid.append(head('', [inst, h('button', { class: 'kicon', 'aria-label': 'Come funziona ABLET', onclick: () => infoSheet(d.notice) }, 'ℹ')]),
    h('main', { class: 'khome' },
      h('div', { class: 'khello' }, av.el, h('div', {}, h('h1', {}, hello), h('p', {}, studyMode==='sequence'?'Modalità sequenza: scegli da dove iniziare.':'Modalità: un’attività alla volta.'),h('button',{class:'kchip',onclick:()=>{studyMode=null;runQueue=[];home();}},'Cambia modalità'),
        playButton({ class: 'kicon', onclick: () => { voice.unlock(); av.setState('speaking'); say(hello + ' Tocca un compito per iniziare.', { onEnd: () => av.setState('idle') }); } }, '🔊 Ascolta'))),
      h('button',{class:'kchip',onclick:sequenceSheet},'Qual è la sequenza delle attività?'),
      (d.programs || []).filter(p=>p.status==='active').slice(0,1).map(p=>{const step=p.steps.find(s=>s.current);return step?h('section',{class:'card'},h('h2',{},'La tua attività'),h('h3',{},step.activity),h('button',{class:'kchip go',disabled:!step.available,onclick:()=>{prepareQueue({step:step.id});startActivity({id:step.activity_id},null,step.id);}},step.available?'Inizia o riprendi':'Chiedi al tuo professionista')):null;}),
      open.filter(s=>!d.activities.some(a=>+a.id===+s.activity_id)&&!(d.programs||[]).some(p=>p.status!=='cancelled'&&p.steps.some(step=>step.session_public_id===s.public_id))).map(s=>h('button',{class:'kchip',onclick:()=>startActivity({id:s.activity_id},s)},`Riprendi sessione precedente: ${s.title}`)),
      h('div', { class: 'ktiles' }, ((d.programs||[]).some(p=>p.status==='active')?[]:d.activities.slice(0,1)).map(a => a.mode !== 'free' ? tile(a) : h('section', {}, tile(a),
        open.filter(s => s.activity_id === a.id).map(s => h('button', { class: 'kchip', onclick: () => startActivity(a, s) }, `Riprendi: ${s.title}`)),
        a.available && open.some(s => s.activity_id === a.id) ? h('button', { class: 'kchip go', onclick: () => startActivity(a, null) }, '＋ Nuovo compito libero') : null))),
      d.sessions.some(s => s.state.startsWith('closed')) ? h('details', { class: 'small' }, h('summary', {}, 'Compiti finiti'), h('ul', {}, d.sessions.filter(s => s.state.startsWith('closed')).map(s => h('li', {}, `${s.title} — ${new Date(s.at * 1000).toLocaleDateString('it-IT')}`)))) : null));
}

function sequenceItems(d=homeData){
  return [...(d?.programs||[]).filter(p=>p.status==='active').flatMap(p=>p.steps.filter(s=>['pending','started','blocked'].includes(s.status)).map(s=>({step:s.id,activity:s.activity_id,title:s.activity}))),
    ...(d?.activities||[]).filter(a=>a.available).map(a=>({activity:a.id,title:a.title}))];
}
function prepareQueue(selected){
  const items=sequenceItems();const index=items.findIndex(x=>selected.step?x.step===selected.step:!x.step&&+x.activity===+selected.activity);
  runQueue=studyMode==='sequence'&&index>=0?items.slice(index+1):[];
}
function sequenceSheet(){
  stopAll();
  const items=sequenceItems();
  const text=items.length?items.map((s,i)=>`${i+1}. ${s.title}`).join('. '):'Non ci sono altre attività assegnate.';
  sheet('La sequenza delle attività',h('div',{},h('p',{},'Ecco le attività assegnate ancora da svolgere, nell’ordine del programma.'),items.length?h('ol',{},items.map(s=>h('li',{},s.title))):h('p',{},text),playButton({class:'kchip',onclick:()=>say(text)},'🔊 Ascolta la sequenza')));
}
async function advanceSequence(){
  const next=runQueue[0];if(!next)return false;const epoch=runEpoch;
  const d=await api('GET','/api/student/home'); if(epoch!==runEpoch)return false;homeData=d;
  if(next.step){const step=(d.programs||[]).flatMap(p=>p.status==='active'?p.steps:[]).find(s=>s.id===next.step);if(!step?.current||!step.available)return false;}
  else if(!d.activities.some(a=>+a.id===+next.activity&&a.available))return false;
  runQueue.shift();await startActivity({id:next.activity},null,next.step||null);return true;
}

async function startActivity(a, resume, plannedStep = null) {
  if(!plannedStep && resume && !runQueue.length)prepareQueue({activity:a.id});
  voice.unlock(); // nel gesto di tocco: necessario su iPad perché l'avatar possa parlare
  try {
    if (plannedStep) {
      ses = (await api('POST', `/api/student/plan-steps/${plannedStep}/start`, {})).session;
    } else if (resume) {
      ses = (await api('GET', `/api/student/sessions/${resume.public_id}`)).session;
    } else {
      ses = (await api('POST', '/api/student/sessions', { activity_id: a.id })).session;
    }
    lastSpoken = Math.max(0, ...ses.messages.filter(m => m.role === 'agent').map(m => m.id)) - (resume ? 1 : 0); // alla ripresa rilegge l'ultimo messaggio
    lastLocal = ''; autoMicBlocked=false; noSpeech=0;
    buildStage();
    if (ses.state === 'created') { await act('start'); } else { afterTurn(); }
  } catch (e) { toast(errText(e)); }
}

// ────────────────────────────────────────────── app sul tablet
let onInstallable = null, wake = null;
const standalone = () => matchMedia('(display-mode: standalone)').matches || matchMedia('(display-mode: fullscreen)').matches || navigator.standalone === true;
const isIos = () => /iPad|iPhone|iPod/.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);

/** Pulsante «Metti sul tablet»: prompt nativo (Chrome/Edge/Android) o istruzioni per Safari su iPad. Nulla se l'app è già installata. */
function installButton() {
  if (standalone()) return null;
  if (pwa.prompt) {
    return h('button', { class: 'kicon wide', onclick: async () => { const p = pwa.prompt; pwa.prompt = null; p.prompt(); try { await p.userChoice; } catch { /* annullato */ } dispatchEvent(new Event('ablet:installabile')); } }, '📲 Metti sul tablet');
  }
  if (isIos()) return h('button', { class: 'kicon wide', onclick: iosSheet }, '📲 Metti sul tablet');
  return null;
}
function iosSheet() {
  const t = 'Per avere ABLET come app: tocca il pulsante Condividi in alto, poi Aggiungi alla schermata Home. Se non lo trovi, chiedi a un adulto.';
  sheet('Metti ABLET sul tablet', h('div', { class: 'kios' },
    h('ol', {}, h('li', {}, 'Tocca ', h('b', {}, 'Condividi'), ' ', h('span', { class: 'kshare', 'aria-hidden': 'true' }, '⬆'), ' in alto.'),
      h('li', {}, 'Scegli ', h('b', {}, 'Aggiungi alla schermata Home'), '.'), h('li', {}, 'Tocca ', h('b', {}, 'Aggiungi'), '. Troverai ABLET tra le app.')),
    h('p', { class: 'small' }, 'Funziona con Safari. Se non lo trovi, chiedi a un adulto.'),
    playButton({ class: 'kchip go', onclick: () => say(t) }, '🔊 Ascolta')));
}

/** Schermo sempre acceso durante il lavoro (Screen Wake Lock). Se il browser non lo consente, nessun effetto. */
async function keepAwake(on) {
  try {
    if (on && !wake && 'wakeLock' in navigator && document.visibilityState === 'visible') {
      wake = await navigator.wakeLock.request('screen');
      wake.addEventListener('release', () => { wake = null; });
    } else if (!on && wake) { const w = wake; wake = null; await w.release(); }
  } catch { wake = null; }
}

// Tablet in standby o app in secondo piano: si zittiscono voce e microfono; al ritorno si rilegge lo stato dal server
// (la sessione può essere cambiata da un'altra scheda o dopo una disconnessione) e si riattiva lo schermo acceso.
document.addEventListener('visibilitychange', async () => {
  if (document.visibilityState === 'hidden') { if (stage) { stopAll(); setUi('idle'); } return; }
  if (!ses || !stage || busy) return;
  try { ses = (await api('GET', `/api/student/sessions/${ses.id}`)).session; update(); } catch { /* la prossima azione mostrerà l'errore */ }
});
addEventListener('offline', () => { if (stage) { stopAll(); toast('Manca la connessione. Il tuo lavoro è salvato.'); } });
addEventListener('online', () => { if (stage) toast('La connessione è tornata.'); });

// ────────────────────────────────────────────── comunicazione con il server
async function send(kind, body) {
  if (busy) return false;
  busy = true; setUi('thinking');
  try {
    const r = await api('POST', `/api/student/sessions/${ses.id}/${kind}`, { ...body, client_event_id: uuid() });
    ses = r.session;
    if (r.notice && NOTICE[r.notice]) {
      if (r.notice.startsWith('provider_')) autoMicBlocked = true;
      toast(NOTICE[r.notice]);
    }
    busy = false;
    afterTurn();
    return true;
  } catch (e) {
    busy = false; setUi('idle'); update();
    const msg = errText(e); toast(msg); say(msg);
    return false;
  }
}
const act = (action, payload = {}) => send('action', { action, payload });
const message = (text, modality) => send('message', { text, modality });

/** Dopo ogni risposta: aggiorna il palco, legge il nuovo messaggio dell'avatar, poi (se attivo) torna ad ascoltare. */
function afterTurn() {
  update();
  if (!ses.agent_enabled) { // attività senza agente: legge le indicazioni fisse quando cambiano, poi ascolta
    const t = soloPrompt(), fresh = !!t && t !== lastLocal;
    lastLocal = t;
    const go = () => { setUi('idle'); if (fresh && K().auto !== false && !autoMicBlocked && voice.canListen && !micBroken && ['forethought', 'self_reflection'].includes(ses.state)) startListening(); };
    if (fresh && K().sound && voice.canSpeak) { setUi('speaking'); speakCaption(t, go, true); } else go();
    return;
  }
  const m = lastAgent();
  const fresh = m && m.id > lastSpoken;
  if (fresh) lastSpoken = m.id;
  const turnEpoch=runEpoch;
  const listenAfter = () => {
    if(turnEpoch!==runEpoch||!stage)return;
    setUi('idle');
    if (K().auto !== false && !autoMicBlocked && voice.canListen && !micBroken && ACTIVE.includes(ses.state) && document.visibilityState !== 'hidden') startListening();
  };
  const prop = ses.proposal && ses.proposal.id !== lastProposal ? ses.proposal : null;
  if (prop) lastProposal = prop.id;
  const text = [fresh ? m.content : null, prop ? prop.text : null].filter(Boolean).join(' ');
  if (text && K().sound && voice.canSpeak) { setUi('speaking'); speakCaption(text, listenAfter); }
  else setUi('idle');
}

// ────────────────────────────────────────────── palco
function buildStage() {
  clearInterval(tick); stopAll();
  const kid = h('div', { class: 'kid' });
  kid.style.setProperty('--k', (ses.avatar.color || look?.avatar?.color || '#2f6f6a'));
  setVoice(ses.voice);
  av = avatar({ ...ses.avatar, style: ses.avatar.enabled ? ses.avatar.style : 'astratto' });
  const timer = h('span', { class: 'kchip-timer', role: 'timer', 'aria-label': 'Tempo di lavoro' }, '0:00');
  const path = h('div', { class: 'kpath', 'aria-label': 'A che punto sei' });
  const status = h('div', { class: 'kstatus', role: 'status', 'aria-live': 'polite' });
  const caption = h('div', { class: 'kcaption', 'aria-live': 'polite' });
  const heard = h('div', { class: 'kheard hidden', 'aria-live': 'assertive' });
  const chips = h('div', { class: 'kchips' });
  const mic = h('button', { class: 'kmic', 'aria-label': 'Parla', onclick: onMic }, icon('mic', 46), h('span', {}, 'Parla'));
  const pause = h('button', { class: 'kround', 'aria-label': 'Pausa', onclick: () => { stopAll(); act('pause'); } }, icon('pause'), h('span', {}, 'Pausa'));
  const replay = h('button', { class: 'kround', 'aria-label': 'Riascolta', onclick: replayLast }, icon('replay'), h('span', {}, 'Riascolta'));
  const overlay = h('div', {});
  stage = { kid, timer, path, status, caption, heard, chips, mic, pause, replay, overlay };
  kid.append(
    head(ses.activity.title, [ses.demo && ses.agent_enabled ? h('span', { class: 'kdemo', title: 'Risposte simulate, non generate da un modello' }, 'prova') : null, timer]),
    path,
    h('div', { class: 'kcenter' }, h('div', { class: 'kav' }, av.el, status), h('div', { class: 'kside' }, caption, heard, chips)),
    h('div', { class: 'kbar' }, pause, mic, replay, h('button',{class:'voice-interrupt',onclick:()=>{stopAll();setUi('idle');}},'■ Interrompi voce e microfono')),
    h('div', { class: 'ktools' },
      h('button',{class:'kicon',onclick:sequenceSheet},'Qual è la sequenza?'),
      ses.materials.length ? h('button', { class: 'kicon', onclick: textSheet }, '📖 Il testo') : null,
      h('button', { class: 'kicon', onclick: planSheet }, '🗺 Il mio piano'),
      h('button', { class: 'kicon', onclick: () => keysSheet('') }, '⌨ Scrivo'),
      h('button', { class: 'kicon', onclick: historySheet }, '📜 Tutto')),
    overlay);
  clear(root).append(kid);
  tick = setInterval(() => {
    if (!ses?.clock?.running) return;
    stage.base ??= { t0: Date.now(), s: ses.clock.active_s || 0 };
    timer.textContent = fmt(stage.base.s + (Date.now() - stage.base.t0) / 1000);
  }, 1000);
}

function setUi(s) {
  ui = s;
  if (!stage) return;
  const map = { idle: 'idle', speaking: 'speaking', listening: 'listening', confirm: 'idle', thinking: 'thinking' };
  if (ses && ['paused', 'safety_hold'].includes(ses.state)) av?.setState('sleeping'); else av?.setState(map[s] || 'idle');
  const canTalk = ACTIVE.includes(ses.state) && !micBroken && voice.canListen;
  const st = stage.status;
  st.textContent = s === 'speaking' ? '🔊 Sto parlando… tocca il microfono per rispondermi'
    : s === 'listening' ? '👂 Ti ascolto…'
    : s === 'thinking' ? '💭 Ci penso…'
    : s === 'confirm' ? 'Ho capito bene?'
    : canTalk ? (S.slow ? 'La rete è lenta: se la voce non va, scrivi ⌨' : 'Tocca il microfono e parla') : (ACTIVE.includes(ses.state) ? 'Scrivi o usa i pulsanti' : '');
  const m = stage.mic;
  m.classList.toggle('on', s === 'listening');
  m.classList.toggle('turn', s === 'idle' && canTalk && myTurn(ses, lastAgent()?.content));
  m.disabled = s === 'thinking' || !ACTIVE.includes(ses.state);
  m.replaceChildren(...(s === 'listening' ? [icon('stop', 40), h('span', {}, 'Basta')] : (canTalk ? [icon('mic', 46), h('span', {}, 'Parla')] : [icon('keys', 44), h('span', {}, 'Scrivi')])));
  m.setAttribute('aria-label', s === 'listening' ? 'Smetti di ascoltare' : canTalk ? 'Parla' : 'Scrivi');
  stage.pause.disabled = !ACTIVE.includes(ses.state) || s === 'thinking';
  stage.replay.disabled = !lastAgent() || s === 'thinking' || s === 'speaking' || !!voice?.speaking;
  stage.replay.setAttribute('aria-busy', String(!!voice?.speaking));
}

/** Didascalia dell'ultimo messaggio dell'avatar, parola per parola (per evidenziare la parola letta). */
function renderCaption(text, local = false) {
  const c = stage.caption;
  c.classList.toggle('local', local);
  c.classList.toggle('hidden-subs', !K().subs);
  const words = [];
  const re = /\S+/g; let m; const frag = [];
  let pos = 0;
  while ((m = re.exec(text)) !== null) {
    if (m.index > pos) frag.push(text.slice(pos, m.index));
    const sp = h('span', { class: 'w' }, m[0]); words.push([m.index, m.index + m[0].length, sp]); frag.push(sp);
    pos = m.index + m[0].length;
  }
  c.replaceChildren(...frag);
  return words;
}
function speakCaption(text, onEnd, local = false) {
  const words = renderCaption(text, local);
  return say(text, { onWord: (s) => { for (const [a, b, sp] of words) sp.classList.toggle('on', s >= a && s < b); }, onEnd: () => { av?.setState('idle'); onEnd?.(); } });
}
function replayLast() {
  if (voice?.speaking) return;
  stopAll(); voice.unlock();
  const m = lastAgent(); if (!m) return;
  if (!K().sound || !voice.canSpeak) { toast('La voce è spenta: puoi riaccenderla con ⚙.'); return; }
  setUi('speaking'); speakCaption(m.content, () => {setUi('idle');if(K().auto !== false&&!autoMicBlocked&&ACTIVE.includes(ses.state))startListening();});
}

function update() {
  if (!stage) return;
  const s = ses;
  const heading = root.querySelector('.khead .t');
  if (heading) heading.textContent = s.activity.title;
  stage.base = null;
  stage.timer.textContent = fmt(s.clock.active_s);
  // percorso
  const goals = s.goals.filter(g => g.status !== 'dropped');
  stage.path.replaceChildren(h('span', { class: 'ph' }, PHASE[s.state] || ''),
    ...(s.phase === 'performance' || s.phase === 'self_reflection' ? goals.map(g => h('span', { class: 'dot ' + g.status, title: g.description, 'aria-label': `Passo ${g.n}: ${g.description}` }, g.status === 'completed' ? '✓' : '')) : []),
    s.state === 'performance' && s.current_goal_id ? h('span', { class: 'kpill' }, `Passo ${goals.findIndex(g => g.id === s.current_goal_id) + 1} di ${goals.length}`) : '');
  // didascalia
  const m = lastAgent();
  if (!s.agent_enabled) renderCaption(soloPrompt(), true);
  else if (m) renderCaption(m.content);
  else renderCaption('Tocca «Iniziamo» quando sei pronto.', true);
  renderChips();
  renderOverlay();
  setUi(ui === 'speaking' || ui === 'listening' ? ui : 'idle');
  keepAwake(ACTIVE.includes(s.state)); // schermo acceso solo mentre si lavora; in pausa il tablet può spegnersi
}

// ────────────────────────────────────────────── risposte rapide (equivalenti ai comandi vocali)
function playChip(label, fn, cls = '', sub) { return playButton({ class: 'kchip ' + cls, onclick: () => { if (voice?.speaking) return; stopAll(); fn(); } }, label, sub ? h('small', {}, sub) : null); }
function chip(label, fn, cls = '', sub) { return h('button', { class: 'kchip ' + cls, onclick: () => { stopAll(); fn(); } }, label, sub ? h('small', {}, sub) : null); }
function scale(q, fn, low, high) {
  return [h('div', { class: 'kq' }, q), ...[1, 2, 3, 4, 5].map(n => chip(String(n), () => fn(n), '', n === 1 ? low : n === 5 ? high : null))];
}
function renderChips() {
  const s = ses, c = stage.chips, last = lastAgent()?.content || '', out = [];
  if (s.state === 'created') out.push(chip('▶ Iniziamo', () => act('start'), 'go'));
  else if (s.proposal) out.push(h('div', { class: 'kq' }, s.proposal.text), chip('👍 Sì, proviamo', () => act('accept_proposal'), 'go'), chip('No, grazie', () => act('decline_proposal')));
  else if (s.state === 'forethought') {
    const need = s.missing[0];
    if (!s.agent_enabled) {
      if (need === 'task_statement') out.push(playChip('📖 Leggimi il compito', () => say(s.activity.assignment), 'soft'));
      if (!s.missing.length) out.push(chip('▶ Inizio a lavorare', () => act('begin'), 'go'));
    } else if (need === 'expected_difficulty') out.push(...scale('Quanto sarà difficile?', (n) => message(String(n), 'text'), 'facile', 'difficile'));
    else if (need === 'self_efficacy') out.push(...scale('Quanto ti senti capace?', (n) => message(String(n), 'text'), 'poco', 'molto'));
    else if (need === 'time_estimate_min') out.push(h('div', { class: 'kq' }, 'Quanto tempo ti serve?'), ...[5, 10, 15, 20, 30, 45].map(n => chip(`${n} min`, () => message(`${n} minuti`, 'text'))));
    else if (need === 'micro_goal') out.push(chip('🤔 Non so da dove iniziare', () => message('Non so da dove iniziare, mi aiuti a trovare il primo passo?', 'text'), 'soft'));
    else if (need === 'task_statement' || need === 'restatement') out.push(playChip('📖 Leggimi il compito', () => say(s.activity.assignment), 'soft'));
    if (s.agent_enabled && !s.missing.length) out.push(chip('▶ Inizio a lavorare', () => act('begin'), 'go'));
  } else if (s.state === 'performance') {
    if (asks15(last) && s.agent_enabled) {
      out.push(...scale('Quanto sei sicuro?', async (n) => { if (await act('self_assessment', { kind: 'sicurezza', value: n })) message(String(n), 'text'); }, 'poco', 'molto'));
    } else if (s.current_goal_id) {
      out.push(chip('✓ Fatto!', () => act('step_done'), 'go'), s.agent_enabled ? chip('💡 Un aiuto', () => act('hint')) : null, chip('😣 Sono bloccato', () => act('difficulty')),
        chip('✗ Non ci sono riuscito', () => act('step_not_done')), chip('🔁 Provo in un altro modo', changeStrategy, 'soft'));
    } else out.push(chip('▶ Vediamo com\'è andata', () => act('to_reflection'), 'go'));
  } else if (s.state === 'self_reflection') {
    if (!s.assessed.includes('valutazione_risultato')) out.push(...scale('Rispetto al tuo obiettivo, com\'è andata?', (n) => act('self_assessment', { kind: 'valutazione_risultato', value: n }), 'male', 'benissimo'));
    else if (!s.assessed.includes('reazione_emotiva')) out.push(h('div', { class: 'kq' }, 'Come ti senti? (se vuoi)'),
      ...[['🙂', 'contento'], ['😐', 'così così'], ['🙁', 'giù']].map(([e, w]) => chip(`${e} ${w}`, () => act('self_assessment', { kind: 'reazione_emotiva', value: w }))),
      chip('Preferisco non dirlo', () => act('self_assessment', { kind: 'reazione_emotiva', value: 'non detto' }), 'soft'));
    out.push(chip('🏁 Ho finito', () => act('close_complete'), 'go'));
  }
  c.replaceChildren(...out.filter(Boolean));
}

function changeStrategy() {
  const prompt = 'Come vuoi provare adesso? Dimmi il nuovo modo.';
  renderCaption(prompt, true);
  const go = () => startListening((text) => act('strategy_change', { strategy: text }));
  if (!speakCaption(prompt, go, true)) go();
}

// Attività senza agente: le indicazioni sono testi fissi dell'interfaccia (non generati).
function soloPrompt() {
  const s = ses;
  if (s.state === 'created') return 'Oggi lavori da solo. Io ti tengo solo il tempo e il piano.';
  if (s.state === 'forethought') return s.missing[0] === 'task_statement' ? 'Oggi lavori da solo: io tengo il tempo e il piano. Con parole tue, che cosa devi fare?' : (s.missing.length ? 'Dimmi un piccolo passo del tuo piano.' : 'Il piano è pronto. Puoi aggiungere un passo o iniziare.');
  if (s.state === 'performance') { const g = s.goals.find(x => x.id === s.current_goal_id); return g ? `Adesso: ${g.description}` : 'Hai chiuso tutti i passi.'; }
  if (s.state === 'self_reflection') return 'Com\'è andata? Dimmi che cosa ha funzionato e che cosa faresti diversamente.';
  return '';
}

// ────────────────────────────────────────────── voce: ascolto, conferma, invio
function onMic() {
  voice.unlock();
  if (ui === 'listening') { voice.stopListening(); return; }
  if (!voice.canListen || micBroken) { keysSheet(''); return; }
  stopAll();
  noSpeech = 0;autoMicBlocked=false;
  startListening();
}

/** Ascolta; il testo riconosciuto passa sempre dalla conferma (modificabile, con invio automatico interrompibile). */
function startListening(onFinal) {
  if (!voice.canListen || micBroken || !stage) return;
  const epoch=++listenEpoch;
  let text = '', fin = false, err = null;
  setUi('listening');
  showHeard('Ti ascolto…', true);
  voice.listen({
    silenceMs:3000,
    onSilence:()=>{if(epoch!==listenEpoch)return;autoMicBlocked=true;toast('Microfono spento dopo 3 secondi di silenzio. Tocca Parla per riattivarlo.');},
    onProcessing: () => { if(epoch!==listenEpoch)return;setUi('thinking'); renderCaption('Sto trascrivendo la tua risposta…', true); },
    onText: (t, f) => { if(epoch!==listenEpoch)return;text = t; fin = f; showHeard(t || '…', !f); },
    onError: (msg, fatal) => { if(epoch!==listenEpoch)return;err = msg; if (fatal) { micBroken = true; } },
    onEnd: () => {
      if(epoch!==listenEpoch)return;
      if (err) {
        hideHeard(); setUi('idle');
        if (/Non ti ho sentito/.test(err)) { autoMicBlocked=true;noSpeech++;toast('Microfono spento. Tocca Parla per riattivarlo.'); } else { toast(err); renderCaption(err, true); say(err); }
        return;
      }
      if (!text) { autoMicBlocked=true;noSpeech++; hideHeard(); setUi('idle'); return; }
      noSpeech = 0;
      confirmHeard(text, onFinal);
    },
  });
}

function showHeard(t, interim) {
  const hd = stage.heard;
  hd.classList.remove('hidden');
  hd.replaceChildren(h('div', { class: 'lbl' }, interim ? 'Sto ascoltando' : 'Hai detto'), h('div', { class: 'txt' + (interim ? ' interim' : '') }, t));
}
function hideHeard() { stage?.heard.classList.add('hidden'); clearCountdown(); }

/** Comandi vocali brevi riconosciuti dall'interfaccia (sempre mostrati prima dell'esecuzione). */
function intent(t) {
  const x = t.toLowerCase().replace(/[.!?,]/g, '').trim();
  if (/\bsequenza\b|ordine delle attività|^(quali sono|che attività|quali attività).*(attività|dopo|fare)/.test(x)) return {label:'Mostra la sequenza',run:sequenceSheet,local:true};
  if (x.split(/\s+/).length > 4) return null;
  if (/^(ripeti|puoi ripetere|ripetimi|non ho capito|cosa hai detto)$/.test(x)) return { label: '🔁 Ripeti', run: replayLast, local: true };
  if (/^(pausa|fermati|facciamo una pausa|stop)$/.test(x) && ACTIVE.includes(ses.state)) return { label: '⏸ Pausa', run: () => act('pause') };
  if (ses.proposal) {
    if (/^(sì|si|va bene|ok|okay|certo|proviamo|sì proviamo|si proviamo|d'accordo)$/.test(x)) return { label: '👍 Sì, proviamo', run: () => act('accept_proposal') };
    if (/^(no|no grazie|non voglio|preferisco di no)$/.test(x)) return { label: 'No, grazie', run: () => act('decline_proposal') };
  }
  if (ses.state === 'performance' && ses.current_goal_id && !asks15(lastAgent()?.content)) {
    if (/^(fatto|ho fatto|ho finito|finito|ok fatto|fatto il passo)$/.test(x)) return { label: '✓ Fatto!', run: () => act('step_done') };
    if (/^(aiuto|aiutami|un aiuto|un indizio|mi dai un indizio)$/.test(x) && ses.agent_enabled) return { label: '💡 Un aiuto', run: () => act('hint') };
    if (/^(sono bloccato|sono bloccata|non ci riesco|mi sono bloccato|mi sono bloccata)$/.test(x)) return { label: '😣 Sono bloccato', run: () => act('difficulty') };
  }
  return null;
}

const NUM = { uno: 1, una: 1, due: 2, tre: 3, quattro: 4, cinque: 5, sei: 6, sette: 7, otto: 8, nove: 9, dieci: 10, quindici: 15, venti: 20, venticinque: 25, trenta: 30, quaranta: 40, quarantacinque: 45, cinquanta: 50, sessanta: 60, mezz: 30 };
function numeric(text) {
  if (!ses || !['expected_difficulty', 'self_efficacy', 'time_estimate_min'].includes(ses.missing[0]) && !asks15(lastAgent()?.content)) return text;
  return text.replace(/\b(uno|una|due|tre|quattro|cinque|sei|sette|otto|nove|dieci|quindici|venti|venticinque|trenta|quaranta|quarantacinque|cinquanta|sessanta)\b/gi, (w) => String(NUM[w.toLowerCase()]));
}

function confirmHeard(text, onFinal) {
  text = numeric(text);
  setUi('confirm');
  const cmd = onFinal ? null : intent(text);
  const sendIt = () => {
    clearCountdown(); hideHeard();
    if (onFinal) return onFinal(text);
    if (cmd) { if (cmd.local) { setUi('idle'); cmd.run(); } else cmd.run(); return; }
    if (ses.agent_enabled) return message(text, 'voice');
    // senza agente: la voce compila il piano o la riflessione
    if (ses.state === 'forethought') return ses.missing[0] === 'task_statement' ? act('set_slot', { slot: 'task_statement', value: text }) : act('add_goal', { description: text });
    return message(text, 'voice');
  };
  const n = 4;
  const btnSend = h('button', { class: 'kchip go', onclick: sendIt }, cmd ? cmd.label : '✓ Invia', h('span', { class: 'kcount' }, String(n)));
  const hd = stage.heard;
  hd.classList.remove('hidden');
  hd.replaceChildren(h('div', { class: 'lbl' }, 'Hai detto'), h('div', { class: 'txt' }, `«${text}»`),
    h('div', { class: 'kchips' }, btnSend,
      h('button', { class: 'kchip', onclick: () => { clearCountdown(); hideHeard(); startListening(onFinal); } }, '🎤 Ridico'),
      h('button', { class: 'kchip', onclick: () => { clearCountdown(); hideHeard(); setUi('idle'); keysSheet(text, onFinal); } }, '✎ Correggo')));
  let left = n;
  const reduce = document.documentElement.classList.contains('calm');
  countdown = { t: setInterval(() => { left--; const c = btnSend.querySelector('.kcount'); if (left <= 0) { sendIt(); return; } if (c) c.textContent = String(left); }, reduce ? 1500 : 1000) };
}

// ────────────────────────────────────────────── schermate sovrapposte
function renderOverlay() {
  const o = stage.overlay, s = ses;
  if (s.state === 'paused') {
    const a2 = avatar({ ...s.avatar, style: s.avatar.enabled ? s.avatar.style : 'astratto' }); a2.setState('sleeping');
    o.replaceChildren(h('div', { class: 'kover', role: 'dialog', 'aria-modal': 'true', 'aria-label': 'Pausa' }, a2.el, h('h2', {}, 'Pausa'),
      h('p', {}, 'Il tuo lavoro è salvato. Quando sei pronto, riprendiamo da dove eravamo.'),
      h('button', { class: 'kbig', onclick: () => { voice.unlock(); act('resume'); } }, icon('play', 28), ' Riprendi'),
      h('button', { class: 'kbig light', onclick: () => home() }, 'Continuo un\'altra volta')));
    o.querySelector('.kbig')?.focus();
  } else if (s.state === 'safety_hold') {
    const m = lastAgent();
    o.replaceChildren(h('div', { class: 'kover safe', role: 'alertdialog', 'aria-modal': 'true' }, h('h2', {}, 'Fermiamoci un momento'), h('p', {}, m?.content || ''),
      h('p', {}, 'Se sei in pericolo, parla subito con un adulto vicino a te oppure chiama il 112.'),
      playButton({ class: 'kbig light', onclick: () => { stopAll(); if (m) say(m.content); } }, '🔁 Riascolta'),
      h('button', { class: 'kbig', onclick: () => act('safety_release') }, 'Riprendo a studiare'),
      h('button', { class: 'kbig light', onclick: () => act('close_incomplete') }, 'Chiudo per oggi')));
  } else if (s.state.startsWith('closed')) {
    clearInterval(tick);
    const done = s.goals.filter(g => g.status === 'completed');
    const a2 = avatar({ ...s.avatar, style: s.avatar.enabled ? s.avatar.style : 'astratto' });
    o.replaceChildren(h('div', { class: 'kover', role: 'dialog', 'aria-modal': 'true' }, a2.el,
      h('h2', {}, s.state === 'closed_complete' ? 'Hai completato questa attività' : 'Ci fermiamo qui'),
      done.length ? h('p', {}, `Passi completati: ${done.length} su ${s.goals.filter(g => g.status !== 'dropped').length}.`) : null,
      s.slots.strategy ? h('p', {}, `La tua strategia: ${s.slots.strategy}`) : null,
      h('button', { class: 'kbig', onclick: () => home() }, 'Torna ai compiti'),
      s.state==='closed_complete'&&studyMode==='sequence'&&runQueue.length?h('button',{class:'kbig light',onclick:async()=>{stopAll();try{if(!await advanceSequence())toast('La prossima attività non è ancora disponibile.');}catch(e){toast(errText(e));}}},'Prosegui con la prossima attività'):null));
    if (s.state === 'closed_complete' && stage.completedAnnounced!==s.id) {
      stage.completedAnnounced=s.id;
      const epoch=runEpoch;
      const next=async()=>{a2.setState('idle');if(epoch!==runEpoch||studyMode!=='sequence'||!runQueue.length)return;try{if(!await advanceSequence())toast('La prossima attività non è disponibile. Puoi tornare ai compiti.');}catch(e){toast(errText(e));}};
      a2.setState('speaking');say('Hai completato questa attività.'+(studyMode==='sequence'&&runQueue.length?' Ora proseguiamo con la successiva.':''),{onEnd:next});
    }
  } else o.replaceChildren();
}

function sheet(title, body, onClose) {
  stopAll();if(stage)setUi('idle');
  const previousFocus = document.activeElement;
  let closed=false;
  const close = () => { if(closed)return; closed=true; scrim.remove(); box.remove(); onClose?.(); previousFocus?.focus?.(); };
  const scrim = h('div', { class: 'kscrim', onclick: close });
  const box = h('div', { class: 'ksheet', role: 'dialog', 'aria-modal': 'true', 'aria-label': title, onkeydown: (e) => { if (e.key === 'Escape') { e.preventDefault(); close(); }
    if(e.key==='Tab'){const controls=[...box.querySelectorAll('button:not(:disabled), textarea, input:not(:disabled), select:not(:disabled), a[href]')];const first=controls[0],last=controls.at(-1);if(e.shiftKey&&document.activeElement===first){e.preventDefault();last?.focus();}else if(!e.shiftKey&&document.activeElement===last){e.preventDefault();first?.focus();}} } },
    h('div', { class: 'sh' }, h('h2', {}, title), h('button', { class: 'kicon', 'aria-label': 'Chiudi', onclick: close }, '✕')), h('div', { class: 'sb' }, body));
  (stage?.kid || root.firstChild || root).append(scrim, box);
  setTimeout(() => box.querySelector('button, textarea, input')?.focus(), 30);
  return close;
}

function keysSheet(initial, onFinal) {
  stopAll();
  const ta = h('textarea', { 'aria-label': 'Scrivi qui', maxlength: 2000, placeholder: 'Scrivi qui…' });
  ta.value = initial;
  const submit = () => { const t = ta.value.trim(); if (!t) return; close();
    if (onFinal) return onFinal(t);
    const cmd = intent(t);
    if (cmd) return cmd.run();
    if (ses.agent_enabled) return message(t, 'text');
    if (ses.state === 'forethought') return ses.missing[0] === 'task_statement' ? act('set_slot', { slot: 'task_statement', value: t }) : act('add_goal', { description: t });
    return message(t, 'text'); };
  ta.addEventListener('keydown', (e) => { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); submit(); } });
  const close = sheet('Scrivo', h('div', { class: 'kkeys' }, ta, h('div', { class: 'kchips' }, h('button', { class: 'kchip go', onclick: submit }, '✓ Invia'))));
}

function textSheet() {
  stopAll();
  const blocks = ses.materials.flatMap(m => m.content.split(/\n\s*\n/).map(t => ({ m, t: t.trim() })).filter(b => b.t));
  let i = 0;
  const box = h('div', {});
  const draw = () => {
    const b = blocks[i];
    const words = []; const frag = []; const re = /\S+/g; let mm, pos = 0;
    while ((mm = re.exec(b.t)) !== null) { if (mm.index > pos) frag.push(b.t.slice(pos, mm.index)); const sp = h('span', { class: 'w' }, mm[0]); words.push([mm.index, mm.index + mm[0].length, sp]); frag.push(sp); pos = mm.index + mm[0].length; }
    box.replaceChildren(h('p', { class: 'kq' }, `${b.m.title} · blocco ${i + 1} di ${blocks.length}`), h('div', { class: 'kread' }, frag),
      h('div', { class: 'kchips' },
        h('button', { class: 'kchip', disabled: i === 0, onclick: () => { voice.stopSpeaking(); i--; draw(); } }, '◀ Prima'),
        playButton({ class: 'kchip go', onclick: () => say(b.t, { onWord: (s) => { for (const [a, z, sp] of words) sp.classList.toggle('on', s >= a && s < z); } }) }, '🔊 Leggi'),
        h('button', { class: 'kchip', onclick: () => voice.stopSpeaking() }, '■ Ferma'),
        h('button', { class: 'kchip', disabled: i >= blocks.length - 1, onclick: () => { voice.stopSpeaking(); i++; draw(); } }, 'Dopo ▶')));
  };
  if (!blocks.length) return;
  draw();
  if (ACTIVE.includes(ses.state)) api('POST', `/api/student/sessions/${ses.id}/action`, { action: 'material_opened', payload: { material_id: blocks[0].m.id }, client_event_id: uuid() }).catch(() => {});
  sheet('Il testo', box, () => voice.stopSpeaking());
}

function planSheet() {
  stopAll();
  const goals = ses.goals.filter(g => g.status !== 'dropped');
  const icon = { completed: '✓', not_completed: '✗', active: '▶', pending: '' };
  let close;
  const estimate = ses.time_plan;
  const timeInput = (value, label, save) => h('form', { class: 'row', onsubmit: async e => {
    e.preventDefault(); const minutes = Number(e.target.elements.minutes.value);
    if (await save(minutes)) { close(); planSheet(); }
  } }, h('label', {}, label, h('input', { name: 'minutes', type: 'number', min: 1, max: 600, step: 1, required: true, value: value ?? '', 'aria-label': label })), h('button', { class: 'kchip', type: 'submit' }, 'Salva'));
  const body = h('div', {},
    ses.activity.mode === 'free' ? h('p', {}, 'Attività libera: il compito lo scegli tu. Ti guido con il percorso stabilito dal tuo psicologo.') : null,
    ses.slots.task_statement ? h('p', {}, h('strong', {}, 'Il mio compito: '), ses.slots.task_statement) : null,
    estimate ? h('p', {}, `Tempo stimato: ${estimate.estimated_min ?? 'da stimare'} min${estimate.estimate_source === 'sum_student_micro_goals' ? ' (somma dei passi)' : ''}. Tempo disponibile: ${estimate.available_min ?? 'non indicato'}${estimate.available_min ? ' min' : ''}.`) : null,
    estimate?.total_micro_gap_min ? h('p', {}, 'Il totale dichiarato e la somma dei passi sono diversi: possiamo rivedere insieme il piano.') : null,
    estimate?.exceeds_available ? h('p', {}, 'Il tempo stimato supera quello disponibile: parliamone per organizzare il lavoro.') : null,
    ses.state === 'forethought' && ses.activity.mode === 'free' ? h('div', {},
      h('p', {}, 'Puoi stimare il tempo totale oppure il tempo di ogni passo. Il tempo disponibile è una cosa diversa.'),
      timeInput(ses.slots.time_estimate_min, 'Minuti che penso necessari in totale', minutes => act('set_slot', { slot: 'time_estimate_min', value: minutes })),
      timeInput(ses.slots.available_time_min, 'Minuti che ho a disposizione (facoltativo)', minutes => act('set_slot', { slot: 'available_time_min', value: minutes }))) : null,
    ses.slots.expected_outcome ? h('p', {}, h('strong', {}, 'Il mio obiettivo: '), ses.slots.expected_outcome) : null,
    goals.length ? h('ol', { class: 'ksteps' }, goals.map((g, k) => h('li', { class: g.status }, h('span', { class: 'ic' }, icon[g.status] || String(k + 1)), h('span', { class: 'tx' }, g.description, g.minutes ? ` · ${g.minutes} min` : '',
      ses.state === 'forethought' && ses.activity.mode === 'free' && g.status === 'pending' ? timeInput(g.minutes, `Minuti stimati per il passo ${k + 1}`, minutes => act('set_goal_time', { goal_id: g.id, minutes })) : null),
      ses.state === 'forethought' && g.status === 'pending' ? h('button', { class: 'kicon', 'aria-label': `Togli: ${g.description}`, onclick: () => { close(); act('drop_goal', { goal_id: g.id }); } }, '✕') : null)))
      : h('p', {}, 'Ancora nessun passo. Li costruiamo insieme parlando.'),
    h('div', { class: 'kchips' },
      playButton({ class: 'kchip', onclick: () => { close(); say(goals.length ? 'Il tuo piano: ' + goals.map((g, k) => `passo ${k + 1}, ${g.description}`).join('. ') : 'Non ci sono ancora passi.'); } }, '🔊 Leggimi il piano'),
      ses.state === 'performance' ? h('button', { class: 'kchip', onclick: () => { close(); act('replan'); } }, '📝 Cambio il piano') : null,
      ses.state === 'forethought' && goals.length < ses.max_goals ? h('button', { class: 'kchip', onclick: () => { close(); keysSheet('', (t) => act('add_goal', { description: t })); } }, '➕ Aggiungo un passo') : null));
  close = sheet('Il mio piano', body);
}

function historySheet() {
  stopAll();
  sheet('Tutta la conversazione', h('div', { class: 'khist' }, ses.messages.map(m => h('div', { class: 'm ' + m.role },
    m.role === 'agent' ? playButton({ class: 'kicon', 'aria-label': 'Riascolta questo messaggio', onclick: () => say(m.content) }, '▶') : null, h('div', { class: 'x' }, m.content)))), () => voice.stopSpeaking());
}

function infoSheet(n) {
  sheet('Come funziona ABLET', h('div', {},
    h('p', {}, 'Qui ti risponde un assistente di intelligenza artificiale: non è una persona e non è uno psicologo. Ti aiuta a organizzare lo studio con domande e piccoli aiuti, ma i compiti li fai tu.'),
    h('p', {}, 'Vengono registrati:'), h('ul', {}, n.recorded.map(x => h('li', {}, x))), h('p', {}, n.who),
    h('p', {}, 'Servizio che elabora i testi: ' + (n.services.join(', ') || 'nessuno') + '.'), h('p', {}, VOICE_NOTICE),
    h('p', {}, 'Nessuno legge la conversazione mentre parli. Se stai male o sei in pericolo, parla con un adulto di cui ti fidi oppure chiama il 112.')));
}

function settingsSheet() {
  stopAll();
  const k = K();
  const tg = (key, label) => h('label', { class: 'inline' }, h('input', { type: 'checkbox', checked: !!k[key], onchange: (e) => { k[key] = e.target.checked; saveK(); if (key === 'sound' && !k.sound) { voice?.stopSpeaking(); if (stage) setUi('idle'); } if (stage && ses) update(); } }), label);
  const tp = (key, label) => h('label', { class: 'inline' }, h('input', { type: 'checkbox', checked: !!prefs[key], onchange: (e) => { prefs[key] = e.target.checked; applyPrefs(); } }), label);
  const size = (d) => () => { prefs.fs = Math.min(1.5, Math.max(0.85, (prefs.fs || 1) + d)); applyPrefs(); };
  const cfg = ses?.voice || look?.voice || {};
  sheet('Impostazioni', h('div', { class: 'kset' },
    tg('sound', '🔊 L\'avatar parla ad alta voce'), tg('auto', '👂 Microfono automatico dopo la voce dell’assistente'), tg('subs', '💬 Mostra le parole (sottotitoli)'),
    tp('contrast', 'Colori più contrastati'), tp('calm', 'Meno animazioni'),
    h('div', { class: 'kchips' }, h('button', { class: 'kchip', onclick: size(-0.1), 'aria-label': 'Testo più piccolo' }, 'A−'), h('button', { class: 'kchip', onclick: size(0.1), 'aria-label': 'Testo più grande' }, 'A+')),
    !voice?.canListen || cfg.input_enabled === false ? h('p', { class: 'muted' }, cfg.consent_missing ? 'La voce non è attiva: manca l\'autorizzazione.' : 'Su questo dispositivo puoi scrivere invece di parlare.') : null,
    h('p', {}, h('a', { href: `${BASE}/refresh.php` }, 'Aggiorna app e svuota cache')),
    h('details', {}, h('summary', {}, 'Come funziona la voce'), h('p', {}, VOICE_NOTICE), cfg.engine === 'server' ? h('p', {}, 'Audio elaborato da: ' + (cfg.audio_service || 'provider configurato') + '.') : null, ses ? h('p', {}, 'Testi elaborati da: ' + ses.text_service + '.') : null),
    h('div', { class: 'kchips' },
      ses && ACTIVE.includes(ses.state) ? h('button', { class: 'kchip', onclick: () => { if (confirm('Chiudere il compito senza finirlo? Quello che hai fatto resta salvato.')) { document.querySelector('.kscrim')?.click(); act('close_incomplete'); } } }, 'Chiudo il compito per oggi') : null,
      h('button', { class: 'kchip', onclick: () => { stopAll(); logout(); } }, '🚪 Esci'))));
}
