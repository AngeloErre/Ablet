import {h,clear,api,guard,toast,errText,BASE} from './app.js';
let dirtyHome=false;
let adminRoot,mode='users',page=1,query='';
const field=(label,input)=>h('label',{},label,input);
const input=(value='',attrs={})=>h('input',{value,...attrs});
const choose=(values,value)=>h('select',{},values.map(([key,label])=>h('option',{value:key,selected:key===value},label)));
export async function mount(el){adminRoot=el;await render();}
async function render(){
 const view=h('section',{class:'admin-content'});
 const footer=h('footer',{class:'small muted','aria-live':'polite'},'Versione installata: caricamento…');
 clear(adminRoot).append(h('header',{class:'row'},h('h1',{},'Super admin'),h('a',{href:`${BASE}/?area=professionista`},'Il mio spazio professionale'),h('a',{href:`${BASE}/?preview_home=1`,target:'_blank',rel:'noopener'},'Anteprima homepage')),
  h('nav',{class:'row','aria-label':'Amministrazione'},...['users','home','mail'].map(k=>h('button',{'aria-pressed':String(mode===k),onclick:guard(async()=>{if(dirtyHome&&!confirm('Ci sono modifiche alla homepage non salvate. Uscire?'))return;dirtyHome=false;mode=k;await render();})},k==='users'?'Professionisti':k==='home'?'Testi e immagini homepage':'Posta e recupero'))),view,footer);
 try { const info=await api('GET','/api/admin/version');footer.textContent=info.version?`ABLET · Versione installata: ${info.version}`:'Versione installata: non disponibile'; }
 catch { footer.textContent='Versione installata: non disponibile'; }
 if(mode==='users')await users(view);else if(mode==='mail')await mailSettings(view);else await home(view);
}
function editor(user,onSave){
 const name=input(user.display_alias||'',{required:true,maxlength:80}),login=input(user.login||'',{required:true,maxlength:190}),profession=choose([['educator','Educatore'],['psychologist','Psicologo']],user.profession||'psychologist'),organization=input(user.organization||'',{maxlength:160}),bio=h('textarea',{maxlength:2000});bio.value=user.biography||'';
 const status=choose([['active','Attivo'],['suspended','Sospeso']],user.status||'active'),button=h('button',{type:'submit',class:'primary'},user.id?'Salva professionista':'Crea professionista');
 return h('form',{class:'card',onsubmit:async e=>{e.preventDefault();button.disabled=true;try{await onSave({full_name:name.value,login:login.value,profession:profession.value,organization:organization.value,biography:bio.value,status:status.value});}catch(x){toast(errText(x));}finally{button.disabled=false;}}},
  h('div',{class:'grid g2'},field('Nome e cognome',name),field('Codice di accesso / e-mail',login),field('Professione dichiarata',profession),field('Organizzazione',organization)),field('Presentazione',bio),user.id?field('Stato dell’account',status):null,button,
  user.id?h('button',{type:'button',class:'danger',onclick:guard(async()=>{if(!confirm('Generare una nuova password e revocare le sessioni precedenti di questo professionista?'))return;const r=await api('POST',`/api/admin/users/${user.id}/reset`,{});credentials.replaceChildren(h('p',{},'Nuova password, visibile solo adesso:'),h('pre',{},r.password));})},'Reimposta password'):null,
  user.id?h('p',{class:'small muted'},'La sospensione blocca gli accessi del professionista e revoca le sue sessioni. Studenti, protocolli e dati restano conservati. Gli account studente non vengono sospesi automaticamente.'):null);
}
let credentials;
async function users(view){
 const d=await api('GET',`/api/admin/users?page=${page}&q=${encodeURIComponent(query)}`);credentials=h('div',{role:'status'});
 const search=input(query,{'aria-label':'Cerca professionista',placeholder:'Nome o codice di accesso'}),list=h('div',{class:'grid'});
 for(const u of d.users)list.append(h('details',{class:'card'},h('summary',{},`${u.display_alias||u.login} · ${u.status==='active'?'attivo':'sospeso'} · ${u.students} studenti`),editor(u,async body=>{await api('PUT',`/api/admin/users/${u.id}`,body);toast('Professionista aggiornato.');await render();})));
 view.append(h('p',{},'Gestione dei soli account professionali. Qualifiche autodichiarate; nessun elenco dei dati degli studenti.'),h('form',{class:'row',onsubmit:guard(async e=>{e.preventDefault();query=search.value;page=1;await render();})},search,h('button',{type:'submit'},'Cerca')),h('p',{},`${d.total} professionisti · pagina ${d.page}`),credentials,
  h('details',{class:'card'},h('summary',{},'Crea un professionista'),editor({},async body=>{const r=await api('POST','/api/admin/users',body);credentials.replaceChildren(h('strong',{},'Account creato. Conserva la password prima di lasciare la pagina.'),h('pre',{},`Login: ${r.login}\nPassword: ${r.password}`));})),list,
  h('div',{class:'row'},h('button',{disabled:page===1,onclick:guard(async()=>{page--;await render();})},'Precedente'),h('button',{disabled:page*d.page_size>=d.total,onclick:guard(async()=>{page++;await render();})},'Successiva')));
}
async function home(view){
 const d=await api('GET','/api/admin/home');let revision=d.revision,texts={...(d.draft.texts||{})},images={...(d.draft.images||{})};
 for(const key of Object.keys(texts))if(!d.fields.some(f=>f.key===key))delete texts[key];
 const status=h('p',{role:'status'}),fields=h('div',{class:'grid'}),filter=input('',{'aria-label':'Filtra testi',placeholder:'Cerca un testo della homepage'});
 const paint=()=>{fields.replaceChildren(...d.fields.filter(f=>(f.default+' '+(texts[f.key]||'')).toLowerCase().includes(filter.value.toLowerCase())).map(f=>{const ta=h('textarea',{maxlength:6000,rows:f.default.length>150?4:2,oninput:e=>{texts[f.key]=e.target.value;dirtyHome=true;}});ta.value=texts[f.key]??f.default;return field(f.default.slice(0,100)+(f.default.length>100?'…':''),ta);}));};filter.addEventListener('input',paint);paint();
 const save=async()=>{const r=await api('PUT','/api/admin/home',{revision,texts,images});revision=r.revision;dirtyHome=false;status.textContent='Bozza salvata. La homepage pubblica non è ancora cambiata.';};
 const media=h('div',{class:'grid g3'});
 for(const [slot,label] of [['hero','Immagine iniziale'],['dashboard','Dashboard'],['analysis','Analisi e conversazione']]){
  const alt=input(images[slot]?.alt||'',{maxlength:250,'aria-label':'Testo alternativo '+label}),caption=input(images[slot]?.caption||'',{maxlength:600,'aria-label':'Didascalia '+label}),file=input('',{type:'file',accept:'image/png,image/jpeg,image/webp','aria-label':'Carica '+label});
  const preview=h('div',{});const show=()=>preview.replaceChildren(images[slot]?h('img',{src:`${BASE}/api/site/media/${images[slot].id}`,alt:images[slot].alt,class:'admin-image-preview'}):h('p',{class:'small'},'Anteprima originale della homepage'));
  alt.addEventListener('input',()=>{if(images[slot]){images[slot].alt=alt.value;dirtyHome=true;}});caption.addEventListener('input',()=>{if(images[slot]){images[slot].caption=caption.value;dirtyHome=true;}});show();
  media.append(h('section',{class:'card'},h('h3',{},label),preview,field('Immagine PNG, JPEG o WebP · massimo 2 MB',file),field('Testo alternativo (obbligatorio per l’immagine)',alt),field('Didascalia',caption),
   h('button',{type:'button',onclick:guard(async()=>{const f=file.files?.[0];if(!f||f.size>2000000){toast('Scegli un’immagine di massimo 2 MB.');return;}if(!alt.value.trim()){toast('Inserisci il testo alternativo.');return;}const data=await new Promise((resolve,reject)=>{const reader=new FileReader();reader.onload=()=>resolve(String(reader.result).split(',')[1]);reader.onerror=reject;reader.readAsDataURL(f);});const r=await api('POST','/api/admin/home/image',{data});images[slot]={id:r.id,alt:alt.value,caption:caption.value};dirtyHome=true;show();status.textContent='Immagine caricata. Salva la bozza per usarla nella homepage.';})},'Carica / sostituisci'),
   h('button',{type:'button',onclick:()=>{delete images[slot];dirtyHome=true;show();status.textContent='Immagine rimossa dalla bozza: verrà mostrata l’anteprima originale. Salva e pubblica per applicare.';}},'Ripristina anteprima originale')));
 }
 const buttons=h('div',{class:'row'},h('button',{class:'primary',onclick:guard(save)},'Salva bozza'),h('button',{onclick:guard(async()=>{await save();window.open(`${BASE}/?preview_home=1`,'_blank','noopener');})},'Salva e apri anteprima'),h('button',{class:'accent',onclick:guard(async()=>{await save();const r=await api('POST','/api/admin/home/publish',{revision});revision=r.revision;status.textContent='Homepage pubblicata. I visitatori vedranno i nuovi contenuti al caricamento.';})},'Pubblica homepage'),h('button',{onclick:guard(async()=>{if(!confirm('Ripristinare testi e immagini originali nella bozza?'))return;texts={};images={};await save();await render();})},'Prepara bozza originale'));
 view.append(h('h2',{},'Contenuti della homepage'),h('p',{},'Modifica i testi senza HTML. Le immagini devono contenere dati dimostrativi e nessun dato personale. Le bozze non vengono mostrate ai visitatori finché non premi Pubblica.'),status,buttons,h('h3',{},'Immagini'),media,h('h3',{},'Testi'),filter,fields,
  h('details',{class:'card'},h('summary',{},'Ultime pubblicazioni · ripristina in bozza'),...d.versions.map(v=>h('div',{class:'row'},h('span',{},`Versione ${v.id} · ${v.created_at} UTC`),h('button',{onclick:guard(async()=>{await api('POST',`/api/admin/home/restore/${v.id}`,{revision});await render();toast('Versione ripristinata in bozza: verifica l’anteprima e pubblica.');})},'Ripristina in bozza')))));
}

async function mailSettings(view) {
  const r=await api('GET','/api/admin/mail'),c=r.config;
  const enabled=input('',{type:'checkbox',checked:!!c.enabled}),from=input(c.from||'',{type:'email',required:true,maxlength:190}),url=input(c.public_url||'',{type:'url',required:true,maxlength:500,placeholder:'https://tuodominio.it/ablet'}),to=input('',{type:'email',required:true,maxlength:190});
  const status=h('p',{role:'status'}),save=h('button',{type:'submit',class:'primary'},'Salva configurazione'),test=h('button',{type:'submit'},'Invia e-mail di prova');
  const form=h('form',{class:'card',onsubmit:async e=>{e.preventDefault();save.disabled=true;try{const result=await api('PUT','/api/admin/mail',{enabled:enabled.checked,from:from.value,public_url:url.value});status.textContent=result.available?'Configurazione salvata. Verifica ora la ricezione con una prova.':'Configurazione salvata; recupero non attivo. Controlla abilitazione e disponibilità di PHP mail sul server.';}catch(x){status.textContent=errText(x);}finally{save.disabled=false;}}},
    h('label',{class:'inline'},enabled,'Abilita recupero password via e-mail'),field('Mittente · casella del tuo dominio',from),field('URL pubblico esatto dell’installazione ABLET',url),h('p',{class:'small muted'},'Il collegamento usa questo URL, non l’indirizzo ricevuto dal browser. Inserisci la cartella di ABLET e usa HTTPS.'),save);
  view.append(h('h2',{},'Posta e recupero password'),h('p',{},'Gli account professionali e admin con un’e-mail come codice di accesso possono richiedere un link monouso di 30 minuti. Per i codici senza e-mail rimane disponibile il reset amministrativo.'),form,
    h('form',{class:'card',onsubmit:async e=>{e.preventDefault();test.disabled=true;try{const result=await api('POST','/api/admin/mail/test',{to:to.value});status.textContent=result.accepted?(result.simulated?'Prova simulata: nessuna e-mail reale inviata.':'Il server ha accettato la prova. Controlla la casella destinataria e la posta indesiderata: non è una conferma di consegna.'):'Il server non ha accettato l’invio. Verifica PHP mail e il mittente nel pannello hosting.';}catch(x){status.textContent=errText(x);}finally{test.disabled=false;}}},h('h3',{},'Prova di invio'),field('Destinatario della prova',to),h('p',{class:'small muted'},'La prova invia solo un messaggio tecnico, senza password o link di accesso. Salva prima la configurazione.'),test),status);
}
