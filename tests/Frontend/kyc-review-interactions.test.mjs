import { after, test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, rmSync, writeFileSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { pathToFileURL } from 'node:url';
import { build } from 'esbuild';

const temporary = mkdtempSync(join(import.meta.dirname, '.tmp-kyc-review-'));
const originalDocument = globalThis.document;
const originalRoute = globalThis.route;
after(() => {
    rmSync(temporary, { recursive: true, force: true });
    delete globalThis.kycReviewHarness;
    if (originalDocument === undefined) delete globalThis.document; else globalThis.document = originalDocument;
    if (originalRoute === undefined) delete globalThis.route; else globalThis.route = originalRoute;
});
const bundle = await build({
    entryPoints: [resolve(import.meta.dirname, '../../resources/js/Pages/Admin/KycQueue.tsx')],
    bundle: true, packages: 'external', platform: 'node', format: 'esm', jsx: 'automatic', write: false,
    plugins: [{ name: 'kyc-review-interactions', setup(builder) {
        builder.onResolve({ filter: /^react$/ }, () => ({ path: 'hooks', namespace: 'kyc' }));
        builder.onResolve({ filter: /^react-dom$/ }, () => ({ path: 'portal', namespace: 'kyc' }));
        builder.onResolve({ filter: /^@inertiajs\/react$/ }, () => ({ path: 'inertia', namespace: 'kyc' }));
        builder.onResolve({ filter: /^@headlessui\/react$/ }, () => ({ path: 'dialog', namespace: 'kyc' }));
        builder.onResolve({ filter: /Layouts\/DashboardLayout$/ }, () => ({ path: 'layout', namespace: 'kyc' }));
        builder.onLoad({ filter: /.*/, namespace: 'kyc' }, ({ path }) => ({ contents: {
            hooks: `export const useState=v=>globalThis.kycReviewHarness.state(v); export const useRef=v=>useState({current:v})[0]; export const useEffect=()=>{}; export default {};`,
            portal: `export const createPortal=children=>children;`,
            inertia: `export const Head='head'; export const router={get(){},post:(...args)=>globalThis.kycReviewHarness.approvals.push(args)}; export const useForm=initial=>globalThis.kycReviewHarness.form(initial);`,
            dialog: `export const Dialog='dialog',DialogPanel='section',DialogTitle='h3';`,
            layout: `export default function Layout(){}`,
        }[path] }));
    } }],
});
const modulePath = join(temporary, 'kyc.mjs'); writeFileSync(modulePath, bundle.outputFiles[0].text);
const { default: KycQueue } = await import(pathToFileURL(modulePath).href);
globalThis.document = { body: { style: {} } };
globalThis.route = (name, value) => '/' + name.replaceAll('.', '/') + (typeof value === 'number' ? '/' + value : '');

const applicant = (id, name) => ({ id, name, email: `applicant-${id}@bagoo.test`, role: 'seller', status: 'active',
    kyc_status: 'pending_approval', created_at: '2026-11-01T00:00:00Z', review_age: 30,
    required_documents: ['id'], id_document_path: `/private/kyc-${id}.jpg`, review_token: `review-${id}`,
    review_issues: [], decision_history: [], application_details: { fields: [], values: {}, errors: {} } });
const applicants = [applicant(1, 'Alma Santos'), applicant(2, 'Berto Cruz')];
const props = {
    applicants: { data: applicants, links: [], current_page: 1, last_page: 1, total: 2, from: 1, to: 2 },
    filters: { search: '', status: 'pending_approval', role: 'all' },
    stats: { pending_count: 2, approved_count: 0, rejected_count: 0, total_count: 2, pending_sellers: 2, pending_couriers: 0, pending_buyers: 0 },
};
function harness() {
    const slots = []; let cursor = 0;
    const h = {
        approvals: [], rejections: [], rejectProcessing: false, rejectErrors: {},
        state(initial) { const i = cursor++; if (!(i in slots)) slots[i] = typeof initial === 'function' ? initial() : initial; return [slots[i], next => { slots[i] = typeof next === 'function' ? next(slots[i]) : next; }]; },
        form(initial) {
            const [data, update] = h.state(initial);
            return { data, setData: (key, value) => update(typeof key === 'object' ? key : previous => ({ ...previous, [key]: value })),
                processing: h.rejectProcessing, errors: h.rejectErrors, clearErrors: () => { h.rejectErrors = {}; },
                reset: () => update(initial), post(url, options) {
                    h.rejectProcessing = true;
                    h.rejections.push({ url, data: structuredClone(data), options: { ...options,
                        onFinish: () => { h.rejectProcessing = false; options.onFinish?.(); } } });
                } };
        },
        render() { globalThis.kycReviewHarness = h; cursor = 0; return KycQueue(props); },
    };
    return h;
}
function nodes(tree, predicate) {
    if (!tree || typeof tree !== 'object') return [];
    if (Array.isArray(tree)) return tree.flatMap(child => nodes(child, predicate));
    return [...(predicate(tree) ? [tree] : []), ...nodes(tree.props?.children, predicate)];
}
function text(tree) {
    if (tree == null || typeof tree === 'boolean') return '';
    if (typeof tree !== 'object') return String(tree);
    return Array.isArray(tree) ? tree.map(text).join('') : text(tree.props?.children);
}
const button = (tree, label) => nodes(tree, node => node.type === 'button' && text(node) === label)[0];
const row = (tree, name) => nodes(tree, node => node.type === 'tr' && text(node).includes(name))[0];
const inspect = (tree, name) => nodes(row(tree, name), node => node.type === 'button' && node.props.title === 'Inspect Submitted Documents')[0];
const chip = (tree, name) => button(row(tree, name), 'Gov ID');
const modalButton = (tree, label) => button(dialogs(tree)[0], label);
const acknowledgement = tree => nodes(tree, node => node.type === 'input' && node.props.type === 'checkbox')[0];
const currentImage = tree => nodes(tree, node => node.type === 'img')[0];
const dialogs = tree => nodes(tree, node => node.type === 'dialog');
const rejectForm = tree => nodes(tree, node => node.type === 'form' && text(node).includes('Confirm Rejection'))[0];

test('opening another applicant through a document chip requires a fresh evidence acknowledgement', () => {
    const h = harness(); let tree = h.render(); inspect(tree, 'Alma Santos').props.onClick();
    tree = h.render(); currentImage(tree).props.onLoad(); tree = h.render();
    acknowledgement(tree).props.onChange({ target: { checked: true } });
    tree = h.render(); assert.equal(button(tree, 'Approve Submission').props.disabled, false);
    button(tree, 'Close').props.onClick(); tree = h.render(); chip(tree, 'Berto Cruz').props.onClick();
    tree = h.render(); assert.equal(acknowledgement(tree).props.checked, false);
    assert.equal(button(tree, 'Approve Submission').props.disabled, true);
    button(tree, 'Approve Submission').props.onClick(); assert.equal(h.approvals.length, 0);
    currentImage(tree).props.onLoad(); tree = h.render();
    acknowledgement(tree).props.onChange({ target: { checked: true } });
    button(h.render(), 'Approve Submission').props.onClick();
    assert.equal(h.approvals.length, 1);
    assert.equal(h.approvals[0][0], '/admin/kyc/approve/2');
    assert.deepEqual(h.approvals[0][1], { review_token: 'review-2', evidence_confirmed: true });
});

test('a previous applicant review error clears when the next document chip opens', () => {
    const h = harness(); let tree = h.render(); inspect(tree, 'Alma Santos').props.onClick();
    tree = h.render(); currentImage(tree).props.onLoad(); tree = h.render(); acknowledgement(tree).props.onChange({ target: { checked: true } });
    button(h.render(), 'Approve Submission').props.onClick();
    h.approvals[0][2].onError({ review_token: 'Alma review is stale.' }); h.approvals[0][2].onFinish();
    tree = h.render(); assert.ok(text(tree).includes('Alma review is stale.'));
    button(tree, 'Close').props.onClick(); chip(h.render(), 'Berto Cruz').props.onClick();
    assert.equal(text(h.render()).includes('Alma review is stale.'), false);
});

test('same-tick approval duplicates and a competing rejection cannot start another decision', () => {
    const h = harness(); let tree = h.render(); inspect(tree, 'Alma Santos').props.onClick();
    tree = h.render(); currentImage(tree).props.onLoad(); tree = h.render(); acknowledgement(tree).props.onChange({ target: { checked: true } }); tree = h.render();
    const approve = button(tree, 'Approve Submission').props.onClick;
    const reject = button(tree, 'Reject with Reason').props.onClick;
    approve(); approve(); reject();
    assert.equal(h.approvals.length, 1);
    tree = h.render();
    assert.equal(text(tree).includes('Reject Application & Provide Feedback'), false);
    button(tree, 'Close').props.onClick(); dialogs(tree)[0].props.onClose(false);
    assert.equal(dialogs(h.render()).length, 1, 'An in-flight approval keeps its original inspector open.');
    h.approvals[0][2].onFinish();
    button(h.render(), 'Reject with Reason').props.onClick();
    assert.ok(text(h.render()).includes('Reject Application & Provide Feedback'));
});

test('same-tick rejection duplicates and competing approval retain one original decision payload', () => {
    const h = harness(); let tree = h.render(); inspect(tree, 'Alma Santos').props.onClick();
    tree = h.render(); currentImage(tree).props.onLoad(); tree = h.render(); acknowledgement(tree).props.onChange({ target: { checked: true } }); tree = h.render();
    const approve = button(tree, 'Approve Submission').props.onClick;
    button(tree, 'Reject with Reason').props.onClick(); tree = h.render();
    nodes(tree, node => node.type === 'textarea')[0].props.onChange({ target: { value: 'Please replace the unreadable photo.' } });
    tree = h.render(); const submit = rejectForm(tree).props.onSubmit;
    submit({ preventDefault() {} }); submit({ preventDefault() {} }); approve();
    assert.equal(h.rejections.length, 1); assert.equal(h.approvals.length, 0);
    assert.deepEqual(h.rejections[0].data, { reason: 'Please replace the unreadable photo.', review_token: 'review-1' });
    h.rejections[0].options.onFinish();
    rejectForm(h.render()).props.onSubmit({ preventDefault() {} });
    assert.equal(h.rejections.length, 2, 'A finished failure can be retried.');
});

test('the rejection dialog nests over inspection and close returns to the same applicant', () => {
    const h = harness(); let tree = h.render(); inspect(tree, 'Alma Santos').props.onClick();
    tree = h.render(); assert.equal(dialogs(tree).length, 1);
    button(tree, 'Reject with Reason').props.onClick(); tree = h.render();
    const [inspector, rejection] = dialogs(tree); assert.ok(rejection);
    assert.equal(dialogs(inspector).length, 2, 'Rejection shares the inspector dialog context.');
    rejection.props.onClose(false); tree = h.render();
    assert.equal(dialogs(tree).length, 1); assert.ok(text(dialogs(tree)[0]).includes('Alma Santos'));
    assert.equal(acknowledgement(tree).props.checked, false);
    assert.equal(h.approvals.length + h.rejections.length, 0);
});

test('required evidence chips show review-needed dots until the protected document loads', () => {
    const h = harness(); let tree = h.render();
    const id = chip(tree, 'Alma Santos');
    assert.match(id.props.className, /border-rose-300/);
    assert.equal(nodes(id, node => node.type === 'span' && node.props.className?.includes('bg-rose-600')).length, 1);
    id.props.onClick(); tree = h.render();
    assert.match(button(tree, 'Gov ID').props.className, /border-rose-300/);
    assert.equal(nodes(tree, node => node.type === 'details')[0].props.open, true);
    assert.ok(text(tree).includes('open this document here to record its secure review'));
    assert.equal(acknowledgement(tree).props.disabled, true);
    assert.equal(nodes(tree, node => node.type === 'a' && text(node).includes('Full Resolution')).length, 0);
    currentImage(tree).props.onLoad(); tree = h.render();
    assert.match(button(tree, 'Gov ID').props.className, /border-emerald-200/);
    assert.equal(acknowledgement(tree).props.disabled, false);
});

test('every role-required document must load in the inspector before the applicant can attest', () => {
    const h = harness(); let tree = h.render();
    const applicantWithPermit = { ...applicant(3, 'Cora Diaz'), required_documents: ['id', 'permit'], business_permit_path: '/private/permit-3.jpg' };
    props.applicants.data = [applicantWithPermit];
    try {
        tree = h.render(); inspect(tree, 'Cora Diaz').props.onClick(); tree = h.render();
        currentImage(tree).props.onLoad(); tree = h.render();
        assert.equal(acknowledgement(tree).props.disabled, true);
        modalButton(tree, 'Business Permit').props.onClick(); tree = h.render();
        currentImage(tree).props.onLoad(); tree = h.render();
        assert.equal(acknowledgement(tree).props.disabled, false);
    } finally {
        props.applicants.data = applicants;
    }
});

test('a standalone rejection remains open while pending and has an associated feedback field', () => {
    const h = harness(); let tree = h.render();
    nodes(row(tree, 'Alma Santos'), node => node.type === 'button' && node.props.title === 'Reject with Feedback')[0].props.onClick();
    tree = h.render(); assert.equal(dialogs(tree).length, 1);
    const field = nodes(tree, node => node.type === 'textarea')[0];
    assert.ok(field.props.id); assert.ok(nodes(tree, node => node.type === 'label' && node.props.htmlFor === field.props.id).length);
    field.props.onChange({ target: { value: 'Please provide a readable document.' } });
    rejectForm(h.render()).props.onSubmit({ preventDefault() {} });
    tree = h.render(); dialogs(tree)[0].props.onClose(false); button(tree, 'Cancel').props.onClick();
    assert.equal(dialogs(h.render()).length, 1);
    h.rejections[0].options.onFinish(); dialogs(h.render())[0].props.onClose(false);
    assert.equal(dialogs(h.render()).length, 0);
});
