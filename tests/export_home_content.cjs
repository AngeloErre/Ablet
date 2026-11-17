// Rigenera il catalogo dei testi dal renderer originale della homepage.
const fs=require('node:fs'),path=require('node:path'),vm=require('node:vm');
const dir=__dirname;let code=fs.readFileSync(path.join(dir,'login_home.cjs'),'utf8');code=code.slice(0,code.indexOf("const home=render('')"));
code+=`\nconst unique=new Map();for(const node of render('')){if(node.tag!=='#text')continue;const text=node.text.trim();if(!text)continue;let n=2166136261;for(let i=0;i<text.length;i++){n^=text.charCodeAt(i);n=Math.imul(n,16777619);}const key='t_'+(n>>>0).toString(16)+'_'+text.length;if(unique.has(key)&&unique.get(key).default!==text)throw Error('collision');unique.set(key,{key,default:text});}fs.writeFileSync(path.join(__dirname,'../resources/home-content.json'),JSON.stringify([...unique.values()],null,2)+'\\n');console.log(unique.size+' testi catalogati.');`;
vm.runInNewContext(code,{require,__dirname:dir,console,setTimeout,clearTimeout,URLSearchParams});
