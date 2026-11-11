import { after, test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, rmSync, writeFileSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { pathToFileURL } from 'node:url';
import { build } from 'esbuild';

const temporary = mkdtempSync(join(import.meta.dirname, '.tmp-invitation-'));
after(() => { rmSync(temporary, { recursive: true, force: true }); delete globalThis.invitationTest; });
const bundle = await build({
    entryPoints: [resolve(import.meta.dirname, '../../resources/js/Pages/Auth/AcceptWorkInvitation.tsx')],
    bundle: true, packages: 'external', platform: 'node', format: 'esm', jsx: 'automatic', write: false,
    plugins: [{ name: 'invitation-interactions', setup(builder) {
        builder.onResolve({ filter: /^react$/ }, () => ({ path: 'react', namespace: 'invitation' }));
        builder.onResolve({ filter: /^@inertiajs\/react$/ }, () => ({ path: 'inertia', namespace: 'invitation' }));
        builder.onResolve({ filter: /^axios$/ }, () => ({ path: 'axios', namespace: 'invitation' }));
        builder.onResolve({ filter: /Layouts\/GuestLayout$/ }, () => ({ path: 'layout', namespace: 'invitation' }));
        builder.onLoad({ filter: /.*/, namespace: 'invitation' }, ({ path }) => ({ contents: {
            react: `export const useState = value => globalThis.invitationTest.state(value);`,
            inertia: `export const Head='head', Link='a';`,
            axios: `export default {post:(...args)=>globalThis.invitationTest.post(...args),isAxiosError:error=>error?.isAxiosError===true};`,
            layout: `export default function Layout(){}`,
        }[path] }));
    } }],
});
const filename = join(temporary, 'invitation.mjs');
writeFileSync(filename, bundle.outputFiles[0].text);
const { default: Invitation } = await import(pathToFileURL(filename).href);
function harness() {
    const slots=[]; let cursor=0;
    const h={ calls: [], reply: async () => ({data:{message:'Sent'}}),
        state(initial) {const i=cursor++; if(!(i in slots)) slots[i]=initial; return [slots[i],next=>{slots[i]=next;}];},
        post(...args) {h.calls.push(args);return h.reply(...args);},
        render() {globalThis.invitationTest=h;cursor=0;return Invitation();},
    };return h;
}
function nodes(tree, predicate) {
    if(!tree || typeof tree!=='object') return [];
    if(Array.isArray(tree)) return tree.flatMap(value=>nodes(value,predicate));
    return [...(predicate(tree)?[tree]:[]),...nodes(tree.props?.children,predicate)];
}
const field=(tree,type)=>nodes(tree,n=>n.type==='input' && n.props.type===type)[0];
const forms=tree=>nodes(tree,n=>n.type==='form');
const click=async form=>{form.props.onSubmit({preventDefault(){}});await new Promise(resolve=>setImmediate(resolve));};
async function verify(h) {
    field(h.render(),'email').props.onChange({target:{value:'worker@bagoo.test'}});
    await click(forms(h.render())[0]);
    nodes(h.render(),n=>n.type==='input' && n.props.autoComplete==='one-time-code')[0].props.onChange({target:{value:'123456'}});
    h.reply=async()=>({data:{message:'Verified',token:'verified-setup',assignment_token:'assignment-signature',assignment:{company:'Assigned Company',hub:'Assigned Hub'}}});
    await click(forms(h.render())[1]);
}
test('verified server assignment and explicit consent accompany activation; no password survives success',async()=>{
    const h=harness();await verify(h);
    let tree=h.render();
    assert.equal(field(tree,'email').props.disabled,true);
    const passwords=nodes(tree,n=>n.type==='input' && n.props.type==='password');
    passwords[0].props.onChange({target:{value:'Worker-Own-Secret!4826'}});
    passwords[1].props.onChange({target:{value:'Worker-Own-Secret!4826'}});
    field(tree,'checkbox').props.onChange({target:{checked:true}});
    h.reply=async()=>({data:{message:'Activated'}});
    await click(forms(h.render())[1]);
    const [path,payload]=h.calls.at(-1);
    assert.equal(path,'/work-account/activate');
    assert.equal(payload.assignment_confirmed,true);
    assert.equal(payload.assignment_token,'assignment-signature');
    assert.equal(payload.token,'verified-setup');
    tree=h.render();
    assert.equal(nodes(tree,n=>n.type==='input' && n.props.type==='password').length,0);
    assert.equal(nodes(tree,n=>n.type==='a')[0].props.href,'/hub/login');
});
test('failed activation clears password fields and keeps the recoverable assignment',async()=>{
    const h=harness();await verify(h);
    const passwords=nodes(h.render(),n=>n.type==='input' && n.props.type==='password');
    for(const input of passwords)input.props.onChange({target:{value:'Worker-Own-Secret!4826'}});
    h.reply=async()=>{throw {isAxiosError:true,response:{data:{errors:{token:['Code expired. Request another.']}}}};};
    await click(forms(h.render())[1]);
    const tree=h.render();
    assert.equal(nodes(tree,n=>n.props?.role==='alert')[0].props.children,'Code expired. Request another.');
    assert.ok(nodes(tree,n=>n.type==='input' && n.props.type==='password').every(n=>n.props.value===''));
    nodes(tree,n=>n.type==='button' && n.props.type==='button')[0].props.onClick();
    assert.equal(field(h.render(),'email').props.disabled,false);
    assert.equal(nodes(h.render(),n=>n.type==='input' && n.props.type==='password').length,0);
});
test('mail failure stays on the email step and allows retry',async()=>{
    const h=harness();
    field(h.render(),'email').props.onChange({target:{value:'worker@bagoo.test'}});
    h.reply=async()=>{throw {isAxiosError:true,response:{data:{message:'Delivery unavailable.'}}};};
    await click(forms(h.render())[0]);
    assert.equal(forms(h.render()).length,1);
    assert.equal(nodes(h.render(),n=>n.props?.role==='alert')[0].props.children,'Delivery unavailable.');
});
