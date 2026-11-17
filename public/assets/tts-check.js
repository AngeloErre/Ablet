import {createVoice,voiceDiagnostics} from './voice.js';
const byId=id=>document.getElementById(id),synth=window.speechSynthesis;
let player=null,native=null,watch=null,generation=0;
const events=[];const short='Ciao, questa è una prova della voce italiana di ABLET.';
const log=(event,extra={})=>{events.push({time:new Date().toISOString(),event,...extra});if(events.length>60)events.shift();render();};
function render(){byId('report').textContent=JSON.stringify({browser:navigator.userAgent,secure:window.isSecureContext,activated:navigator.userActivation?.hasBeenActive,voices:synth?.getVoices().map(v=>({name:v.name,lang:v.lang,local:v.localService,default:v.default})),heard:byId('heard').value,events,ablet:voiceDiagnostics()},null,2);}
function busy(on){for(const id of ['native','ablet','long'])byId(id).disabled=on;}
function stop(){generation++;clearTimeout(watch);player?.stopSpeaking();native=null;synth?.cancel();busy(false);}
function fill(){const old=byId('voice').value;const voices=synth?.getVoices()||[];byId('voice').replaceChildren(new Option('Predefinita italiana',''),...voices.filter(v=>/^it[-_]/i.test(v.lang)).map(v=>new Option(v.name+(v.localService?' · locale':' · rete'),v.name)));byId('voice').value=old;byId('voices-state').textContent=`${voices.length} voci totali; ${voices.filter(v=>/^it[-_]/i.test(v.lang)).length} italiane. `+(voices.length?'':'Chrome non ha ancora fornito voci.');render();}
function run(kind){stop();const mine=generation;busy(true);byId('result').textContent='Attendo l’avvio della voce…';log('request',{kind});
 const fail=message=>{if(mine!==generation)return;clearTimeout(watch);byId('result').textContent=message;log('failure',{kind,message});player?.stopSpeaking();synth?.cancel();busy(false);};
 const done=()=>{if(mine!==generation)return;clearTimeout(watch);byId('result').textContent='Ricevuto evento di fine. Hai sentito la voce?';busy(false);log('end',{kind});};
 if(!synth){fail('Sintesi vocale non disponibile.');return;}
 if(kind==='native'){
  native=new SpeechSynthesisUtterance(short);native.lang='it-IT';native.volume=1;const voice=synth.getVoices().find(v=>v.name===byId('voice').value);if(voice)native.voice=voice;
  native.onstart=()=>{if(mine!==generation)return;clearTimeout(watch);byId('result').textContent='Chrome segnala avvio della voce.';log('start',{kind});watch=setTimeout(()=>fail('Manca l’evento di fine.'),20000);};native.onend=done;native.onerror=e=>fail('Errore Chrome: '+e.error);
  watch=setTimeout(()=>fail('Chrome non ha segnalato l’avvio entro 8 secondi.'),8000);try{synth.resume();synth.speak(native);}catch(e){fail(String(e));}
 }else{player=createVoice({engine:'browser',language:'it-IT',voice_name:byId('voice').value,input_enabled:false,output_enabled:true,rate:1,pitch:1},on=>{if(mine===generation&&on){byId('result').textContent='ABLET sta preparando o riproducendo la voce…';render();}});player.unlock();player.speak(kind==='long'?('Questa prova verifica che la lettura continui senza interrompersi anche quando il testo contiene molti passaggi e una frase lunga priva di punteggiatura '+ 'per organizzare il lavoro e riflettere sul proprio metodo '.repeat(12)+'. Ora la prova è conclusa.'):short,{onEnd:done,onError:fail});}
}
for(const kind of ['native','ablet','long'])byId(kind).onclick=()=>run(kind);
byId('stop').onclick=()=>{stop();byId('result').textContent='Prova fermata.';log('manual-stop');};
byId('refresh').onclick=fill;byId('heard').oninput=render;
byId('copy').onclick=async()=>{render();try{await navigator.clipboard.writeText(byId('report').textContent);byId('result').textContent='Report copiato: puoi incollarlo nella conversazione.';}catch{byId('result').textContent='Seleziona e copia il report manualmente.';}};
synth?.addEventListener('voiceschanged',fill);addEventListener('pagehide',()=>{stop();synth?.removeEventListener('voiceschanged',fill);});fill();
