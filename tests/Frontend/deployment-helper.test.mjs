import { after, test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, writeFileSync, readFileSync, rmSync, chmodSync } from 'node:fs';
import { spawnSync } from 'node:child_process';
import { join, resolve } from 'node:path';
const directory = mkdtempSync(join(import.meta.dirname, '.tmp-deploy-'));
after(() => rmSync(directory, { recursive: true, force: true }));
const fake = join(directory, 'docker');
writeFileSync(fake, `#!/usr/bin/env node
import fs from 'node:fs';
const args = process.argv.slice(2);
fs.appendFileSync(process.env.BAGOO_DEPLOY_TEST_LOG, JSON.stringify(args) + '\\n');
if (process.env.BAGOO_DEPLOY_TEST_FAIL_MIGRATION === '1' && args.includes('migrate')) process.exit(1);
if (args.includes('ps') && process.env.BAGOO_DEPLOY_TEST_RUNNING === '1') process.stdout.write('scheduler-test-container\\n');
`);
chmodSync(fake, 0o755);
const run = (name, command, extra = {}) => {
    const log = join(directory, name + '.jsonl');
    const result = spawnSync('bash', [resolve(import.meta.dirname, '../../bagoo.sh'), command], { encoding: 'utf8', env: { ...process.env, PATH: `${directory}:${process.env.PATH}`, BAGOO_DEPLOY_TEST_LOG: log, ...extra } });
    return { ...result, calls: readFileSync(log, 'utf8').trim().split('\n').map(line => JSON.parse(line)) };
};
test('deploy pauses scheduled work before dependency changes and restarts it only after migration and caches', () => {
    const result = run('deploy', 'deploy');
    assert.equal(result.status, 0, result.stderr);
    assert.deepEqual(result.calls[0], ['compose', 'stop', 'scheduler']);
    const composer = result.calls.find(args => args.includes('composer'));
    const npm = result.calls.find(args => args.includes('npm'));
    assert.ok(composer.includes('COMPOSER_CACHE_DIR=/var/www/html/storage/framework/cache/composer'));
    assert.ok(npm.includes('--cache'));
    assert.ok(npm.includes('/var/www/html/storage/framework/cache/npm'));
    assert.ok(npm.includes('--prefer-offline'));
    const migration = result.calls.findIndex(args => args.includes('migrate'));
    const caches = result.calls.findIndex(args => args.includes('view:cache'));
    const scheduler = result.calls.findIndex(args => args.join(' ') === 'compose up -d --no-deps scheduler');
    assert.ok(migration > 0 && caches > migration && scheduler > caches);
    assert.equal(scheduler, result.calls.length - 1);
});
test('a failed migration cannot start the scheduler or report deployment success', () => {
    const result = run('failed-deploy', 'deploy', { BAGOO_DEPLOY_TEST_FAIL_MIGRATION: '1' });
    assert.notEqual(result.status, 0);
    assert.equal(result.calls.some(args => args.join(' ') === 'compose up -d --no-deps scheduler'), false);
    assert.doesNotMatch(result.stdout, /Deployment checks passed/);
});
test('verify rejects a stopped scheduler and boots the application in a running scheduler when available', () => {
    const stopped = run('stopped', 'verify');
    assert.notEqual(stopped.status, 0);
    assert.match(stopped.stderr, /scheduler is not running/);
    const running = run('running', 'verify', { BAGOO_DEPLOY_TEST_RUNNING: '1' });
    assert.equal(running.status, 0, running.stderr);
    assert.ok(running.calls.some(args => args.join(' ') === 'compose exec -T scheduler php artisan schedule:list --no-ansi'));
    assert.match(running.stdout, /Deployment verification passed/);
});
