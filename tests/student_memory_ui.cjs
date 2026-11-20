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

context.memory={settings:{enabled:true,guidance:'Indicazione sintetica',revision:0},window:20,bytes:350,sessions:[{session_id:42,activity_id:3,ended_at:'2026-11-20',excluded:false,facts:{closed:'incomplete',goals:3,done:1,hints:2,difficulty:1,reflection_messages:2}}]};context.calls=[];
vm.runInContext(`api=async(method,path,body)=>{calls.push({method,path,body});if(method==='PUT'){memory.settings={...body,revision:body.revision+1};return structuredClone(memory.settings);}if(method==='POST'&&path.endsWith('/42'))memory.sessions[0].excluded=body.excluded;return structuredClone(memory);};`,context);
(async()=>{
 await context.studentMemory(context.data.students[0]);
 check('Memoria mostra intestazione studente',nodes().some(e=>e.tag==='h1'&&text(e).includes('Studente reale')));
 check('Indicazioni limitate a 500 caratteri',nodes().some(e=>e.tag==='textarea'&&e.attrs.maxlength===500));
 check('Contatori dichiarati non psicometrici',nodes().some(e=>text(e).includes('non sono punteggi psicologici')));
 check('Sessione incompleta visibile',nodes().some(e=>e.tag==='td'&&text(e)==='Incompleta'));
 check('Evidenza apribile dalla sessione',nodes().some(e=>e.tag==='button'&&text(e)==='#42'));
 check('Limiti della finestra e del contesto visibili',nodes().some(e=>text(e).includes('massimo 5 sessioni')&&text(e).includes('20 sessioni')));
 const form=nodes().find(e=>e.tag==='form');nodes().find(e=>e.tag==='textarea').value='Nuova indicazione';await form.events.submit({preventDefault(){}});
 let req=context.calls.find(x=>x.method==='PUT');check('Salva con revisione e indicazioni',req.body.revision===0&&req.body.guidance==='Nuova indicazione'&&req.body.enabled===true);
 await form.events.submit({preventDefault(){}});check('Salvataggio successivo usa revisione aggiornata',context.calls.filter(x=>x.method==='PUT').at(-1).body.revision===1);
 await nodes().find(e=>e.tag==='button'&&text(e)==='Escludi dall’agente').events.click();check('Esclusione esplicita della sola sessione',context.calls.some(x=>x.method==='POST'&&x.path.endsWith('/42')&&x.body.excluded===true));
 check('Reinclusione disponibile',nodes().some(e=>e.tag==='button'&&text(e)==='Reincludi'));
 await nodes().find(e=>e.tag==='button'&&text(e).includes('Recupera ultime')).events.click();check('Recupero storico con comando dedicato',context.calls.some(x=>x.method==='POST'&&x.path.endsWith('/rebuild')));
 context.memory.sessions=[];await context.studentMemory(context.data.students[0]);check('Stato vuoto spiegato',nodes().some(e=>text(e).includes('Nessuna sessione consolidata')));
 console.log(`Memoria UI: ${pass} controlli superati.`);
})().catch(e=>{console.error(e);process.exitCode=1;});
