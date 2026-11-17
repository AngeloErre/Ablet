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
  profilo_non_valido: 'Controlla nome, professione e lunghezza dei campi.', email_non_valida:'Inserisci un indirizzo e-mail valido.', password_non_valida:'Usa almeno 12 caratteri e una password non eccessivamente lunga.', account_gia_presente:'Questo codice di accesso è già utilizzato. Accedi con il tuo account.', registrazione_disabilitata:'La registrazione non è attiva in questa installazione.', conferma_informazioni_account:'Conferma di aver letto le informazioni sull’account.',
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
  if (new URLSearchParams(location.search).get('registrazione') === 'professionista') { registration(); return; }
  if (!role) { publicHome(); return; }
  const err = h('p', { class: 'small', role: 'alert' });
  const f = h('form', { class: 'card', onsubmit: async (e) => {
    e.preventDefault(); err.textContent = '';
    try { const r = await api('POST', '/api/login', { login: f.login.value, password: f.password.value, expected_role: role }); S.csrf = r.csrf; S.user = r.user; boot(); } catch (x) { err.textContent = errText(x); }
  } },
    h('div', { class: 'brand' }, h('i', { 'aria-hidden': 'true' })), h('h1', {}, role === 'student' ? 'Accesso studente' : 'Accesso docente / psicologo'),
    h('p', { class: 'muted' }, 'Uno spazio per organizzare lo studio, un passo alla volta.'),
    h('label', { for: 'login' }, role === 'student' ? 'Codice di accesso' : 'Codice di accesso o e-mail'), h('input', { id: 'login', name: 'login', autocomplete: 'username', required: true, autocapitalize: 'none' }),
    h('label', { for: 'password' }, 'Password'), h('input', { id: 'password', name: 'password', type: 'password', autocomplete: 'current-password', required: true }),
    err, role === 'psychologist' ? h('p', {}, h('a', {href:`${BASE}/?registrazione=professionista`},'Nuovo account? Crea il tuo profilo')) : null, h('p', {}, h('a', { href: `${BASE}/` }, '← Scegli un altro accesso')), h('div', { class: 'row' }, h('button', { class: 'primary', type: 'submit' }, 'Accedi')),
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

function publicHome() {
  const student=`${BASE}/?accesso=studente`,professional=`${BASE}/?accesso=docente`,register=`${BASE}/?registrazione=professionista`;
  const link=(label,href,style='')=>h('a',{href,class:'site-button '+style},label);
  const card=(n,title,text)=>h('article',{class:'site-card'},h('span',{class:'site-number'},n),h('h3',{},title),h('p',{},text));
  const section=(id,eyebrow,title,intro,...children)=>h('section',{id,class:'site-section'},h('div',{class:'site-section-head'},h('p',{class:'site-eyebrow'},eyebrow),h('h2',{},title),h('p',{},intro)),...children);
  const phasePreview=h('div',{class:'site-preview', 'aria-label':'Anteprima illustrativa di una sessione di studio'},
    h('div',{class:'site-preview-top'},h('strong',{},'ABLET'),h('span',{},'Prepariamo il piano')),
    h('div',{class:'site-face','aria-hidden':'true'},'◕‿◕'),h('p',{class:'site-bubble'},'Qual è il primo piccolo passo che puoi fare da solo?'),
    h('div',{class:'site-mini-plan'},h('span',{},'IL MIO OBIETTIVO'),h('strong',{},'Capire un testo e raccontarlo con parole mie'),h('div',{class:'site-step'},'01  Leggo un paragrafo'),h('div',{class:'site-step'},'02  Individuo le idee principali')),
    h('div',{class:'site-preview-controls'},h('span',{},'Pausa'),h('span',{class:'site-mic'},'●  Parla'),h('span',{},'Riascolta'))),phaseFigure=h('figure',{},phasePreview,h('figcaption',{},'Anteprima illustrativa con contenuti sintetici.'));
  clear(root).append(h('div',{class:'public-site'},
    h('header',{class:'site-header'},h('a',{class:'site-brand',href:`${BASE}/`},h('span',{'aria-hidden':'true'},'a'), 'ABLET'),
      h('nav',{'aria-label':'Scopri ABLET',class:'site-nav'},h('a',{href:'#approccio'},'L’approccio'),h('a',{href:'#strumenti'},'Come funziona'),h('a',{href:'#dashboard'},'Dashboard e dati'),h('a',{href:'#domande'},'Domande')),
      h('div',{class:'site-access'},link('Accedi come studente',student),link('Accedi come educatore / psicologo',professional,'outline'))),
    h('main',{id:'main'},h('section',{class:'site-hero'},h('div',{},h('p',{class:'site-eyebrow'},'TECNOLOGIE PER L’APPRENDIMENTO · AUTOREGOLAZIONE'),h('h1',{},'Imparare a guidare',h('br'),h('em',{},'il proprio studio.')),
      h('p',{class:'site-lead'},'Un agente pedagogico che accompagna lo studente a pianificare, monitorare e ripensare il proprio modo di imparare. Con la regia di educatori e psicologi.'),
      h('div',{class:'site-actions'},link('Crea il tuo profilo professionale',register,'solid'),link('Esplora l’approccio','#approccio','outline')),
      h('p',{class:'site-footnote'},'Dialogo a voce o per iscritto. Attività libere e percorsi assegnati. Un passo alla volta.')),phaseFigure),
    h('div',{class:'site-principles'},h('span',{},'Una domanda che orienta'),h('span',{},'Un obiettivo comprensibile'),h('span',{},'Una strategia da sperimentare'),h('span',{},'Una riflessione per ripartire')),
    section('approccio','IL FONDAMENTO PSICOLOGICO','Dallo svolgere un compito al comprendere come si impara.',
      'ABLET è progettato attorno al Self-Regulated Learning: un processo ciclico in cui lo studente partecipa attivamente alla definizione degli obiettivi, al controllo del lavoro e alla valutazione delle proprie strategie.',
      h('div',{class:'site-grid three'},card('01','Previsione e pianificazione','Che cosa devo fare? Quale risultato voglio raggiungere? Lo studente analizza il compito, sceglie una strategia, stima i tempi e riflette sulla fiducia nelle proprie capacità.'),card('02','Prestazione e monitoraggio','La strategia sta funzionando? Durante il lavoro l’agente sollecita controlli di comprensione, attenzione al passo corrente e riconoscimento degli ostacoli, senza sostituirsi allo studente.'),card('03','Auto-riflessione','Che cosa ha funzionato e che cosa cambierei? Il confronto fra obiettivi e risultati aiuta a ragionare sulle strategie e sulle emozioni, e a preparare il tentativo successivo.')),
      h('div',{class:'site-callout'},h('strong',{},'La guida si progetta. L’autonomia si esercita.'),h('p',{},'Il professionista definisce il protocollo, le domande e il livello di supporto. L’agente è istruito a offrire indizi e domande di processo, evitando di consegnare direttamente la soluzione. Le risposte generate richiedono comunque supervisione.'))),
    section('educazione','IL VALORE EDUCATIVO','Dare struttura, lasciando spazio alla scelta.',
      'Il compito diventa un’occasione per esplicitare un piano e osservare il proprio modo di procedere. I micro-obiettivi rendono visibili i passi; il dialogo invita a motivare le scelte e a rivederle.',
      h('div',{class:'site-grid two'},card('→','Per educatori e insegnanti','Prepara attività e materiali, assegna sessioni singole o percorsi ordinati, accompagna la gestione del tempo e confronta ciò che lo studente aveva previsto con ciò che ha registrato.'),card('→','Per psicologi','Configura protocolli fondati sulle tre fasi SRL, descrivi esigenze funzionali e livelli di supporto, rileggi le interazioni e usa gli indicatori come elementi per la tua analisi professionale.')),
      h('p',{class:'site-footnote'},'Il progetto può essere adattato a differenti esigenze di apprendimento. La compatibilità con uno specifico bisogno educativo e gli effetti sull’apprendimento devono essere valutati nel contesto d’uso: non sono garantiti dalla piattaforma.')),
    section('strumenti','DENTRO ABLET','Due spazi, un percorso condiviso.',
      'Un’interfaccia essenziale per chi studia e un ambiente di progettazione e osservazione per chi lo accompagna.',
      h('div',{class:'site-grid three'},card('A','Lo spazio dello studente','Avatar e interazione vocale, testo sempre disponibile, scelta fra attività libera e programma assegnato. La sequenza viene mostrata quando lo studente la richiede.'),card('B','Lo spazio del professionista','Studenti, attività, protocolli personalizzabili e modelli di base protetti. Ogni sessione mantiene il riferimento alla versione del protocollo assegnata.'),card('C','Osservare il processo','Tempi dichiarati e registrati, micro-obiettivi, richieste d’aiuto, strategie e conversazioni. Indicatori descrittivi da interpretare, senza trasformarli in un voto globale di autoregolazione.')),
      h('div',{class:'site-product-band'},h('div',{},h('p',{class:'site-eyebrow'},'PROGETTARE → ACCOMPAGNARE → RILEGGERE'),h('h3',{},'La tecnologia rende visibile il percorso.'),h('p',{},'Puoi partire dai modelli SRL, creare un’attività e programmare le sessioni dalla scheda dello studente. Poi osservi come si sviluppa il dialogo e adatti il tuo intervento.')),h('div',{class:'site-demo-metrics'},h('span',{},'ESEMPIO ILLUSTRATIVO'),h('strong',{},'Piano · Strategie · Riflessione'),h('p',{},'Tempo previsto 20 min'),h('div',{class:'site-demo-bar'}),h('p',{},'Micro-obiettivi 2 su 3 · dati sintetici')))),
    section('dashboard','LA DASHBOARD DEL PROFESSIONISTA','Dai dati del percorso alle domande educative.',
      'La panoramica aiuta psicologi ed educatori a rileggere le sessioni. Puoi filtrare per studente, attività e periodo, quindi aprire una sessione per ricostruire piano, strategie, richieste d’aiuto e dialogo.',
      h('div',{class:'site-grid three'},card('01','Tempi e micro-obiettivi','Confronta il tempo previsto dallo studente con quello registrato nell’app e osserva quanti passi sono stati completati. Il tempo registrato non descrive tutto lo studio svolto su carta o fuori dalla piattaforma.'),card('02','Supporto e strategie','Rileggi indizi richiesti, difficoltà segnalate e adattamenti del supporto. Chiediti quando lo studente cerca aiuto, quali strategie dichiara e che cosa decide di modificare.'),card('03','Previsioni e valutazioni','Quando ci sono dati confrontabili, la calibrazione mette in relazione sicurezza dichiarata e prestazione valutata. Se mancano i dati necessari, il risultato viene indicato come non calcolabile.')),
      h('figure',{class:'site-dashboard-figure'},h('iframe',{src:`${BASE}/assets/demo/dashboard.html`,title:'Anteprima della dashboard ABLET con dati sintetici',loading:'lazy',sandbox:'',class:'site-dashboard-preview'}),h('figcaption',{},'Anteprima della dashboard prodotta dal renderer di ABLET con tre sessioni simulate. Non è uno screenshot di studenti reali. Scorri nel riquadro per esplorare i grafici.')),
      h('div',{class:'site-callout'},h('strong',{},'Un dato suggerisce una domanda, non una diagnosi.'),h('p',{},'Una durata lunga non prova disattenzione, una risposta rapida non prova comprensione, un miglioramento fra sessioni non dimostra da solo efficacia o trasferimento dell’apprendimento. Gli indicatori vanno letti insieme al compito, al contesto e al giudizio professionale.'))),
    section('analisi-dati','ANALISI DEI DATI E DELLE INTERAZIONI','Rileggere il processo, senza ridurlo a un punteggio.',
      'Il dettaglio della sessione affianca tempi, obiettivi, autovalutazioni, valutazioni del professionista, adattamenti e conversazione. Le informazioni osservate, dichiarate dallo studente e valutate dal professionista hanno origini differenti.',
      h('div',{class:'site-grid two'},card('A','Conversazione e codifiche','La conversazione conserva le parole dello studente e i messaggi dell’agente. Le codifiche lessicali o proposte dal modello possono evidenziare segmenti legati al processo SRL: sono proposte da rivedere, non interpretazioni psicologiche definitive.'),card('B','Lettura critica degli indicatori','La polarità lessicale descrive le parole usate, non lo stato emotivo. Le latenze di risposta non misurano attenzione o impegno. I raggruppamenti esplorativi non identificano tipologie diagnostiche di studenti.')),
      h('figure',{class:'site-dashboard-figure'},h('iframe',{src:`${BASE}/assets/demo/sessione.html#conversazione`,title:'Anteprima del dettaglio sessione e analisi testuale con dati sintetici',loading:'lazy',sandbox:'',class:'site-dashboard-preview'}),h('figcaption',{},'Anteprima del dettaglio sessione: dialogo sintetico e strumenti di revisione delle codifiche. Il codebook iniziale richiede validazione; le analisi automatiche richiedono controllo umano.')),
      h('div',{class:'site-grid three'},card('1','Filtra e confronta','Parti da una domanda concreta: la stima dei tempi diventa più aderente? I micro-obiettivi sono sostenibili? In quali momenti viene richiesto un indizio?'),card('2','Apri le evidenze','Rileggi i messaggi e i passaggi del piano che accompagnano l’indicatore. Verifica i dati mancanti e rivedi le annotazioni proposte.'),card('3','Progetta il passo successivo','Usa quanto osservato per scegliere con lo studente una strategia da provare o una modifica al supporto. Gli export permettono ulteriori analisi; non rappresentano una validazione automatica.'))),
    section('inizia','INIZIA CON ABLET','Dal tuo profilo alla prima sessione.',
      'La registrazione è riservata a educatori e psicologi. Gli studenti accedono con le credenziali predisposte dal professionista.',
      h('div',{class:'site-grid three'},card('1','Crea il profilo','Indica il tuo nome, la professione e, se vuoi, l’organizzazione e il tuo ambito di lavoro. Il profilo rimane privato.'),card('2','Prepara lo spazio','Configura un provider oppure esplora la modalità dimostrativa. Scegli un modello di protocollo e personalizza attività e supporti.'),card('3','Accompagna lo studente','Crea l’accesso dello studente, registra le autorizzazioni necessarie e programma un’attività o una sequenza di sessioni.')),
      h('div',{class:'site-actions'},link('Registrati come professionista',register,'solid'),link('Leggi il manuale',`${BASE}/manual.php`,'outline'))),
    section('domande','DOMANDE FREQUENTI','Prima di iniziare.', 'Le funzioni e i loro limiti, con chiarezza.',
      ...[
        ['ABLET fornisce le risposte al compito?','L’obiettivo progettuale è guidare lo studente con domande, indizi e controlli di processo. I modelli generativi possono commettere errori: il professionista configura e supervisiona il percorso.'],
        ['È un trattamento o uno strumento diagnostico?','ABLET è una piattaforma di supporto educativo. Non sostituisce valutazioni, insegnamento o interventi professionali. Il funzionamento tecnico non dimostra efficacia clinica o educativa.'],
        ['Voce e avatar funzionano su ogni dispositivo?','La disponibilità dipende da browser, autorizzazioni e motore vocale configurato. Sono presenti comandi testuali alternativi e strumenti di diagnostica; il browser può richiedere un tocco per avviare l’audio.'],
        ['Quali dati vengono registrati?','L’app registra testo dei dialoghi, azioni, tempi e valutazioni inserite. ABLET non archivia permanentemente l’audio; l’eventuale elaborazione vocale esterna dipende dal motore scelto e dalle autorizzazioni.'],
        ['La registrazione include un servizio AI gratuito?','L’account non include credito dei provider. Il professionista configura il servizio e verifica disponibilità, tariffe e quote. La modalità dimostrativa utilizza risposte simulate.'],
        ['I profili professionali sono pubblici o verificati?','No. Il profilo è privato e la professione è autodichiarata. La registrazione non verifica un’abilitazione professionale e non attribuisce certificazioni.']
      ].map(([q,a])=>h('details',{class:'site-faq'},h('summary',{},q),h('p',{},a)))),
    section('account-data','INFORMAZIONI SULL’ACCOUNT','Uno spazio professionale, con dati separati.',
      'Per creare l’account vengono salvati nome, e-mail usata come codice di accesso, password in forma di hash, professione dichiarata e gli eventuali dati di presentazione. Il profilo non è un elenco pubblico e non è inviato al modello linguistico per il dialogo dello studente.',
      h('p',{},'L’e-mail non viene verificata e non viene inviata una conferma automatica: conserva le credenziali. Gli account studente vengono creati dal professionista. Per il trattamento dei dati degli studenti il professionista deve configurare le autorizzazioni e le condizioni applicabili alla propria attività.'),
      h('details',{},h('summary',{},'Riferimenti del modello educativo'),h('p',{},'Zimmerman, B. J. (2002). Becoming a Self-Regulated Learner: An Overview. Theory Into Practice, 41(2), 64–70. Il modello orienta il progetto; la sua citazione non costituisce una validazione di ABLET.'),h('a',{href:'https://doi.org/10.1207/s15430421tip4102_2',target:'_blank',rel:'noopener'},'Leggi il riferimento scientifico')))),
    h('footer',{class:'site-footer'},h('div',{},h('strong',{},'ABLET'),h('p',{},'Adaptive Based Learning Enhancer Tool'),h('p',{},'Progetto scientifico: Prof. Angelo Rega.')),
      h('div',{},h('a',{href:student},'Accesso studente'),h('a',{href:professional},'Accesso educatore / psicologo'),h('a',{href:register},'Crea profilo'),h('a',{href:'#account-data'},'Informazioni sull’account')))));
  if(location.hash && /^#[a-z-]+$/.test(location.hash)) document.getElementById(location.hash.slice(1))?.scrollIntoView?.();
}
function registration() {
  const status=h('p',{role:'alert','aria-live':'polite'}),submit=h('button',{type:'submit',class:'primary'},'Crea il mio profilo');
  const name=h('input',{required:true,maxlength:80,autocomplete:'name'}),email=h('input',{type:'email',required:true,maxlength:190,autocomplete:'username',autocapitalize:'none'});
  const profession=h('select',{},h('option',{value:'educator'},'Educatore / educatrice'),h('option',{value:'psychologist'},'Psicologo / psicologa'));
  const org=h('input',{maxlength:160,autocomplete:'organization'}),bio=h('textarea',{maxlength:2000}),password=h('input',{type:'password',required:true,minlength:12,maxlength:72,autocomplete:'new-password'}),repeat=h('input',{type:'password',required:true,minlength:12,maxlength:72,autocomplete:'new-password'});
  const accept=h('input',{type:'checkbox',required:true}),trap=h('input',{name:'website',tabindex:'-1',autocomplete:'off'});
  const field=(label,input)=>h('label',{},label,input);
  const form=h('form',{class:'card signup-card',onsubmit:async e=>{e.preventDefault();status.textContent='';if(password.value!==repeat.value){status.textContent='Le password non coincidono.';return;}submit.disabled=true;
    try{const r=await api('POST','/api/register',{full_name:name.value,email:email.value,profession:profession.value,organization:org.value,biography:bio.value,password:password.value,accepted_notice:accept.checked,website:trap.value});S.csrf=r.csrf;S.user=r.user;boot();}catch(x){status.textContent=errText(x);}finally{submit.disabled=false;}}},
    h('p',{class:'site-eyebrow'},'IL TUO SPAZIO DI LAVORO'),h('h1',{},'Crea il profilo professionale'),h('p',{},'Per educatori e psicologi. Gli studenti ricevono le credenziali dal professionista.'),
    h('div',{class:'grid g2'},field('Nome e cognome',name),field('Professione dichiarata',profession)),field('E-mail · sarà il tuo codice di accesso',email),field('Organizzazione (facoltativa)',org),field('Presentazione professionale (facoltativa)',bio),
    h('div',{class:'grid g2'},field('Password · almeno 12 caratteri',password),field('Conferma password',repeat)),h('div',{class:'signup-trap','aria-hidden':'true'},field('Lascia vuoto',trap)),
    h('label',{class:'inline'},accept,h('span',{},'Ho letto le ',h('a',{href:`${BASE}/#account-data`,target:'_blank',rel:'noopener'},'informazioni sull’account'),'. Comprendo che il profilo è privato e la professione autodichiarata.')),
    h('p',{class:'small muted'},'Non viene inviata un’e-mail di conferma. Conserva le credenziali. Nessun credito AI è incluso nella registrazione.'),status,submit,
    h('p',{},h('a',{href:`${BASE}/?accesso=docente`},'Ho già un account → Accedi')),h('p',{},h('a',{href:`${BASE}/`},'← Torna alla homepage')));
  clear(root).append(h('main',{id:'main',class:'signup-page'},form));
}
