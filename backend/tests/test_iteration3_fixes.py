"""Iteration 3 — targeted retest of two bug fixes:
(1) status resets to 'offline' (not stuck 'installing') after successful modpack install
(2) ZIP override extraction guards against path traversal
Plus a small regression sanity block.
"""

import io
import os
import re
import sys
import time
import zipfile

import pymysql
import pytest
import requests

# These historical suites require a disposable, configured game host.
pytestmark = pytest.mark.skipif(
    os.environ.get("APEX_RUN_LIVE_TESTS") != "1",
    reason="Set APEX_RUN_LIVE_TESTS=1 only for an explicitly provisioned disposable host",
)

BASE = os.environ.get("REACT_APP_BACKEND_URL", "").rstrip("/") or "http://127.0.0.1:3001"

CSRF_RE = re.compile(r'name="_csrf"\s+value="([^"]+)"')
META_CSRF_RE = re.compile(r'<meta\s+name="csrf"\s+content="([^"]+)"')

sys.path.insert(0, "/app/panel/daemon")


def _csrf(html: str) -> str:
    m = CSRF_RE.search(html) or META_CSRF_RE.search(html)
    assert m, "csrf token not found"
    return m.group(1)


@pytest.fixture(scope="module")
def admin_session():
    s = requests.Session()
    r = s.get(f"{BASE}/login", verify=False)
    csrf = _csrf(r.text)
    r = s.post(
        f"{BASE}/login",
        data={"_csrf": csrf, "email": "admin", "password": "admin123"},
        allow_redirects=False,
        verify=False,
    )
    assert r.status_code in (302, 303), f"login failed: {r.status_code}"
    return s


@pytest.fixture(scope="module")
def db():
    conn = pymysql.connect(
        host="127.0.0.1",
        user="apexnode",
        password="apex_local_dev",
        database="apexnode",
        cursorclass=pymysql.cursors.DictCursor,
        autocommit=True,
    )
    yield conn
    conn.close()


# ---------- Regression fix #1 — status not stuck 'installing' ----------
class TestStatusResetAfterInstall:
    def test_fresh_install_resets_status_to_offline(self, admin_session, db):
        uniq = f"StatusFix3_{int(time.time())}"
        # find a free port
        with db.cursor() as c:
            c.execute("SELECT COALESCE(MAX(port),25600)+1 AS p FROM servers")
            port = c.fetchone()["p"]

        r = admin_session.get(f"{BASE}/servers/new", verify=False)
        csrf = _csrf(r.text)
        r = admin_session.post(
            f"{BASE}/servers",
            data={
                "_csrf": csrf,
                "name": uniq,
                "game": "minecraft-java",
                "loader_id": 9,
                "modpack_ref": "adrenaserver",
                "node_id": 1,
                "port": port,
                "cpu_limit": 2,
                "ram_mb": 2048,
                "disk_gb": 5,
            },
            allow_redirects=False,
            verify=False,
        )
        assert r.status_code in (302, 303), r.text[:400]
        sid = int(re.search(r"/servers/(\d+)", r.headers["Location"]).group(1))

        # trigger install
        r = admin_session.get(f"{BASE}/servers/{sid}", verify=False)
        csrf = _csrf(r.text)
        r = admin_session.post(
            f"{BASE}/servers/{sid}/modpack/install",
            data={"_csrf": csrf},
            allow_redirects=False,
            verify=False,
            timeout=240,
        )
        assert r.status_code in (200, 302, 303), r.text[:400]

        deadline = time.time() + 60
        row = None
        while time.time() < deadline:
            with db.cursor() as c:
                c.execute("SELECT status, modpack_status FROM servers WHERE id=%s", (sid,))
                row = c.fetchone()
            if row["modpack_status"] in ("installed", "failed"):
                break
            time.sleep(1)
        assert row["modpack_status"] == "installed", row
        assert row["status"] == "offline", f"status stuck: {row}"

        # UI verification
        r = admin_session.get(f"{BASE}/servers/{sid}", verify=False)
        html = r.text
        assert 'data-testid="modpack-status"' in html
        assert 'data-testid="server-status"' in html
        assert "INSTALLED" in html
        # server-status pill should contain OFFLINE (case insensitive check for surrounding html)
        # extract span with server-status
        m = re.search(r'data-testid="server-status"[^>]*>([^<]+)<', html)
        assert m, "server-status pill not found"
        assert "OFFLINE" in m.group(1).upper(), f"pill text: {m.group(1)!r}"

        pytest.iter3_sid = sid


# ---------- Regression fix #2 — ZIP traversal guard ----------
class TestExtractOverridesTraversalGuard:
    def test_malicious_override_blocked(self, tmp_path):
        import pack_resolver  # imported from /app/panel/daemon

        wd = tmp_path / "apexwd"
        wd.mkdir()

        zip_bytes = io.BytesIO()
        with zipfile.ZipFile(zip_bytes, "w") as zf:
            zf.writestr("overrides/config/ok.txt", "hello")
            zf.writestr("overrides/../../etc/foo.txt", "PWNED")

        zip_bytes.seek(0)
        logs = []

        def log(line, level):
            logs.append((line, level))

        with zipfile.ZipFile(zip_bytes, "r") as zf:
            count = pack_resolver._extract_overrides(zf, wd, "overrides", log)

        # only benign file extracted
        assert count == 1, f"expected 1, got {count}"
        ok_file = wd / "config" / "ok.txt"
        assert ok_file.exists(), "benign override was not extracted"
        assert ok_file.read_text() == "hello"

        # malicious file must NOT exist anywhere
        assert not os.path.exists("/etc/foo.txt") or open("/etc/foo.txt").read() != "PWNED", (
            "malicious traversal wrote outside sandbox!"
        )

        # warning line emitted
        warn_lines = [line for line, lv in logs if line.startswith("[modpack] ! skipped")]
        assert warn_lines, f"no 'skipped' warning logged. logs={logs}"


# ---------- Failure path — invalid ref sets status='crashed' + modpack_status='failed' ----------
class TestFailurePathStatusReset:
    def test_invalid_ref_sets_crashed_and_failed(self, admin_session, db):
        uniq = f"FailPathTest_{int(time.time())}"
        with db.cursor() as c:
            c.execute("SELECT COALESCE(MAX(port),25700)+1 AS p FROM servers")
            port = c.fetchone()["p"]

        # Create with a valid ref first (so store validation passes), then patch to bad ref
        r = admin_session.get(f"{BASE}/servers/new", verify=False)
        csrf = _csrf(r.text)
        r = admin_session.post(
            f"{BASE}/servers",
            data={
                "_csrf": csrf,
                "name": uniq,
                "game": "minecraft-java",
                "loader_id": 9,
                "modpack_ref": "adrenaserver",
                "node_id": 1,
                "port": port,
                "cpu_limit": 2,
                "ram_mb": 2048,
                "disk_gb": 5,
            },
            allow_redirects=False,
            verify=False,
        )
        assert r.status_code in (302, 303), r.text[:400]
        sid = int(re.search(r"/servers/(\d+)", r.headers["Location"]).group(1))

        with db.cursor() as c:
            c.execute(
                "UPDATE servers SET modpack_ref=%s, modpack_status='pending' WHERE id=%s",
                ("definitely-not-a-real-pack-slug-xyz", sid),
            )

        r = admin_session.get(f"{BASE}/servers/{sid}", verify=False)
        csrf = _csrf(r.text)
        r = admin_session.post(
            f"{BASE}/servers/{sid}/modpack/install",
            data={"_csrf": csrf},
            allow_redirects=False,
            verify=False,
            timeout=60,
        )
        assert r.status_code in (200, 302, 303, 400, 500)

        time.sleep(2)
        with db.cursor() as c:
            c.execute("SELECT status, modpack_status FROM servers WHERE id=%s", (sid,))
            row = c.fetchone()
            c.execute("SELECT line FROM server_logs WHERE server_id=%s", (sid,))
            logs = [r["line"] for r in c.fetchall()]

        assert row["modpack_status"] == "failed", row
        assert row["status"] == "crashed", row
        assert any(line.startswith("[modpack] \u2717 FAILED") for line in logs), (
            f"no FAILED log line found. sample={logs[-5:]}"
        )


# ---------- Regression sanity ----------
class TestRegressionSanity:
    def test_preview_modrinth(self, admin_session):
        r = admin_session.get(
            f"{BASE}/json/modpack/preview?source=modrinth&ref=fabulously-optimized",
            verify=False,
            timeout=30,
        )
        assert r.status_code == 200
        assert r.json().get("ok") is True

    def test_preview_curseforge(self, admin_session):
        r = admin_session.get(
            f"{BASE}/json/modpack/preview?source=curseforge&ref=all-the-mods-9",
            verify=False,
            timeout=45,
        )
        assert r.status_code == 200
        assert r.json().get("ok") is True

    def test_dashboard(self, admin_session):
        r = admin_session.get(f"{BASE}/dashboard", verify=False)
        assert r.status_code == 200

    def test_server_1_console(self, admin_session):
        r = admin_session.get(f"{BASE}/servers/1", verify=False)
        assert r.status_code == 200
        assert "console" in r.text.lower()

    def test_existing_installed_server_14(self, admin_session, db):
        # Only run if server 14 exists from iteration 2 seed
        with db.cursor() as c:
            c.execute("SELECT id, status, modpack_status, name FROM servers WHERE id=14")
            row = c.fetchone()
        if not row:
            pytest.skip("server id 14 not present")
        # Note: iteration 2 left this stuck at 'installing' (the bug). After the fix
        # only NEW installs will reset — existing rows retain their historical status.
        # We only assert modpack_status is installed.
        assert row["modpack_status"] == "installed", row
        r = admin_session.get(f"{BASE}/servers/14", verify=False)
        assert r.status_code == 200
        assert "INSTALLED" in r.text
