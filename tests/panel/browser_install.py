"""Exercise browser setup on a disposable panel copy and an empty test database."""
import http.cookiejar
import json
import os
from pathlib import Path
import re
import shutil
import socket
import subprocess
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request

repo = Path(__file__).resolve().parents[2]
php = os.environ.get('PHP_BINARY', 'php')
with tempfile.TemporaryDirectory() as tmp:
    tmp = Path(tmp)
    shutil.copytree(repo / 'panel', tmp / 'games')
    panel = tmp / 'games'
    for path in ('config/installed.php', 'config/.env', 'config/install-progress.php'):
        (panel / path).unlink(missing_ok=True)
    # Simulate the web server's subdirectory front controller.
    router = tmp / 'router.php'
    router.write_text('''<?php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (is_file(__DIR__ . $path) && $path !== '/games/index.php') return false;
$_SERVER['SCRIPT_NAME'] = '/games/index.php';
require __DIR__ . '/games/index.php';
''')
    with socket.socket() as sock:
        sock.bind(('127.0.0.1', 0))
        port = sock.getsockname()[1]
    origin = f'http://127.0.0.1:{port}'
    jar = http.cookiejar.CookieJar()
    browser = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))
    def request(path, data=None, headers=None):
        req = urllib.request.Request(origin + path, data=urllib.parse.urlencode(data).encode() if data is not None else None, headers=headers or {})
        try:
            response = browser.open(req, timeout=10)
        except urllib.error.HTTPError as error:
            response = error
        return response.status, response.read().decode(), response.headers
    with open(tmp / 'server.log', 'w+') as log:
        env = os.environ.copy()
        for key in ('DB_NAME', 'DB_HOST', 'DB_USER', 'DB_PASS', 'APEX_BASE_PATH'):
            env.pop(key, None)
        server = subprocess.Popen([php, '-S', f'127.0.0.1:{port}', '-t', str(tmp), str(router)], stdout=log, stderr=log, env=env)
        try:
            for _ in range(50):
                try:
                    status, html, _ = request('/games/install.php')
                    break
                except OSError:
                    time.sleep(0.1)
            assert status == 200 and 'Install ApexNode' in html
            csrf = re.search(r'name="_csrf" value="([^"]+)"', html)[1]
            data = dict(_csrf=csrf, db_host=os.environ.get('TEST_DB_HOST', '127.0.0.1'), db_port=os.environ.get('TEST_DB_PORT', '3306'), db_name=os.environ.get('TEST_DB_NAME', 'apex_setup_test'), db_user=os.environ.get('TEST_DB_USER', 'apex_setup_test'), db_pass=os.environ.get('TEST_DB_PASS', 'test-database-password'), admin='testadmin', email='admin@example.test', password='test-admin-password', confirm='test-admin-password')
            status, html, _ = request('/games/install.php', dict(data, _csrf='wrong'))
            assert 'Session expired' in html and not (panel / 'config/installed.php').exists()
            status, html, _ = request('/games/install.php', dict(data, confirm='mismatch'))
            assert 'matching passwords' in html and not (panel / 'config/installed.php').exists(), html
            status, html, _ = request('/games/install.php', data)
            assert 'Your panel is ready' in html, html
            assert 'href="/games/login"' in html
            assert 'test-database-password' not in html
            status, _, _ = request('/games/install.php', data)
            assert status == 403
            status, html, _ = request('/games/login')
            assert status == 200 and '/games/assets/app.js' in html
            csrf = re.search(r'name="_csrf" value="([^"]+)"', html)[1]
            status, html, _ = request('/games/login', dict(_csrf=csrf, email='testadmin', password='test-admin-password'))
            assert status == 200 and 'Dashboard' in html, html
            assert '/games/servers/new' in html and 'Survival SMP' not in html
            status, html, _ = request('/games/eggs')
            assert status == 200 and 'PaperMC' in html
            status, html, _ = request('/games/mods')
            assert status == 200 and 'Fabric' in html
            status, html, _ = request('/games/manifest.webmanifest')
            assert json.loads(html)['start_url'] == '/games/dashboard'
            status, body, headers = request('/games/assets/app.js')
            assert status == 200 and headers.get('ETag')
            status, body, _ = request('/games/assets/app.js', headers={'If-None-Match': headers['ETag']})
            assert status == 304 and not body
            # Database contains one administrator; no default/demo accounts or servers.
            code = 'require '+json.dumps(str(panel / 'app/db.php'))+'; echo json_encode([DB::all("SELECT username,role FROM users"), DB::one("SELECT COUNT(*) c FROM servers")]);'
            result = json.loads(subprocess.check_output([php, '-r', code], env=env))
            assert result == [[{'username': 'testadmin', 'role': 'admin'}], {'c': 0}]
            print('Browser installer, CSRF, account creation, lock, subdirectory login/routes, template seeds and asset revalidation passed.')
        finally:
            server.terminate()
            server.wait(timeout=10)
            log.seek(0)
            logs = log.read()
            assert 'Fatal error' not in logs and 'Warning:' not in logs, logs
