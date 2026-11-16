import { after, test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, rmSync, writeFileSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { pathToFileURL } from 'node:url';
import { buildSync } from 'esbuild';

const temporary = mkdtempSync(join(import.meta.dirname, '.tmp-touch-menu-'));
after(() => rmSync(temporary, { recursive: true, force: true }));
const bundle = buildSync({ entryPoints: [resolve(import.meta.dirname, '../../resources/js/utils/touchMenu.ts')], bundle: true, platform: 'node', format: 'esm', write: false });
const path = join(temporary, 'menu.mjs');
writeFileSync(path, bundle.outputFiles[0].text);
const { openMenuOnFirstTap, isMousePointer } = await import(pathToFileURL(path).href);
const click = pointerType => { const e = { nativeEvent: { pointerType }, prevented: false, preventDefault() { this.prevented = true; } }; return e; };

test('a tap opens the account menu first; a mouse click still goes to the page', () => {
    let open = false; const set = value => { open = value; };
    const tap = click('touch');
    openMenuOnFirstTap(tap, open, set);
    assert.equal(tap.prevented, true); assert.equal(open, true);
    const second = click('touch');
    openMenuOnFirstTap(second, open, set);
    assert.equal(second.prevented, false, 'with the menu open, a second tap follows the link');
    const mouse = click('mouse');
    openMenuOnFirstTap(mouse, false, set);
    assert.equal(mouse.prevented, false);
    assert.equal(isMousePointer(click('mouse')), true);
    assert.equal(isMousePointer(click('pen')), false);
});
