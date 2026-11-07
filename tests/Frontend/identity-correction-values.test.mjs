import { after, test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, rmSync, writeFileSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { pathToFileURL } from 'node:url';
import { buildSync } from 'esbuild';

const temporary = mkdtempSync(join(import.meta.dirname, '.tmp-correction-'));
after(() => rmSync(temporary, { recursive: true, force: true }));
const bundle = buildSync({ entryPoints: [resolve(import.meta.dirname, '../../resources/js/utils/identityCorrectionValues.ts')], bundle: true, platform: 'node', format: 'esm', write: false });
const modulePath = join(temporary, 'values.mjs');
writeFileSync(modulePath, bundle.outputFiles[0].text);
const { appendCorrectionChanges, correctionValueLabel } = await import(pathToFileURL(modulePath).href);

test('multipart corrections preserve service regions as indexed lists beside scalar details', () => {
    const body = new FormData();
    appendCorrectionChanges(body, { service_areas: ['NCR', 'Region IV-A'], company_name: 'Bagoo & Partners', birthday: null, root_category_id: 4 });
    assert.deepEqual([...body.entries()], [
        ['changes[service_areas][0]', 'NCR'], ['changes[service_areas][1]', 'Region IV-A'],
        ['changes[company_name]', 'Bagoo & Partners'], ['changes[birthday]', ''], ['changes[root_category_id]', '4'],
    ]);
    assert.equal(body.has('changes[service_areas]'), false);
});

test('an empty region choice never becomes a scalar empty string', () => {
    const body = new FormData();
    appendCorrectionChanges(body, { service_areas: [] });
    assert.equal(body.has('changes[service_areas]'), false);
    assert.equal(correctionValueLabel([]), 'None selected');
});

test('region lists have readable current and review history labels', () => {
    assert.equal(correctionValueLabel(['NCR', 'Region IV-A']), 'NCR, Region IV-A');
    assert.equal(correctionValueLabel(null), 'Not recorded');
    assert.equal(correctionValueLabel(12), 12);
});
