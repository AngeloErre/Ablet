const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict'),path=require('node:path');let n=0;const ck=(name,ok)=>{assert(ok,name);n++;console.log('✓ '+name);};
class E {constructor(tag,text=''){this.tag=tag;this.text=text;this.nodeType=tag==='#text'?3:1;this.nodeValue=text;this.children=[];this.attrs={};this.style={setProperty(){}};this.classList={toggle(){},add(){},remove(){}};}setAttribute(k,v){this.attrs[k]=v;}addEventListener(){}append(...c){this.children.push(...c);}replaceChildren(...c){this.children=c;}get childNodes(){return this.children;}querySelector(){return null;}}
const root=new E('div'),html=new E('html');html.dataset={base:''};const ctx=vm.createContext({document:{documentElement:html,getElementById:()=>root,createElement:t=>new E(t),createTextNode:t=>new E('#text',String(t))},location:{search:''},navigator:{},window:{},URLSearchParams,localStorage:{getItem:()=>null,setItem(){}},setTimeout,clearTimeout,addEventListener(){},console});
const clean=s=>s.replace(/^import .*;$/mg,'').replace(/^export /mg,'').replace(/^boot\(\)\.catch.*$/m,'');
vm.runInContext(clean(fs.readFileSync(path.join(__dirname,'../public/assets/app.js'),'utf8')),ctx);
vm.runInContext(clean(fs.readFileSync(path.join(__dirname,'../public/assets/admin.js'),'utf8')),ctx);
const all=e=>[e,...e.children.flatMap(all)],text=e=>e.textContent??(e.nodeType===3?e.nodeValue:e.children.map(text).join(' '));
(async()=>{
 ctx.api=async(m,p)=>p==='/api/admin/version'?{version:'0.12.2'}:{users:[],total:0,page:1,page_size:25};await ctx.mount(root);
 ck('Footer riporta la versione comunicata dal server',all(root).some(e=>e.tag==='footer'&&text(e)==='ABLET · Versione installata: 0.12.2'));
 ck('Area super admin separata',text(root).includes('Super admin')&&text(root).includes('Professionisti'));
 ck('Accesso al proprio spazio preservato',all(root).some(e=>e.attrs.href==='/?area=professionista'));
 ck('Creazione professionista disponibile',text(root).includes('Crea un professionista'));
 ck('Link anteprima homepage disponibile',all(root).some(e=>e.attrs.href==='/?preview_home=1'));
 ctx.api=async()=>({revision:1,draft:{},fields:[{key:ctx.homeTextKey('Titolo prova'),default:'Titolo prova'}],versions:[]});await ctx.home(root);
 ck('Editor espone bozze e pubblicazione',text(root).includes('Salva bozza')&&text(root).includes('Pubblica homepage'));
 ck('Tre slot immagine con alternative testuali',all(root).filter(e=>e.tag==='input'&&e.attrs.type==='file').length===3);
 const content={texts:{[ctx.homeTextKey('Titolo prova')]:'<script>non eseguire</script>'}};
 ctx.api=async()=>({content});const node=new E('#text','Titolo prova'),container=new E('div');container.append(node);await ctx.hydrateHome(container);
 ck('CMS applica testo semplice senza creare elementi HTML',node.nodeValue==='<script>non eseguire</script>'&&container.children.length===1);
 const defaults=JSON.parse(fs.readFileSync(path.join(__dirname,'../resources/home-content.json')));ck('Catalogo copre i testi pubblici con chiavi univoche',defaults.length>100&&new Set(defaults.map(x=>x.key)).size===defaults.length);
 ctx.api=async()=>({config:{enabled:false,from:'',public_url:''},available:false});await ctx.mailSettings(root);
 ck('Posta e recupero configurabili dall’area admin',text(root).includes('Posta e recupero password')&&text(root).includes('URL pubblico esatto'));
 ck('Prova e-mail distinta dal recupero',text(root).includes('Invia e-mail di prova')&&text(root).includes('senza password o link'));
 ctx.api=async(m,p)=>{if(p==='/api/admin/version')throw new Error('offline');return {users:[],total:0,page:1,page_size:25};};await ctx.mount(root);ck('Errore versione non blocca la gestione professionisti',text(root).includes('Crea un professionista')&&text(root).includes('Versione installata: non disponibile'));
 console.log('Admin UI: '+n+' controlli superati.');
})().catch(e=>{console.error(e);process.exit(1);});
