"""Exercise browser setup on a disposable panel copy and an empty test database."""
import http.cookiejar
import http.server
import threading
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
            old_session = next(c.value for c in jar if c.name == 'apexnode_sess')
            status, html, _ = request('/games/login', dict(_csrf=csrf, email='testadmin', password='test-admin-password'))
            assert status == 200 and 'Dashboard' in html, html
            assert next(c.value for c in jar if c.name == 'apexnode_sess') != old_session
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
            # Real daemon responses make authorization regressions visible without game processes.
            calls = []
            class Daemon(http.server.BaseHTTPRequestHandler):
                def do_GET(self):
                    calls.append(self.path)
                    self.send_response(200)
                    self.send_header('Content-Type', 'application/json')
                    self.end_headers()
                    self.wfile.write(b'{"ok":true,"marker":"private-daemon-response"}')
                do_POST = do_GET
                def log_message(self, *_):
                    pass
            daemon = http.server.HTTPServer(('127.0.0.1', 8001), Daemon)
            thread = threading.Thread(target=daemon.serve_forever, daemon=True)
            thread.start()
            try:
                status, html, _ = request('/games/dashboard')
                csrf = re.search(r'name="_csrf" value="([^"]+)"', html)[1]
                # Admin health succeeds; state-changing GETs and tokenless POSTs never forward.
                assert request('/games/api/daemon/health')[0] == 200
                count = len(calls)
                assert request('/games/api/daemon/start/1')[0] == 405
                assert request('/games/api/daemon/start/1', {})[0] == 419
                assert request('/games/api/daemon/start/1', {'_csrf[]':csrf})[0] == 419
                assert len(calls) == count
                malicious = "';}body{background:url(https://evil.invalid/leak)}/*"
                assert request('/games/theme', dict(_csrf=csrf, font=malicious, radius=malicious))[0] == 200
                status, css, _ = request('/games/theme.css')
                assert 'evil.invalid' not in css and "'Outfit'" in css
                # Seed another user and private server/job with no permissions for that user.
                fixture = """DB::insert('users', ['username'=>'viewer','email'=>'viewer@example.test','role'=>'viewer','password_hash'=>password_hash('viewer-password', PASSWORD_BCRYPT)]);
DB::insert('nodes', ['name'=>'test-node','hostname'=>'localhost','ip'=>'127.0.0.1']);
DB::insert('servers', ['name'=>'private-server','game'=>'minecraft-java','node_id'=>1,'owner_id'=>1]);
DB::insert('jobs', ['kind'=>'private-job','target_kind'=>'system','payload'=>'{"secret":"private-payload"}']);
"""
                bootstrap = 'require '+json.dumps(str(panel / 'app/db.php'))+';'
                subprocess.run([php, '-r', bootstrap+fixture], env=env, check=True)
                request('/games/logout', {'_csrf':csrf})
                status, html, _ = request('/games/login')
                csrf = re.search(r'name="_csrf" value="([^"]+)"', html)[1]
                count = len(calls)
                status, body, _ = request('/games/api/daemon/status/1')
                assert 'private-daemon-response' not in body and len(calls) == count
                request('/games/login', dict(_csrf=csrf, email='viewer', password='viewer-password'))
                _, html, _ = request('/games/dashboard')
                csrf = re.search(r'name="_csrf" value="([^"]+)"', html)[1]
                assert request('/games/api/daemon/health')[0] == 403
                assert request('/games/api/daemon/status/1')[0] == 403
                assert request('/games/api/daemon/start/1', {'_csrf':csrf})[0] == 403
                assert request('/games/json/jobs/1')[0] == 403
                assert len(calls) == count
                # A granted control permission permits forwarding with a valid token.
                grant = "DB::insert('server_access', ['server_id'=>1,'user_id'=>2,'control_server'=>1]);"
                subprocess.run([php, '-r', bootstrap+grant], env=env, check=True)
                assert request('/games/api/daemon/start/1', {'_csrf':csrf})[0] == 200
                assert len(calls) == count+1
            finally:
                daemon.shutdown()
                daemon.server_close()
            includes = 'require '+json.dumps(str(panel / 'app/includes.php'))+';'
            # Empty tokens used to pass hash_equals('', ''). Forged proxy/Host headers cannot bypass HTTPS.
            check = includes+"$_SESSION=[]; $_POST=[]; register_shutdown_function(static function(){echo http_response_code();}); check_csrf();"
            assert subprocess.check_output([php, '-r', check], env=env).decode().endswith('419')
            check = includes+"$_SERVER['REMOTE_ADDR']='198.51.100.1'; $_SERVER['HTTP_HOST']='localhost'; $_SERVER['HTTP_X_FORWARDED_PROTO']='https'; echo json_encode([is_local_request(),is_https_request()]);"
            assert json.loads(subprocess.check_output([php, '-r', check], env=env)) == [False, False]
            # Deleting a directory containing a symlink must leave the external target untouched.
            external = tmp / 'external'
            external.mkdir()
            (external / 'sentinel').write_text('keep')
            tree = tmp / 'delete-me'
            tree.mkdir()
            (tree / 'link').symlink_to(external, target_is_directory=True)
            check = includes+"$r=new ReflectionMethod(App\\Controllers\\Files::class, 'rrmdir'); $r->setAccessible(true); $r->invoke(new App\\Controllers\\Files(), "+json.dumps(str(tree))+");"
            subprocess.run([php, '-r', check], env=env, check=True)
            assert (external / 'sentinel').read_text() == 'keep' and not tree.exists()
            print('Security regression tests passed: daemon authorization/CSRF/methods, session rotation, theme injection, job access, proxy spoofing and symlink-safe deletion.')
            print('Browser installer, CSRF, account creation, lock, subdirectory login/routes, template seeds and asset revalidation passed.')
        finally:
            server.terminate()
            server.wait(timeout=10)
            log.seek(0)
            logs = log.read()
            assert 'Fatal error' not in logs and 'Warning:' not in logs, logs
