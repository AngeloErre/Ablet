const fs=require('node:fs'),vm=require('node:vm'),path=require('node:path'),assert=require('node:assert/strict');
let pass=0;const check=(name,ok)=>{assert(ok,name);pass++;console.log('✓ '+name);};const flush=()=>new Promise(resolve=>setImmediate(resolve));
const source=fs.readFileSync(path.join(__dirname,'../public/assets/voice.js'),'utf8').replace(/^import .*;$/mg,'').replace(/^export /mg,'').replace(/^\{ h \};$/mg,'');
function env(){const calls=[],players=[],recorders=[],events={},revoked=[];let tracksStopped=0;let pending=null;
 class Player {constructor(){players.push(this);}pause(){}removeAttribute(){}load(){}async play(){this.played=true;}}
 class Recorder{static isTypeSupported(t){return t.startsWith('audio/webm');}constructor(stream,options){this.mimeType=options?.mimeType||'audio/webm';this.state='inactive';recorders.push(this);}start(){this.state='recording';}stop(){this.state='inactive';this.ondataavailable?.({data:new Blob(['test audio'],{type:this.mimeType})});this.onstop?.();}}
 class Reader{readAsDataURL(blob){blob.arrayBuffer().then(buffer=>{this.result='data:'+blob.type+';base64,'+Buffer.from(buffer).toString('base64');this.onload();});}}
 const ctx=vm.createContext({window:{},navigator:{mediaDevices:{getUserMedia:async()=>({getTracks:()=>[{stop:()=>tracksStopped++}]})}},Audio:Player,MediaRecorder:Recorder,FileReader:Reader,Blob,Uint8Array,atob,btoa,URL:{createObjectURL:()=> 'blob:test',revokeObjectURL:url=>revoked.push(url)},setTimeout,clearTimeout,addEventListener:(name,fn)=>events[name]=fn,
 api:async(method,url,payload)=>{calls.push({method,url,payload});if(pending)return pending;return url.endsWith('/speech')?{audio:Buffer.from('RIFF-test-WAVE').toString('base64'),mime:'audio/wav'}:{text:'Devo studiare le frazioni.'};},errText:error=>error.detail||error.message||'Errore audio'});
 vm.runInContext(source,ctx);return {ctx,calls,players,recorders,revoked,events,get stopped(){return tracksStopped;},hold(){let resolve;pending=new Promise(r=>resolve=r);return resolve;}};
}
(async()=>{
 const cfg={engine:'server',input_enabled:true,output_enabled:true,api_context:{owner_id:8}};
 {
 const e=env();let ends=0;const states=[];const v=e.ctx.createVoice(cfg,on=>states.push(on));
 check('Voce server disponibile anche senza SpeechSynthesis',v.canSpeak&&v.canListen);
 check('Stato occupato prima della risposta del server',v.speak('Prova.',{onEnd:()=>ends++})&&v.speaking);
 check('Doppio clic ignorato',v.speak('Doppio.')===false);
 await flush();check('Richiesta di sintesi usa il contesto autorizzabile',e.calls[0].url==='/api/voice/speech'&&e.calls[0].payload.context.owner_id===8);
 check('Riproduce l’audio ricevuto',e.players[0].played===true);
 e.players[0].onended();check('Fine riabilita i comandi',!v.speaking&&ends===1&&states.at(-1)===false);
 check('URL audio temporaneo rilasciato',e.revoked.includes('blob:test'));
 e.players[0].onended();check('Fine tardiva non viene duplicata',ends===1);
 }
 {
 const e=env();const resolve=e.hold();let ends=0;const v=e.ctx.createVoice(cfg);
 v.speak('Prova.',{onEnd:()=>ends++});v.stopSpeaking();resolve({audio:Buffer.from('RIFF').toString('base64'),mime:'audio/wav'});await flush();
 check('Ferma durante il download impedisce l’avvio tardivo',!e.players[0].played&&!v.speaking&&ends===0);
 }
 {
 const e=env();const v=e.ctx.createVoice(cfg);let text='',ends=0,processing=0;
 v.listen({onText:value=>text=value,onEnd:()=>ends++,onProcessing:()=>processing++});await flush();
 check('Microfono server avviato',v.listening&&e.recorders[0].state==='recording');
 v.stopListening();await flush();await flush();
 check('Registrazione arresta le tracce del microfono',e.stopped===1);
 check('Durante trascrizione viene notificata l’attesa',processing===1);
 check('La trascrizione è restituita per conferma, non inviata alla conversazione',text==='Devo studiare le frazioni.'&&ends===1&&e.calls.every(c=>c.url.startsWith('/api/voice/')));
 check('Payload audio limitato al servizio di trascrizione',e.calls[0].url==='/api/voice/transcribe'&&e.calls[0].payload.audio===Buffer.from('test audio').toString('base64'));
 }
 {
 const e=env();const v=e.ctx.createVoice(cfg);let ended=0;v.listen({onEnd:()=>ended++});await flush();v.abortListening();await flush();
 check('Annullare la registrazione non invia audio al server',e.calls.length===0&&ended===0&&!v.listening);
 }
 {
 const e=env(), ticks=new Map();let serial=0,sound=false,closed=0,silent=0,ends=0;
 e.ctx.setInterval=fn=>{ticks.set(++serial,fn);return serial;};e.ctx.clearInterval=id=>ticks.delete(id);
 e.ctx.window.AudioContext=class {createMediaStreamSource(){return {connect(){},disconnect(){}};}createAnalyser(){return {fftSize:1024,getByteTimeDomainData(a){a.fill(sound?150:128);},disconnect(){}};}resume(){return Promise.resolve();}close(){closed++;return Promise.resolve();}};
 const v=e.ctx.createVoice(cfg);v.listen({silenceMs:3000,onSilence:()=>silent++,onEnd:()=>ends++});await flush();
 for(let i=0;i<30;i++)for(const tick of [...ticks.values()])tick();await flush();
 check('Silenzio server ferma e rilascia il microfono',silent===1&&ends===1&&!v.listening&&e.stopped===1&&closed===1);
 check('Audio silenzioso non viene inviato al provider',e.calls.length===0&&ticks.size===0);
 sound=true;v.listen({silenceMs:3000,onSilence:()=>silent++,onEnd:()=>ends++});await flush();
 for(let i=0;i<3;i++)for(const tick of [...ticks.values()])tick();sound=false;
 for(let i=0;i<30;i++)for(const tick of [...ticks.values()])tick();await flush();await flush();
 check('Dopo parlato e tre secondi di silenzio invia la trascrizione',e.calls.some(c=>c.url.endsWith('/transcribe'))&&ends===2&&ticks.size===0);
 }
 console.log(`Superati: ${pass}`);
})().catch(error=>{console.error(error);process.exit(1);});
