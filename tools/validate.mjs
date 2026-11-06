import 'global-jsdom/register';
import fs from 'node:fs';
import { createRequire } from 'node:module';
globalThis.matchMedia = globalThis.matchMedia || (()=>({matches:false,addListener(){},removeListener(){},addEventListener(){},removeEventListener(){}}));
globalThis.window.matchMedia = globalThis.matchMedia;
globalThis.IntersectionObserver = class{observe(){}unobserve(){}disconnect(){}};
globalThis.ResizeObserver = class{observe(){}unobserve(){}disconnect(){}};
const { registerCoreBlocks } = createRequire(import.meta.url)('@wordpress/block-library');
const { parse, validateBlock } = createRequire(import.meta.url)('@wordpress/blocks');
registerCoreBlocks();
let bad=0;
for (const f of process.argv.slice(2)) {
  const src = fs.readFileSync(f,'utf8');
  const walk=(bs,depth=0)=>{for(const b of bs){ if(b.name && b.isValid===false){bad++;console.log(`INVALID ${f}: ${b.name}`);const v=b.validationIssues||[];for(const i of v.slice(0,2))console.log('   ',JSON.stringify(i.args?.slice?.(0,3)).slice(0,700));}
    if(b.name==='core/missing'){bad++;console.log('MISSING',f,b.attributes.originalName);}
    walk(b.innerBlocks||[],depth+1);}};
  walk(parse(src));
}
console.log(bad? `${bad} invalid`:'all valid');
process.exit(bad?1:0);
