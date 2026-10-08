import assert from 'node:assert/strict';
import { existsSync, mkdtempSync, mkdirSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import path from 'node:path';
import { spawnSync } from 'node:child_process';
import { test } from 'node:test';
import { fileURLToPath } from 'node:url';

const root = fileURLToPath(new URL('../../', import.meta.url));
const scratch = path.join(root, '.codex', 'deployment-tests');

function runHelper(command, env = {}) {
    mkdirSync(scratch, { recursive: true });
    const directory = mkdtempSync(path.join(scratch, 'check-'));
    const log = path.join(directory, 'calls.jsonl');
    const docker = path.join(directory, 'docker');

    writeFileSync(docker, `#!/usr/bin/env node
import fs from 'node:fs';
const args = process.argv.slice(2);
fs.appendFileSync(process.env.TASK_DOCKER_LOG, JSON.stringify(args) + '\\n');
if (args.slice(-3).join(' ') === 'npm run build') {
    process.exit(Number(process.env.TASK_BUILD_EXIT || 0));
}
if (args.includes('sh') && args.includes('-lc')) {
    process.exit(Number(process.env.TASK_MANIFEST_EXIT || 0));
}
if (args.includes('migrate:status') && args.includes('--pending=1')) {
    process.exit(Number(process.env.TASK_PENDING_EXIT || 0));
}
`, { mode: 0o755 });

    try {
        const environment = { ...process.env };
        delete environment.NODE_OPTIONS;
        const result = spawnSync('bash', [path.join(root, 'bagoo.sh'), command], {
            cwd: root,
            encoding: 'utf8',
            env: {
                ...environment,
                ...env,
                PATH: `${directory}${path.delimiter}${process.env.PATH}`,
                TASK_DOCKER_LOG: log,
            },
        });
        assert.ifError(result.error);
        assert.ok(existsSync(log), result.stderr || 'The Docker stub was not invoked.');
        const calls = readFileSync(log, 'utf8').trim().split('\n').map(JSON.parse);
        return { ...result, calls };
    } finally {
        rmSync(directory, { recursive: true, force: true });
    }
}

test('deploy gives the container build a 1 GiB heap by default', () => {
    const result = runHelper('deploy');
    assert.equal(result.status, 0);
    const build = result.calls.find(args => args.slice(-3).join(' ') === 'npm run build');
    const option = build.indexOf('-e');
    assert.ok(option >= 0, 'The memory option must reach Docker, not just the host shell.');
    assert.equal(build[option + 1], 'NODE_OPTIONS=--max-old-space-size=1024');
});

test('deploy preserves an explicit build memory override', () => {
    const result = runHelper('deploy', { NODE_OPTIONS: '--max-old-space-size=1536' });
    assert.equal(result.status, 0);
    const build = result.calls.find(args => args.slice(-3).join(' ') === 'npm run build');
    assert.ok(build.includes('NODE_OPTIONS=--max-old-space-size=1536'));
});

test('a failed asset build stops deployment before database changes', () => {
    const result = runHelper('deploy', { TASK_BUILD_EXIT: '134' });
    assert.equal(result.status, 134);
    assert.ok(!result.calls.some(args => args.includes('migrate')));
    assert.ok(!result.stdout.includes('Deployment checks passed'));
});

test('verify fails while migrations remain pending', () => {
    const result = runHelper('verify', { TASK_PENDING_EXIT: '1' });
    assert.equal(result.status, 1);
    assert.ok(!result.stdout.includes('Deployment verification passed'));
});

test('verify succeeds when the manifest exists and no migrations are pending', () => {
    const result = runHelper('verify');
    assert.equal(result.status, 0);
    assert.match(result.stdout, /Deployment verification passed/);
});

test('verify fails before checking migrations if assets are missing', () => {
    const result = runHelper('verify', { TASK_MANIFEST_EXIT: '1' });
    assert.equal(result.status, 1);
    assert.ok(!result.calls.some(args => args.includes('migrate:status')));
    assert.ok(!result.stdout.includes('Deployment verification passed'));
});
