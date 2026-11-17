// node tests/voice.cjs — tests de cycle audio avec synthèse et micro simulés.
const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict'),path=require('node:path');
let pass=0;const check=(name,condition)=>{assert(condition,name);console.log('✓ '+name);pass++;};
const source=fs.readFileSync(path.join(__dirname,'../public/assets/voice.js'),'utf8').replace(/^import .*;$/mg,'').replace(/^export /mg,'').replace(/^\{ h \};$/mg,'');
function environment(){
 const recognizers=[];const calls=[],timers=new Map(),listeners={};let id=0;
 const synth={paused:true,getVoices:()=>[{name:'Italiano locale',lang:'it-IT'}],resume(){calls.push('resume');this.paused=false;},cancel(){calls.push('cancel');},speak(u){calls.push(u);}};
 class Rec {constructor(){recognizers.push(this);}start(){this.started=true;}abort(){this.onend?.();}stop(){this.onend?.();}}
 const ctx=vm.createContext({window:{speechSynthesis:synth,SpeechRecognition:Rec},SpeechSynthesisUtterance:class{constructor(text){this.text=text;}},speechSynthesis:synth,
 addEventListener:(name,fn)=>listeners[name]=fn,setTimeout:(fn,ms)=>{timers.set(++id,{fn,ms});return id;},clearTimeout:key=>timers.delete(key),console});
 vm.runInContext(source,ctx);return {ctx,synth,calls,timers,listeners,recognizers,utterances:()=>calls.filter(x=>typeof x==='object')};
}
const cfg={language:'it-IT',voice_name:'Voce assente',input_enabled:true,output_enabled:true,rate:1,pitch:1};
{
 const e=environment();let ended=0,errors=[],state=[];const voice=e.ctx.createVoice(cfg,on=>state.push(on));
 voice.unlock();check('Sblocco non accoda frasi mute',e.utterances().length===0);
 check('La riproduzione parte e risulta occupata prima di onstart',voice.speak('Ciao.',{onEnd:()=>ended++,onError:m=>errors.push(m)})&&voice.speaking);
 const first=e.utterances()[0];check('Voce italiana di riserva e volume esplicito',first.voice.name==='Italiano locale'&&first.volume===1);
 check('Riprende la sintesi in pausa',e.calls.includes('resume')&&!e.synth.paused);
 check('Doppio clic non accoda né interrompe la lettura',voice.speak('Seconda prova.')===false&&e.utterances().length===1);
 first.onstart();check('Dopo avvio usa un controllo di blocco, non una fine fittizia',Array.from(e.timers.values())[0].ms>=20000);
 first.onend();check('Fine reale riabilita il lettore una sola volta',!voice.speaking&&ended===1&&state.at(-1)===false);
 first.onend();check('Evento finale duplicato ignorato',ended===1);
 voice.speak('Nuovo.',{onEnd:()=>ended++});const old=e.utterances().at(-1);voice.stopSpeaking();old.onend();
 check('Ferma annulla la coda senza simulare il completamento',!voice.speaking&&ended===1);
}
{
 const e=environment();let error='',ended=0;const v=e.ctx.createVoice(cfg);
 v.speak('Test.',{onError:m=>error=m,onEnd:()=>ended++});e.utterances()[0].onerror({error:'not-allowed'});
 check('Blocco audio del browser produce un messaggio e sblocca i pulsanti',!!error&&!v.speaking);
 check('Errore audio non fa partire la fase successiva',ended===0);
 v.speak('Riprova.');check('Si può riprovare dopo un errore',v.speaking);v.stopSpeaking();
}
{
 const e=environment();let error='';const v=e.ctx.createVoice(cfg);
 v.speak('Test.',{onError:m=>error=m});Array.from(e.timers.values())[0].fn();
 check('Mancato avvio rilevato senza fingere una lettura riuscita',!!error&&!v.speaking);
}
{
 const e=environment();let done=0;const a=e.ctx.createVoice(cfg),b=e.ctx.createVoice(cfg);
 a.speak('Prima frase. Seconda frase.',{onEnd:()=>done++});const first=e.utterances()[0];first.onend();const second=e.utterances()[1];
 check('Le frasi vengono lette in sequenza',first.text.trim()==='Prima frase.'&&second.text.trim()==='Seconda frase.');
 first.onerror({error:'network'});check('Errore tardivo di una frase già conclusa ignorato',a.speaking);
 second.onend();check('Fine della sequenza notificata una volta',done===1&&!a.speaking);
 a.speak('A.');b.speak('B.');check('Un solo lettore vocale attivo nella pagina',!a.speaking&&b.speaking);
 const before=e.calls.filter(x=>x==='cancel').length;a.stopSpeaking();
 check('Un pannello inattivo non interrompe il lettore attivo',before===e.calls.filter(x=>x==='cancel').length&&b.speaking);
 e.listeners.pagehide();check('Uscendo dalla pagina la voce si ferma',!b.speaking);
}
{
 const e=environment();let error='';const disabled=e.ctx.createVoice({...cfg,output_enabled:false});
 check('Rispetta la disattivazione della voce',disabled.speak('Ciao.',{onError:m=>error=m})===false&&!!error&&e.utterances().length===0);
 const v=e.ctx.createVoice(cfg);let ended=0;v.listen({onEnd:()=>ended++});check('Microfono può avviarsi',v.listening);
 v.abortListening();check('Annullamento microfono non riavvia callback obsolete',!v.listening&&ended===0);
}


{const e=environment();const v=e.ctx.createVoice(cfg);let silent=0,ended=0;v.listen({silenceMs:3000,onSilence:()=>silent++,onEnd:()=>ended++});const r=e.recognizers[0];r.onstart();check('Silenzio browser misurato dall’avvio reale',Array.from(e.timers.values()).some(t=>t.ms===3000));Array.from(e.timers.values())[0].fn();check('Tre secondi senza voce spengono il microfono una volta',silent===1&&ended===1&&!v.listening);}
{const e=environment();const v=e.ctx.createVoice(cfg);v.listen({silenceMs:3000});const r=e.recognizers[0];r.onstart();r.onspeechstart();check('Il parlato annulla il timeout iniziale',e.timers.size===0);r.onspeechend();check('Il silenzio dopo il parlato riavvia il timeout',e.timers.size===1);v.abortListening();check('Annullamento pulisce il timeout',e.timers.size===0);}



{
 const e=environment();let voices=[],listener=null,ended=0;const v=e.ctx.createVoice(cfg);
 e.synth.getVoices=()=>voices;e.synth.addEventListener=(name,fn)=>listener=fn;e.synth.removeEventListener=(name,fn)=>{if(listener===fn)listener=null;};
 v.speak('Test voci.',{onEnd:()=>ended++});check('Attende le voci senza accodare una lettura senza voce',e.utterances().length===0&&v.speaking);
 voices=[{name:'Italiano',lang:'it-IT',localService:true}];listener();check('voiceschanged avvia la voce e rimuove il listener',e.utterances().length===1&&listener===null);e.utterances()[0].onend();check('Fine dopo caricamento notificata una sola volta',ended===1);
}
{
 const e=environment();let listener=null,error='';e.synth.getVoices=()=>[];e.synth.addEventListener=(n,fn)=>listener=fn;e.synth.removeEventListener=()=>listener=null;const v=e.ctx.createVoice(cfg);
 v.speak('Non registrare questo testo',{onError:m=>error=m});Array.from(e.timers.values())[0].fn();check('Assenza persistente delle voci produce errore esplicito',!!error&&!v.speaking&&listener===null&&e.utterances().length===0);
 check('Diagnosi non contiene il testo letto',!JSON.stringify(vm.runInContext('voiceDiagnostics()',e.ctx)).includes('Non registrare questo testo'));
}
{
 const e=environment();let listener=null;e.synth.getVoices=()=>[];e.synth.addEventListener=(n,fn)=>listener=fn;e.synth.removeEventListener=()=>listener=null;const v=e.ctx.createVoice(cfg);v.speak('Attesa');v.stopSpeaking();check('Stop annulla anche l’attesa delle voci',!v.speaking&&listener===null&&e.timers.size===0);
}
{
 const e=environment();const v=e.ctx.createVoice(cfg);let ended=0;v.speak('Parola '.repeat(100)+'.',{onEnd:()=>ended++});let parts=0;while(v.speaking&&parts<20){const u=e.utterances().at(-1);assert(u.text.length<=180);parts++;u.onstart();u.onend();}
 check('Frase lunga suddivisa in parti limitate senza doppia fine',parts>1&&ended===1&&!v.speaking);
}
console.log(`Superati: ${pass}`);
