// ABLET — area del professionista: panoramica e analisi, studenti, configuratore dei protocolli, attività, provider, voce e avatar.
import { S, boot, BASE, api, h, clear, toast, guard, errText } from './app.js';
import { barChart, heatmap, downloadJson, downloadText } from './charts.js';
import { createVoice, avatar, voiceNames, voiceSupport, VOICE_NOTICE } from './voice.js';

let main, view, B = null, tab = 'dash', voiceCleanup = () => {};
let dirty = false, navigationCleanup = () => {};
const leaveEditor = () => !dirty || confirm('Ci sono modifiche non salvate. Uscire senza salvarle?');
addEventListener('beforeunload', e => { if (dirty) { e.preventDefault(); e.returnValue = ''; } });
const TABS = [['dash','Panoramica'],['tokens','Consumi token'],['students','Studenti'],['protocols','Protocolli'],['activities','Attività'],['manual','Manuale e tutorial']];
const SETTINGS = [['account','Il mio profilo'],['providers','Assistente IA'],['voice','Voce e avatar'],['coding','Validazione codifiche'],['privacy','Dati e autorizzazioni']];
const STATE = { created: 'Non avviata', forethought: 'Pianificazione', performance: 'Lavoro', self_reflection: 'Riflessione', paused: 'In pausa', safety_hold: 'Sospensione di sicurezza', closed_complete: 'Conclusa', closed_incomplete: 'Chiusa incompleta' };
const LEVELS = ['0 · autonomo con checkpoint essenziali', '1 · supporto su richiesta', '2 · guida condivisa', '3 · guida strutturata'];
const nd = (v, u = '') => v == null ? 'non calcolabile' : `${v}${u}`;
const field = (label, input, help) => { const id = 'f' + Math.random().toString(36).slice(2, 9); input.id = id; return h('div', {}, h('label', { for: id }, label), input, help ? h('div', { class: 'small muted' }, help) : null); };
const sel = (opts, value, attrs = {}) => h('select', attrs, opts.map(([v, t]) => h('option', { value: v, selected: String(v) === String(value ?? '') }, t)));
const reload = async () => { B = await api('GET', '/api/psy/bootstrap'); };
const alias = (id) => { const s = B.students.find(x => x.id === id); return s ? (s.display_alias || s.pseudonym) : 'studente sintetico'; };

export async function mount(el) {
  voiceCleanup(); voiceCleanup = () => {}; navigationCleanup();
  main = el;
  await reload();
  dirty = false;
  view = h('div', {class:'application-content', id:'professional-content', tabindex:'-1'});
  const bar = h('div', { class: 'tabs', 'aria-label': 'Sezioni principali' }, TABS.map(([k, t]) => h('button', { 'aria-current': k === tab ? 'page' : null, onclick: () => { if (!leaveEditor()) return; tab = k; mount(main); } }, t)));
  const settings = h('details', {class:'application-settings', open:SETTINGS.some(([key])=>key===tab)},
    h('summary',{},'☰ Impostazioni applicazione'),
    h('nav',{'aria-label':'Impostazioni applicazione'},SETTINGS.map(([key,label])=>h('button',{type:'button','aria-current':tab===key?'page':null,onclick:()=>{if(!leaveEditor())return;tab=key;mount(main);}},label))));
  const menu = h('details',{class:'application-navigation',open:true},h('summary', {class:'navigation-toggle'}, '☰ Menu · ' + ([...TABS,...SETTINGS].find(([key])=>key===tab)?.[1] || 'ABLET')),bar,settings);
  // Su dispositivi piccoli il menu parte chiuso; il contenuto resta raggiungibile senza overlay.
  if (typeof matchMedia === 'function') { const narrow=matchMedia('(max-width: 63.99rem)'); menu.open=!narrow.matches; const adapt=()=>{menu.open=!narrow.matches;}; narrow.addEventListener?.('change',adapt); navigationCleanup=()=>narrow.removeEventListener?.('change',adapt); }
  clear(main).append(h('div',{class:'professional-layout'},menu, view));
  await ({ dash, tokens, students, protocols, activities, providers, voice, coding, privacy, manual, account })[tab]().catch(e => toast(errText(e)));
}

// ───────────────────────── monitoraggio token
async function tokens(q = {}) {
  const d = await api('GET', '/api/psy/tokens?' + new URLSearchParams(q));
  const count = v => Number(v || 0).toLocaleString('it-IT');
  const sum = x => Number(x.tokens_in || 0) + Number(x.tokens_out || 0);
  const names = {gemini:'Google Gemini',openai:'OpenAI',anthropic:'Claude',deepseek:'DeepSeek',openai_compatible:'OpenAI compatibile',demo:'Demo'};
  const card = (title,x) => h('div',{class:'card'},h('div',{class:'small muted'},title),h('div',{class:'kpi'},count(sum(x)),h('small',{},'token in + out registrati')),h('p',{class:'small'},`${count(x.tokens_in)} ingresso · ${count(x.tokens_out)} uscita · ${count(x.calls)} chiamate · ${count(x.unknown_usage)} con dati incompleti`));
  const form = h('form',{class:'card row',onsubmit:guard(async e=>{e.preventDefault();const f=e.target;await tokens({provider:f.provider.value,from:f.from.value,to:f.to.value});})},
    field('Provider',sel(Object.entries(names),d.provider,{name:'provider'})),field('Dal (UTC)',h('input',{type:'date',name:'from',value:d.from,required:true})),field('Al (UTC)',h('input',{type:'date',name:'to',value:d.to,required:true})),h('button',{class:'primary',type:'submit'},'Aggiorna'));
  const table=(headers,rows)=>h('div',{class:'scroll'},h('table',{},h('thead',{},h('tr',{},headers.map(x=>h('th',{},x)))),h('tbody',{},rows)));
  clear(view).append(h('h1',{},'Consumi token'),h('p',{class:'small muted'},`Predefinito: ${d.default.label} · ${names[d.default.provider_key]} · ${d.default.model_name || 'modello demo'}. Dati del tuo spazio professionale, aggregati per tipo di provider, comprese configurazioni e modelli precedenti.`),form,
    h('div',{class:'grid g3'},card('Oggi · UTC',d.today),card('Mese corrente · UTC',d.month),card('Totale registrato',d.lifetime)),
    h('p',{class:'banner'},'Sono consumi registrati da ABLET, non il saldo del conto API né la quota residua del fornitore. Ingresso + uscita può escludere token aggiuntivi, per esempio di ragionamento o cache. Chiamate fallite, consumi non restituiti, tentativi HTTP interni e utilizzi esterni ad ABLET possono rendere il totale incompleto. Demo: consumo simulato, senza addebiti.'),
    d.local_budget ? h('p',{class:'small'},`Tetto mensile locale della configurazione predefinita (${d.default.label}): ${count(d.local_budget.used)} / ${count(d.local_budget.limit)} token · margine ${count(d.local_budget.remaining)}. Si riferisce solo a questa configurazione, indipendentemente dal filtro; non è la quota del provider.`) : null,
    h('h2',{},`Periodo ${d.from} – ${d.to}`),card('Consumo nel periodo',d.period),
    h('p',{class:'small muted'},`Chiamate riuscite: ${count(d.period.successful)} · rinvii per attesa: ${count(d.period.cooldowns)} · chiamate di fallback: ${count(d.period.fallbacks)}. Prove, simulazioni e altre chiamate senza sessione: ${count(d.outside_sessions.calls)}, ${count(sum(d.outside_sessions))} token in + out (già inclusi nel totale).`),
    d.days.length ? barChart({title:'Consumo giornaliero nel periodo (UTC)',labels:d.days.map(x=>x.day),series:[{name:'Ingresso',values:d.days.map(x=>+x.tokens_in)},{name:'Uscita',values:d.days.map(x=>+x.tokens_out)}],unit:'token'}) : h('p',{},'Nessuna chiamata registrata nel periodo per questo provider.'),
    h('h2',{},'Ripartizione per modello'),table(['Modello','Chiamate','Ingresso','Uscita','Dati incompleti'],d.models.map(x=>h('tr',{},...[x.model_name || 'Demo / non disponibile',count(x.calls),count(x.tokens_in),count(x.tokens_out),count(x.unknown_usage)].map(v=>h('td',{},v))))),
    h('h2',{},'Storico delle sessioni'),h('p',{class:'small muted'},'I valori si riferiscono alle chiamate del provider e del periodo selezionati, anche per sessioni ancora aperte. Apri una sessione per vedere le chiamate dei suoi diversi provider. Le cancellazioni previste dalla conservazione dei dati rimuovono anche il relativo storico.'),
    table(['Sessione','Studente','Attività','Modelli','Ultima chiamata UTC','Chiamate','Ingresso','Uscita','Dati incompleti'],d.sessions.map(x=>h('tr',{},h('td',{},h('button',{onclick:guard(()=>session(+x.session_id))},`#${x.session_id}`)),...[alias(+x.student_id),x.activity,x.models || 'Demo / non disponibile',x.last_call,count(x.calls),count(x.tokens_in),count(x.tokens_out),count(x.unknown_usage)].map(v=>h('td',{},v))))),
    !d.sessions.length ? h('p',{},'Nessuna sessione con chiamate nel periodo selezionato.') : null,
    h('div',{class:'row'},h('button',{disabled:d.page<=1,onclick:guard(()=>tokens({...q,provider:d.provider,from:d.from,to:d.to,page:d.page-1}))},'← Precedenti'),h('span',{},`Pagina ${d.page} · ${count(d.session_count)} sessioni`),h('button',{disabled:d.page*25>=d.session_count,onclick:guard(()=>tokens({...q,provider:d.provider,from:d.from,to:d.to,page:d.page+1}))},'Successive →'),h('button',{onclick:()=>downloadJson(`ablet-token-${d.provider}-${d.from}-${d.to}.json`,d)},'Esporta pagina JSON')),
    h('p',{class:'small muted'},`Aggiornato: ${d.generated_at}. Premi Aggiorna per rileggere i dati. Intervallo massimo: 366 giorni.`));
}

// ───────────────────────── panoramica
async function dash(q = {}) {
  const qs = new URLSearchParams(Object.entries(q).filter(([, v]) => v)).toString();
  const d = await api('GET', '/api/psy/dashboard' + (qs ? '?' + qs : ''));
  const a = d.aggregate, ses = d.sessions, lab = ses.map(s => `#${s.session.id} ${alias(s.session.student_id)}`);
  const f = h('form', { class: 'card row', onsubmit: (e) => { e.preventDefault(); const x = e.target; dash({ student_id: x.st.value, activity_id: x.ac.value, from: x.from.value, to: x.to.value, include_sim: x.sim.checked ? 1 : '', k: x.k.value }); } },
    field('Studente', sel([['', 'Tutti'], ...B.students.map(s => [s.id, s.display_alias || s.pseudonym])], q.student_id, { name: 'st' })),
    field('Attività', sel([['', 'Tutte'], ...B.activities.map(x => [x.id, x.title])], q.activity_id, { name: 'ac' })),
    field('Dal', h('input', { type: 'date', name: 'from', value: q.from || '' })), field('Al', h('input', { type: 'date', name: 'to', value: q.to || '' })),
    field('Gruppi (k)', sel([[2, '2'], [3, '3'], [4, '4']], q.k || 3, { name: 'k' })),
    h('label', { class: 'inline' }, h('input', { type: 'checkbox', name: 'sim', checked: !!q.include_sim }), 'Includi simulazioni'), h('button', { class: 'primary', type: 'submit' }, 'Aggiorna'));
  const kpi = (t, v, sub) => h('div', { class: 'card' }, h('div', { class: 'small muted' }, t), h('div', { class: 'kpi' }, v, sub ? h('small', {}, ' ' + sub) : null));
  const cl = d.clusters, ids = Object.keys(cl.profiles || {});
  const order = ids.slice().sort((x, y) => (cl.assignment[x] ?? 0) - (cl.assignment[y] ?? 0));
  clear(view).append(providerSetupNotice(),f,
    h('div', { class: 'grid g3' }, kpi('Sessioni avviate', a.started), kpi('Sessioni concluse', a.completion_pct == null ? '—' : a.completion_pct + '%', `${a.completed} su ${a.started}; ${a.open} aperte`),
      kpi('Micro-obiettivi completati', a.goals_pct == null ? '—' : a.goals_pct + '%', `${a.goals_completed} su ${a.goals_agreed}`), kpi('Richieste di indizio', a.hints), kpi('Solleciti dell\'agente', a.agent_prompts),
      kpi('Sospensioni di sicurezza', a.safety, a.safety ? 'da rivedere' : '')),
    h('p', { class: 'small muted' }, 'Indicatori descrittivi di processo: nessuno misura direttamente attenzione o apprendimento. Non è previsto un punteggio globale di autoregolazione. Le sospensioni di sicurezza sono visibili qui al tuo accesso: non esiste un monitoraggio in tempo reale.'),
    h('div', { class: 'grid gc' },
      barChart({ title: 'Micro-obiettivi completati per sessione', labels: lab, series: [{ name: '% completati', values: ses.map(s => s.goals.pct) }], unit: '%', max: 100 }),
      barChart({ title: 'Tempo previsto e tempo attivo registrato', labels: lab, series: [{ name: 'previsto (dichiarato)', values: ses.map(s => s.time.estimated_min) }, { name: 'attivo nell\'app (osservato)', values: ses.map(s => s.time.active_min) }], unit: 'min' }),
      barChart({ title: 'Richieste di indizio e solleciti dell\'agente', labels: lab, series: [{ name: 'indizi richiesti', values: ses.map(s => s.help.hint_requests) }, { name: 'solleciti dell\'agente', values: ses.map(s => s.agent_prompts) }], unit: 'n' }),
      barChart({ title: 'Latenza di avvio', labels: lab, series: [{ name: 'secondi fra creazione e avvio', values: ses.map(s => s.time.start_latency_s) }], unit: 's' })),
    q.student_id && ses.length ? h('section', {}, h('h2', {}, `Andamento di ${alias(+q.student_id)}`),
      h('div', { class: 'grid gc' },
        barChart({ title: 'Micro-obiettivi completati: con e senza agente', labels: ses.map(s => new Date(s.session.created_at * 1000).toLocaleDateString('it-IT')),
          series: [{ name: 'con agente', values: ses.map(s => s.session.agent_enabled ? s.goals.pct : null) }, { name: 'senza agente', values: ses.map(s => s.session.agent_enabled ? null : s.goals.pct) }], unit: '%', max: 100 }),
        barChart({ title: 'Livello di supporto a fine sessione', labels: ses.map(s => new Date(s.session.created_at * 1000).toLocaleDateString('it-IT')), series: [{ name: 'livello (3 = guida strutturata, 0 = autonomia)', values: ses.map(s => s.session.agent_enabled ? s.session.support_level : null) }], max: 3 }),
        barChart({ title: 'Autoefficacia dichiarata e valutazione del risultato', labels: ses.map(s => new Date(s.session.created_at * 1000).toLocaleDateString('it-IT')),
          series: [{ name: 'autoefficacia prima (1–5)', values: ses.map(s => s.session.slots.self_efficacy ?? null) }, { name: 'valutazione del risultato (1–5)', values: ses.map(s => { const v = s.self_assessments.filter(a => a.kind === 'valutazione_risultato').pop(); return v ? +v.value_num : null; }) }], max: 5 })),
      h('p', { class: 'small muted' }, 'Confronto descrittivo fra sessioni. Le attività senza agente permettono di osservare il lavoro autonomo, ma un andamento favorevole non dimostra il transfer: per inferenze servono un disegno controllato e misure indipendenti dallo strumento.')) : null,
    ids.length >= 2 ? heatmap({ title: `Profili di codifica per sessione, raggruppati (k-means, k = ${cl.k})`, cols: d.codes,
        rows: order.map(i => { const s = ses.find(x => String(x.session.id) === String(i)); return `#${i} ${s ? alias(s.session.student_id) : ''}`; }), values: order.map(i => cl.profiles[i]), groups: order.map(i => cl.assignment[i]) })
      : h('div', { class: 'card muted' }, cl.note || 'Clustering non calcolabile: servono almeno due sessioni con annotazioni valide.'),
    h('p', { class: 'small muted' }, 'I gruppi sono esplorativi: dipendono da k, dal numero di sessioni e dalla qualità delle annotazioni (in gran parte ancora da rivedere). Non descrivono tipologie di studenti.'),
    h('div', { class: 'card scroll' }, h('h2', {}, 'Sessioni'), h('table', {}, h('thead', {}, h('tr', {}, ['#', 'Studente', 'Attività', 'Stato', 'Obiettivi', 'Tempo attivo', 'Supporto', 'Calibrazione', ''].map(t => h('th', {}, t)))),
      h('tbody', {}, ses.slice().reverse().map(s => h('tr', {}, h('td', {}, s.session.id), h('td', {}, alias(s.session.student_id), s.session.is_simulation ? h('span', { class: 'chip' }, 'simulazione') : null),
        h('td', {}, s.session.activity, s.session.agent_enabled ? null : h('span', { class: 'chip' }, 'senza agente')), h('td', {}, h('span', { class: 'chip ' + (s.session.safety_flag ? 'bad' : s.session.state === 'closed_complete' ? 'ok' : '') }, STATE[s.session.state])),
        h('td', {}, `${s.goals.completed}/${s.goals.agreed}`), h('td', {}, nd(s.time.active_min, ' min')), h('td', {}, `liv. ${s.session.support_level}, passo ${s.session.step_size}`),
        h('td', {}, s.calibration.status === 'calcolata' ? `bias ${s.calibration.bias}` : 'non calcolabile'), h('td', {}, h('button', { onclick: guard(() => session(s.session.id, q)) }, 'Apri'))))))),
    h('details', { class: 'card' }, h('summary', {}, 'Definizione degli indicatori (formula, unità, origine, denominatore, dati mancanti)'), h('div', { class: 'scroll' }, h('table', {},
      h('thead', {}, h('tr', {}, ['Indicatore', 'Classe', 'Formula', 'Unità', 'Origine', 'Denominatore', 'Dati mancanti'].map(t => h('th', {}, t)))),
      h('tbody', {}, B.indicators.indicators.map(i => h('tr', {}, [i.key, i.class, i.formula, i.unit, i.source, i.denominator, i.missing].map(t => h('td', {}, t))))))),
      h('p', { class: 'small muted' }, 'Classi: O = osservato, A = autovalutazione dello studente, P = valutazione del professionista. Le inferenze del modello linguistico non entrano in alcun indicatore.')));
}

async function session(id, back) {
  const d = await api('GET', `/api/psy/sessions/${id}`);
  const codes = B.codebook.content.codes, t = d.time, stu = d.messages.filter(m => m.role === 'student');
  const annOf = (mid) => d.annotations.filter(x => x.message_id === mid);
  const refresh = () => session(id, back);
  const review = (a) => { const s = sel([['', '— astensione —'], ...codes.map(c => [c.key, `${c.key} · ${c.label}`])], a.code_key);
    return h('span', { class: 'row small' }, h('span', { class: 'chip ' + ({ confermata: 'ok', corretta: 'warn', rifiutata: 'bad' }[a.review_status] || 'brand') }, `${a.code_key || 'astensione'} · ${a.review_status.replace('_', ' ')}`),
      h('span', { class: 'muted' }, `«${a.segment_text || ''}» — ${a.rationale || ''} · incertezza ${a.uncertainty} · ${a.elicitation} · ${a.annotator_type === 'umano' ? 'revisione umana' : a.model_name}${a.supersedes_id ? ' · sostituisce #' + a.supersedes_id : ''}`),
      a.review_status === 'corretta' ? null : [h('button', { onclick: guard(async () => { await api('POST', `/api/psy/annotations/${a.id}/review`, { decision: 'confirm' }); refresh(); }) }, 'Conferma'),
        h('button', { onclick: guard(async () => { await api('POST', `/api/psy/annotations/${a.id}/review`, { decision: 'reject' }); refresh(); }) }, 'Rifiuta'), s,
        h('button', { onclick: guard(async () => { await api('POST', `/api/psy/annotations/${a.id}/review`, { decision: 'correct', code_key: s.value || null, rationale: 'Correzione del professionista' }); refresh(); }) }, 'Correggi')]); };
  const rub = B.rubrics;
  const rate = h('form', { class: 'row', onsubmit: guard(async (e) => { e.preventDefault(); const f = e.target; await api('POST', `/api/psy/sessions/${id}/rating`, { rubric_id: f.r.value, goal_id: f.g.value || null, score: f.s.value, note: f.n.value }); toast('Valutazione registrata.'); refresh(); }) },
    field('Rubrica', sel(rub.map(r => [r.id, `${r.title} (${r.purpose}, ${+r.scale_min}–${+r.scale_max})`]), null, { name: 'r' })), field('Micro-obiettivo', sel([['', 'Intera sessione'], ...d.goals.items.map(g => [g.id, `${g.n}. ${g.description}`])], null, { name: 'g' })),
    field('Punteggio', h('input', { name: 's', type: 'number', step: '0.5', required: true })), field('Nota', h('input', { name: 'n' })), h('button', { type: 'submit' }, 'Registra'));
  const lat = d.gaps.items.filter(g => g.kind === 'agent→student');
  clear(view).append(
    h('div', { class: 'row' }, h('button', { class: 'ghost', onclick: guard(() => dash(back)) }, '← Panoramica'), h('h1', {}, `Sessione #${id} · ${alias(d.session.student_id)}`), h('span', { class: 'chip ' + (d.session.safety_flag ? 'bad' : '') }, STATE[d.session.state]),
      h('button', { onclick: () => downloadJson(`ablet-sessione-${id}.json`, d) }, 'Esporta dati (JSON)')),
    d.session.safety_flag ? h('div', { class: 'banner safe' }, 'In questa sessione è stata attivata una sospensione di sicurezza. Rivedi la conversazione e valuta come coinvolgere le persone di riferimento.') : null,
    h('p', { class: 'small muted' }, `${d.session.activity_mode === 'free' ? 'Attività libera · ' : ''}${d.session.activity} · protocollo versione id ${d.session.protocol_version_id ?? '—'} · hash del prompt ${(d.session.prompt_hash || '—').slice(0, 12)}…`),
    h('div', { class: 'grid g3' },
      h('div', { class: 'card' }, h('h3', {}, 'Tempi'), d.session.activity_mode === 'free' ? h('p', { class: 'small' },
        `Totale dichiarato: ${nd(d.time_plan.declared_total_min, ' min')} · somma completa dei passi: ${nd(d.time_plan.micro_total_min, ' min')} · tempo disponibile: ${nd(d.time_plan.available_min, ' min')}`) : null, h('p', { class: 'small' }, `Previsto (${d.time_plan?.estimate_source === 'sum_student_micro_goals' ? 'somma delle stime dei passi' : 'dichiarato'}): ${nd(t.estimated_min, ' min')}`, h('br'), `Trascorso: ${nd(t.elapsed_min, ' min')}`, h('br'), `Attivo (senza pause): ${nd(t.active_min, ' min')}`, h('br'),
        `Interazione registrata: ${nd(t.interaction_min, ' min')}`, h('br'), `Studio dichiarato fuori dall'app: ${t.declared_outside_app_min == null ? 'non dichiarato' : t.declared_outside_app_min + ' min'}`, h('br'),
        `Pause: ${t.pause_count} (${nd(t.paused_min, ' min')}) · inattività: ${t.inactivity_gaps} · latenza di avvio: ${nd(t.start_latency_s, ' s')}`)),
      h('div', { class: 'card' }, h('h3', {}, 'Processo'), h('p', { class: 'small' }, `Micro-obiettivi: ${d.goals.completed}/${d.goals.agreed} (${nd(d.goals.pct, '%')})`, h('br'), `Indizi richiesti: ${d.help.hint_requests} · difficoltà segnalate: ${d.help.difficulty_flags}`, h('br'),
        `Richieste della soluzione: ${d.help.solution_requests}`, h('br'), `Solleciti dell'agente: ${d.agent_prompts}`, h('br'), `Strategia dichiarata: ${d.strategies.declared || 'non dichiarata'} · cambi: ${d.strategies.changes}`, h('br'),
        `Fasi: ${d.phases.map(p => p.to_state).join(' → ') || '—'}`)),
      h('div', { class: 'card' }, h('h3', {}, 'Calibrazione sicurezza–prestazione'), d.calibration.status === 'calcolata'
        ? h('p', { class: 'small' }, `Bias ${d.calibration.bias} (>0 sovrastima), accuratezza assoluta ${d.calibration.absolute_accuracy}, su ${d.calibration.n} elementi (scale normalizzate 0–1).`)
        : h('p', { class: 'small' }, h('strong', {}, 'Non calcolabile: '), d.calibration.reason + '.'),
        h('h3', {}, 'Affidabilità tecnica'), h('p', { class: 'small' }, `Errori del provider: ${d.system.provider_errors} · output scartati: ${d.system.invalid_outputs} · output bloccati: ${d.system.blocked_outputs}`))),
    d.usage ? h('div', { class: 'card' }, h('h3', {}, 'Consumi e chiamate della sessione'),
      h('p', { class: 'small' }, `Richieste registrate: ${d.usage.requests} · riuscite: ${d.usage.successful || 0} · rinvii senza contattare il provider: ${d.usage.cooldowns || 0}. Token comunicati dal provider: ingresso ${d.usage.tokens_in}, uscita ${d.usage.tokens_out}. Richieste con consumo non disponibile: ${d.usage.unknown_usage || 0}. I tentativi HTTP interni a una richiesta non sono conteggiati separatamente; il consumo totale può essere superiore.`),
      h('div', { class: 'scroll' }, h('table', {}, h('thead', {}, h('tr', {}, ...['Data (UTC)', 'Modello', 'Esito', 'HTTP', 'Durata (ms)', 'Token in / out'].map(x => h('th', {}, x)))),
        h('tbody', {}, ...(d.llm_calls || []).map(x => h('tr', {}, ...[x.created_at, x.model_name || x.provider_key, x.status, x.http_status ?? '—', x.duration_ms, `${x.tokens_in ?? '—'} / ${x.tokens_out ?? '—'}`].map(v => h('td', {}, String(v))))))))) : null,
    h('div', { class: 'grid gc' },
      barChart({ title: 'Stima e tempo registrato per micro-obiettivo', labels: d.goals.items.map(g => `${g.n}. ${g.description}`), series: [{ name: 'stima (dichiarata)', values: d.goals.items.map(g => g.estimated_min) }, { name: 'registrato (osservato)', values: d.goals.items.map(g => g.recorded_min) }], unit: 'min' }),
      barChart({ title: 'Intervallo fra messaggio dell\'agente e risposta dello studente', labels: lat.map((g, i) => `risposta ${i + 1}`), series: [{ name: 'secondi', values: lat.map(g => g.s) }], unit: 's' }),
      barChart({ title: 'Polarità lessicale dei messaggi dello studente', labels: d.sentiment.items.map((x, i) => `msg ${i + 1}`), series: [{ name: 'polarità', values: d.sentiment.items.map(x => x.score) }], diverging: true, max: 1 }),
      barChart({ title: 'Segmenti codificati per codice (annotazioni valide)', labels: codes.map(c => c.key), series: [{ name: 'segmenti', values: codes.map(c => d.codes.counts[c.key] || 0) }], unit: 'n' })),
    h('p', { class: 'small muted' }, `Polarità: ${d.sentiment.algorithm}. Descrive le parole usate, non lo stato emotivo. Gli intervalli di risposta non misurano attenzione né impegno: lo studente può lavorare su carta. `
      + `Produzioni spontanee: ${d.codes.elicitation.spontanea} · sollecitate da una domanda dell'agente: ${d.codes.elicitation.sollecitata}.`),
    h('div', { class: 'card' }, h('h2', {}, 'Livelli di supporto e adattamenti'), d.scaffolding.length ? h('div', { class: 'scroll' }, h('table', {}, h('thead', {}, h('tr', {}, ['Origine', 'Regola', 'Azione', 'Esito', 'Supporto', 'Passo', 'Evidenze (id eventi)'].map(x => h('th', {}, x)))),
      h('tbody', {}, d.scaffolding.map(a => h('tr', {}, [a.source, a.rule_key, a.action, a.outcome, `${a.prev_support_level} → ${a.new_support_level}`, `${a.prev_step_size} → ${a.new_step_size}`, a.evidence_event_ids].map(x => h('td', {}, x))))))) : h('p', { class: 'muted' }, 'Nessun adattamento in questa sessione.')),
    h('div', { class: 'card' }, h('h2', {}, 'Autovalutazioni e valutazioni'), h('p', { class: 'small' }, d.self_assessments.length ? d.self_assessments.map(a => `${a.kind.replaceAll('_', ' ')}: ${a.value_text ?? +a.value_num}`).join(' · ') : 'Nessuna autovalutazione raccolta (facoltative).'),
      h('p', { class: 'small' }, d.ratings.length ? d.ratings.map(r => `${r.title}: ${+r.score}${r.note ? ' — ' + r.note : ''}`).join(' · ') : 'Nessuna valutazione professionale.'), rub.length ? rate : null,
      h('form', { class: 'row', onsubmit: guard(async (e) => { e.preventDefault(); await api('POST', `/api/psy/sessions/${id}/note`, { body: e.target.b.value }); e.target.reset(); toast('Annotazione salvata.'); }) }, h('input', { name: 'b', 'aria-label': 'Annotazione professionale', placeholder: 'Annotazione professionale…', required: true }), h('button', { type: 'submit' }, 'Salva nota'))),
    h('div', { class: 'card' }, h('div', { class: 'row' }, h('h2', {}, 'Conversazione e codifiche'),
      h('button', { onclick: guard(async () => { const r = await api('POST', `/api/psy/sessions/${id}/annotate`, { mode: 'lessicale' }); toast(`${r.created} annotazioni proposte (${r.annotator}).`); refresh(); }) }, 'Annota con regole lessicali'),
      h('button', { onclick: guard(async () => { const r = await api('POST', `/api/psy/sessions/${id}/annotate`, { mode: 'llm' }); toast(r.note || `${r.created} annotazioni proposte (${r.annotator}).`); refresh(); }) }, 'Annota con il modello')),
      h('p', { class: 'small muted' }, `Codebook v${B.codebook.version} (iniziale, non validato). Le annotazioni automatiche sono proposte da rivedere; si codificano solo i messaggi dello studente. Ogni correzione conserva l'originale.`),
      h('div', { class: 'msg-log' }, d.messages.map(m => h('div', { class: 'm ' + m.role }, h('div', { class: 'small muted' }, `${m.role === 'student' ? 'Studente' : 'Agente'} · ${m.phase}${m.modality === 'voice' ? ' · voce (trascrizione confermata)' : ''}${m.simulated ? ' · risposta simulata' : ''}${m.meta?.fallback ? ' · messaggio di ripiego' : ''}${m.meta?.fixed ? ' · messaggio fisso di sicurezza' : ''}`),
        h('div', {}, m.content), m.role === 'student' ? annOf(m.id).map(review) : null))),
      stu.length ? null : h('p', { class: 'muted' }, 'Nessun messaggio dello studente.')),
    h('details', { class: 'card' }, h('summary', {}, 'Codebook'), codes.map(c => h('p', { class: 'small' }, h('strong', {}, `${c.key} · ${c.label} (${c.phase}). `), c.definition, ' Includere: ', c.include, ' Escludere: ', c.exclude, ' Esempi: ', c.examples.join(' / '))),
      h('p', { class: 'small muted' }, 'Protocollo di validazione: ' + B.codebook.content.validation_protocol.steps.join(' '))));
}

// ───────────────────────── studenti
async function students() {
  view.oninput=null;view.onchange=null;
  const create = h('form', { class: 'card row', onsubmit: guard(async (e) => { e.preventDefault(); const f = e.target; const r = await api('POST', '/api/psy/students', { alias: f.a.value, age_band: f.g.value });
    out.replaceChildren(h('div', { class: 'banner demo', role: 'status' }, h('strong', {}, 'Credenziali (mostrate una sola volta): '), `codice ${r.login} · password ${r.password}`)); await reload(); list(); f.reset(); }) },
    field('Alias non identificativo', h('input', { name: 'a', required: true, maxlength: 60 }), 'Non usare nome e cognome.'), field('Fascia d\'età', sel(['8-10', '11-13', '14-16', '17-19', 'adulto'].map(x => [x, x]), '11-13', { name: 'g' })), h('button', { class: 'primary', type: 'submit' }, 'Nuovo studente'));
  const out = h('div', {}), box = h('div', { class: 'grid g2 student-list' });
  const search = h('input', {type:'search',placeholder:'Cerca per alias, pseudonimo o codice', 'aria-label':'Cerca uno studente',oninput:()=>list()});
  const count = h('p',{class:'small muted',role:'status'});
  const list = () => { const term=(search.value || '').trim().toLocaleLowerCase('it'); const found=B.students.filter(s=>[s.display_alias,s.pseudonym,s.login].some(v=>String(v||'').toLocaleLowerCase('it').includes(term))); count.textContent=found.length + ' studenti visualizzati'; box.replaceChildren(...found.map(s => {
    const prefs = (() => { try { return JSON.parse(s.model_prefs || '{}'); } catch { return {}; } })();
    const prof = h('form', { onsubmit: guard(async (e) => { e.preventDefault(); const f = e.target; const r = await api('PUT', `/api/psy/students/${s.id}/profile`, { age_band: f.g.value, strengths: f.st.value, constraints_text: f.c.value }); toast(r.warnings[0] || 'Profilo funzionale salvato (nuova versione).'); await reload(); }) },
      field('Fascia d\'età', sel(['8-10', '11-13', '14-16', '17-19', 'adulto'].map(x => [x, x]), s.age_band, { name: 'g' })), field('Punti di forza', h('textarea', { name: 'st' }, s.strengths || '')),
      field('Vincoli ed esigenze', h('textarea', { name: 'c' }, s.constraints_text || ''), 'Descrivi il funzionamento, non la diagnosi.'), h('button', { type: 'submit' }, 'Salva profilo'));
    const model = h('form', { class: 'row', onsubmit: guard(async (e) => { e.preventDefault(); const f = e.target; await api('PUT', `/api/psy/students/${s.id}/model`, { support_level: f.l.value, step_size: f.p.value, lock_support_level: f.k.checked }); toast('Modello dello studente aggiornato.'); await reload(); }) },
      field('Livello di supporto', sel(LEVELS.map((t, i) => [i, t]), s.support_level ?? 3, { name: 'l' })), field('Ampiezza del passo (1–5)', h('input', { name: 'p', type: 'number', min: 1, max: 5, value: s.step_size ?? 3 })),
      h('label', { class: 'inline' }, h('input', { type: 'checkbox', name: 'k', checked: !!prefs.lock_support_level }), 'Lo studente preferisce non cambiare livello'), h('button', { type: 'submit' }, 'Salva'));
    const mine = B.consents.filter(c => c.student_id === s.id);
    const missing = B.privacy.require_consents ? ['informativa', 'uso_piattaforma'].filter(k => !mine.some(c => c.kind === k)) : [];
    const cons = h('div', {}, mine.length ? h('ul', { class: 'small' }, mine.map(c => h('li', {}, `${B.consent_kinds[c.kind]} — ${B.consent_by[c.granted_by]} — informativa v${c.document_version} — ${c.granted_at.slice(0, 10)} `,
        h('button', { class: 'ghost', onclick: guard(async () => { if (!confirm('Registrare la revoca di questa autorizzazione?')) return; await api('POST', `/api/psy/consents/${c.id}/revoke`); await reload(); list(); }) }, 'Revoca')))) : h('p', { class: 'small muted' }, 'Nessuna autorizzazione registrata.'),
      h('form', { class: 'row', onsubmit: guard(async (e) => { e.preventDefault(); const f = e.target; await api('POST', `/api/psy/students/${s.id}/consents`, { kind: f.k.value, granted_by: f.b.value, document_version: f.v.value, granted_at: f.d.value }); toast('Autorizzazione registrata.'); await reload(); list(); }) },
        field('Voce', sel(Object.entries(B.consent_kinds), null, { name: 'k' })), field('Rilasciata da', sel(Object.entries(B.consent_by), s.age_band === 'adulto' || s.age_band === '17-19' ? 'studente' : 'esercente_responsabilita_genitoriale', { name: 'b' })),
        field('Versione informativa', h('input', { name: 'v', value: B.privacy.notice_version, required: true, maxlength: 40 })), field('Data', h('input', { name: 'd', type: 'date', required: true, value: new Date().toISOString().slice(0, 10) })), h('button', { type: 'submit' }, 'Registra')),
      h('p', { class: 'small muted' }, 'Registra qui le autorizzazioni raccolte (es. modulo firmato). Chi debba firmarle lo stabilisce il titolare del trattamento.'));
    return h('article', { class: 'card' }, h('h3', {}, s.display_alias || s.pseudonym), h('p', { class: 'small muted' }, `Codice di accesso ${s.login} · pseudonimo ${s.pseudonym} · ${s.sessions} sessioni`),
      missing.length ? h('p', { class: 'chip bad' }, 'Autorizzazioni mancanti: lo studente non può avviare sessioni') : null,
      h('button', { class: 'primary', onclick: () => studentProgramme(s.id) }, 'Programma sessioni · attività e protocolli'), h('details', {}, h('summary', {}, 'Profilo funzionale'), prof), h('details', { open: missing.length > 0 }, h('summary', {}, `Autorizzazioni (${mine.length})`), cons), h('details', {}, h('summary', {}, 'Supporto e ritiro graduale'), model, h('p', { class: 'small muted' }, 'Soglie e livelli sono parametri progettuali da validare, non valori clinici.')),
      h('div', { class: 'row' }, h('button',{onclick:guard(async()=>{const r=await api('POST',`/api/psy/students/${s.id}/pin`);out.replaceChildren(h('div',{class:'banner',role:'status'},`PIN per ${s.display_alias||s.login}: ${r.pin}. Mostrato una sola volta. Il PIN precedente non funziona più.`));})},'Genera nuovo PIN'),h('button',{onclick:guard(async()=>{await api('DELETE',`/api/psy/students/${s.id}/pin`);out.replaceChildren(h('p',{role:'status'},'PIN disattivato. Resta disponibile la password.'));})},'Disattiva PIN'), h('button', { onclick: guard(async () => { const r = await api('POST', `/api/psy/students/${s.id}/reset`); out.replaceChildren(h('div', { class: 'banner demo' }, `Nuova password per ${r.login}: ${r.password}`)); }) }, 'Nuova password'),
        h('button', { onclick: guard(async () => downloadJson(`ablet-studente-${s.pseudonym}.json`, await api('GET', `/api/psy/students/${s.id}/export`))) }, 'Esporta dati'),
        h('button', { class: 'danger', onclick: guard(async () => { if (!confirm(`Eliminare definitivamente ${s.display_alias || s.pseudonym} e tutti i dati collegati?`)) return; await api('DELETE', `/api/psy/students/${s.id}`); await reload(); list(); }) }, 'Elimina')));
  })); if(!found.length)box.append(h('p',{class:'banner'},term?'Nessuno studente corrisponde alla ricerca.':'Non ci sono studenti. Crea il primo account per programmare le sessioni.')); };
  list();
  const groupForm = (g = null) => { const members = new Set(B.group_members.filter(m => g && m.group_id === g.id).map(m => m.student_id));
    const boxes = B.students.map(s => [s.id, h('input', { type: 'checkbox', checked: members.has(s.id) }), s.display_alias || s.pseudonym]);
    return h('form', { class: 'card', onsubmit: guard(async (e) => { e.preventDefault(); const ids = boxes.filter(b => b[1].checked).map(b => b[0]); const title = e.target.t.value;
        g ? await api('PUT', `/api/psy/groups/${g.id}`, { title, student_ids: ids }) : await api('POST', '/api/psy/groups', { title, student_ids: ids }); toast('Gruppo salvato.'); await reload(); students(); }) },
      h('h3', {}, g ? `Gruppo: ${g.title}` : 'Nuovo gruppo'), field('Nome del gruppo', h('input', { name: 't', required: true, maxlength: 100, value: g?.title || '' })),
      h('div', { class: 'row' }, boxes.map(b => h('label', { class: 'inline' }, b[1], b[2]))),
      h('div', { class: 'row' }, h('button', { type: 'submit' }, 'Salva gruppo'), g ? h('button', { type: 'button', class: 'danger', onclick: guard(async () => { if (!confirm('Eliminare il gruppo? Gli studenti restano.')) return; await api('DELETE', `/api/psy/groups/${g.id}`); await reload(); students(); }) }, 'Elimina gruppo') : null)); };
  clear(view).append(h('h1', {}, 'Studenti'), h('p',{class:'muted'},'Scegli uno studente per programmare attività e protocolli delle sue sessioni.'), h('details',{class:'card'},h('summary',{},'＋ Crea uno studente'),create), out, field('Cerca uno studente',search), count, box,
    h('h2', {}, 'Gruppi'), h('p', { class: 'small muted' }, 'I gruppi organizzano gli studenti. Le nuove sessioni si programmano nella scheda del singolo studente.'),
    h('div', { class: 'grid g2' }, B.groups.map(groupForm), groupForm()));
}

const PLAN_STATUS={active:'Attivo',completed:'Completato',cancelled:'Annullato'};
const STEP_STATUS={pending:'Da iniziare',blocked:'Da riprogrammare',started:'Avviata',completed:'Completata',skipped:'Saltata dal professionista',cancelled:'Annullata'};
async function studentProgramme(studentId) {
  const student=B.students.find(s=>+s.id===+studentId);
  if(!student){toast('Seleziona uno studente reale.');return;}
  const result=await api('GET',`/api/psy/students/${studentId}/plans`);
  let rows=[{activity_id:'',protocol_version_id:''}];
  const list=h('div',{}), mode=sel([['single','Una sessione'],['chain','Una sequenza di sessioni']], 'single', {'aria-label':'Tipo di programma'});
  const name=h('input',{maxlength:160,placeholder:'Es. Metodo di studio · tre incontri','aria-label':'Titolo del programma'});
  const add=h('button',{type:'button',onclick:()=>{if(rows.length>=30)return;rows.push({activity_id:'',protocol_version_id:''});drawRows();}},'＋ Aggiungi sessione');
  const drawRows=()=>{
    add.hidden=mode.value!=='chain';add.disabled=rows.length>=30;
    list.replaceChildren(...rows.map((row,i)=>{
      const activity=sel([['','Scegli l’attività'],...B.activities.map(a=>[a.id,a.title])],row.activity_id,{required:true,'aria-label':`Attività sessione ${i+1}`});
      const version=sel([['','Scegli il protocollo pubblicato'],...(B.published_versions||[]).map(v=>[v.id,`${v.title} · v${v.version_no}`])],row.protocol_version_id,{required:true,'aria-label':`Protocollo sessione ${i+1}`});
      const sync=()=>{const a=B.activities.find(a=>+a.id===+activity.value);row.activity_id=activity.value;version.disabled=!a||!a.agent_enabled;version.required=!!a?.agent_enabled;if(a&&!a.agent_enabled){row.protocol_version_id='';version.value='';}};
      activity.addEventListener('change',sync);version.addEventListener('change',()=>{row.protocol_version_id=version.value;});sync();
      const move=direction=>{const target=i+direction;[rows[i],rows[target]]=[rows[target],rows[i]];drawRows();};
      return h('fieldset',{class:'card programme-step'},h('legend',{},`Sessione ${i+1}`),h('div',{class:'grid g2'},field('Attività',activity),field('Protocollo e versione',version)),h('div',{class:'row'},
        h('button',{type:'button',disabled:i===0||mode.value==='single',onclick:()=>move(-1),'aria-label':`Sposta prima la sessione ${i+1}`},'↑ Prima'),
        h('button',{type:'button',disabled:i===rows.length-1||mode.value==='single',onclick:()=>move(1),'aria-label':`Sposta dopo la sessione ${i+1}`},'↓ Dopo'),
        h('button',{type:'button',disabled:rows.length===1,onclick:()=>{rows.splice(i,1);drawRows();}},'Togli dal programma')));
    }));
  };
  mode.addEventListener('change',()=>{if(mode.value==='single'&&rows.length>1){if(!confirm('Passare a una sola sessione? Verranno rimosse dalla bozza le righe successive, non ancora assegnate.')){mode.value='chain';return;}rows=rows.slice(0,1);}drawRows();});
  drawRows();
  const form=h('form',{class:'card programme-form',onsubmit:guard(async e=>{
    e.preventDefault();const submit=e.currentTarget.querySelector('[type=submit]');submit.disabled=true;
    try{await api('POST',`/api/psy/students/${studentId}/plans`,{title:name.value,steps:rows.map(row=>({activity_id:+row.activity_id,protocol_version_id:row.protocol_version_id?+row.protocol_version_id:null}))});await reload();toast('Programma assegnato allo studente.');await studentProgramme(studentId);}finally{submit.disabled=false;}
  })},h('h2',{},'Nuovo programma'),field('Titolo (facoltativo)',name),field('Che cosa vuoi assegnare?',mode),list,add,h('p',{class:'small'},'Ogni riga corrisponde a una sessione completa nelle tre fasi SRL e conserva attività e versione del protocollo. Lo studente può avviare solo la prima sessione non conclusa. Le successive si sbloccano dopo la chiusura completa oppure dopo una tua decisione esplicita di saltare una sessione.'),h('div',{class:'editor-actions'},h('button',{type:'submit',class:'primary'},'Assegna programma allo studente')));
  const plans=(result.plans||[]).map(plan=>h('article',{class:'card'},h('h3',{},plan.title),h('span',{class:'chip'},PLAN_STATUS[plan.status]||plan.status),h('ol',{},plan.steps.map(step=>h('li',{},
    h('strong',{},`${step.activity} · ${step.protocol||'senza agente'}${step.version_no?' · v'+step.version_no:''}`),h('p',{class:'small'},`${STEP_STATUS[step.status]||step.status}${step.session_state?' · '+(STATE[step.session_state]||step.session_state):''}${step.protocol_archived&&step.status==='pending'?' · protocollo archiviato: riprogramma o salta questa sessione':''}`),
    plan.status==='active'&&(['pending','blocked'].includes(step.status)||(step.status==='started'&&(!step.session_state||step.session_state==='closed_incomplete'))) ? h('button',{onclick:guard(async()=>{if(!confirm(`Saltare la sessione ${step.position}? Le sessioni successive potranno sbloccarsi. I dati già salvati resteranno disponibili.`))return;await api('POST',`/api/psy/students/${studentId}/plan-steps/${step.id}/skip`,{});studentProgramme(studentId);})},'Salta questa sessione') : null))),
    plan.status==='active'?h('button',{class:'danger',onclick:guard(async()=>{if(!confirm('Annullare le sessioni future di questo programma? La sessione eventualmente già avviata e i suoi dati rimarranno disponibili.'))return;await api('POST',`/api/psy/students/${studentId}/plans/${plan.id}/cancel`,{});studentProgramme(studentId);})},'Annulla programma'):null));
  const legacy=(B.activity_assignments||[]).filter(a=>+a.student_id===+studentId&&+a.active);
  clear(view).append(h('button',{class:'ghost',onclick:students},'← Studenti'),h('h1',{},`Sessioni di ${student.display_alias||student.pseudonym}`),form,h('h2',{},'Programmi assegnati'),plans.length?plans:h('p',{},'Nessun programma ancora assegnato.'),
    legacy.length?h('details',{},h('summary',{},'Assegnazioni precedenti alla programmazione delle sessioni'),legacy.map(a=>h('p',{},B.activities.find(x=>+x.id===+a.activity_id)?.title||'Attività',h('button',{onclick:guard(async()=>{await api('POST',`/api/psy/activity-assignments/${a.id}/revoke`,{});await reload();studentProgramme(studentId);})},'Revoca assegnazione precedente')))):null);
}

const PHASE_GUIDE = [
 ['1 · Previsione e pianificazione', 'Prima dello studio', 'Analisi del compito: requisiti, difficoltà, conoscenze e obiettivi. Pianificazione strategica: metodo di studio e tempi. Credenze motivazionali: autoefficacia dichiarata, interesse e obiettivi di padronanza o prestazione.'],
 ['2 · Prestazione e controllo volitivo', 'Durante lo studio', 'Autocontrollo: applicare la strategia, gestire motivazione, pause e distrazioni segnalate. Auto-osservazione: verificare in modo metacognitivo se si comprende, con evidenze e non con la sola sensazione di familiarità.'],
 ['3 · Auto-riflessione', 'Dopo il compito o una sua parte', 'Autovalutazione rispetto ai criteri iniziali. Esplorazione delle attribuzioni causali senza attribuire automaticamente l’esito a intelligenza o scarso impegno. Riconoscimento delle reazioni affettive e scelta di una modifica per la prossima volta.']
];
function srlGuide() { return h('section', {class:'card'}, h('h2',{},'Il ciclo di autoregolazione dell’apprendimento (SRL)'), h('div',{class:'grid g3'},PHASE_GUIDE.map(([title,when,body])=>h('article',{},h('h3',{},title),h('strong',{},when),h('p',{},body)))),h('p',{class:'small'},'Il ciclo è flessibile: lo studente può tornare alla pianificazione. Tempi e tracce digitali non dimostrano da soli attenzione o apprendimento.')); }
function templateView(key) { const t=B.templates[key]; clear(view).append(h('button',{onclick:protocols},'← Protocolli'),h('h1',{},t.title),h('p',{},'Originale protetto: non modificabile né eliminabile. Crea una copia per personalizzare e assegnare.'),srlGuide(),['forethought','performance','self_reflection'].map((phase,i)=>h('section',{class:'card'},h('h2',{},PHASE_GUIDE[i][0]),h('ol',{},t.srl_procedures[phase].steps.map(step=>h('li',{},step))))),h('button',{class:'primary',onclick:guard(async()=>{const r=await api('POST','/api/psy/protocols',{template_key:key});await reload();editor(r.id);})},'Crea copia personale')); }
async function archiveProtocol(id) { const impact=await api('GET',`/api/psy/protocols/${id}/impact`); if(!confirm(`Eliminare dall’elenco e archiviare questo protocollo?\nAssegnazioni generali: ${impact.global_assignments}; attività assegnate: ${impact.activity_assignments}; sessioni conservate: ${impact.sessions}; sessioni future da riprogrammare: ${impact.planned_sessions || 0}.\n${impact.consequence}`))return; await api('DELETE',`/api/psy/protocols/${id}`);await reload();protocols(); }
// ───────────────────────── protocolli (configuratore)
const get = (o, p) => p.split('.').reduce((a, k) => a?.[k], o);
const set = (o, p, v) => { const ks = p.split('.'); let a = o; ks.slice(0, -1).forEach(k => { a = a[k] ??= {}; }); a[ks.at(-1)] = v; };
const SLOTS = [['task_statement', 'consegna'], ['expected_outcome', 'risultato atteso'], ['completion_criterion', 'criterio di completamento'], ['prior_knowledge', 'conoscenze pregresse'], ['expected_difficulty', 'difficoltà attesa'], ['self_efficacy', 'autoefficacia'], ['strategy', 'strategia'], ['time_estimate_min', 'tempo previsto']];
const SPEC = [
  ['Intestazione', [['title', 'Titolo', 'text']]],
  ['1 · Ruolo dell\'agente', [['role.identity', 'Identità', 'text'], ['role.function', 'Funzione', 'area'], ['role.boundaries', 'Delimitazione del ruolo', 'area']]],
  ['2 · Obiettivo psicoeducativo', [['psychoeducational_goal.target_process', 'Processo target', 'area'], ['psychoeducational_goal.observable_behavior', 'Comportamento osservabile', 'area'], ['psychoeducational_goal.attainment_criterion', 'Criterio di raggiungimento', 'area']]],
  ['3 · Profilo funzionale (generale)', [['functional_profile.age_band', 'Fascia d\'età', 'sel:8-10,11-13,14-16,17-19,adulto'], ['functional_profile.strengths', 'Punti di forza', 'area'], ['functional_profile.accessibility_needs', 'Esigenze di accessibilità', 'area'], ['functional_profile.constraints', 'Vincoli', 'area'], ['functional_profile.preferences', 'Preferenze', 'area']]],
  ['4 · Strategie di interazione', [['interaction_strategies.guiding_questions', 'Domande guida (una per riga)', 'list'], ['interaction_strategies.segmentation', 'Segmentazione', 'area'], ['interaction_strategies.restatement', 'Riformulazione', 'area'], ['interaction_strategies.checkpoints', 'Checkpoint', 'area'], ['interaction_strategies.choice_support', 'Sostegno alla scelta', 'area']]],
  ['5 · Regole di adattamento', 'rules'],
  ['6 · Modalità di feedback', [['feedback.style', 'Stile', 'area'], ['feedback.do', 'Esempi coerenti (uno per riga)', 'list'], ['feedback.dont', 'Da evitare (uno per riga)', 'list']]],
  ['7 · Criteri di monitoraggio', [['monitoring.events', 'Eventi registrati (uno per riga)', 'list'], ['monitoring.indicators', 'Indicatori (uno per riga)', 'list'], ['monitoring.frequency', 'Frequenza', 'text'], ['monitoring.rubric', 'Rubrica', 'text']]],
  ['8 · Limiti operativi', [['operational_limits', 'Comportamenti vietati (uno per riga)', 'list']]],
  ['9 · Condizioni di escalation', [['escalation.signals', 'Segnali (uno per riga)', 'list'], ['escalation.response', 'Risposta prevista', 'area'], ['escalation.human_involvement', 'Coinvolgimento umano', 'area']]],
  ['Fase 1 · Previsione e pianificazione (Forethought)', [['srl_procedures.forethought.steps', 'Guida all’analisi del compito, alla strategia e alla motivazione (un intervento per riga)', 'list'], ['srl_procedures.forethought.required_slots', 'Informazioni da raccogliere prima dello svolgimento', 'slots'], ['srl_procedures.forethought.require_restatement', 'Chiedere la riformulazione della consegna', 'bool'], ['srl_procedures.forethought.min_micro_goals', 'Micro-obiettivi: minimo', 'num'], ['srl_procedures.forethought.max_micro_goals', 'Micro-obiettivi: massimo', 'num']]],
  ['Fase 2 · Prestazione e controllo volitivo (Performance)', [['srl_procedures.performance.steps', 'Guida all’autocontrollo e all’auto-osservazione (un intervento per riga)', 'list']]],
  ['Fase 3 · Auto-riflessione (Self-reflection)', [['srl_procedures.self_reflection.steps', 'Guida ad autovalutazione, attribuzioni causali e reazioni affettive (un intervento per riga)', 'list']]],
  ['Costo e limiti del prompt', [['prompt_budget.economy', 'Modalità economica: formato compatto e cronologia recente (non elimina le regole SRL)', 'bool'], ['prompt_budget.context_tokens', 'Capienza del contesto in token (verificare sul provider)', 'num'], ['prompt_budget.input_usd_million', 'Prezzo input in USD per milione di token (facoltativo)', 'num'], ['prompt_budget.output_usd_million', 'Prezzo output in USD per milione di token (facoltativo)', 'num']]],
  ['Criteri di completamento', [['task.completion_criteria', 'Criteri', 'area']]],
  ['Durata, pause e checkpoint', [['timing.expected_minutes', 'Durata prevista (minuti)', 'num'], ['timing.break_policy', 'Pause', 'area'], ['timing.checkpoint_every_steps', 'Automonitoraggio ogni N passi', 'num'], ['timing.checkpoint_every_minutes', 'Automonitoraggio ogni N minuti (facoltativo)', 'num']]],
  ['Livelli di scaffolding e fading', [['scaffolding.initial_level', 'Livello iniziale', 'sel:3,2,1,0'], ['scaffolding.levels.livello_3', 'Livello 3 · guida strutturata', 'area'], ['scaffolding.levels.livello_2', 'Livello 2 · guida condivisa', 'area'], ['scaffolding.levels.livello_1', 'Livello 1 · supporto su richiesta', 'area'], ['scaffolding.levels.livello_0', 'Livello 0 · autonomia', 'area']]],
  ['Modalità testuale e vocale', [['modality.voice_input', 'Dettatura vocale consentita', 'bool'], ['modality.voice_output', 'Lettura ad alta voce consentita', 'bool']]],
  ['Rubrica di osservazione', [['observation_rubric', 'Rubrica (JSON: titolo, scala, criteri con ancore)', 'json']]],
  ['Provider, modello e parametri', 'provider'],
];

async function protocols() {
  const imp = h('input', { type: 'file', accept: 'application/json', 'aria-label': 'Importa configurazione JSON', onchange: guard(async (e) => { const cfg = JSON.parse(await e.target.files[0].text()); const r = await api('POST', '/api/psy/protocols', { config: cfg.config || cfg }); await reload(); editor(r.id); }) });
  clear(view).append(h('h1', {}, 'Protocolli'), srlGuide(), h('p', {}, 'Percorso: scegli un modello → crea una copia → personalizza le tre fasi → pubblica → apri Studenti e programma le sessioni dello studente reale.'),
    h('div', { class: 'card row' }, h('strong', {}, 'Modelli originali protetti (le modifiche si fanno solo su copie personali):'), Object.entries(B.templates).map(([k, t]) => h('article', { class: 'card' }, h('h3', {}, t.title), t.system_template ? h('span', { class: 'chip' }, 'Modello di base protetto') : null, h('button', { onclick: () => templateView(k) }, 'Visualizza modello'), h('button', { onclick: guard(async () => { const r = await api('POST', '/api/psy/protocols', { template_key: k }); await reload(); editor(r.id); }) }, 'Crea copia personale'))), h('span', {}, 'oppure importa JSON:'), imp),
    h('h2',{},'Protocolli personali'),h('p',{class:'small muted'},'Questi protocolli sono copie modificabili. Anche quando hanno lo stesso nome di un modello, le modifiche non cambiano l’originale protetto.'),h('div', { class: 'grid g2' }, B.protocols.map(p => h('article', { class: 'card' }, h('span',{class:'chip'},'Copia personale modificabile'),h('h3', {}, p.title), h('p', { class: 'small muted' }, `Ultima versione ${p.latest} · pubblicata: ${p.published ? 'v' + p.published : 'nessuna'}`), h('button', { class: 'primary', onclick: guard(() => editor(p.id)) }, 'Apri'), h('button', { class: 'danger', onclick: guard(() => archiveProtocol(p.id)) }, 'Elimina protocollo')))),
    h('details', {}, h('summary', {}, 'Protocolli eliminati (archivio recuperabile)'), (B.archived_protocols || []).map(p => h('p', {}, p.title, ' ', h('button', { onclick: guard(async () => { await api('POST', `/api/psy/protocols/${p.id}/restore`, {}); await reload(); protocols(); toast('Ripristinato. Riassegna esplicitamente le attività o il protocollo.'); }) }, 'Ripristina')))));

}

async function editor(pid) {
  const d = await api('GET', `/api/psy/protocols/${pid}`), cur = d.versions[0], cfg = structuredClone(cur.config), out = h('div', {});
  dirty=false;
  const savedState=h('span',{class:'small',role:'status'},'Nessuna modifica non salvata');
  const markDirty=()=>{dirty=true;savedState.textContent='Modifiche non salvate';};
  const inputs = [];
  const mk = ([path, label, type]) => {
    const v = get(cfg, path); let el, read;
    if (type === 'area' || type === 'list' || type === 'json') { el = h('textarea', { rows: type === 'area' ? 3 : 5 }); el.value = type === 'list' ? (v || []).join('\n') : type === 'json' ? JSON.stringify(v ?? {}, null, 2) : (v ?? '');
      read = () => type === 'list' ? el.value.split('\n').map(x => x.trim()).filter(Boolean) : type === 'json' ? JSON.parse(el.value || '{}') : el.value; }
    else if (type === 'bool') { el = h('input', { type: 'checkbox', checked: !!v }); read = () => el.checked; inputs.push([path, read]); return h('label', { class: 'inline' }, el, label); }
    else if (type === 'num') { el = h('input', { type: 'number', min: 0, step: path.includes('usd_million') ? 'any' : 1, value: v ?? '' }); read = () => el.value === '' ? null : +el.value; }
    else if (type.startsWith('sel:')) { el = sel(type.slice(4).split(',').map(x => [x, x]), v); read = () => /^\d+$/.test(el.value) ? +el.value : el.value; }
    else if (type === 'slots') { const cb = SLOTS.map(([k, t]) => [k, h('input', { type: 'checkbox', checked: (v || []).includes(k) }), t]); inputs.push([path, () => cb.filter(c => c[1].checked).map(c => c[0])]); return h('div', {}, h('div', { class: 'small' }, h('strong', {}, label)), h('div', { class: 'row' }, cb.map(c => h('label', { class: 'inline' }, c[1], c[2])))); }
    else { el = h('input', { value: v ?? '' }); read = () => el.value; }
    inputs.push([path, read]);
    return field(label, el);
  };
  const rules = cfg.adaptation_rules ||= [], rbox = h('div', {});
  const drawRules = () => rbox.replaceChildren(...rules.map((r, i) => { const c = r.when.all[0];
    const up = (fn) => (e) => { fn(e.target.value); };
    return h('div', { class: 'card' }, h('div', { class: 'grid g3' },
      field('Chiave', h('input', { value: r.key, oninput: up(v => { r.key = v; }) })), field('Indicatore osservato', sel(B.rule_vocabulary.indicators.map(x => [x, x]), c.indicator, { onchange: up(v => { c.indicator = v; }) })),
      field('Condizione', sel(['>=', '>', '<=', '<', '=='].map(x => [x, x]), c.op, { onchange: up(v => { c.op = v; }) })), field('Soglia', h('input', { type: 'number', value: c.value, oninput: up(v => { c.value = +v; }) })),
      field('Azione', sel(B.rule_vocabulary.actions.map(x => [x, x]), r.then.action, { onchange: up(v => { r.then.action = v; }) })), field('Nuove evidenze minime', h('input', { type: 'number', min: 1, value: r.min_new_events ?? 1, oninput: up(v => { r.min_new_events = +v || 1; }) }))),
      field('Descrizione (compare nel prompt)', h('input', { value: r.description || '', oninput: up(v => { r.description = v; }) })), h('button', { class: 'danger', onclick: () => { rules.splice(i, 1); drawRules(); } }, 'Rimuovi regola')); }),
    h('button', { onclick: () => { rules.push({ key: 'regola_' + (rules.length + 1), description: '', when: { all: [{ indicator: 'consecutive_steps_not_completed', op: '>=', value: 2 }] }, then: { action: 'reduce_step_size' }, min_new_events: 1 }); drawRules(); } }, '+ Aggiungi regola'),
    h('p', { class: 'small muted' }, 'Le regole sono valutate in ordine: si applica la prima soddisfatta. Tempo di risposta e numero di parole non possono essere l\'unica condizione; le soglie sono parametri da validare.'));
  drawRules();
  const provSel = sel([['', 'Predefinito del professionista'], ...B.providers.map(p => [p.id, `${p.label} (${p.provider_key}${p.model_name ? ', ' + p.model_name : ''})`])], cfg.provider?.provider_config_id);
  const temp = h('input', { type: 'number', step: '0.1', min: 0, max: 2, value: cfg.provider?.params?.temperature ?? '' }), maxTok = h('input', { type: 'number', min: 100, max: 4000, value: cfg.provider?.params?.max_output_tokens ?? 600 });
  const collect = () => { for (const [p, read] of inputs) set(cfg, p, read()); cfg.modality.text = true; for (const k of ['context_tokens','input_usd_million','output_usd_million']) { if (cfg.prompt_budget?.[k] == null) delete cfg.prompt_budget[k]; }
    cfg.provider = { ...(cfg.provider || {}), provider_config_id: provSel.value ? +provSel.value : undefined, params: { ...(temp.value !== '' ? { temperature: +temp.value } : {}), max_output_tokens: +maxTok.value || 600 } }; return cfg; };
  const issues = (r) => h('div', {}, r.errors?.length ? h('div', { class: 'banner safe', role: 'alert' }, h('strong', {}, 'Da correggere prima della pubblicazione:'), h('ul', {}, r.errors.map(x => h('li', {}, x)))) : h('div', { class: 'banner prop' }, 'Nessun errore di convalida.'),
    r.warnings?.length ? h('div', { class: 'banner demo' }, h('strong', {}, 'Avvisi:'), h('ul', {}, r.warnings.map(x => h('li', {}, x)))) : null);
  const save = async () => { const r = await api('PUT', `/api/psy/protocols/${pid}/draft`, { config: collect() }); dirty=false; savedState.textContent='Bozza salvata'; return r; };
  const vsel = (name, dflt) => sel(d.versions.map(v => [v.version_no, `v${v.version_no} · ${v.status === 'published' ? 'pubblicata' : 'bozza'}`]), dflt, { name });
  const pubV = d.versions.filter(v => v.status === 'published');
  clear(view).append(
    h('div', { class: 'row' }, h('button', { class: 'ghost', onclick: guard(async () => { if(!leaveEditor())return; dirty=false; view.oninput=null;view.onchange=null; await reload(); protocols(); }) }, '← Protocolli'), h('h1', {}, d.protocol.title), h('span', { class: 'chip ' + (cur.status === 'published' ? 'ok' : 'warn') }, cur.status === 'published' ? `v${cur.version_no} pubblicata (immutabile)` : `bozza v${cur.version_no}`)),
    cur.status === 'published' ? h('p', { class: 'small muted' }, `Salvando una modifica si apre la bozza v${cur.version_no + 1}: la versione pubblicata e le ${cur.sessions} sessioni che la usano restano invariate.`) : null,
    h('p', { class: 'small muted' }, 'Le regole fondamentali della piattaforma (identità di IA, nessuna soluzione, sicurezza) precedono il protocollo e non sono modificabili da qui.'),
    srlGuide(), h('nav',{class:'srl-navigation','aria-label':'Fasi del protocollo'},PHASE_GUIDE.map(([title],i)=>h('a',{href:'#protocol-phase-'+i,onclick:()=>{const section=document.getElementById('protocol-phase-'+i);if(section)section.open=true;}},title))), SPEC.slice().sort((a,b) => Number(!a[0].startsWith('Fase ')) - Number(!b[0].startsWith('Fase '))).map(([title, fields]) => h('details', { class: 'card protocol-section', id:title.startsWith('Fase ')?'protocol-phase-'+(['forethought','performance','self_reflection'].findIndex(phase=>Array.isArray(fields)&&fields.some(f=>f[0].includes(phase)))):null, open: title.startsWith('Fase ') }, h('summary', {}, title), fields === 'rules' ? rbox : fields === 'provider'
      ? h('div', { class: 'grid g3' }, field('Configurazione del provider', provSel, 'Si gestisce in Impostazioni applicazione → Assistente IA.'), field('Temperatura', temp, 'Vuoto = valore predefinito del modello.'), field('Lunghezza massima della risposta (token)', maxTok))
      : h('div', { class: 'grid g2' }, fields.map(mk)))),
    h('div', { class: 'card row editor-actions' }, savedState,
      h('button', { onclick: guard(async () => { collect(); cfg.prompt_budget = { ...(cfg.prompt_budget || {}), economy: true }; await api('PUT', `/api/psy/protocols/${pid}/draft`, { config: cfg }); dirty=false; toast('Modalità economica salvata in bozza. Verifica l’anteprima e pubblica per le nuove sessioni.'); editor(pid); }) }, 'Rendi il prompt più economico'),
      h('button', { class: 'primary', onclick: guard(async () => { const r = await save(); out.replaceChildren(h('p', {}, `Bozza v${r.version_no} salvata.`), issues(r)); }) }, 'Salva bozza'),
      h('button', { onclick: guard(async () => { const r = await api('POST', '/api/psy/protocols/preview', { config: collect() }); out.replaceChildren(issues(r), h('p', { class: 'small muted' }, `Prompt + schema: circa ${r.estimate.estimated_input_tokens} token · modello ${r.estimate.model || 'non selezionato'} · costo input stimato ${r.estimate.input_cost_usd == null ? 'non disponibile (inserire tariffe)' : '$' + r.estimate.input_cost_usd.toFixed(6)} · fino a $${r.estimate.turn_cost_usd_up_to_reserved_output?.toFixed(6) ?? '—'} con riserva di risposta. ${r.estimate.fits ? 'Rientra nel limite locale.' : 'Supera il limite locale: riduci il prompt o verifica il limite del modello.'} Materiali e conversazione non inclusi in questa anteprima. ${r.estimate.note}`), h('pre', { tabindex: '0' }, r.prompt)); }) }, 'Anteprima del prompt'),
      h('button', { class: 'accent', onclick: async () => { try { await save(); const r = await api('POST', `/api/psy/protocols/${pid}/publish`, {}); toast(`Versione ${r.version_no} pubblicata.`); await reload(); editor(pid); } catch (e) { e.detail?.errors ? out.replaceChildren(issues(e.detail)) : toast(errText(e)); } } }, 'Pubblica'),
      h('button', { onclick: () => downloadJson(`ablet-protocollo-${pid}-v${cur.version_no}.json`, { exported_at: new Date().toISOString(), version_no: cur.version_no, config: collect() }) }, 'Esporta JSON')),
    h('div', { class: 'grid g2' },
      h('form', { class: 'card', onsubmit: guard(async (e) => { e.preventDefault(); const r = await api('GET', `/api/psy/protocols/${pid}/diff?a=${e.target.a.value}&b=${e.target.b.value}`);
        out.replaceChildren(h('h3', {}, 'Differenze'), r.changes.length ? h('div', { class: 'scroll' }, h('table', {}, h('thead', {}, h('tr', {}, ['Campo', 'Prima', 'Dopo'].map(x => h('th', {}, x)))), h('tbody', {}, r.changes.map(c => h('tr', {}, h('td', {}, c.path), h('td', {}, c.a ?? '—'), h('td', {}, c.b ?? '—')))))) : h('p', {}, 'Nessuna differenza.')); }) },
        h('h3', {}, 'Confronta versioni'), h('div', { class: 'row' }, vsel('a', d.versions[1]?.version_no ?? cur.version_no), vsel('b', cur.version_no), h('button', { type: 'submit' }, 'Confronta'))),
      h('form', { class: 'card', onsubmit: guard(async (e) => { e.preventDefault(); const f = e.target; out.replaceChildren(h('p', {}, 'Simulazione in corso…'));
        const r = await api('POST', `/api/psy/protocols/${pid}/simulate`, { version_no: +f.v.value, activity_id: +f.a.value, profile: f.p.value });
        out.replaceChildren(h('h3', {}, `Simulazione · stato finale: ${STATE[r.final_state]}`), h('p', { class: 'small muted' }, `Studente sintetico a copione fisso. Adattamenti: ${r.scaffolding.map(a => `${a.rule_key} (${a.outcome})`).join(', ') || 'nessuno'} · errori del provider: ${r.system.provider_errors} · output scartati: ${r.system.invalid_outputs}`),
          h('div', { class: 'msg-log' }, r.messages.map(m => h('div', { class: 'm ' + m.role }, h('span', { class: 'small muted' }, (m.role === 'student' ? 'Studente sintetico' : 'Agente') + ' · ' + m.phase + ': '), m.content)))); }) },
        h('h3', {}, 'Simula con uno studente sintetico'), h('div', { class: 'row' }, vsel('v', cur.version_no), sel(B.activities.filter(a => a.agent_enabled).map(a => [a.id, a.title]), null, { name: 'a', 'aria-label': 'Attività' }), sel(Object.entries(B.simulation_profiles), null, { name: 'p', 'aria-label': 'Profilo' }), h('button', { type: 'submit' }, 'Avvia')),
        h('p', { class: 'small muted' }, 'Salva la bozza prima di simulare. La simulazione usa il provider configurato: in modalità dimostrativa le risposte dell\'agente sono simulate.'))),
    out);
  view.oninput=markDirty; view.onchange=markDirty;
  rbox.addEventListener('click',e=>{if(e.target.closest?.('button'))markDirty();});
}

// ───────────────────────── attività
async function activities() {
  const mode = h('select', { name: 'mode', onchange: () => syncMode() }, h('option', { value: 'prepared' }, 'Attività preimpostata'), h('option', { value: 'free' }, 'Attività libera: compito scelto dallo studente'));
  const syncMode = () => {
    const free = mode.value === 'free';
    for (const name of ['mn', 'ms', 'mt', 'na']) { f.elements[name].disabled = free; }
    f.elements.a.required = !free;
    if (free) { f.elements.na.checked = false; if (!f.elements.t.value.trim()) f.elements.t.value = 'Attività libera'; }
  };
  const f = h('form', { class: 'card', onsubmit: guard(async (e) => { e.preventDefault(); const x = e.target; const free = x.mode.value === 'free'; const r = await api('POST', '/api/psy/activities', { visibility: 'assigned', activity_mode: x.mode.value, title: x.t.value, assignment_text: x.a.value, completion_criteria: x.c.value, expected_minutes: +x.m.value, agent_enabled: free || !x.na.checked, materials: !free && x.mt.value.trim() ? [{ title: x.mn.value || 'Materiale', content_text: x.mt.value, source_note: x.ms.value }] : [] });
    toast(r.material_warnings.length ? 'Attività creata. Attenzione: il materiale contiene possibili istruzioni incorporate (' + r.material_warnings[0].flags.join(', ') + ').' : 'Attività creata.'); await reload(); activities(); }) },
    h('h2', {}, 'Nuova attività'), field('Modalità', mode), h('p',{class:'small'},'Questa sezione prepara il catalogo. Per assegnare attività e protocolli, apri la scheda dello studente nella sezione Studenti.'),
    h('p', { class: 'small muted' }, 'In attività libera lo studente racconta il compito e stima i tempi nel dialogo. Si applica sempre il protocollo pubblicato assegnato allo studente (o al gruppo); senza assegnazione non può iniziare. Il modello usa le proprie conoscenze per guidare il processo SRL, con contenuti da verificare.'),
    h('div', { class: 'grid g2' }, field('Titolo', h('input', { name: 't', required: true })), field('Durata indicativa (minuti)', h('input', { name: 'm', type: 'number', min: 0 }))),
    field('Consegna', h('textarea', { name: 'a', required: true })), field('Criteri di completamento', h('textarea', { name: 'c' })),
    h('div', { class: 'grid g2' }, field('Titolo del materiale', h('input', { name: 'mn' })), field('Fonte', h('input', { name: 'ms' }))), field('Testo del materiale', h('textarea', { name: 'mt', rows: 6 }), 'I paragrafi separati da una riga vuota diventano blocchi di lettura. Il materiale è trattato come dato: eventuali istruzioni al suo interno non vengono eseguite.'),
    h('label', { class: 'inline' }, h('input', { type: 'checkbox', name: 'na' }), 'Attività senza agente (per osservare il lavoro autonomo; non dimostra il transfer)'), h('div', { class: 'row' }, h('button', { class: 'primary', type: 'submit' }, 'Crea attività')));
  clear(view).append(h('h1', {}, 'Attività e materiali'), h('div', { class: 'grid g2' }, B.activities.map(a => h('article', { class: 'card' }, h('h3', {}, a.title), h('p', { class: 'small' }, a.visibility === 'assigned' ? 'Visibile solo agli studenti assegnati' : 'Visibile a tutti i tuoi studenti'), a.activity_mode === 'free' ? h('div',{},h('span', { class: 'chip' }, 'Attività libera'),h('button',{onclick:()=>editFreeActivity(a)},'Modifica caratteristiche')) : null, h('p', {}, a.assignment_text), h('p', { class: 'small muted' }, `Criteri: ${a.completion_criteria || '—'} · ${a.materials} materiali`),
    a.agent_enabled ? null : h('span', { class: 'chip' }, 'senza agente'), +a.flagged ? h('span', { class: 'chip bad' }, 'materiale con possibili istruzioni incorporate') : null))), f);
}

function editFreeActivity(a) {
  const title=h('input',{required:true,maxlength:150,value:a.title});
  const instructions=h('textarea',{required:true,maxlength:4000},a.assignment_text||'');
  const criteria=h('textarea',{maxlength:2000},a.completion_criteria||'');
  const minutes=h('input',{type:'number',min:1,max:600,step:1,value:a.expected_minutes||''});
  const submit=h('button',{class:'primary',type:'submit'},'Salva modifiche');
  clear(view).append(h('button',{class:'ghost',onclick:activities},'← Attività'),h('h1',{},'Modifica Attività Libera'),h('p',{},'Puoi modificare queste caratteristiche anche dopo l’assegnazione. Le modifiche valgono per le nuove sessioni; quelle già create conservano le indicazioni precedenti.'),
    h('form',{class:'card',onsubmit:guard(async e=>{e.preventDefault();submit.disabled=true;try{await api('PUT',`/api/psy/activities/${a.id}`,{title:title.value,assignment_text:instructions.value,completion_criteria:criteria.value,expected_minutes:minutes.value===''?0:Number(minutes.value)});await reload();await activities();toast('Caratteristiche aggiornate. Le sessioni già create sono conservate.');}finally{submit.disabled=false;}})},field('Titolo',title),field('Indicazioni generali per l’attività libera',instructions,'Il compito specifico viene sempre scelto e descritto dallo studente.'),field('Indicazioni per il completamento',criteria),field('Durata indicativa (minuti, facoltativa)',minutes,'È un riferimento: lo studente formula la propria stima nel dialogo.'),h('p',{class:'small'},'L’agente rimane attivo e segue il protocollo assegnato. In modalità libera non si inseriscono materiali preimpostati.'),submit));
}

// ───────────────────────── provider
// Guide informative: fonti ufficiali verificate il 20/11/2026. Disponibilità e condizioni dipendono dall'account.
const LLM_GUIDES = {
 gemini: {name:'Google Gemini',access:'Livello gratuito con limiti per alcuni modelli. Disponibilità e quote vanno controllate nel tuo progetto.',
  account:'https://aistudio.google.com/apikey',pricing:'https://ai.google.dev/gemini-api/docs/pricing',usage:'https://aistudio.google.com/usage',source:'https://ai.google.dev/gemini-api/docs/api-key',
  steps:['Accedi a Google AI Studio con il tuo account Google.','Apri API Keys e crea una chiave nel progetto scelto. Se necessario, importa il progetto.','Controlla nella pagina Usage quali modelli e quote sono disponibili. Copia la chiave e torna qui.'],
  economy:'Per iniziare valuta Gemini 3.5 Flash-Lite, se compare nel tuo elenco. È una scelta orientata al costo contenuto, da provare con il tuo protocollo.',candidates:['gemini-3.5-flash-lite'],
  note:'Il livello gratuito può prevedere l’uso dei contenuti per migliorare i prodotti Google. Per le prove usa dati inventati; verifica le condizioni del tuo progetto prima di inviare dati degli studenti.'},
 openai: {name:'OpenAI (modelli di ChatGPT)',access:'API con fatturazione separata. Plus non include crediti API nel beneficio verificato; alcuni piani Pro e Business idonei possono attivare crediti. Controlla il tuo account.',
  account:'https://platform.openai.com/api-keys',pricing:'https://developers.openai.com/api/docs/pricing',usage:'https://platform.openai.com/usage',source:'https://help.openai.com/en/articles/20001626-api-credits-benefit-for-eligible-chatgpt-subscriptions',
  steps:['Accedi alla piattaforma API di OpenAI con il tuo account.','Controlla crediti e fatturazione del progetto. Se hai un piano idoneo, verifica il beneficio API e attivalo lì.','Apri API keys, crea una chiave segreta per il progetto, copiala e torna qui.'],
  economy:'Valuta GPT-6 Luna, se disponibile nel tuo elenco: è orientato a compiti frequenti a costo contenuto. I modelli mini/nano precedenti possono avere disponibilità diversa: verifica il catalogo.',candidates:['gpt-6-luna'],
  note:'Usare ChatGPT nel sito o nell’app non equivale a collegare l’API. Crediti, limiti e scadenze devono essere verificati nella piattaforma API.'},
 deepseek: {name:'DeepSeek',access:'API a consumo. Non diamo per disponibile una quota gratuita permanente: controlla il saldo e gli eventuali crediti promozionali del tuo account.',
  account:'https://platform.deepseek.com/api_keys',pricing:'https://api-docs.deepseek.com/quick_start/pricing',usage:'https://platform.deepseek.com',source:'https://api-docs.deepseek.com/',
  steps:['Accedi alla piattaforma API di DeepSeek.','Controlla saldo, eventuali crediti e prezzi: la chat gratuita non garantisce API gratuite.','Apri API keys, crea la chiave segreta, copiala e torna qui.'],
  economy:'Valuta deepseek-flash se disponibile. Nel listino consultato costa meno di deepseek-v4-pro. Ragionamento, cache e fascia oraria possono incidere sul costo.',candidates:['deepseek-flash'],
  note:'L’elenco dei modelli arriva dal servizio. Se la prova non riesce, controlla credito e permessi prima di cambiare modello.'},
 anthropic: {name:'Anthropic Claude',access:'La documentazione indica crediti iniziali di prova per i nuovi utenti. Verifica importo e condizioni nel tuo account; non è una quota gratuita illimitata. Alcuni piani idonei offrono benefici API.',
  account:'https://platform.claude.com/settings/keys',pricing:'https://platform.claude.com/docs/en/about-claude/pricing',usage:'https://platform.claude.com/settings/billing',source:'https://platform.claude.com/docs/en/about-claude/pricing',
  steps:['Accedi alla Console di Claude Platform.','Controlla Billing, eventuali crediti iniziali e benefici del tuo piano.','Apri API keys, crea una chiave segreta, copiala e torna qui.'],
  economy:'Valuta Claude Haiku 5.5 se compare nell’elenco. La famiglia Haiku è orientata a rapidità e costo contenuto rispetto ai modelli più grandi. Verifica qualità delle domande e formato della risposta.',candidates:['claude-haiku-5-5'],
  note:'La chat Claude e la piattaforma API hanno configurazioni distinte. I crediti effettivamente utilizzabili sono quelli visibili nella Console.'},
};
function llmSetupState(providers = []) {
 const ordered=providers.slice().sort((a,b)=>Number(!!b.is_default)-Number(!!a.is_default)||Number(a.id)-Number(b.id));
 const current=ordered[0];
 if(!current||current.provider_key==='demo')return {needed:true,reason:'demo',current};
 if(!current.key_mask||!String(current.model_name||'').trim()||!current.capabilities)return {needed:true,reason:'incomplete',current};
 return {needed:false,current};
}
function providerSetupNotice() {
 const state=llmSetupState(B.providers||[]);if(!state.needed)return null;
 return h('section',{class:'banner demo','aria-label':'Completa configurazione assistente IA'},h('h2',{},'Completa la configurazione del tuo assistente IA'),
  h('p',{},state.reason==='demo'?'Il tuo spazio usa ancora risposte simulate. Per un dialogo con un’IA reale collega un servizio personale.':'Il servizio predefinito non è completo: controlla chiave, modello e configurazione.'),
  h('p',{},'Un servizio IA, detto anche provider, è l’azienda che genera le risposte. Ti accompagniamo nella scelta e nel collegamento; non devi conoscere termini tecnici.'),
  h('button',{type:'button',class:'primary',onclick:guard(async()=>{tab='providers';await mount(main);providerWizard();})},'Completa configurazione guidata'),
  h('p',{class:'small'},'Puoi continuare a esplorare ABLET in modalità dimostrativa. Nessun acquisto viene attivato automaticamente.'));
}
function providerGuide(key) {
 const g=LLM_GUIDES[key];
 const link=(label,url)=>h('a',{href:url,target:'_blank',rel:'noopener noreferrer'},label);
 return h('section',{class:'card llm-guide'},h('h3',{},g.name),h('p',{},g.access),h('p',{},g.economy),h('p',{class:'small'},g.note),
  h('div',{class:'row'},link('Account e chiave di collegamento',g.account),link('Prezzi ufficiali',g.pricing),link('Crediti e consumi',g.usage),link('Fonte della guida',g.source)),h('p',{class:'small muted'},'Verifica informativa: 20 novembre 2026. Condizioni e nomi dei modelli possono cambiare.'));
}
function providerFailure(result) {
 if(result.http_status===429||result.status===429||['quota','budget','rate_limit','cooldown'].includes(result.error))return 'Limite o quota raggiunti. Controlla crediti e limiti nel tuo account.'+(result.retry_after?' Attendi almeno '+result.retry_after+' secondi prima di riprovare.':' Non ripetere subito la prova.');
 if([401,403].includes(result.http_status||result.status))return 'La chiave non è autorizzata. Controlla di aver copiato la chiave completa e di avere accesso al modello.';
 if([503,529].includes(result.http_status)||result.error==='unavailable')return 'Il servizio è temporaneamente occupato. Attendi e riprova più tardi.';
 if(result.error==='timeout'||result.error==='rete')return 'Il servizio non ha risposto in tempo o manca la connessione. I dati già salvati restano disponibili.';
 return 'La prova non è riuscita. Controlla il modello, la chiave e il credito nella piattaforma del servizio. '+errText(result);
}
function providerWizard() {
 if(!B.secrets_ready){clear(view).append(h('h1',{},'Collega il tuo assistente IA'),h('p',{role:'alert'},'Il server non è pronto a custodire le chiavi. Chiedi al gestore di ABLET di completare la configurazione; non inviare la chiave in chat o per e-mail.'),h('button',{onclick:providers},'Torna ai servizi'));return;}
 let step=1,service='gemini',configId=null,model='',limit='',testedModel=null,busy=false;
 const steps=['Scegli il servizio','Ottieni la chiave','Scegli il modello','Prova e attiva'];
 const cancel=()=>{model='';testedModel=null;providers();};
 const draw=()=>{
  const heading=h('h1',{tabindex:'-1'},'Collega il tuo assistente IA');
  const g=LLM_GUIDES[service],status=h('p',{role:'status','aria-live':'polite'}),content=h('div',{class:'card llm-wizard-body'});
  const back=h('button',{type:'button',onclick:()=>{if(busy)return;step--;draw();}},'Indietro');back.disabled=step===1;
  clear(view).append(heading,h('p',{},'Passo '+step+' di 4 · '+steps[step-1]),h('p',{class:'small'},'La chiave API è una credenziale segreta: autorizza ABLET a usare il tuo servizio. Non è la password del tuo account. I token sono frammenti di testo elaborati, non euro né parole esatte.'),content,status,
   h('div',{class:'row'},back,h('button',{type:'button',onclick:()=>{if(!busy)cancel();}},'Esci dalla guida')));
  const work=async(button,fn)=>{if(busy)return;busy=true;const controls=Array.from(content.querySelectorAll?.('input, select, button')||[]).map(el=>[el,!!el.disabled]);controls.forEach(([el])=>el.disabled=true);button.disabled=true;back.disabled=true;status.textContent='Operazione in corso…';try{await fn();}catch(e){status.textContent=providerFailure(e);}finally{busy=false;controls.forEach(([el,disabled])=>el.disabled=disabled);button.disabled=false;back.disabled=step===1;}};
  const payload=(extra={})=>({...(configId?{id:configId}:{}),provider_key:service,label:g.name+' · configurazione guidata',model_name:model,monthly_token_limit:limit===''?0:Number(limit),timeout_s:30,max_retries:0,is_default:false,...extra});
  const save=async(extra={})=>{const r=await api('POST','/api/psy/providers',payload(extra));configId=r.provider.id;return r;};
  if(step===1){
   const choice=sel(Object.entries(LLM_GUIDES).map(([k,g])=>[k,g.name]),service,{'aria-label':'Servizio IA',onchange:e=>{service=e.target.value;configId=null;model='';testedModel=null;draw();}});
   content.append(field('Quale servizio vuoi collegare?',choice),providerGuide(service),h('p',{},'Per una prima prova con un livello gratuito, valuta Gemini e controlla la quota nel tuo progetto. La qualità pedagogica va verificata con conversazioni simulate.'),
    h('button',{type:'button',class:'primary',onclick:()=>{step=2;draw();}},'Continua'));
  }else if(step===2){
   const secret=h('input',{type:'password',autocomplete:'new-password',spellcheck:'false','aria-label':'Chiave API personale',placeholder:configId?'Chiave già salvata: lascia vuoto per mantenerla':'Incolla qui la chiave segreta'});
   const acknowledged=h('input',{type:'checkbox','aria-label':'Confermo condizioni e invio al servizio'});
   const button=h('button',{type:'button',class:'primary',onclick:async()=>{
    if(!acknowledged.checked){status.textContent='Prima conferma di aver verificato le condizioni del servizio.';return;}
    if(!configId&&!secret.value.trim()){status.textContent='Incolla la chiave creata nella piattaforma del servizio.';return;}
    await work(button,async()=>{await save(secret.value.trim()?{api_key:secret.value.trim()}:{});secret.value='';testedModel=null;busy=false;step=3;draw();});
   }},'Salva chiave e continua');
   content.append(h('h2',{},'Crea la chiave nel tuo account '+g.name),h('ol',{},g.steps.map(t=>h('li',{},t))),h('a',{href:g.account,target:'_blank',rel:'noopener noreferrer'},'Apri la piattaforma ufficiale'),
    field('Chiave di collegamento (API key)',secret),h('p',{class:'small'},'La chiave viene salvata cifrata sul server. Non la conserviamo nel browser e non viene mostrata agli studenti.'),h('p',{},g.access),h('p',{class:'small'},g.note),
    h('label',{class:'inline'},acknowledged,'Ho verificato le condizioni e gli eventuali costi. So che il servizio riceverà protocollo, materiali e messaggi delle sessioni.'),button);
  }else if(step===3){
   const modelInput=h('input',{value:model,maxlength:120,'aria-label':'Modello IA',oninput:e=>{model=e.target.value;testedModel=null;}}),list=h('div',{});
   const load=h('button',{type:'button',onclick:async()=>{await work(load,async()=>{
    const r=await api('GET',`/api/psy/providers/${configId}/models`);
    if(r.error){status.textContent=providerFailure(r);return;}
    const models=(r.models||[]).filter(m=>typeof m==='string'&&m&&!/(?:embedding|moderation|tts|transcrib|audio|realtime|image|dall-e|sora|whisper|codex|deep-research)/i.test(m));
    if(!models.length){status.textContent='Il servizio non ha restituito modelli. Controlla l’account o inserisci il nome esatto dalla documentazione ufficiale.';return;}
    const sorted=models.slice().sort((a,b)=>Number(g.candidates.includes(b))-Number(g.candidates.includes(a))||a.localeCompare(b));
    const chooseModel=sel([['','Scegli un modello'],...sorted.map(m=>[m,m+(g.candidates.includes(m)?' · candidato economico da provare':'')])],model,{'aria-label':'Modelli disponibili nel tuo account',onchange:e=>{model=e.target.value;modelInput.value=model;testedModel=null;}});
    list.replaceChildren(field('Modelli restituiti dal servizio',chooseModel));status.textContent='Elenco aggiornato. Scegli un modello; la disponibilità nell’elenco non garantisce una quota gratuita.';
   });}},'Carica modelli disponibili');
   const cap=h('input',{type:'number',min:1,step:1,value:limit,'aria-label':'Tetto mensile locale di token',oninput:e=>{limit=e.target.value;}});
   const next=h('button',{type:'button',class:'primary',onclick:async()=>{model=modelInput.value.trim();if(!model){status.textContent='Scegli un modello dall’elenco o inserisci il nome esatto.';return;}if(limit!==''&&(!Number.isSafeInteger(Number(limit))||Number(limit)<1)){status.textContent='Il tetto mensile deve essere un numero intero positivo, oppure lascia il campo vuoto.';return;}await work(next,async()=>{await save();busy=false;step=4;draw();});}},'Salva modello e continua');
   content.append(h('h2',{},'Scegli la versione dell’IA'),h('p',{},g.economy),h('p',{},'I candidati economici sono indicati solo se il servizio li restituisce. Non sono una garanzia di costo minimo o di adeguatezza educativa.'),load,list,field('Nome del modello (puoi anche copiarlo dalla documentazione)',modelInput),
    field('Tetto mensile per questa configurazione, in token (facoltativo)',cap,'Limite locale di ABLET: non è un budget in euro, non comprende altre applicazioni e può essere superato dall’ultima chiamata già partita. Imposta anche i controlli di spesa del servizio.'),
    h('a',{href:g.pricing,target:'_blank',rel:'noopener noreferrer'},'Confronta i prezzi ufficiali prima di scegliere'),h('details',{},h('summary',{},'Come ridurre costi e token senza indebolire la guida SRL'),h('p',{},'Un modello economico costa meno per token, ma non usa necessariamente meno token. Contano lunghezza del protocollo, cronologia, risposta e ragionamento. Nei Protocolli puoi usare «Rendi il prompt più economico» e verificare l’anteprima. Valuta poi i dati in Consumi token, mantenendo tutte le regole educative. Risposte troppo brevi possono interrompere il formato richiesto.')),next);
  }else{
   const consent=h('input',{type:'checkbox','aria-label':'Attiva come servizio predefinito'});
   const activate=h('button',{type:'button',class:'primary',disabled:true,onclick:async()=>{
    if(testedModel!==model||!consent.checked||busy)return;
    await work(activate,async()=>{await save({is_default:true});await reload();busy=false;await providers();toast('Assistente IA configurato e impostato come predefinito. Prova ora una sessione dimostrativa.');});
   }},'Attiva per il mio spazio');
   consent.addEventListener('change',()=>{activate.disabled=testedModel!==model||!consent.checked||busy;});
   const test=h('button',{type:'button',onclick:async()=>{
    testedModel=null;activate.disabled=true;
    await work(test,async()=>{const r=await api('POST',`/api/psy/providers/${configId}/test`,{});
     if(r.ok&&r.json_valid&&!r.simulated){testedModel=model;status.textContent='Collegamento riuscito e formato della risposta valido. Puoi attivare il servizio. La prova non certifica la qualità pedagogica né le quote future.';}
     else status.textContent=r.ok?'La risposta non ha il formato richiesto. Scegli un altro modello o riprova dopo aver controllato la configurazione.':providerFailure(r);
    });activate.disabled=testedModel!==model||!consent.checked;
   }},'Prova il collegamento');
   content.append(h('h2',{},'Prova prima di attivare'),h('p',{},g.name+' · modello '+model),h('p',{},'La prova invia un messaggio tecnico, senza dati degli studenti. Può consumare token e credito del tuo servizio.'),test,
    h('label',{class:'inline'},consent,'Voglio usare questo servizio come predefinito per il mio spazio.'),activate,h('p',{class:'small'},'L’attivazione non modifica i protocolli che selezionano esplicitamente un’altra configurazione. Il livello gratuito può esaurirsi: controlla quote e consumi.'));
  }
  heading.focus?.();
 };
 draw();
}

async function providers() {
  const NAMES = { demo: 'Modalità dimostrativa (nessun servizio esterno)', gemini: 'Google Gemini', openai: 'OpenAI (ChatGPT)', anthropic: 'Anthropic', deepseek: 'DeepSeek', openai_compatible: 'Endpoint compatibile con «chat completions»' };
  const form = (p = {}) => { const models = h('datalist', { id: 'ml' + (p.id || 'n') });
    const testResult=h('div',{class:'banner',role:'status','aria-live':'polite'},p.id?`La prova usa la configurazione salvata: ${p.provider_key} · ${p.model_name || 'modello non indicato'}. Salva prima eventuali modifiche.`:'Salva la configurazione prima di provarla.');
    const f = h('form', { class: 'card', onsubmit: guard(async (e) => { e.preventDefault(); const x = e.target;
      await api('POST', '/api/psy/providers', { id: p.id, provider_key: x.k.value, label: x.l.value, base_url: x.u.value, model_name: x.m.value, api_key: x.key.value, monthly_token_limit: +x.lim.value, timeout_s: +x.to.value, max_retries: +x.re.value, is_default: x.d.checked, fallback_config_id: x.fb.value ? +x.fb.value : null, ack_fallback: x.ack.checked });
      toast('Configurazione salvata.'); await reload(); providers(); }) },
      h('h3', {}, p.id ? p.label : 'Nuova configurazione'),
      h('div', { class: 'grid g3' }, field('Fornitore', sel(Object.entries(NAMES), p.provider_key || 'gemini', { name: 'k' })), field('Etichetta', h('input', { name: 'l', value: p.label || '', required: true })),
        field('Modello', h('input', { name: 'm', value: p.model_name || '', list: models.id }), 'Usa «Carica modelli» dopo aver salvato la chiave: l\'elenco arriva dal fornitore.'),
        field('Chiave API', h('input', { name: 'key', type: 'password', autocomplete: 'off', placeholder: p.key_mask || '' }), p.key_mask ? 'Lascia vuoto per mantenere la chiave salvata.' : 'Cifrata sul server; non viene mai restituita al browser.'),
        field('Indirizzo dell\'endpoint (facoltativo)', h('input', { name: 'u', value: p.base_url || '', placeholder: 'https://…' }), 'Obbligatorio solo per gli endpoint compatibili.'), field('Tetto mensile di token', h('input', { name: 'lim', type: 'number', min: 0, value: p.monthly_token_limit || '' }), 'Raggiunto il tetto, le chiamate si fermano.'),
        field('Timeout (s)', h('input', { name: 'to', type: 'number', min: 3, max: 120, value: p.timeout_s || 30 })), field('Nuovi tentativi', h('input', { name: 're', type: 'number', min: 0, max: 3, value: p.max_retries ?? 1 })),
        field('Fallback su un altro fornitore', sel([['', 'Nessuno'], ...B.providers.filter(x => x.id !== p.id).map(x => [x.id, x.label])], p.fallback_config_id, { name: 'fb' }))),
      h('label', { class: 'inline' }, h('input', { type: 'checkbox', name: 'ack' }), 'Confermo che il fallback invia i dati della sessione a un fornitore diverso'), h('label', { class: 'inline' }, h('input', { type: 'checkbox', name: 'd', checked: !!p.is_default }), 'Usa come predefinito'), models,
      p.capabilities ? h('p', { class: 'small muted' }, `Capacità dichiarate — output strutturato: ${p.capabilities.structured_output}; streaming: no (le risposte sono mostrate solo dopo i controlli); voce: gestita dal browser.`) : null,
      h('div', { class: 'row' }, h('button', { class: 'primary', type: 'submit' }, 'Salva'),
        p.id ? [h('button', { type: 'button', onclick: async e => {
            const button=e.currentTarget;if(button.disabled)return;button.disabled=true;button.textContent='Prova in corso…';
            testResult.textContent=`Richiesta al server per ${p.provider_key} · ${p.model_name || 'modello non indicato'}…`;
            try{const r=await api('POST', `/api/psy/providers/${p.id}/test`, {});
              testResult.textContent=r.ok?`Connessione riuscita${r.simulated?' (simulata)':''}. Risposta JSON ${r.json_valid?'valida':'non valida'}. Modello salvato: ${p.model_name||'—'}.`:`Connessione non riuscita. Tipo: ${r.error||'non specificato'}${r.http_status?' · HTTP '+r.http_status:''}. ${r.detail||'Nessun dettaglio restituito.'}${r.retry_after?' Attendi almeno '+r.retry_after+' secondi prima di riprovare.':''}`;
            }catch(error){testResult.textContent=`La prova non è stata completata: ${errText(error)}${error.status?' · HTTP '+error.status:''}`;}
            finally{button.disabled=false;button.textContent='Prova la connessione';}
          } }, 'Prova la connessione'),
          h('button', { type: 'button', onclick: guard(async () => { const r = await api('GET', `/api/psy/providers/${p.id}/models`); models.replaceChildren(...r.models.map(m => h('option', { value: m })));
            // Elenco visibile in tutti i browser: la scelta copia il nome nel campo Modello.
            const inp = f.elements.m, pick = h('select', { 'aria-label': 'Modelli disponibili', onchange: () => { if (pick.value) { inp.value = pick.value; toast('Modello scelto: premi Salva.'); } } },
              h('option', { value: '' }, `Scegli tra ${r.models.length} modelli…`), ...r.models.map(m => h('option', { value: m, selected: m === inp.value }, m)));
            inp.parentNode.querySelector('select[aria-label="Modelli disponibili"]')?.remove(); inp.after(pick); toast(r.models.length ? `${r.models.length} modelli disponibili: scegli dal campo Modello.` : 'Nessun modello restituito' + (r.error ? ` (${r.error}${r.detail ? ' — ' + r.detail : ''})` : '') + '.'); }) }, 'Carica modelli'),
          h('button', { type: 'button', class: 'danger', onclick: guard(async () => { if (!confirm('Rimuovere la configurazione e la chiave salvata?')) return; await api('DELETE', `/api/psy/providers/${p.id}`); await reload(); providers(); }) }, 'Rimuovi')] : null),testResult);
    return f; };
  clear(view).append(h('h1', {}, 'Il tuo assistente IA'),
    h('p',{},'Scegli il servizio che genera le risposte di ABLET. La guida spiega account, chiavi, modelli e costi con parole semplici.'),
    h('p',{role:'status'},llmSetupState(B.providers||[]).needed?'Configurazione da completare: le risposte predefinite sono simulate o il collegamento non è completo.':'Servizio predefinito: '+llmSetupState(B.providers||[]).current.label+' · '+llmSetupState(B.providers||[]).current.model_name),
    h('button',{type:'button',class:'primary',onclick:providerWizard},'Avvia configurazione guidata'),
    h('details',{},h('summary',{},'Guide ai servizi, gratuità e modelli economici'),Object.keys(LLM_GUIDES).map(providerGuide)),
    B.secrets_ready ? null : h('div', { class: 'banner safe' }, 'APP_SECRET_KEY non è impostata sul server: non è possibile salvare chiavi API. Resta disponibile la modalità dimostrativa.'),
    h('p', { class: 'muted' }, 'Le chiamate partono solo dal server. Al fornitore sono inviati il protocollo, lo stato della sessione, i materiali dell\'attività e i messaggi: mai nomi, codici di accesso o alias. Verifica condizioni d\'uso e trattamento dei dati del fornitore scelto prima di usarlo con studenti reali.'),
    h('details',{class:'card'},h('summary',{},'Configurazione manuale e avanzata'),B.providers.map(form), form()));
}

// ───────────────────────── voce e avatar
async function voice() {
  const v = { input_enabled: true, output_enabled: true, autoplay: false, language: 'it-IT', rate: 1, pitch: 1, voice_name: '', ...(B.settings.voice || {}) }, a = { enabled: false, name: 'Ablet', style: 'astratto', color: '#2f6f6a', ...(B.settings.avatar || {}) };
  const prev = h('div', { class: 'row' });
  let av = null;
  const status = h('p', { class: 'small', role: 'status', 'aria-live': 'polite' });
  const play = h('button', { type: 'button' }, '▶ Prova la voce');
  const stop = h('button', { type: 'button', disabled: true }, '■ Ferma');
  const onSpeechState = on => {
    av?.setSpeaking(on); play.disabled = on; play.setAttribute('aria-busy', String(on)); stop.disabled = !on;
    play.textContent = on ? 'Voce in riproduzione…' : '▶ Prova la voce';
  };
  let tts = null, testMic = null;
  const micTest = h('button', {type:'button'}, '🎤 Prova microfono');
  micTest.addEventListener('click', () => {
    if(testMic?.listening) { testMic.stopListening(); return; }
    tts?.stopSpeaking();
    testMic = createVoice({...v,input_enabled:true,output_enabled:true,preview:{...v}},()=>{});
    micTest.textContent='■ Termina registrazione'; status.textContent='Parla: la trascrizione sarà mostrata qui senza inviarla a una sessione.';
    testMic.listen({onProcessing:()=>{micTest.disabled=true;status.textContent='Trascrizione in corso…';},onText:(text,final)=>{if(final)status.textContent='Hai detto: '+(text||'(nessuna parola riconosciuta)');},onError:message=>{status.textContent=message;},onEnd:()=>{micTest.disabled=false;micTest.textContent='🎤 Prova microfono';}});
  });
  // La prova è disponibile anche quando la lettura per gli studenti è disattivata.
  play.addEventListener('click', () => {
    if (tts?.speaking) return;
    testMic?.abortListening(); micTest.textContent='🎤 Prova microfono'; micTest.disabled=false;
    tts = createVoice({ ...v, output_enabled: true, preview: { ...v } }, onSpeechState);
    status.textContent = 'Avvio della voce…'; tts.unlock();
    tts.speak(`Ciao, sono ${a.name}, un sistema di intelligenza artificiale che ti aiuta a organizzare lo studio.`, {
      onEnd: () => { status.textContent = 'Prova della voce terminata.'; },
      onError: message => { status.textContent = message; toast(message); },
    });
  });
  stop.addEventListener('click', () => { tts?.stopSpeaking(); status.textContent = 'Riproduzione fermata.'; });
  const draw = () => { av = avatar(a); prev.replaceChildren(av.el, h('div', {}, h('strong', {}, a.name), h('div', { class: 'small muted' }, a.enabled ? 'Avatar attivo nella schermata dello studente' : 'Avatar disattivato'))); };
  const names = () => voiceNames().filter(x => x.lang.startsWith(v.language.slice(0, 2)));
  const vs = h('select', { onchange: (e) => { v.voice_name = e.target.value; } });
  const fill = () => vs.replaceChildren(h('option', { value: '' }, 'Voce predefinita del dispositivo'), ...names().map(n => h('option', { value: n.name, selected: n.name === v.voice_name }, `${n.name} (${n.lang}${n.local ? ', sul dispositivo' : ', servizio in rete'})`)));
  fill(); if (voiceSupport.output) speechSynthesis.addEventListener('voiceschanged', fill);
  voiceCleanup = () => { testMic?.abortListening(); tts?.stopSpeaking(); if (voiceSupport.output) speechSynthesis.removeEventListener('voiceschanged', fill); };
  const chk = (o, k, label) => h('label', { class: 'inline' }, h('input', { type: 'checkbox', checked: !!o[k], onchange: (e) => { o[k] = e.target.checked; draw(); } }), label);
  const rng = (k, label) => { const out = h('span', {}, String(v[k])); return field(label, h('input', { type: 'range', min: 0.5, max: 1.6, step: 0.1, value: v[k], oninput: (e) => { v[k] = +e.target.value; out.textContent = e.target.value; } }), out); };
  draw();
  clear(view).append(h('h1', {}, 'Voce e avatar'), h('div', { class: 'grid g2' },
    h('section', { class: 'card' }, h('h2', {}, 'Voce'),
      field('Motore vocale', sel([['browser','Voce del browser'],['server','Audio tramite server e provider']], v.engine || 'browser', {onchange:e=>{tts?.stopSpeaking();v.engine=e.target.value;}})),
      field('Provider audio (se usi il server)', sel([['','Scegli il provider'],...B.providers.filter(p=>['gemini','openai','openai_compatible'].includes(p.provider_key)).map(p=>[p.id,p.label+' · '+p.provider_key])],v.provider_config_id,{onchange:e=>{v.provider_config_id=+e.target.value;}})),
      field('Modello di sintesi TTS (server)', h('input',{name:'tts_model',value:v.tts_model||'',placeholder:'Modello audio TTS del provider',oninput:e=>{v.tts_model=e.target.value;}})),
      field('Modello di trascrizione (server)', h('input',{name:'stt_model',value:v.stt_model||'',placeholder:'Modello che accetta audio in ingresso',oninput:e=>{v.stt_model=e.target.value;}})),
      field('Nome della voce del provider (server)',h('input',{name:'server_voice',value:v.server_voice||'',placeholder:'Esempio: Kore per Gemini, alloy per OpenAI',oninput:e=>{v.server_voice=e.target.value;}})),
      h('button',{type:'button',onclick:()=>{const provider=B.providers.find(p=>+p.id===+v.provider_config_id);if(provider?.provider_key!=='gemini'){toast('Seleziona un provider Gemini per usare questo esempio.');return;}v.tts_model='gemini-3.8-flash-tts';v.stt_model='gemini-2.5-flash';v.server_voice='Kore';for(const key of ['tts_model','stt_model','server_voice'])view.querySelector(`[name=${key}]`).value=v[key];toast('Esempio inserito. Verifica disponibilità e quote con Prova la voce e Prova microfono.');}},'Inserisci esempio Gemini'),
      h('p',{class:'small'},'La modalità server usa la chiave già salvata nel provider. Per lo studente registra anche l’autorizzazione Invio temporaneo dell’audio al provider. Audio e trascrizione possono avere costi separati dal modello testuale.'),
      h('details',{},h('summary',{},'Diagnostica audio del dispositivo'),h('a',{href:`${BASE}/tts-check.html`,target:'_blank',rel:'noopener'},'Apri diagnosi TTS Chrome (senza microfono)'),h('p',{class:'small'},`Connessione sicura: ${window.isSecureContext?'sì':'no'}. Sintesi browser: ${voiceSupport.output?'disponibile':'non disponibile'}. Riconoscimento browser: ${voiceSupport.input?'disponibile':'non disponibile'}. Registrazione server: ${!!navigator.mediaDevices?.getUserMedia&&typeof MediaRecorder!=='undefined'?'disponibile':'non disponibile'}.`),h('p',{class:'small'},'Se la voce del browser non funziona, prova in Chrome o Safari esterno, verifica volume multimediale e Bluetooth, oppure configura il motore server. Usa Prova la voce per controllare il percorso scelto.')),
      chk(v, 'input_enabled', 'Consenti la dettatura vocale (con trascrizione modificabile prima dell\'invio)'), chk(v, 'output_enabled', 'Consenti la lettura ad alta voce delle risposte'), chk(v, 'autoplay', 'Lettura automatica delle risposte attiva all\'avvio'),
      field('Lingua', sel([['it-IT', 'Italiano'], ['en-GB', 'Inglese'], ['es-ES', 'Spagnolo'], ['fr-FR', 'Francese'], ['de-DE', 'Tedesco']], v.language, { onchange: (e) => { v.language = e.target.value; fill(); } })),
      field('Voce preferita', vs, 'Le voci dipendono dal dispositivo: se quella scelta non è presente sul dispositivo dello studente si usa la voce predefinita per la lingua.'), rng('rate', 'Velocità'), rng('pitch', 'Tono'),
      h('div', { class: 'row' }, play, stop, micTest), status,
      h('p', { class: 'small muted' }, VOICE_NOTICE + ' Il testo resta sempre disponibile come alternativa; con rete lenta la lettura automatica viene disattivata.')),
    h('section', { class: 'card' }, h('h2', {}, 'Avatar che parla'), prev, chk(a, 'enabled', 'Mostra l\'avatar allo studente'),
      field('Nome', h('input', { value: a.name, maxlength: 30, oninput: (e) => { a.name = e.target.value; draw(); } })), field('Aspetto', sel([['astratto', 'Forma astratta'], ['volto', 'Volto stilizzato'], ['robot', 'Robot']], a.style, { onchange: (e) => { a.style = e.target.value; draw(); } })),
      field('Colore', h('input', { type: 'color', value: a.color, oninput: (e) => { a.color = e.target.value; draw(); } })),
      h('p', { class: 'small muted' }, 'L\'avatar è volutamente stilizzato: l\'agente si presenta come sistema di intelligenza artificiale e non simula una persona. La bocca si anima durante la lettura ad alta voce; con «Meno animazioni» resta ferma.'))),
    h('div', { class: 'row' }, h('button', { class: 'primary', onclick: guard(async () => { const r = await api('PUT', '/api/psy/settings', { voice: v, avatar: a, retention: B.settings.retention || {} }); B.settings = r.settings; toast('Impostazioni salvate.'); }) }, 'Salva impostazioni')));
}

// ───────────────────────── validazione delle codifiche
function agreementTable(r) {
  const codes = B.codebook.content.codes;
  return h('div', {},
    h('p', {}, `${r.reference} · messaggi confrontati: ${r.n_messages} · kappa medio sui codici calcolabili: ${nd(r.mean_kappa)}`),
    h('div', { class: 'scroll' }, h('table', {}, h('thead', {}, h('tr', {}, ['Codice', 'Kappa', 'Accordo %', 'Positivi A', 'Positivi B', 'Solo A', 'Solo B', 'Precisione B', 'Richiamo B', 'Nota'].map(t => h('th', {}, t)))),
      h('tbody', {}, codes.map(c => { const k = r.codes[c.key]; return h('tr', {}, h('td', {}, `${c.key} · ${c.label}`), h('td', {}, nd(k.kappa)), h('td', {}, k.agreement == null ? '—' : Math.round(k.agreement * 100)),
        h('td', {}, k.pos_a), h('td', {}, k.pos_b), h('td', {}, k.only_a ?? 0), h('td', {}, k.only_b ?? 0), h('td', {}, nd(k.precision)), h('td', {}, nd(k.recall)), h('td', { class: 'small muted' }, k.note || '')); })))),
    barChart({ title: 'Kappa di Cohen per codice', labels: codes.map(c => c.key), series: [{ name: 'kappa', values: codes.map(c => r.codes[c.key].kappa) }], min: -1, max: 1 }),
    h('div', { class: 'row' }, h('button', { onclick: () => downloadJson('ablet-accordo.json', r) }, 'Esporta risultati (JSON)')));
}

async function coding() {
  const out = h('div', {}), mout = h('div', {});
  clear(view).append(h('h1', {}, 'Validazione delle codifiche'),
    h('p', { class: 'muted' }, 'Protocollo: il professionista rivede le proposte automatiche (riferimento A); un secondo codificatore codifica alla cieca lo stesso campione; si confrontano A e B per codice. Si confrontano anche le proposte automatiche con la revisione umana. Sono inclusi solo i messaggi dello studente completamente revisionati.'),
    h('section', { class: 'card' }, h('h2', {}, '1 · Esporta il campione per il secondo codificatore'),
      h('form', { class: 'row', onsubmit: guard(async (e) => { e.preventDefault(); const f = e.target;
        const r = await api('GET', `/api/psy/coding/export?per_phase=${+f.n.value || 0}&seed=${+f.s.value || 1}&reviewed_only=${f.r.checked ? 1 : ''}`);
        if (!r.rows) return toast('Nessun messaggio da esportare con questi criteri.');
        downloadText(`ablet-campione-codifica-seed${f.s.value}.csv`, r.csv, 'text/csv'); toast(`${r.rows} messaggi esportati.`); }) },
        field('Messaggi per fase (0 = tutti)', h('input', { name: 'n', type: 'number', min: 0, max: 500, value: 30 })), field('Seme casuale', h('input', { name: 's', type: 'number', min: 1, value: 1 }), 'Lo stesso seme riproduce lo stesso campione.'),
        h('label', { class: 'inline' }, h('input', { type: 'checkbox', name: 'r', checked: true }), 'Solo messaggi già revisionati da me'), h('button', { class: 'primary', type: 'submit' }, 'Scarica CSV')),
      h('p', { class: 'small muted' }, 'Il file non contiene codici, nomi né identificativi diversi dal numero del messaggio. Il codificatore scrive nella colonna «codici» le sigle del codebook separate da | (es. PIA|STR), lasciandola vuota se nessun codice si applica. Le celle che iniziano con = + - @ sono protette dall\'interpretazione come formule.')),
    h('section', { class: 'card' }, h('h2', {}, '2 · Carica le codifiche del secondo codificatore'),
      h('input', { type: 'file', accept: '.csv,text/csv', 'aria-label': 'File CSV del secondo codificatore', onchange: guard(async (e) => { const f = e.target.files[0]; if (!f) return;
        const r = await api('POST', '/api/psy/coding/agreement', { csv: await f.text() });
        out.replaceChildren(agreementTable(r), r.unknown_codes.length ? h('p', { class: 'banner demo' }, 'Codici non presenti nel codebook, ignorati: ' + r.unknown_codes.join(', ')) : null,
          r.excluded_not_reviewed_or_not_owned ? h('p', { class: 'small muted' }, `${r.excluded_not_reviewed_or_not_owned} righe escluse (messaggi non revisionati o non appartenenti alle tue sessioni).`) : null,
          r.disagreements.length ? h('details', {}, h('summary', {}, `Disaccordi (${r.disagreements.length})`), h('table', {}, h('thead', {}, h('tr', {}, ['Messaggio', 'A', 'B'].map(t => h('th', {}, t)))),
            h('tbody', {}, r.disagreements.map(x => h('tr', {}, h('td', {}, x.message_id), h('td', {}, x.a.join(' | ') || '—'), h('td', {}, x.b.join(' | ') || '—')))))) : null); }) }), out),
    h('section', { class: 'card' }, h('h2', {}, 'Proposte automatiche e revisione umana'),
      h('button', { onclick: guard(async () => mout.replaceChildren(agreementTable(await api('GET', '/api/psy/coding/model-agreement')))) }, 'Calcola'), mout,
      h('p', { class: 'small muted' }, 'Kappa per dati dicotomici (presenza/assenza del codice nel messaggio), senza intervalli di confidenza; con meno di 5 casi positivi è instabile. Precisione e richiamo di B sono calcolati rispetto ad A. Per la pubblicazione esportare i dati e usare un software statistico (es. alpha di Krippendorff per più codificatori).')));
}

// ───────────────────────── dati e autorizzazioni
async function privacy() {
  const p = B.privacy, r = B.settings.retention || { messages_days: 365, technical_logs_days: 30 };
  const prev = h('div', {});
  const showPreview = async () => { const x = await api('GET', '/api/psy/retention/preview');
    prev.replaceChildren(h('p', {}, `Con le impostazioni salvate verrebbero cancellate ora: ${x.sessions} sessioni, ${x.simulations} simulazioni, ${x.llm_calls} righe del registro tecnico.`),
      x.sessions + x.simulations + x.llm_calls ? h('button', { class: 'danger', onclick: guard(async () => { if (!confirm('Cancellare ora definitivamente questi dati?')) return; const y = await api('POST', '/api/psy/retention/run'); toast(`Cancellati: ${y.sessions} sessioni, ${y.simulations} simulazioni, ${y.llm_calls} righe tecniche.`); showPreview(); }) }, 'Applica ora') : null); };
  clear(view).append(h('h1', {}, 'Dati e autorizzazioni'),
    h('p', { class: 'muted' }, 'ABLET non stabilisce quali autorizzazioni servano, né per quanto tempo conservare i dati: sono decisioni del titolare del trattamento. Qui si applicano le scelte fatte.'),
    h('form', { class: 'card', onsubmit: guard(async (e) => { e.preventDefault(); const f = e.target;
      const x = await api('PUT', '/api/psy/privacy', { require_consents: f.rc.checked, notice_version: f.nv.value, retention: { messages_days: +f.md.value, technical_logs_days: +f.td.value } });
      B.privacy = x.privacy; B.settings.retention = x.retention; toast('Impostazioni salvate.'); showPreview(); }) },
      h('h2', {}, 'Autorizzazioni'),
      h('label', { class: 'inline' }, h('input', { type: 'checkbox', name: 'rc', checked: !!p.require_consents }), 'Richiedi le autorizzazioni registrate prima dell\'uso'),
      h('ul', { class: 'small muted' }, h('li', {}, 'avvio delle sessioni: informativa e uso della piattaforma;'), h('li', {}, 'risposte dell\'agente con un fornitore esterno: invio dei testi al fornitore (senza, l\'agente non risponde e lo studente vede un avviso);'), h('li', {}, 'dettatura e lettura ad alta voce: funzioni vocali.')),
      field('Versione corrente dell\'informativa', h('input', { name: 'nv', value: p.notice_version, maxlength: 40 }), 'Proposta come predefinita quando registri un\'autorizzazione.'),
      h('h2', {}, 'Conservazione'),
      h('div', { class: 'grid g3' }, field('Sessioni e messaggi (giorni)', h('input', { name: 'md', type: 'number', min: 30, max: 3650, value: r.messages_days }), 'Minimo 30. Le simulazioni durano 30 giorni.'),
        field('Registro tecnico delle chiamate (giorni)', h('input', { name: 'td', type: 'number', min: 7, max: 365, value: r.technical_logs_days }))),
      h('p', { class: 'small muted' }, 'La cancellazione avviene automaticamente al più una volta al giorno, quando accedi a quest\'area (non serve un cron). Il registro di audit non viene cancellato automaticamente.'),
      h('button', { class: 'primary', type: 'submit' }, 'Salva')),
    h('section', { class: 'card' }, h('h2', {}, 'Anteprima della cancellazione'), prev),
    h('section', { class: 'card' }, h('h2', {}, 'Modello di informativa'), h('p', {}, 'Bozza da adattare con il titolare del trattamento: descrive che cosa registra ABLET, chi elabora i dati (server, fornitore di IA, funzioni vocali del browser) e che cosa la piattaforma non fa.'),
      h('button', { onclick: guard(async () => downloadText('ablet-modello-informativa.md', (await api('GET', '/api/psy/privacy/notice-template')).text, 'text/markdown')) }, 'Scarica il modello (Markdown)')));
  showPreview().catch(() => {});
}

async function manual() { clear(view).append(h('h1',{},'Manuale e tutorial'),h('p',{},'Guida completa alle tre fasi SRL, protocolli, attività assegnate agli studenti reali, voce, costi e gestione dei dati.'),h('a',{class:'access-card',href:`${BASE}/manual.php`,target:'_blank',rel:'noopener'},'Apri il manuale dettagliato e il tutorial passo passo')); }

// Profilo dell'account autenticato: nessun identificatore altrui nel percorso.
async function account() {
  const r=await api('GET','/api/psy/account'),p=r.profile;
  const name=h('input',{value:p.full_name,required:true,maxlength:80,autocomplete:'name'});
  const profession=sel([['educator','Educatore / educatrice'],['psychologist','Psicologo / psicologa']],p.profession);
  const organization=h('input',{value:p.organization||'',maxlength:160,autocomplete:'organization'});
  const biography=h('textarea',{maxlength:2000});biography.value=p.biography||'';
  const status=h('p',{role:'status'}),save=h('button',{type:'submit',class:'primary'},'Salva profilo');
  const form=h('form',{class:'card',onsubmit:async e=>{e.preventDefault();save.disabled=true;status.textContent='';try{await api('PUT','/api/psy/account',{full_name:name.value,profession:profession.value,organization:organization.value,biography:biography.value});status.textContent='Profilo aggiornato. Il nome nella barra si aggiornerà al prossimo accesso.';}catch(x){status.textContent=errText(x);}finally{save.disabled=false;}}},
    field('Nome e cognome',name),field('Professione dichiarata',profession),field('Organizzazione (facoltativa)',organization),field('Presentazione professionale (facoltativa)',biography),status,save);
  clear(view).append(h('h1',{},'Il mio profilo'),h('p',{},'Il profilo è privato e appartiene al tuo account. Non viene pubblicato in un elenco di professionisti. La qualifica è autodichiarata e non verificata da ABLET.'),h('p',{class:'small muted'},'Codice di accesso: '+r.login),form, S.user?.role==='admin'?null:pinAccountCard(),
    h('details',{class:'card'},h('summary',{},'Attivazione del primo super admin · titolare dell’installazione'),h('p',{},'Richiede la chiave INSTALL_KEY di config.php. Disponibile soltanto finché non esiste un amministratore. Il tuo account conserva il proprio spazio professionale.'),
      (()=>{const key=h('input',{type:'password',autocomplete:'off','aria-label':'Chiave di installazione'});return h('form',{onsubmit:async e=>{e.preventDefault();try{await api('POST','/api/psy/activate-admin',{owner_key:key.value});S.user=null;await boot();}catch(x){toast(errText(x));}finally{key.value='';}}},key,h('button',{type:'submit'},'Attiva il mio account come super admin'));})()));
}

function pinAccountCard(){
 const password=h('input',{type:'password',autocomplete:'current-password',required:true,'aria-label':'Password attuale'}),result=h('p',{role:'status'});
 const generate=h('button',{type:'submit',class:'primary'},'Genera nuovo PIN'),revoke=h('button',{type:'button',onclick:()=>manage('DELETE')},'Disattiva PIN');
 async function manage(method){generate.disabled=true;revoke.disabled=true;result.textContent='';try{const r=await api(method,'/api/psy/account/pin',{password:password.value});result.textContent=method==='POST'?`Il tuo PIN: ${r.pin}. Mostrato una sola volta. Conservalo in privato. Il PIN precedente non funziona più.`:'PIN disattivato. Puoi accedere con e-mail e password.';}catch(e){result.textContent=errText(e);}finally{password.value='';generate.disabled=false;revoke.disabled=false;}}
 return h('form',{class:'card',onsubmit:e=>{e.preventDefault();manage('POST');}},h('h2',{},'Accesso con il solo PIN'),h('p',{},'Per accedere digitando soltanto 6 cifre, genera un PIN e conservalo in privato. Chi lo conosce può accedere al tuo account. La password resta disponibile per il recupero. Per generare o disattivare il PIN, conferma la password attuale.'),field('Password attuale',password),result,h('div',{class:'row'},generate,revoke));
}
