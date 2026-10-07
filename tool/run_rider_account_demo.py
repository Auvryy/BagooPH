"""Run the actual Laravel app with a separate synthetic SQLite database."""
import base64
import os
from pathlib import Path
import subprocess

repo = Path(__file__).resolve().parent.parent
common = Path(subprocess.check_output(['git', 'rev-parse', '--path-format=absolute', '--git-common-dir'], cwd=repo, text=True).strip()).parent
relative = repo.relative_to(common)
container_repo = '/work/' + str(relative) if str(relative) != '.' else '/work'
runtime = repo / '.runtime'
runtime.mkdir(exist_ok=True)
key_file = runtime / 'demo.key'
if not key_file.exists():
    key_file.write_bytes(base64.b64encode(os.urandom(32))); key_file.chmod(0o600)
database = repo / 'storage/app/rider-demo.sqlite'
database.parent.mkdir(parents=True, exist_ok=True)
database.touch(exist_ok=True)
settings = {
    'APP_ENV': 'local', 'APP_DEBUG': 'false', 'APP_KEY': 'base64:'+key_file.read_text(),
    'APP_URL': 'http://127.0.0.1:8089', 'APP_DOMAIN': 'localhost',
    'DB_CONNECTION': 'sqlite', 'DB_DATABASE': container_repo+'/storage/app/rider-demo.sqlite', 'DB_URL': '',
    'CACHE_STORE': 'file', 'SESSION_DRIVER': 'file', 'QUEUE_CONNECTION': 'sync',
    'MAIL_MAILER': 'smtp', 'MAIL_HOST': '127.0.0.1', 'MAIL_PORT': '1027', 'MAIL_USERNAME': '', 'MAIL_PASSWORD': '',
    'MAIL_SCHEME': 'smtp', 'MAIL_ENCRYPTION': 'null', 'MAIL_FROM_ADDRESS': 'no-reply@bagoo.test',
    'RIDER_BROWSER_ORIGINS': 'http://127.0.0.1:4173',
}
environment = os.environ.copy(); environment.update(settings)
command = ['docker','run','--rm','--network','host','--user',str(os.getuid())+':'+str(os.getgid()),
    '--volume',str(common)+':/work','--volume',str(common)+':/var/www/html','--workdir',container_repo]
for name in settings: command.extend(['--env',name])
command += ['bagoo-app','sh','-c',
    'php artisan migrate --force --database=sqlite && php artisan db:seed --class=RiderAuthDemoSeeder --force && php artisan serve --host=127.0.0.1 --port=8089']
print('Starting isolated Bagoo account demo on loopback port 8089.', flush=True)
raise SystemExit(subprocess.call(command, env=environment))
