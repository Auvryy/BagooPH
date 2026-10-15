const fs = require('node:fs');
const path = require('node:path');

// Use an existing verification tool; the application gains no runtime dependency.
let Ajv;
try {
    Ajv = require(process.env.AJV_2020_MODULE || 'ajv/dist/2020.js').default;
} catch {
    process.stderr.write('Set AJV_2020_MODULE to an installed Ajv 2020 module before running this contract check.\n');
    process.exit(1);
}

const root = path.resolve(__dirname, '..');
const spec = JSON.parse(fs.readFileSync(path.join(root, 'docs/api/rider-operations.openapi.json')));
const ajv = new Ajv({strict: false, allErrors: true, validateFormats: false});
const uri = 'urn:bagoo:rider:company';
ajv.addSchema({$id: uri, components: spec.components});
const validators = new Map(Object.keys(spec.components.schemas).map(name => [name, ajv.compile({$ref: `${uri}#/components/schemas/${name}`})]));
let checked = 0;

function check(schema, value, source, expected = true) {
    const validate = validators.get(schema);
    if (!validate) throw new Error(`Unknown schema: ${schema}`);
    if (validate(value) !== expected) {
        throw new Error(JSON.stringify({schema, source, errors: validate.errors}));
    }
    checked++;
}

const examples = path.join(root, 'docs/api/examples/rider-company');
const manifest = JSON.parse(fs.readFileSync(path.join(examples, 'manifest.json')));
for (const [file, schema] of Object.entries(manifest)) {
    if (path.basename(file) !== file) throw new Error('Example paths must be local filenames.');
    check(schema, JSON.parse(fs.readFileSync(path.join(examples, file))), file);
}

let captured = 0;
if (process.argv[2]) {
    const directory = path.resolve(process.argv[2]);
    for (const file of fs.readdirSync(directory).filter(file => file.endsWith('.json'))) {
        check(file.slice(0, -5).split('--')[0], JSON.parse(fs.readFileSync(path.join(directory, file))), file);
        captured++;
    }
    if (!captured) throw new Error('No actual response samples were captured.');
}

for (const value of [true, '01', '0', '1e0', '9223372036854775808', [], -1]) {
    check('CompanyCounterInput', value, 'invalid counter', false);
}
for (const value of [1, '1', '9223372036854775807']) {
    check('CompanyCounterInput', value, 'valid counter');
}
const acceptance = {expected_version: 2, offer_id: '1', terms_hash: 'a'.repeat(64), acknowledged: true};
check('CompanyAcceptBody', acceptance, 'true acceptance');
for (const value of [1, '1', 'true', false, null]) {
    check('CompanyAcceptBody', {...acceptance, acknowledged: value}, 'invalid acknowledgement', false);
}
check('CompanyAcceptBody', {...acceptance, company_id: '1'}, 'forged managed input', false);

const methods = Object.values(spec.paths).flatMap(item => Object.values(item).map(operation => operation.operationId));
if (new Set(methods).size !== methods.length) throw new Error('Operation IDs must be unique.');
const companyMethods = Object.entries(spec.paths).filter(([name]) => /^\/rider\/compan(?:ies|y-)/.test(name))
    .reduce((total, [, item]) => total + Object.keys(item).length, 0);
if (companyMethods !== 10) throw new Error('The company contract must document all ten methods.');
process.stdout.write(JSON.stringify({passed: true, compiledSchemas: validators.size, checked, captured,
    publishedExamples: Object.keys(manifest).length, companyMethods}) + '\n');
