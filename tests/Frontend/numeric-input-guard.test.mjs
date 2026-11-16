import { after, test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, rmSync, writeFileSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { pathToFileURL } from 'node:url';
import { buildSync } from 'esbuild';

const temporary = mkdtempSync(join(import.meta.dirname, '.tmp-numeric-'));
after(() => rmSync(temporary, { recursive: true, force: true }));
const bundle = buildSync({ entryPoints: [resolve(import.meta.dirname, '../../resources/js/utils/numericInputGuard.ts')], bundle: true, platform: 'node', format: 'esm', write: false });
const path = join(temporary, 'guard.mjs');
writeFileSync(path, bundle.outputFiles[0].text);
const { acceptsNumeric, numericRules } = await import(pathToFileURL(path).href);

const field = (attributes, type = 'number') => ({ type, getAttribute: name => attributes[name] ?? null });

test('weights and sizes take digits and one decimal point only', () => {
    const weight = numericRules(field({ step: '0.001', min: '0.001', inputmode: 'decimal' }));
    assert.deepEqual(weight, { decimal: true, negative: false });
    for (const ok of ['', '1', '12.5', '0.001', '.5']) assert.ok(acceptsNumeric(ok, weight), ok);
    for (const bad of ['xf', '1x', 'e', '1e3', '+1', '-1', '1.2.3', '12 kg']) assert.ok(!acceptsNumeric(bad, weight), bad);
});

test('counts and limits take whole numbers only', () => {
    const count = numericRules(field({ step: '1', min: '1', inputmode: 'numeric' }));
    assert.deepEqual(count, { decimal: false, negative: false });
    assert.ok(acceptsNumeric('40', count));
    for (const bad of ['4.5', '4o', '-3', '1e2']) assert.ok(!acceptsNumeric(bad, count), bad);
    // Text fields marked numeric (claim limits, custom discounts) follow the same rule.
    assert.deepEqual(numericRules(field({ inputmode: 'numeric' }, 'text')), { decimal: false, negative: false });
    assert.equal(numericRules(field({}, 'text')), null);
});

test('a minus sign is allowed only where negatives are valid', () => {
    const signed = numericRules(field({ min: '-10' }));
    assert.ok(acceptsNumeric('-2.5', signed));
    assert.ok(!acceptsNumeric('2-', signed));
});
