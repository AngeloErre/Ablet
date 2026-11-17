// Test del rendering dei pannelli con DOM minimo: non sostituisce le prove visive su tablet.
const fs=require('node:fs'),path=require('node:path'),vm=require('node:vm'),assert=require('node:assert/strict');
const rootDir=path.join(__dirname,'..');let pass=0;const check=(name,ok)=>{assert(ok,name);console.log('✓ '+name);pass++;};
class El{constructor(tag,text=''){this.tag=tag;this.text=text;this.children=[];this.attrs={};this.events={};this.nodeType=1;this.style={setProperty(){}};this.classList={toggle(){},add(){},remove(){}};}setAttribute(k,v){this.attrs[k]=v;}addEventListener(k,fn){this.events[k]=fn;}append(...xs){this.children.push(...xs);}replaceChildren(...xs){this.children=xs;}}
const all=e=>[e,...e.children.flatMap(all)];const text=e=>(e.textContent??e.text)+e.children.map(text).join(' ');
const root=new El('div'),html=new El('html');html.dataset={base:'/ablet'};
const template=JSON.parse(fs.readFileSync(path.join(rootDir,'resources/templates/base_piano.json')));
const templates={};for(const file of fs.readdirSync(path.join(rootDir,'resources/templates')).filter(x=>x.endsWith('.json'))){const c=JSON.parse(fs.readFileSync(path.join(rootDir,'resources/templates',file)));templates[c.template_key]=c;}
const document={documentElement:html,body:new El('body'),getElementById:()=>root,createElement:tag=>new El(tag),createTextNode:v=>new El('#text',String(v))};
const context=vm.createContext({document,localStorage:{getItem:()=>null,setItem(){}},location:{search:''},URLSearchParams,navigator:{},window:{isSecureContext:true},structuredClone,setTimeout,clearTimeout,addEventListener(){},console,
 createVoice:()=>({speaking:false,stopSpeaking(){},listen(){}}),avatar:()=>({el:new El('svg'),setSpeaking(){}}),voiceNames:()=>[],voiceSupport:{output:false,input:false},VOICE_NOTICE:'Nota vocale',
 barChart:()=>new El('div'),heatmap:()=>new El('div'),downloadJson(){},downloadText(){}});
const clean=s=>s.replace(/^import .*;$/mg,'').replace(/^export /mg,'').replace(/^boot\(\)\.catch.*$/m,'');
vm.runInContext(clean(fs.readFileSync(path.join(rootDir,'public/assets/app.js'),'utf8')),context);
vm.runInContext(clean(fs.readFileSync(path.join(rootDir,'public/assets/psy.js'),'utf8')),context);
context.data={templates,students:[{id:1,display_alias:'Studente reale'}],protocols:[{id:1,title:'Personale',latest:1,published:1}],activities:[{id:1,title:'Libera',agent_enabled:true,visibility:'assigned',activity_mode:'free'}],providers:[],settings:{},groups:[],archived_protocols:[],published_versions:[{id:9,title:'Personale',version_no:1}],activity_assignments:[],simulation_profiles:{},rule_vocabulary:{indicators:['consecutive_steps_not_completed'],actions:['reduce_step_size']}};
context.response={protocol:{id:1,title:'Personale'},versions:[{id:9,version_no:1,status:'published',sessions:0,config:template}]};
vm.runInContext('B=data;view=document.createElement("div");api=async()=>response;',context);
const nodes=()=>all(vm.runInContext('view',context));
(async()=>{
 await context.protocols();check('Lista protocolli mostra il percorso operativo SRL',nodes().some(e=>e.tag==='h2'&&text(e).includes('ciclo di autoregolazione')));
 for(const title of ['Pianificazione e autoregolazione','Comprensione del testo'])check('Etichetta protetta per '+title,nodes().some(e=>e.tag==='article'&&text(e).includes(title)&&text(e).includes('Modello di base protetto')));
 check('Archivio e ripristino sono identificabili',nodes().some(e=>e.tag==='summary'&&text(e).includes('Protocolli eliminati')));
 context.templateView('base_piano');check('Modello originale esplicitamente non modificabile',nodes().some(e=>text(e).includes('Originale protetto: non modificabile')));
 await context.editor(1);const phases=nodes().filter(e=>e.tag==='details'&&e.open&&text(e).includes('Fase '));
 check('Editor espone tre sezioni SRL separate e aperte',phases.length===3);
 check('Le tre fasi hanno destinazioni distinte',new Set(nodes().filter(e=>e.tag==='details'&&e.open).map(e=>e.attrs.id).filter(Boolean)).size===3);
 check('I collegamenti alle fasi sono disponibili',nodes().filter(e=>e.tag==='a'&&String(e.attrs.href).startsWith('#protocol-phase-')).length===3);
 check('Editor offre comando di economia del prompt',nodes().some(e=>e.tag==='button'&&text(e)==='Rendi il prompt più economico'));
 context.response={plans:[]}; await context.studentProgramme(1);check('Programmazione aperta nella scheda dello studente',nodes().some(e=>e.tag==='h1'&&text(e).includes('Sessioni di')));
 check('Assegnazione richiede attività e versione pubblicata',nodes().some(e=>e.tag==='label'&&text(e).includes('Protocollo e versione')));
 await context.voice();check('Pannello voce offre diagnostica',nodes().some(e=>e.tag==='summary'&&text(e).includes('Diagnostica audio')));
 check('Pannello voce offre prova di sintesi e microfono',nodes().some(e=>e.tag==='button'&&text(e).includes('Prova la voce'))&&nodes().some(e=>e.tag==='button'&&text(e).includes('Prova microfono')));
 await context.activities();check('Attività Libera offre sempre modifica caratteristiche',nodes().some(e=>e.tag==='button'&&text(e)==='Modifica caratteristiche'));context.editFreeActivity(context.data.activities[0]);check('Editor libera espone durata e indicazioni',nodes().some(e=>e.tag==='label'&&text(e).includes('Durata indicativa'))&&nodes().some(e=>e.tag==='button'&&text(e)==='Salva modifiche'));await context.activities();check('Il catalogo attività non offre assegnazioni',!nodes().some(e=>e.tag==='button'&&text(e)==='Assegna attività'));
 context.response={protocol:{id:1,title:'Personale'},versions:[{id:9,version_no:1,status:'published',sessions:0,config:template}]};
 await context.editor(1);check('L’editor protocolli non offre assegnazione del solo protocollo',!nodes().some(e=>e.tag==='button'&&text(e)==='Assegna'));
 context.data={...context.data,consents:[],privacy:{require_consents:false,notice_version:'1'},group_members:[],consent_kinds:{},consent_by:{}};context.response=context.data;
 vm.runInContext("tab='students';",context);await context.mount(root);
 const nav=all(root);
 check('Menu principale richiudibile presente',nav.some(e=>e.tag==='details'&&e.className==='application-navigation'));
 const search=nav.find(e=>e.tag==='input'&&e.attrs.type==='search');check('Ricerca studente disponibile',!!search);
 search.value='inesistente';search.events.input();check('Ricerca gestisce nessun risultato',text(root).includes('Nessuno studente corrisponde'));
 search.value='reale';search.events.input();check('Ricerca recupera lo studente per alias',text(root).includes('Programma sessioni'));
 context.response={protocol:{id:1,title:'Personale'},versions:[{id:9,version_no:1,status:'published',sessions:0,config:template}]};
 await context.editor(1);vm.runInContext('view.oninput();',context);check('Modifica del protocollo segnalata',text(vm.runInContext('view',context)).includes('Modifiche non salvate'));
 context.confirm=()=>false;check('Uscita dal protocollo bloccata se non confermata',vm.runInContext('leaveEditor()',context)===false);
 vm.runInContext('dirty=false;',context);
 check('Impostazioni applicazione è un menu dedicato',nav.some(e=>e.tag==='summary'&&text(e).includes('Impostazioni applicazione')));
 check('I quattro pannelli non sono schede principali',!nav.some(e=>e.attrs.role==='tab'&&['Provider','Voce e avatar','Validazione codifiche','Dati e autorizzazioni'].includes(text(e))));
 context.data.providers=[{id:4,label:'Gemini',provider_key:'gemini',model_name:'gemini-3.6-flash'}];vm.runInContext('B=data;',context);await context.providers();
 const testButton=nodes().find(e=>e.tag==='button'&&text(e)==='Prova la connessione');context.response={ok:false,error:'unavailable',detail:'Quota di prova superata',http_status:429};
 await testButton.events.click({currentTarget:testButton});check('Errore provider resta visibile nel pannello',nodes().some(e=>e.attrs.role==='status'&&e.textContent?.includes('HTTP 429')&&e.textContent.includes('Quota di prova')));check('Pulsante connessione riabilitato dopo errore',!testButton.disabled);
 context.response={ok:true,json_valid:true};await testButton.events.click({currentTarget:testButton});check('Connessione riuscita mostrata nel pannello',nodes().some(e=>e.textContent?.includes('Connessione riuscita')));
 console.log(`Superati: ${pass}`);
})().catch(e=>{console.error(e);process.exit(1);});
