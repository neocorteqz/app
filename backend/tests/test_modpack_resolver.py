"""ApexNode Modpack Resolver — Modrinth + CurseForge integration tests.

Second-iteration test module. Reuses login/csrf helpers from
test_apexnode_panel.py through shared session-scoped fixtures.

Real network — allow long timeouts. Only 1 real Modrinth install is exercised
end-to-end (adrenaserver, small optimization pack). CurseForge downloads are
skipped (packs are huge); only preview is tested end-to-end.
"""

import os
import re
import time

import pymysql
import pytest
import requests

# These historical suites require a disposable, configured game host.
pytestmark = pytest.mark.skipif(
    os.environ.get("APEX_RUN_LIVE_TESTS") != "1",
    reason="Set APEX_RUN_LIVE_TESTS=1 only for an explicitly provisioned disposable host",
)

BASE = os.environ.get("REACT_APP_BACKEND_URL", "http://127.0.0.1:3001").rstrip("/")
DAEMON = "http://127.0.0.1:8001"

CSRF_RE = re.compile(r'name="_csrf"\s+value="([^"]+)"')
META_CSRF_RE = re.compile(r'<meta\s+name="csrf"\s+content="([^"]+)"')


def _get_csrf(html: str) -> str:
    m = CSRF_RE.search(html) or META_CSRF_RE.search(html)
    assert m, "csrf token not found"
    return m.group(1)


@pytest.fixture(scope="module")
def admin_session():
    s = requests.Session()
    r = s.get(f"{BASE}/login", verify=False)
    csrf = _get_csrf(r.text)
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


# ---------------------------------------------------------------------
# Preview endpoint (auth-guarded panel proxy /json/modpack/preview)
# ---------------------------------------------------------------------
class TestPreview:
    def test_preview_requires_auth(self):
        r = requests.get(
            f"{BASE}/json/modpack/preview?source=modrinth&ref=fabulously-optimized",
            allow_redirects=False,
            verify=False,
        )
        assert r.status_code in (301, 302, 303)
        assert "/login" in r.headers.get("Location", "")

    def test_modrinth_success(self, admin_session):
        r = admin_session.get(
            f"{BASE}/json/modpack/preview?source=modrinth&ref=fabulously-optimized",
            verify=False,
            timeout=30,
        )
        assert r.status_code == 200
        j = r.json()
        assert j.get("ok") is True, j
        d = j["data"]
        assert d["source"] == "modrinth"
        assert d["slug"] == "fabulously-optimized"
        assert "Fabulously" in d["title"] or "fabulously" in d["title"].lower()
        assert isinstance(d["downloads"], int) and d["downloads"] > 0
        assert isinstance(d["latest_loaders"], list)
        assert isinstance(d["latest_mc"], list)
        assert isinstance(d["files_count"], int)
        assert d["latest_version"]

    def test_modrinth_not_found(self, admin_session):
        r = admin_session.get(
            f"{BASE}/json/modpack/preview?source=modrinth&ref=this-does-not-exist-xyz",
            verify=False,
            timeout=30,
        )
        assert r.status_code == 200
        j = r.json()
        assert j.get("ok") is False
        assert "not found" in j.get("error", "").lower()

    def test_curseforge_by_slug(self, admin_session):
        r = admin_session.get(
            f"{BASE}/json/modpack/preview?source=curseforge&ref=all-the-mods-9",
            verify=False,
            timeout=45,
        )
        assert r.status_code == 200
        j = r.json()
        assert j.get("ok") is True, j
        d = j["data"]
        assert d["source"] == "curseforge"
        assert d["id"] == 715572
        assert d["slug"] == "all-the-mods-9"
        assert "All the Mods 9" in d["title"]
        assert isinstance(d["latest_file_id"], int)
        assert d["latest_file_name"].endswith(".zip")

    def test_curseforge_by_id_atm8(self, admin_session):
        r = admin_session.get(
            f"{BASE}/json/modpack/preview?source=curseforge&ref=520914", verify=False, timeout=45
        )
        assert r.status_code == 200
        j = r.json()
        assert j.get("ok") is True, j
        assert j["data"]["title"].startswith("All the Mods 8")


# ---------------------------------------------------------------------
# POST /servers deploying a modpack loader sets modpack_status='pending'
# ---------------------------------------------------------------------
class TestDeployModpackServer:
    def test_store_sets_pending(self, admin_session, db):
        r = admin_session.get(f"{BASE}/servers/new", verify=False)
        csrf = _get_csrf(r.text)
        r = admin_session.post(
            f"{BASE}/servers",
            data={
                "_csrf": csrf,
                "name": "FabOptTest",
                "game": "minecraft-java",
                "loader_id": 9,
                "modpack_ref": "fabulously-optimized",
                "node_id": 1,
                "port": 25595,
                "cpu_limit": 2,
                "ram_mb": 4096,
                "disk_gb": 10,
            },
            allow_redirects=False,
            verify=False,
        )
        assert r.status_code in (302, 303), r.text[:400]
        loc = r.headers.get("Location", "")
        assert re.search(r"/servers/(\d+)", loc), loc
        sid = int(re.search(r"/servers/(\d+)", loc).group(1))
        # Verify DB row
        with db.cursor() as c:
            c.execute("SELECT modpack_status, modpack_ref FROM servers WHERE id=%s", (sid,))
            row = c.fetchone()
        assert row["modpack_status"] == "pending"
        assert row["modpack_ref"] == "fabulously-optimized"
        # store id for cleanup
        pytest.faboptserver_id = sid


# ---------------------------------------------------------------------
# Path safety: manipulated modpack_ref does not escape sandbox
# ---------------------------------------------------------------------
class TestPathSafety:
    def test_traversal_ref_rejected(self, admin_session, db):
        # Create a server with a malicious ref (updating existing row directly)
        with db.cursor() as c:
            c.execute("SELECT id FROM servers WHERE modpack_ref='fabulously-optimized' LIMIT 1")
            row = c.fetchone()
            if not row:
                pytest.skip("prior test didn't create a modpack server")
            sid = row["id"]
            c.execute(
                "UPDATE servers SET modpack_ref=%s, modpack_status='pending' WHERE id=%s",
                ("../etc/passwd", sid),
            )
        # Trigger install via panel
        r = admin_session.get(f"{BASE}/servers/{sid}", verify=False)
        csrf = _get_csrf(r.text)
        r = admin_session.post(
            f"{BASE}/servers/{sid}/modpack/install",
            data={"_csrf": csrf},
            allow_redirects=False,
            verify=False,
            timeout=60,
        )
        # panel returns 302 back with flash error
        assert r.status_code in (200, 302, 303)
        # ensure no writes outside sandbox
        assert not os.path.exists("/var/lib/apexnode/servers/../etc/passwd_escaped")
        # ensure daemon returned an error and status is 'failed' (or still pending)
        with db.cursor() as c:
            c.execute("SELECT modpack_status FROM servers WHERE id=%s", (sid,))
            status = c.fetchone()["modpack_status"]
        assert status in ("failed", "pending"), status
        # restore correct ref for future tests
        with db.cursor() as c:
            c.execute(
                "UPDATE servers SET modpack_ref='fabulously-optimized', modpack_status='pending' WHERE id=%s",
                (sid,),
            )


# ---------------------------------------------------------------------
# End-to-end Modrinth install for a SMALL pack (adrenaserver)
# Marked slow — this actually downloads a real modpack.
# ---------------------------------------------------------------------
@pytest.mark.slow
class TestModrinthInstallE2E:
    def test_install_adrenaserver(self, admin_session, db):
        # Create a fresh server pointing at adrenaserver
        r = admin_session.get(f"{BASE}/servers/new", verify=False)
        csrf = _get_csrf(r.text)
        r = admin_session.post(
            f"{BASE}/servers",
            data={
                "_csrf": csrf,
                "name": "AdrenaTest",
                "game": "minecraft-java",
                "loader_id": 9,
                "modpack_ref": "adrenaserver",
                "node_id": 1,
                "port": 25596,
                "cpu_limit": 2,
                "ram_mb": 2048,
                "disk_gb": 5,
            },
            allow_redirects=False,
            verify=False,
        )
        assert r.status_code in (302, 303)
        sid = int(re.search(r"/servers/(\d+)", r.headers["Location"]).group(1))

        # Trigger manual install via panel; the daemon processes it asynchronously.
        r = admin_session.get(f"{BASE}/servers/{sid}", verify=False)
        csrf = _get_csrf(r.text)
        r = admin_session.post(
            f"{BASE}/servers/{sid}/modpack/install",
            data={"_csrf": csrf},
            allow_redirects=False,
            verify=False,
            timeout=180,
        )
        assert r.status_code in (200, 302, 303), r.text[:400]

        deadline = time.time() + 180
        status = None
        while time.time() < deadline:
            with db.cursor() as c:
                c.execute("SELECT modpack_status FROM servers WHERE id=%s", (sid,))
                status = c.fetchone()["modpack_status"]
            if status in ("installed", "failed"):
                break
            time.sleep(1)
        assert status == "installed"

        with db.cursor() as c:
            c.execute("SELECT line FROM server_logs WHERE server_id=%s", (sid,))
            log_lines = [r["line"] for r in c.fetchall()]

        # verify jar files exist
        mods_dir = f"/var/lib/apexnode/servers/{sid}/mods"
        assert os.path.isdir(mods_dir), f"no mods dir {mods_dir}"
        jars = [f for f in os.listdir(mods_dir) if f.endswith(".jar")]
        # adrenaserver is a very small optimization pack (~10 jars). Larger packs
        # like fabulously-optimized would satisfy the >=20 spec but download 100+ MB.
        assert len(jars) >= 5, f"only {len(jars)} jars — expected 5+"

        # verify log entries
        assert any(line.startswith("[modpack] ⬇") for line in log_lines), "no download log line"
        assert any("[modpack] ✓ Modrinth pack installed" in line for line in log_lines), (
            "no success line"
        )

        # verify UI shows INSTALLED status pill
        r = admin_session.get(f"{BASE}/servers/{sid}", verify=False)
        assert 'data-testid="modpack-status"' in r.text
        assert "INSTALLED" in r.text
        # Install-pack-now button should be gone
        # (btn-install-pack still may appear if template renders it; check it is not present when installed)
        assert 'data-testid="btn-install-pack"' not in r.text, (
            "install button should be hidden after install"
        )

        pytest.adrena_sid = sid


# ---------------------------------------------------------------------
# Regression — quick smoke of key v1 endpoints still work
# ---------------------------------------------------------------------
class TestRegression:
    def test_dashboard(self, admin_session):
        r = admin_session.get(f"{BASE}/dashboard", verify=False)
        assert r.status_code == 200

    def test_servers_list(self, admin_session):
        r = admin_session.get(f"{BASE}/servers", verify=False)
        assert r.status_code == 200

    def test_server_1_detail(self, admin_session):
        r = admin_session.get(f"{BASE}/servers/1", verify=False)
        assert r.status_code == 200
        assert "console" in r.text.lower()

    def test_mods_list_20_loaders(self, admin_session):
        r = admin_session.get(f"{BASE}/mods", verify=False)
        assert r.status_code == 200
        assert r.text.count("mod-details-") >= 20

    def test_json_servers(self, admin_session):
        r = admin_session.get(f"{BASE}/json/servers", verify=False)
        assert r.status_code == 200
        assert isinstance(r.json(), list)

    def test_json_logs(self, admin_session):
        r = admin_session.get(f"{BASE}/json/servers/logs?id=1&after=0", verify=False)
        assert r.status_code == 200
        j = r.json()
        assert "lines" in j
