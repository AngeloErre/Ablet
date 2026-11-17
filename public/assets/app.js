// ABLET — nucleo dell'interfaccia: chiamate API, costruzione sicura del DOM, accesso, preferenze di accessibilità.
export const S = { csrf: '', user: null, slow: false };
/** Percorso di installazione (es. /ablet su hosting in sottocartella); impostato dal server. */
export const BASE = document.documentElement.dataset.base || '';
const root = document.getElementById('app');

/** Crea elementi senza innerHTML: tutto il testo passa da textContent (difesa XSS). */
export function h(tag, attrs = {}, ...kids) {
  const el = document.createElement(tag);
  for (const [k, v] of Object.entries(attrs || {})) {
    if (v == null || v === false) continue;
    if (k.startsWith('on')) el.addEventListener(k.slice(2), v);
    else if (k === 'class') el.className = v;
    else if (k === 'value') el.value = v;
    else if (k === 'checked' || k === 'disabled' || k === 'selected' || k === 'open') el[k] = !!v;
    else el.setAttribute(k, v === true ? '' : v);
  }
  if (String(attrs?.class || '').split(' ').includes('scroll')) { el.setAttribute('tabindex','0'); el.setAttribute('role','region'); el.setAttribute('aria-label',attrs['aria-label'] || 'Tabella scorrevole'); }
  for (const c of kids.flat(Infinity)) if (c != null && c !== false) el.append(c.nodeType ? c : document.createTextNode(String(c)));
  return el;
}
/** Svuota l'elemento; l'oggetto restituito accetta figli annidati e ignora null/false (come h). */
export const clear = (el) => { el.replaceChildren(); return { append: (...kids) => { for (const c of kids.flat(Infinity)) if (c != null && c !== false) el.append(c.nodeType ? c : document.createTextNode(String(c))); return el; } }; };
export const uuid = () => crypto.randomUUID ? crypto.randomUUID() : '10000000-1000-4000-8000-100000000000'.replace(/[018]/g, c => (c ^ crypto.getRandomValues(new Uint8Array(1))[0] & 15 >> c / 4).toString(16));

const ERR = {
  sessione_da_riprogrammare: 'Il protocollo di questa sessione è stato ritirato. Il professionista deve riprogrammarla o saltarla.',
  usa_sessione_programmata: 'Questa attività fa parte di un programma: avvia la sessione dalla sua sequenza.', prima_completa_sessione_precedente: 'Prima completa la sessione precedente del programma.', percorso_non_attivo: 'Questo programma è già concluso o è stato annullato.', sequenza_non_valida: 'Prepara da 1 a 30 sessioni.', chiudi_sessione_prima_di_saltare: 'La sessione è ancora aperta: chiudila prima di saltarla.', sessione_rimossa_per_conservazione: 'I dati di questa sessione non sono più disponibili. Chiedi al professionista come proseguire.',
  attivita_non_assegnata: 'Questa attività non è assegnata al tuo account.', scegli_protocollo_pubblicato: 'Scegli una versione pubblicata di un protocollo attivo.', protocollo_archiviato: 'Il protocollo è archiviato: ripristinalo prima di usarlo per nuove assegnazioni.', provider_audio_non_trovato: 'Il provider audio non è più disponibile: controlla la configurazione.', provider_audio_non_supportato: 'Scegli Gemini o un provider compatibile con le API audio OpenAI.', voce_disattivata: 'Questa funzione vocale è disattivata dal professionista.',
  autorizzazione_audio_provider_mancante: 'Manca l’autorizzazione dedicata all’invio audio: chiedi al professionista di registrarla.', voce_server_non_configurata: 'Configura il motore vocale server in Voce e avatar.', scegli_provider_audio: 'Scegli un provider per l’audio.', prompt_troppo_lungo: 'Il prompt supera il limite configurato: riduci il materiale o usa la modalità economica.',
  accesso_non_corrispondente: 'Le credenziali appartengono all’altra area. Torna alla scelta dell’accesso e seleziona quella corretta.',
  non_autenticato: 'La sessione è scaduta: accedi di nuovo.', credenziali_non_valide: 'Codice o password non corretti.', troppe_richieste: 'Troppe richieste ravvicinate: riprova fra qualche minuto.',
  non_autorizzato: 'Operazione non consentita.', non_trovato: 'Elemento non trovato.', errore_interno: 'Si è verificato un errore. Il lavoro svolto finora è salvato.',
  nessun_protocollo_assegnato: 'Per questa attività non è ancora stato assegnato un protocollo.', autorizzazioni_mancanti: 'Prima di iniziare devono essere registrate alcune autorizzazioni: chiedi al tuo psicologo.', sessione_occupata: 'Sto ancora elaborando la richiesta precedente.',
  tempo_non_valido: 'Indica un numero intero di minuti tra 1 e 600.', tempo_micro_obiettivo_non_valido: 'Puoi impostare da 1 a 600 minuti per passo durante la pianificazione.',
  modalita_attivita_non_valida: 'Scegli una modalità di attività valida.', attivita_libera_richiede_agente_senza_materiali: 'L’attività libera richiede l’agente e non prevede materiali preimpostati.',
  nessun_passo_attivo: 'Non c\'è un passo attivo.', azione_non_disponibile_in_questa_fase: 'Questa azione non è disponibile in questa fase.', rete: 'Connessione assente o molto lenta. Il testo che hai scritto non è andato perso.',
};
export const errText = (e) => e?.error?.startsWith?.('pianificazione_incompleta') ? 'Prima di iniziare completa il piano: mancano ' + planLabels(e.error.split(':')[1]) + '.' : (ERR[e?.error] || (typeof e?.detail === 'string' ? e.detail : null) || e?.error || 'Errore');
const PL = { task_statement: 'che cosa devi fare', restatement: 'la consegna con parole tue', expected_outcome: 'il risultato atteso', time_estimate_min: 'il tempo previsto', strategy: 'la strategia' };
const planLabels = (s = '') => s.split(',').map(x => PL[x] || (x.startsWith('micro_goals') ? 'alcuni passi' : x)).join(', ');

export async function api(method, path, body) {
  const t0 = performance.now();
  const ctl = new AbortController();
  const timer = setTimeout(() => ctl.abort(), 70000);
  let res;
  try {
    res = await fetch(BASE + path, { method, signal: ctl.signal, credentials: 'same-origin', headers: { 'Content-Type': 'application/json', ...(method !== 'GET' ? { 'X-CSRF-Token': S.csrf } : {}) }, body: body ? JSON.stringify(body) : undefined });
  } catch { throw { status: 0, error: 'rete' }; } finally { clearTimeout(timer); }
  S.slow = performance.now() - t0 > 8000 || ['slow-2g', '2g'].includes(navigator.connection?.effectiveType);
  const j = await res.json().catch(() => ({ error: 'errore_interno' }));
  if (!res.ok) { if (res.status === 401 && S.user) { S.user = null; boot(); } throw { status: res.status, ...j }; }
  return j;
}

let toastT;
export function toast(msg) {
  let t = document.getElementById('toast');
  if (!t) { t = h('div', { id: 'toast', role: 'status', 'aria-live': 'polite' }); document.body.append(t); }
  t.textContent = msg; t.classList.remove('hidden');
  clearTimeout(toastT); toastT = setTimeout(() => t.classList.add('hidden'), 6000);
}
export const guard = (fn) => async (...a) => { try { return await fn(...a); } catch (e) { toast(errText(e)); } };

// Preferenze di accessibilità (per dispositivo; se la memoria locale non è disponibile restano valide per la pagina).
export const prefs = (() => { try { return JSON.parse(localStorage.getItem('ablet_prefs')) || {}; } catch { return {}; } })();
export function applyPrefs() {
  document.documentElement.style.setProperty('--fs', String(prefs.fs || 1));
  document.documentElement.classList.toggle('contrast', !!prefs.contrast);
  document.documentElement.classList.toggle('calm', !!prefs.calm);
  try { localStorage.setItem('ablet_prefs', JSON.stringify(prefs)); } catch { /* facoltativo */ }
}
applyPrefs();

function topbar() {
  const tg = (key, label, title) => h('button', { 'aria-pressed': String(!!prefs[key]), title, onclick: (e) => { prefs[key] = !prefs[key]; e.currentTarget.setAttribute('aria-pressed', String(prefs[key])); applyPrefs(); } }, label);
  const size = (d) => () => { prefs.fs = Math.min(1.5, Math.max(0.85, (prefs.fs || 1) + d)); applyPrefs(); };
  return h('header', { class: 'top' },
    h('div', { class: 'brand' }, h('i', { 'aria-hidden': 'true' }), 'ABLET'),
    h('span', { class: 'small' }, S.user.role === 'student' ? 'Supporto allo studio' : 'Area del professionista'),
    h('span', { class: 'sp' }),
    h('div', { class: 'row', role: 'group', 'aria-label': 'Accessibilità' },
      h('button', { onclick: size(-0.1), 'aria-label': 'Riduci il carattere' }, 'A−'), h('button', { onclick: size(0.1), 'aria-label': 'Ingrandisci il carattere' }, 'A+'),
      tg('contrast', 'Contrasto', 'Contrasto elevato'), tg('calm', 'Meno animazioni', 'Riduci le animazioni')),
    h('a', { href: `${BASE}/refresh.php`, class: 'small' }, 'Aggiorna app e svuota cache'),
    h('span', { class: 'small' }, S.user.alias),
    h('button', { onclick: guard(() => logout()) }, 'Esci'));
}

function login() {
  const portal = new URLSearchParams(location.search).get('accesso');
  const role = portal === 'studente' ? 'student' : portal === 'docente' ? 'psychologist' : null;
  const studentLink = `${BASE}/?accesso=studente`, teacherLink = `${BASE}/?accesso=docente`;
  if (!role) {
    clear(root).append(h('main', { class: 'login', id: 'main' }, h('section', { class: 'card' },
      h('h1', {}, 'Benvenuto in ABLET'), h('p', {}, 'Scegli il tuo accesso.'),
      h('div', { class: 'access-links' },
        h('a', { class: 'access-card', href: studentLink }, h('strong', {}, 'Accedi come studente'), h('span', {}, 'Organizza il tuo studio e riprendi le attività.')),
        h('a', { class: 'access-card', href: teacherLink }, h('strong', {}, 'Accedi come docente / psicologo'), h('span', {}, 'Configura i protocolli e segui gli studenti.'))),
      h('p', { class: 'small muted' }, 'Usa le credenziali già assegnate al tuo account.'))));
    return;
  }
  const err = h('p', { class: 'small', role: 'alert' });
  const f = h('form', { class: 'card', onsubmit: async (e) => {
    e.preventDefault(); err.textContent = '';
    try { const r = await api('POST', '/api/login', { login: f.login.value, password: f.password.value, expected_role: role }); S.csrf = r.csrf; S.user = r.user; boot(); } catch (x) { err.textContent = errText(x); }
  } },
    h('div', { class: 'brand' }, h('i', { 'aria-hidden': 'true' })), h('h1', {}, role === 'student' ? 'Accesso studente' : 'Accesso docente / psicologo'),
    h('p', { class: 'muted' }, 'Uno spazio per organizzare lo studio, un passo alla volta.'),
    h('label', { for: 'login' }, 'Codice di accesso'), h('input', { id: 'login', name: 'login', autocomplete: 'username', required: true, autocapitalize: 'none' }),
    h('label', { for: 'password' }, 'Password'), h('input', { id: 'password', name: 'password', type: 'password', autocomplete: 'current-password', required: true }),
    err, h('p', {}, h('a', { href: `${BASE}/` }, '← Scegli un altro accesso')), h('div', { class: 'row' }, h('button', { class: 'primary', type: 'submit' }, 'Accedi')),
    h('p', { class: 'small muted' }, 'ABLET usa un sistema di intelligenza artificiale di supporto allo studio. Non è una persona e non sostituisce insegnanti o psicologi.'));
  clear(root).append(h('main', { class: 'login', id: 'main' }, f));
}

export async function logout() { dispatchEvent(new Event('ablet:voice-stop')); const r = await api('POST', '/api/logout'); S.csrf = r.csrf; S.user = null; boot(); }

export async function boot() {
  if(!root)return; // Le pagine diagnostiche importano gli strumenti senza avviare l’app.
  if (!S.csrf || !S.user) { const me = await api('GET', '/api/me'); S.csrf = me.csrf; S.user = me.user; }
  if (!S.user) return login();
  const main = h('main', { id: 'main', class: 'wrap', tabindex: '-1' });
  // L'interfaccia studente (tablet, voce prima) ha una propria barra essenziale; quella del professionista resta invariata.
  if (S.user.role === 'student') main.className = 'kidroot';
  clear(root).append(S.user.role === 'student' ? null : topbar(), main);
  const mod = await import(S.user.role === 'student' ? './student.js' : './psy.js');
  mod.mount(main);
}
// Installazione come app: l'evento viene conservato e offerto solo nella home dello studente.
export const pwa = { prompt: null };
addEventListener('beforeinstallprompt', (e) => { e.preventDefault(); pwa.prompt = e; dispatchEvent(new Event('ablet:installabile')); });
addEventListener('appinstalled', () => { pwa.prompt = null; dispatchEvent(new Event('ablet:installabile')); });
// App installabile: il service worker serve solo i file statici (mai /api/). Richiede HTTPS (o localhost).
if (root && 'serviceWorker' in navigator && window.isSecureContext) {
  addEventListener('load', () => navigator.serviceWorker.register(`${BASE}/sw.js`, { scope: `${BASE}/`, updateViaCache: 'none' }).catch(() => {}));
}
boot().catch(() => { clear(root).append(h('p', { class: 'pad' }, 'Il servizio non è raggiungibile. Riprova fra poco.')); });
