// Verifica del rendering della scelta di accesso senza dipendenze DOM esterne.
const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict'),path=require('node:path');
let pass=0;const check=(name,ok)=>{assert(ok,name);pass++;console.log('✓ '+name);};
class Element {
 constructor(tag,text=''){this.tag=tag;this.text=text;this.children=[];this.attrs={};this.events={};this.nodeType=1;this.style={setProperty(){}};this.classList={toggle(){}};}
 setAttribute(k,v){this.attrs[k]=v;}addEventListener(k,fn){this.events[k]=fn;}
 append(...children){this.children.push(...children);}replaceChildren(...children){this.children=children;}
}
const all=e=>[e,...e.children.flatMap(all)];
const text=e=>e.text+e.children.map(text).join('');
let source=fs.readFileSync(path.join(__dirname,'../public/assets/app.js'),'utf8').replace(/^export /mg,'').replace(/^boot\(\)\.catch.*$/m,'');
function render(search,hash=''){const root=new Element('div'),html=new Element('html');html.dataset={base:'/ablet'};
 const ctx=vm.createContext({document:{documentElement:html,getElementById:()=>root,createElement:tag=>new Element(tag),createTextNode:value=>new Element('#text',String(value))},location:{search,hash,pathname:'/ablet/'},URLSearchParams,localStorage:{getItem:()=>null,setItem(){}},window:{},navigator:{},addEventListener(){},setTimeout,clearTimeout,console});
 vm.runInContext(source,ctx);ctx.login();return {elements:all(root),root,ctx};
}
const home=render('').elements;const links=home.filter(e=>e.tag==='a');
check('La home propone due link di accesso distinti',links.some(e=>e.attrs.href==='/ablet/?accesso=studente')&&links.some(e=>e.attrs.href==='/ablet/?accesso=docente'));
check('Link studente corretto',links.some(e=>e.attrs.href==='/ablet/?accesso=studente'&&text(e).includes('Accedi come studente')));
check('Link docente corretto',links.some(e=>e.attrs.href==='/ablet/?accesso=docente'&&text(e).includes('educatore / psicologo')));
check('La home non chiede credenziali prima della scelta',!home.some(e=>e.tag==='input'));
for (const [portal,label] of [['studente','Accesso studente'],['docente','Accesso docente / psicologo'],['admin','Accesso super admin']]){
 const state=render('?accesso='+portal),elements=state.elements;
 check('Titolo della pagina '+portal,elements.some(e=>e.tag==='h1'&&text(e)===label));
 check('Modulo credenziali presente per '+portal,elements.filter(e=>e.tag==='input').length===(portal==='admin'?2:1));
 if(portal!=='admin'){
 const pin=elements.find(e=>e.attrs.name==='pin');check('PIN numerico di sei cifre '+portal,pin.attrs.inputmode==='numeric'&&pin.attrs.maxlength===6&&pin.attrs.pattern==='[0-9]{6}');
 elements.find(e=>e.tag==='button'&&text(e).includes('codice / e-mail')).events.click();
 check('Password alternativa '+portal,all(state.root).some(e=>e.attrs.name==='login')&&all(state.root).some(e=>e.attrs.name==='password')&&!all(state.root).some(e=>e.attrs.name==='pin'));
 all(state.root).find(e=>e.tag==='button'&&text(e).includes('solo PIN')).events.click();check('Ritorno PIN '+portal,all(state.root).filter(e=>e.tag==='input').length===1);
 } else check('Super admin resta con password',!elements.some(e=>e.attrs.name==='pin'));
 check('Ritorno alla scelta disponibile per '+portal,elements.some(e=>e.tag==='a'&&e.attrs.href==='/ablet/'));
}
check('La home presenta le tre fasi SRL',home.some(e=>text(e).includes('Previsione e pianificazione'))&&home.some(e=>text(e).includes('Auto-riflessione')));
check('La home offre registrazione professionale',links.some(e=>e.attrs.href==='/ablet/?registrazione=professionista'));
check('Le anteprime sono dichiarate illustrative',home.some(e=>e.tag==='figcaption'&&text(e).includes('illustrativa')));
const signup=render('?registrazione=professionista').elements;
check('Modulo registrazione presente',signup.some(e=>e.tag==='form')&&signup.some(e=>e.tag==='input'&&e.attrs.type==='email'));
check('Registrazione richiede conferma delle informazioni',signup.some(e=>e.tag==='input'&&e.attrs.type==='checkbox'&&Object.hasOwn(e.attrs,'required')));
check('Homepage spiega dashboard e limiti dei dati',home.some(e=>e.attrs.id==='dashboard')&&home.some(e=>e.attrs.id==='analisi-dati'));
check('Due anteprime isolate con dati sintetici',home.filter(e=>e.tag==='iframe'&&e.attrs.sandbox==='').length===2);
check('Il menu collega alla dashboard',links.some(e=>e.attrs.href==='#dashboard'));
check('Accesso professionale offre recupero password',render('?accesso=docente').elements.some(e=>e.tag==='a'&&e.attrs.href==='/ablet/?recupero=password'));
check('Accesso studente mantiene il recupero gestito dal professionista',!render('?accesso=studente').elements.some(e=>e.tag==='a'&&e.attrs.href==='/ablet/?recupero=password'));
const forgot=render('?recupero=password').elements;check('Richiesta recupero usa campo e-mail',forgot.some(e=>e.tag==='input'&&e.attrs.type==='email'));
const reset=render('?recupero=password','#token='+ 'a'.repeat(64)).elements;check('Link recupero apre la scelta della nuova password',reset.filter(e=>e.tag==='input'&&e.attrs.type==='password').length===2);
console.log(`Superati: ${pass}`);
