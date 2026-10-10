import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { test } from 'node:test';
import { fileURLToPath } from 'node:url';

const root = fileURLToPath(new URL('../../', import.meta.url));

function databaseConfiguration(forwardPort) {
    const result = spawnSync('docker', [
        'compose', '--env-file', '/dev/null', '-f', 'docker-compose.yml',
        'config', '--format', 'json',
    ], {
        cwd: root,
        encoding: 'utf8',
        env: {
            PATH: process.env.PATH,
            FORWARD_DB_PORT: forwardPort ?? '',
        },
    });

    assert.ifError(result.error);
    assert.equal(result.status, 0, result.stderr);

    return JSON.parse(result.stdout);
}

test('the default database host port is limited to IPv4 localhost', () => {
    const { services } = databaseConfiguration();

    assert.equal(services.db.ports.length, 1);
    assert.deepEqual(services.db.ports[0], {
        mode: 'ingress',
        host_ip: '127.0.0.1',
        target: 5432,
        published: '5432',
        protocol: 'tcp',
    });
});

test('a custom database port retains the localhost boundary', () => {
    const { services } = databaseConfiguration('15432');

    assert.equal(services.db.ports.length, 1);
    assert.equal(services.db.ports[0].host_ip, '127.0.0.1');
    assert.equal(services.db.ports[0].published, '15432');
    assert.equal(services.db.ports[0].target, 5432);
});

test('application database access uses the internal network and persistent volume', () => {
    const { services, networks, volumes } = databaseConfiguration('15432');

    assert.equal(services.app.environment.DB_HOST, 'db');
    assert.equal(services.app.environment.DB_PORT, '5432');
    assert.ok(Object.hasOwn(services.app.networks, 'bagoo_network'));
    assert.ok(Object.hasOwn(services.db.networks, 'bagoo_network'));
    assert.equal(networks.bagoo_network.driver, 'bridge');
    assert.equal(services.app.depends_on.db.condition, 'service_healthy');
    assert.ok(services.db.volumes.some(volume => (
        volume.type === 'volume'
        && volume.source === 'bagoo_pgdata'
        && volume.target === '/var/lib/postgresql/data'
    )));
    assert.equal(volumes.bagoo_pgdata.driver, 'local');
});
