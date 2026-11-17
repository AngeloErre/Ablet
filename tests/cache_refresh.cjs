// node tests/cache_refresh.cjs — senza browser e senza dipendenze.
const {readFileSync} = require('node:fs');
const {join} = require('node:path');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const root = join(__dirname,'..'); let pass = 0;
function check(name,fn) {fn();console.log('✓ '+name);pass++;}
(async()=>{
 const removed=[], unregistered=[];
 const keys = {
   'ablet-v3-free-activity':['https://example.test/ablet/assets/psy.js','https://example.test/other/assets/app.js'],
   'ablet-%2Fablet%2F-v4-cache-refresh':['https://example.test/ablet/assets/app.js?v=old'],
   'unrelated-app':['https://example.test/ablet/picture.png']
 };
 const registrations=['https://example.test/ablet/','https://example.test/','https://example.test/other/'].map(scope=>({scope,active:{scriptURL:scope+'sw.js'},unregister:async()=>{unregistered.push(scope);return true;}}));
 const caches={keys:async()=>Object.keys(keys),open:async name=>({keys:async()=>keys[name].map(url=>({url})),delete:async request=>{removed.push([name,request.url]);return true;}})};
 const source=readFileSync(join(root,'public/assets/cache-tools.js'),'utf8').replace('export async function','async function');
 const context=vm.createContext({URL,location:{origin:'https://example.test'},window:{caches},caches,navigator:{serviceWorker:{getRegistrations:async()=>registrations}}});
 vm.runInContext(source,context);
 const result=await context.clearAppCache('/ablet');
 check('Rimuove i due file vecchi della sola installazione ABLET',()=>assert.equal(result.removed,2));
 check('Conserva file di altre installazioni anche nelle cache ABLET legacy',()=>assert(!removed.some(x=>x[1].includes('/other/'))));
 check('Conserva cache di applicazioni estranee',()=>assert(!removed.some(x=>x[0]==='unrelated-app')));
 check('Rimuove solo il service worker con ambito esatto',()=>assert.deepEqual(unregistered,['https://example.test/ablet/']));
 check('Destinazione di ricarica corretta',()=>assert.equal(result.url,'https://example.test/ablet/'));
 const noFeatures=vm.createContext({URL,location:{origin:'https://example.test'},window:{},navigator:{}});vm.runInContext(source,noFeatures);
 check('Funziona anche senza cache o service worker supportati',()=>assert.equal(typeof noFeatures.clearAppCache,'function'));
 assert.equal((await noFeatures.clearAppCache('/ablet')).removed,0);
 const events={},deleted=[],puts=[]; let offline=false;let fetched=null;
 const worker=vm.createContext({URL,Response,self:{registration:{scope:'https://example.test/ablet/'},addEventListener:(name,fn)=>events[name]=fn,clients:{claim:async()=>{}},skipWaiting:async()=>{}},
 caches:{keys:async()=>['ablet-%2Fablet%2F-old','ablet-%2Fother%2F-old','other-cache'],delete:async name=>deleted.push(name),open:async()=>({match:async()=>new Response('old'),put:async(req,res)=>puts.push(await res.text()),addAll:async()=>{}})},
 fetch:async(req,opts)=>{fetched={req,opts};if(offline)throw Error('offline');return new Response('new');}});
 vm.runInContext(readFileSync(join(root,'public/sw.js'),'utf8'),worker);
 let pending; events.activate({waitUntil:p=>pending=p});await pending;
 check('Attivazione non elimina cache di altri siti o installazioni',()=>assert.deepEqual(deleted,['ablet-%2Fablet%2F-old']));
 const request={method:'GET',url:'https://example.test/ablet/assets/psy.js',mode:'cors'};
 events.fetch({request,respondWith:p=>pending=p});let response=await pending;
 const onlineText=await response.text();
 check('Quando online serve subito il file nuovo',()=>assert.equal(onlineText,'new'));
 check('Rivalida la cache HTTP per JavaScript',()=>assert.equal(fetched.opts.cache,'no-cache'));
 check('Conserva una copia del nuovo file per uso senza rete',()=>assert.equal(puts[0],'new'));
 offline=true;events.fetch({request,respondWith:p=>pending=p});response=await pending;
 check('Senza rete conserva il fallback alla cache',()=>assert.equal(response.status,200));assert.equal(await response.text(),'old');
 let intercepted=false;
 for(const path of ['api/student/home','refresh.php','install.php']) events.fetch({request:{...request,url:'https://example.test/ablet/'+path},respondWith:()=>{intercepted=true;}});
 check('API e pagine di installazione/ripristino non vengono intercettate',()=>assert.equal(intercepted,false));
 console.log(`Superati: ${pass}`);
})().catch(error=>{console.error(error);process.exit(1);});
