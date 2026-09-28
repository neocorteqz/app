"""
ApexNode iteration 5 tests — four features:
  1. Existing-web-panel installer (/install page with host picker + install.sh coexist modes)
  2. Real loader runtimes (Paper JAR downloaded via runtime.py on start)
  3. Job cancellation (POST /jobs/{id}/cancel + daemon endpoint)
  4. Concurrency guard (double-enqueue returns existing job, no duplicate row)

Also regression: /dashboard, /servers, /jobs, /json/modpack/preview still fine.
"""
import os
import re
import asyncio
import hashlib
import importlib.util
import io
import json
import sys
import time
import pymysql
import pytest
import requests

BASE_URL = os.environ.get("REACT_APP_BACKEND_URL", "").rstrip("/") or "http://127.0.0.1:3001"
DAEMON_URL = "http://127.0.0.1:8001"

DB_CFG = dict(host="127.0.0.1", user="apexnode", password="apex_local_dev",
              database="apexnode", autocommit=True, cursorclass=pymysql.cursors.DictCursor)


# ---------- helpers ----------

@pytest.fixture(scope="session")
def session():
    s = requests.Session()
    # login
    r = s.get(f"{BASE_URL}/login")
    m = re.search(r'name="_csrf"\s+value="([^"]+)"', r.text)
    assert m, "csrf token missing on login page"
    r = s.post(f"{BASE_URL}/login",
               data={"_csrf": m.group(1), "email": "admin", "password": "admin123"},
               allow_redirects=False)
    assert r.status_code in (302, 303), f"login failed: {r.status_code} {r.text[:200]}"
    return s


def _login():
    s = requests.Session()
    r = s.get(f"{BASE_URL}/login")
    m = re.search(r'name="_csrf"\s+value="([^"]+)"', r.text)
    s.post(f"{BASE_URL}/login",
           data={"_csrf": m.group(1), "email": "admin", "password": "admin123"},
           allow_redirects=False)
    return s


def _csrf(session, path="/dashboard"):
    html = session.get(f"{BASE_URL}{path}").text
    m = re.search(r'<meta name="csrf" content="([^"]+)"', html)
    if not m:
        m = re.search(r'name="_csrf"\s+value="([^"]+)"', html)
    assert m, f"csrf missing on {path}"
    return m.group(1)


def _db():
    return pymysql.connect(**DB_CFG)


# ---------- Feature 1: Installer page + install.sh ----------

class TestInstaller:
    def test_install_page_loads_with_host_picker(self, session):
        r = session.get(f"{BASE_URL}/install")
        assert r.status_code == 200
        for tid in ["host-picker", "host-mode-standalone", "host-mode-cpanel",
                    "host-mode-plesk", "host-mode-directadmin", "host-mode-apache",
                    "host-mode-nginx", "host-mode-caddy", "host-mode-other",
                    "input-panel-port", "install-cmd", "reverse-proxy-snippet",
                    "copy-install-btn"]:
            assert f'data-testid="{tid}"' in r.text, f"missing testid: {tid}"

    def test_install_sh_downloads_and_has_coexist_modes(self):
        r = requests.get(f"{BASE_URL}/install.sh", timeout=30)
        assert r.status_code == 200
        body = r.text
        assert "--coexist" in body
        assert "--port" in body
        assert "--install-dir" in body
        assert "--data-dir" in body
        assert "--plugin-dir" in body
        assert "--interactive" in body
        assert "--web-server" in body
        assert "apt-get" in body and "dnf" in body
        for mode in ["standalone", "apache", "cpanel", "plesk", "directadmin", "nginx", "caddy", "other"]:
            assert mode in body, f"coexist mode {mode} missing from install.sh"
            if mode != "standalone":
                helper = f"/app/panel/install/integrations/{mode}/install.sh"
                assert os.path.isfile(helper), f"integration helper missing: {mode}"
        # verifies loopback binding for coexist mode
        assert "127.0.0.1:" in body

    def test_installer_settings_seeded(self):
        conn = _db()
        with conn.cursor() as c:
            c.execute("SELECT k,v FROM settings WHERE k IN ('panel_port','coexist_mode','panel_url_path')")
            rows = {r["k"]: r["v"] for r in c.fetchall()}
        conn.close()
        assert set(rows.keys()) == {"panel_port", "coexist_mode", "panel_url_path"}


# ---------- Feature 2: Real loader runtime (runtime.py) ----------

class TestRuntimeResolver:
    def test_paper_checksum_mismatch_is_rejected(self, tmp_path, monkeypatch):
        sys.path.insert(0, "/app/panel/daemon")
        import runtime

        expected = hashlib.sha256(b"official-paper-jar").hexdigest()
        metadata = [{"id": 1, "downloads": {"server:default": {
            "name": "paper.jar", "url": "https://paper.invalid/paper.jar",
            "checksums": {"sha256": expected},
        }}}]
        monkeypatch.setattr(runtime.urllib.request, "urlopen", lambda *args, **kwargs: io.BytesIO(json.dumps(metadata).encode()))
        monkeypatch.setattr(runtime, "_download", lambda url, dst, log, label: dst.write_bytes(b"tampered-jar"))

        with pytest.raises(RuntimeError, match="SHA256 mismatch"):
            runtime._ensure_paper(tmp_path, lambda line, level: None, "1.21.7")
        assert not (tmp_path / "server.jar").exists()
        assert not list(tmp_path.glob(".paper-*.jar"))

    def test_selected_minecraft_version_reaches_paper_resolver(self, tmp_path, monkeypatch):
        sys.path.insert(0, "/app/panel/daemon")
        import runtime

        selected = []
        monkeypatch.setattr(runtime, "_ensure_paper", lambda work_dir, log, version: selected.append(version) or tmp_path / "server.jar")
        monkeypatch.setattr(runtime, "_write_eula", lambda work_dir: None)
        monkeypatch.setattr(runtime, "_java_cmd", lambda work_dir, ram: "java -jar server.jar nogui")
        runtime.resolve({"id": 77, "game": "minecraft-java", "work_dir": str(tmp_path), "minecraft_version": "1.21.7"},
                        {"slug": "paper"}, lambda line, level: None)
        assert selected == ["1.21.7"]

    def test_bootstrap_failure_marks_crashed_without_demo_process(self, tmp_path, monkeypatch):
        daemon_path = "/app/panel/daemon/daemon.py"
        spec = importlib.util.spec_from_file_location("apexnode_daemon_test", daemon_path)
        daemon_app = importlib.util.module_from_spec(spec)
        sys.modules[spec.name] = daemon_app
        spec.loader.exec_module(daemon_app)
        server = {"id": 700001, "game": "minecraft-java", "work_dir": str(tmp_path),
                  "ram_mb": 2048, "loader_id": 1, "egg_id": None, "status": "offline"}
        statuses = []
        log_lines = []
        monkeypatch.setattr(daemon_app, "get_server", lambda sid: server)
        monkeypatch.setattr(daemon_app, "ensure_workdir", lambda row: tmp_path)
        monkeypatch.setattr(daemon_app, "install_modpack_if_needed", lambda row: None)
        monkeypatch.setattr(daemon_app, "get_loader", lambda loader_id: {"slug": "paper"})
        monkeypatch.setattr(daemon_app, "get_egg", lambda egg_id: None)
        monkeypatch.setattr(daemon_app, "build_start_cmd", lambda row, egg: (_ for _ in ()).throw(RuntimeError("Paper CDN unavailable")))
        monkeypatch.setattr(daemon_app, "set_status", lambda sid, status, **fields: statuses.append(status))
        monkeypatch.setattr(daemon_app, "log_line", lambda sid, line, level="info": log_lines.append((line, level)))
        monkeypatch.setattr(daemon_app.subprocess, "Popen", lambda *args, **kwargs: pytest.fail("process must not start"))

        with pytest.raises(daemon_app.HTTPException) as exc:
            asyncio.run(daemon_app.start(server["id"]))
        assert exc.value.status_code == 500
        assert "loader bootstrap failed" in exc.value.detail
        assert "crashed" in statuses
        assert any("loader bootstrap failed" in line for line, level in log_lines)

    def test_runtime_module_resolves_paper_command(self):
        """Import runtime.py and call resolve() with a fake server row.
        Paper CDN may 403 in sandbox — we accept either a successful download
        OR a clean RuntimeError (both prove the code path works).
        Per instructions: Paper CDN 403 is not a test failure."""
        import sys, tempfile, pathlib
        sys.path.insert(0, "/app/panel/daemon")
        import runtime  # noqa

        tmp = tempfile.mkdtemp(prefix="apex-rt-")
        server = {"id": 999999, "game": "minecraft-java", "work_dir": tmp, "ram_mb": 2048}
        loader = {"slug": "paper"}
        logs = []
        log = lambda l, lv: logs.append((lv, l))
        try:
            cmd = runtime.resolve(server, loader, log)
            assert "java" in cmd and "-jar server.jar" in cmd
            jar = pathlib.Path(tmp) / "server.jar"
            eula = pathlib.Path(tmp) / "eula.txt"
            assert jar.exists() and jar.stat().st_size > 100_000, "server.jar not downloaded"
            assert eula.exists() and "eula=true" in eula.read_text()
            print(f"Paper JAR downloaded OK ({jar.stat().st_size} bytes)")
        except RuntimeError as e:
            # Paper CDN 403 accepted per instructions
            print(f"Paper CDN unavailable in sandbox — accepted per instructions: {e}")
            pytest.skip(f"Paper CDN 403 (accepted): {e}")

    def test_runtime_fallback_for_non_java_game(self):
        import sys
        sys.path.insert(0, "/app/panel/daemon")
        import runtime
        server = {"id": 1, "game": "cs2", "work_dir": "/tmp", "ram_mb": 4096}
        cmd = runtime.resolve(server, {"slug": "vanilla"}, lambda l, lv: None)
        assert "fake_game.py" in cmd and "cs2" in cmd

    def test_runtime_vanilla_reuses_paper(self):
        # Just check the code path is wired — no need to double-download
        import sys, inspect
        sys.path.insert(0, "/app/panel/daemon")
        import runtime
        src = inspect.getsource(runtime._ensure_vanilla)
        assert "_ensure_paper" in src


# ---------- Feature 3: Job cancellation ----------

class TestJobCancellation:
    def _make_test_server(self, s, name):
        # Deploy via the /servers POST endpoint (fills owner_id and defaults correctly)
        r = s.get(f"{BASE_URL}/servers/new")
        csrf = re.search(r'name="_csrf"\s+value="([^"]+)"', r.text).group(1)
        conn = _db()
        with conn.cursor() as c:
            c.execute("SELECT COALESCE(MAX(port),25600)+1 AS p FROM servers")
            port = c.fetchone()["p"]
            c.execute("SELECT id FROM mod_loaders WHERE slug='modrinth' LIMIT 1")
            l = c.fetchone()
        conn.close()
        r = s.post(f"{BASE_URL}/servers", data={
            "_csrf": csrf, "name": name, "game": "minecraft-java",
            "loader_id": l["id"] if l else 9, "modpack_ref": "fabulously-optimized",
            "node_id": 1, "port": port, "cpu_limit": 2, "ram_mb": 2048, "disk_gb": 5,
        }, allow_redirects=False)
        assert r.status_code in (302, 303), f"deploy failed: {r.status_code} {r.text[:400]}"
        sid = int(re.search(r"/servers/(\d+)", r.headers["Location"]).group(1))
        return sid

    def test_cancel_endpoint_daemon_direct(self):
        """Enqueue via daemon then POST cancel — job status becomes 'cancelled'."""
        s = _login()
        sid = self._make_test_server(s, f"TEST_CANCEL_{int(time.time())}")
        # Enqueue via daemon directly
        r = requests.post(f"{DAEMON_URL}/api/daemon/modpack/install-async/{sid}", timeout=10)
        assert r.status_code == 200
        job_id = r.json()["job_id"]
        assert job_id
        # Give the worker ~0.5s to enter running state
        time.sleep(1.5)
        # Cancel
        r = requests.post(f"{DAEMON_URL}/api/daemon/jobs/{job_id}/cancel", timeout=10)
        assert r.status_code == 200
        assert r.json().get("ok") is True
        # Wait for status to flip
        deadline = time.time() + 30
        final = None
        while time.time() < deadline:
            time.sleep(1)
            conn = _db()
            with conn.cursor() as c:
                c.execute("SELECT status, cancel_requested FROM jobs WHERE id=%s", (job_id,))
                row = c.fetchone()
            conn.close()
            if row["status"] in ("cancelled", "completed", "failed"):
                final = row["status"]
                break
        assert final == "cancelled", f"expected cancelled, got {final}"
        # Server modpack_status rolled back to 'none'
        conn = _db()
        with conn.cursor() as c:
            c.execute("SELECT modpack_status, status FROM servers WHERE id=%s", (sid,))
            srv = c.fetchone()
        conn.close()
        assert srv["modpack_status"] == "none"

    def test_cancel_already_finished_job(self):
        """Cancel on a completed job returns already_finished."""
        conn = _db()
        with conn.cursor() as c:
            c.execute("INSERT INTO jobs (kind, target_kind, target_id, status, progress, total, message, created_at, completed_at) "
                      "VALUES ('modpack_install','server',9999999,'completed',10,10,'done',NOW(),NOW())")
            jid = c.lastrowid
        conn.close()
        r = requests.post(f"{DAEMON_URL}/api/daemon/jobs/{jid}/cancel", timeout=10)
        assert r.status_code == 200
        assert r.json().get("already_finished") is True

    def test_cancel_nonexistent_job(self):
        r = requests.post(f"{DAEMON_URL}/api/daemon/jobs/999999999/cancel", timeout=10)
        assert r.status_code == 404

    def test_jobs_page_shows_cancel_button_for_running(self, session):
        # Create a queued job manually to check the UI renders the cancel form
        conn = _db()
        with conn.cursor() as c:
            c.execute("INSERT INTO jobs (kind, target_kind, target_id, status, progress, total, message, created_at) "
                      "VALUES ('modpack_install','server',9999998,'running',3,10,'testing',NOW())")
            jid = c.lastrowid
        conn.close()
        try:
            r = session.get(f"{BASE_URL}/jobs")
            assert r.status_code == 200
            assert f'data-testid="cancel-job-{jid}"' in r.text
            assert f"/jobs/{jid}/cancel" in r.text
        finally:
            conn = _db()
            with conn.cursor() as c:
                c.execute("DELETE FROM jobs WHERE id=%s", (jid,))
            conn.close()


# ---------- Feature 4: Concurrency guard ----------

class TestConcurrencyGuard:
    def test_double_enqueue_returns_already_running(self):
        s = _login()
        r = s.get(f"{BASE_URL}/servers/new")
        csrf = re.search(r'name="_csrf"\s+value="([^"]+)"', r.text).group(1)
        conn = _db()
        with conn.cursor() as c:
            c.execute("SELECT COALESCE(MAX(port),25600)+1 AS p FROM servers")
            port = c.fetchone()["p"]
            c.execute("SELECT id FROM mod_loaders WHERE slug='modrinth' LIMIT 1")
            l = c.fetchone()
        conn.close()
        r = s.post(f"{BASE_URL}/servers", data={
            "_csrf": csrf, "name": f"TEST_CONCUR_{int(time.time())}",
            "game": "minecraft-java", "loader_id": l["id"] if l else 9,
            "modpack_ref": "fabulously-optimized",
            "node_id": 1, "port": port, "cpu_limit": 2, "ram_mb": 2048, "disk_gb": 5,
        }, allow_redirects=False)
        assert r.status_code in (302, 303)
        sid = int(re.search(r"/servers/(\d+)", r.headers["Location"]).group(1))

        # First enqueue
        r1 = requests.post(f"{DAEMON_URL}/api/daemon/modpack/install-async/{sid}", timeout=10)
        assert r1.status_code == 200
        j1 = r1.json()
        job_id = j1["job_id"]
        assert job_id and not j1.get("already_running")

        # Second enqueue immediately — must NOT create a new row
        r2 = requests.post(f"{DAEMON_URL}/api/daemon/modpack/install-async/{sid}", timeout=10)
        assert r2.status_code == 200
        j2 = r2.json()
        assert j2.get("already_running") is True
        assert j2["job_id"] == job_id, f"expected same job_id {job_id}, got {j2['job_id']}"

        # DB count: only one modpack_install job for this sid
        conn = _db()
        with conn.cursor() as c:
            c.execute("SELECT COUNT(*) AS cnt FROM jobs WHERE kind='modpack_install' AND target_id=%s", (sid,))
            cnt = c.fetchone()["cnt"]
        conn.close()
        assert cnt == 1, f"expected 1 job row, got {cnt}"

        # Clean up — cancel the running job
        requests.post(f"{DAEMON_URL}/api/daemon/jobs/{job_id}/cancel", timeout=10)


# ---------- Regression: core routes still 200 ----------

class TestRegression:
    @pytest.mark.parametrize("path", ["/dashboard", "/servers", "/jobs", "/mods", "/eggs", "/install"])
    def test_route_ok(self, session, path):
        r = session.get(f"{BASE_URL}{path}")
        assert r.status_code == 200, f"{path} → {r.status_code}"

    def test_modrinth_preview_still_works(self, session):
        r = session.get(f"{BASE_URL}/json/modpack/preview",
                        params={"source": "modrinth", "ref": "fabulously-optimized"},
                        timeout=15)
        assert r.status_code == 200
        j = r.json()
        assert j.get("ok") is True
        assert "title" in j.get("data", {})
