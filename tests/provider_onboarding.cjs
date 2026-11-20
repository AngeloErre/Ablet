const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict'),path=require('node:path');let n=0;const ck=(label,ok)=>{assert(ok,label);n++;console.log('✓ '+label);};
class El{constructor(tag,text=''){this.tag=tag;this.text=text;this.children=[];this.attrs={};this.events={};this.nodeType=1;this.style={setProperty(){}};this.classList={toggle(){},add(){},remove(){}};}setAttribute(k,v){this.attrs[k]=v;}addEventListener(k,fn){this.events[k]=fn;}append(...c){this.children.push(...c);}replaceChildren(...c){this.children=c;}}
const root=new El('div'),html=new El('html');html.dataset={base:'/ablet'};
const ctx=vm.createContext({root,document:{documentElement:html,body:new El('body'),getElementById:()=>root,createElement:t=>new El(t),createTextNode:t=>new El('#text',String(t))},location:{search:''},localStorage:{getItem:()=>null,setItem(){throw Error('No credentials in browser storage');}},navigator:{},window:{},URLSearchParams,setTimeout,clearTimeout,addEventListener(){},console});
const clean=s=>s.replace(/^import .*;$/mg,'').replace(/^export /mg,'').replace(/^boot\(\)\.catch.*$/m,'');
for(const f of ['app.js','psy.js'])vm.runInContext(clean(fs.readFileSync(path.join(__dirname,'../public/assets/'+f),'utf8')),ctx);
const data={secrets_ready:true,providers:[{id:1,provider_key:'demo',is_default:true}],students:[],activities:[],settings:{},groups:[],protocols:[],templates:{},published_versions:[],activity_assignments:[],archived_protocols:[],simulation_profiles:{}};
ctx.data=data;vm.runInContext('B=data;main=root;view=document.createElement("div");',ctx);
const all=e=>[e,...e.children.flatMap(all)],text=e=>(e.textContent??e.text)+e.children.map(text).join(' '),nodes=()=>all(vm.runInContext('view',ctx));
const byLabel=s=>nodes().find(e=>e.attrs['aria-label']===s),button=s=>nodes().find(e=>e.tag==='button'&&text(e)===s);
const click=async name=>{const b=button(name);assert(b,name);await b.events.click({currentTarget:b,preventDefault(){}});};
let saveFailure=false,modelsFailure=false,calls=[],testResult={ok:true,json_valid:true,simulated:false},modelList=[],config=null;
ctx.api=async(method,url,body)=>{
 if(url==='/api/psy/bootstrap')return data;
 calls.push({method,url,body});
 if(url==='/api/psy/providers'){
  if(saveFailure)throw {error:'rete'};
  config={...config,...body,id:config?.id||2,key_mask:'••••synt',capabilities:{text:true},api_key:undefined};
  if(body.is_default){data.providers.forEach(p=>p.is_default=false);}
  data.providers=[...data.providers.filter(p=>p.id!==config.id),config];return {provider:config};
 }
 if(url.endsWith('/models')){if(modelsFailure)throw {error:'rete'};return {models:modelList};}
 if(url.endsWith('/test'))return testResult;
 throw Error(url);
};
(async()=>{
 ck('Nessun servizio: configurazione richiesta',ctx.llmSetupState([]).needed);
 ck('Modalità dimostrativa: configurazione richiesta',ctx.llmSetupState(data.providers).needed);
 const ready={id:2,provider_key:'gemini',key_mask:'mask',model_name:'model',capabilities:{text:true},is_default:true};
 ck('Chiave mancante: configurazione richiesta',ctx.llmSetupState([{...ready,key_mask:null}]).needed);
 ck('Modello mancante: configurazione richiesta',ctx.llmSetupState([{...ready,model_name:''}]).needed);
 ck('Configurazione reale completa: nessun avviso',!ctx.llmSetupState([ready]).needed);
 ck('Un servizio non predefinito non nasconde la modalità demo',ctx.llmSetupState([data.providers[0],{...ready,is_default:false}]).needed);
 const notice=ctx.providerSetupNotice();ck('Avviso in home con percorso guidato',text(notice).includes('Completa la configurazione')&&text(notice).includes('risposte simulate'));
 ck('Guida specifica per quattro servizi',Object.keys(vm.runInContext('LLM_GUIDES',ctx)).length===4);
 ck('Errori di quota spiegati senza suggerire ripetizioni immediate',ctx.providerFailure({http_status:429,retry_after:30}).includes('30 secondi'));
 ck('Errore credenziali spiegato',ctx.providerFailure({http_status:401}).includes('chiave non è autorizzata'));
 ck('Indisponibilità spiegata',ctx.providerFailure({http_status:503}).includes('temporaneamente occupato'));
 for(const [key,candidate] of [['gemini','gemini-3.5-flash-lite'],['openai','gpt-6-luna'],['deepseek','deepseek-flash'],['anthropic','claude-haiku-5-5']]){
  data.providers=[{id:1,provider_key:'demo',is_default:true}];config=null;calls=[];testResult={ok:true,json_valid:true,simulated:false};modelList=[candidate,'modello-piu-grande'];
  ctx.providerWizard();byLabel('Servizio IA').events.change({target:{value:key}});await click('Continua');
  ck(key+': istruzioni prima di usare la chiave',nodes().some(e=>e.tag==='ol')&&nodes().some(e=>e.tag==='input'&&e.attrs.type==='password'));
  await click('Salva chiave e continua');ck(key+': nessun salvataggio senza conferma',calls.length===0);
  const secret=byLabel('Chiave API personale');secret.value='synthetic-test-key';byLabel('Confermo condizioni e invio al servizio').checked=true;
  await click('Salva chiave e continua');ck(key+': chiave rimossa dal campo dopo salvataggio',secret.value==='');
  ck(key+': il servizio non diventa predefinito durante la bozza',calls.filter(c=>c.method==='POST').every(c=>!c.body.is_default));
  await click('Carica modelli disponibili');const select=byLabel('Modelli disponibili nel tuo account');ck(key+': candidato economico tratto dall’elenco reale',select.children.some(e=>e.value===candidate&&text(e).includes('candidato economico')));
  ck(key+': nessun modello suggerito se assente dalla risposta',select.children.filter(e=>e.tag==='option').every(e=>!e.value||modelList.includes(e.value)));
  select.events.change({target:{value:candidate}});
  if(key==='gemini'){
   const cap=byLabel('Tetto mensile locale di token');cap.value='-1';cap.events.input({target:cap});await click('Salva modello e continua');ck('Tetto locale invalido mantiene il passo modello',!!byLabel('Modello IA'));cap.value='4294967296';cap.events.input({target:cap});await click('Salva modello e continua');ck('Tetto oltre il massimo database respinto prima del salvataggio',!!byLabel('Modello IA'));cap.value='10000';cap.events.input({target:cap});
  }
  await click('Salva modello e continua');ck(key+': attivazione impedita prima della prova',button('Attiva per il mio spazio').disabled);
  if(key==='gemini'){
   testResult={ok:false,error:'rate_limit',http_status:429,retry_after:20};await click('Prova il collegamento');ck('Quota esaurita non attiva il servizio',button('Attiva per il mio spazio').disabled&&data.providers[0].is_default);
   testResult={ok:true,json_valid:false};await click('Prova il collegamento');ck('JSON invalido non attiva il servizio',button('Attiva per il mio spazio').disabled);
   testResult={ok:true,json_valid:true,simulated:true};await click('Prova il collegamento');ck('Una risposta simulata non supera il collaudo reale',button('Attiva per il mio spazio').disabled);
  }
  testResult={ok:true,json_valid:true,simulated:false};await click('Prova il collegamento');ck(key+': successo non basta senza scelta del professionista',button('Attiva per il mio spazio').disabled);
  const consent=byLabel('Attiva come servizio predefinito');consent.checked=true;consent.events.change();ck(key+': prova riuscita abilita attivazione esplicita',!button('Attiva per il mio spazio').disabled);
  await click('Attiva per il mio spazio');ck(key+': attivazione usa solo la configurazione personale verificata',config.is_default&&config.provider_key===key&&config.model_name===candidate);
  ck(key+': avviso iniziale scompare con un servizio completo',ctx.providerSetupNotice()===null);
 }
 data.providers=[{id:1,provider_key:'demo',is_default:true}];config=null;calls=[];ctx.providerWizard();await click('Continua');
 const pendingKey=byLabel('Chiave API personale');pendingKey.value='synthetic-retry-key';byLabel('Confermo condizioni e invio al servizio').checked=true;saveFailure=true;await click('Salva chiave e continua');
 ck('Errore di rete nel salvataggio conserva la chiave per riprovare',pendingKey.value==='synthetic-retry-key'&&!!button('Salva chiave e continua'));
 saveFailure=false;await click('Salva chiave e continua');modelsFailure=true;await click('Carica modelli disponibili');ck('Errore elenco mantiene inserimento manuale disponibile',!!byLabel('Modello IA')&&!!button('Carica modelli disponibili'));
 modelsFailure=false;modelList=['gemini-test-text','gemini-test-tts','text-embedding-test'];await click('Carica modelli disponibili');ck('Modelli dedicati a voce ed embedding esclusi dal percorso dialogo',byLabel('Modelli disponibili nel tuo account').children.filter(e=>e.tag==='option'&&e.value).every(e=>e.value==='gemini-test-text'));
 await click('Esci dalla guida');ck('Uscita dalla guida non attiva un servizio incompleto',data.providers[0].is_default&&config.is_default===false);
 data.secrets_ready=false;ctx.providerWizard();ck('Server senza cifratura non chiede la chiave',!nodes().some(e=>e.tag==='input'&&e.attrs.type==='password')&&nodes().some(e=>text(e).includes('Chiedi al gestore')));
 console.log('Configurazione guidata: '+n+' controlli superati.');
})().catch(e=>{console.error(e);process.exit(1);});
