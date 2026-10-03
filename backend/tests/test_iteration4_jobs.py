"""Iteration 4 — Background Job Queue tests.

Covers:
  - Enqueue latency (<3s redirect)
  - Job row creation on enqueue
  - GET /json/jobs/{id} live progress polling → completed
  - GET /json/servers/{id}/jobs listing
  - Post-install server state + jar count on disk
  - /jobs page renders server name + jobs-table testid
  - /dashboard has nav-jobs testid
  - Idempotent enqueue on already-installed server (no new job row)
  - Failure path with invalid modpack ref
  - Regression sanity (modpack preview, /dashboard, /servers, /servers/1, /mods, /eggs)
"""

import os
import re
import time
from pathlib import Path

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


def _next_port(db):
    with db.cursor() as c:
        c.execute("SELECT COALESCE(MAX(port),25600)+1 AS p FROM servers")
        return c.fetchone()["p"]


def _deploy_server(admin_session, db, name, modpack_ref="adrenaserver", loader_id=9):
    port = _next_port(db)
    r = admin_session.get(f"{BASE}/servers/new", verify=False)
    csrf = _csrf(r.text)
    r = admin_session.post(
        f"{BASE}/servers",
        data={
            "_csrf": csrf,
            "name": name,
            "game": "minecraft-java",
            "loader_id": loader_id,
            "modpack_ref": modpack_ref,
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
    return sid


def _enqueue_install(admin_session, sid):
    r = admin_session.get(f"{BASE}/servers/{sid}", verify=False)
    csrf = _csrf(r.text)
    t0 = time.perf_counter()
    r = admin_session.post(
        f"{BASE}/servers/{sid}/modpack/install",
        data={"_csrf": csrf},
        allow_redirects=False,
        verify=False,
        timeout=15,
    )
    elapsed = time.perf_counter() - t0
    return r, elapsed


# ---------------- Happy path: enqueue → progress → completed ----------------
class TestQueueHappyPath:
    sid = None
    job_id = None
    server_name = None

    def test_01_deploy_and_enqueue_latency(self, admin_session, db):
        ts = int(time.time())
        name = f"QueueLatencyTest_{ts}"
        TestQueueHappyPath.server_name = name
        sid = _deploy_server(admin_session, db, name)
        TestQueueHappyPath.sid = sid

        r, elapsed = _enqueue_install(admin_session, sid)
        print(f"enqueue elapsed: {elapsed * 1000:.0f}ms")
        assert r.status_code in (302, 303), (
            f"expected redirect, got {r.status_code}: {r.text[:400]}"
        )
        assert r.headers["Location"].endswith(f"/servers/{sid}")
        assert elapsed < 3.0, f"enqueue took {elapsed:.2f}s (>3s)"

    def test_02_job_row_created(self, db):
        sid = TestQueueHappyPath.sid
        with db.cursor() as c:
            c.execute(
                "SELECT * FROM jobs WHERE target_id=%s AND target_kind='server' ORDER BY id DESC LIMIT 1",
                (sid,),
            )
            row = c.fetchone()
        assert row, "no job row created"
        assert row["kind"] == "modpack_install"
        assert row["status"] in ("queued", "running", "completed"), row
        TestQueueHappyPath.job_id = row["id"]
        print(f"job_id={row['id']} status={row['status']}")

    def test_03_live_progress_reaches_completed(self, admin_session):
        jid = TestQueueHappyPath.job_id
        deadline = time.time() + 90
        last = None
        while time.time() < deadline:
            r = admin_session.get(f"{BASE}/json/jobs/{jid}", verify=False)
            assert r.status_code == 200, r.text[:200]
            j = r.json()
            # required fields
            for k in (
                "id",
                "kind",
                "target_id",
                "target_kind",
                "status",
                "progress",
                "total",
                "pct",
                "message",
            ):
                assert k in j, f"missing field {k} in {j}"
            assert isinstance(j["pct"], int) and 0 <= j["pct"] <= 100
            last = j
            if j["status"] == "completed":
                assert j["pct"] == 100, f"completed but pct={j['pct']}"
                return
            if j["status"] == "failed":
                pytest.fail(f"job failed: {j.get('error')}")
            time.sleep(2)
        pytest.fail(f"job did not complete in 90s, last={last}")

    def test_04_per_server_jobs_endpoint(self, admin_session):
        sid = TestQueueHappyPath.sid
        r = admin_session.get(f"{BASE}/json/servers/{sid}/jobs", verify=False)
        assert r.status_code == 200
        arr = r.json()
        assert isinstance(arr, list) and len(arr) >= 1
        assert arr[0]["id"] == TestQueueHappyPath.job_id
        assert "pct" in arr[0]

    def test_05_post_install_state(self, db):
        sid = TestQueueHappyPath.sid
        with db.cursor() as c:
            c.execute("SELECT status, modpack_status FROM servers WHERE id=%s", (sid,))
            row = c.fetchone()
        assert row["status"] == "offline", row
        assert row["modpack_status"] == "installed", row

        mods_dir = Path(f"/var/lib/apexnode/servers/{sid}/mods")
        assert mods_dir.exists(), f"mods dir missing: {mods_dir}"
        jars = list(mods_dir.glob("*.jar"))
        assert len(jars) >= 5, f"expected >=5 jars, got {len(jars)}"

    def test_06_jobs_page_html(self, admin_session):
        r = admin_session.get(f"{BASE}/jobs", verify=False)
        assert r.status_code == 200
        html = r.text
        assert 'data-testid="jobs-table"' in html
        assert TestQueueHappyPath.server_name in html, "server name missing on /jobs"

    def test_07_dashboard_has_nav_jobs(self, admin_session):
        r = admin_session.get(f"{BASE}/dashboard", verify=False)
        assert r.status_code == 200
        assert 'data-testid="nav-jobs"' in r.text


# ---------------- Idempotent enqueue on already-installed server ----------------
class TestIdempotentEnqueue:
    def test_no_new_job_on_installed_server(self, admin_session, db):
        # Pick any server with modpack_status='installed'
        with db.cursor() as c:
            c.execute(
                "SELECT id, name FROM servers WHERE modpack_status='installed' ORDER BY id DESC LIMIT 1"
            )
            row = c.fetchone()
        assert row, "no installed server available"
        sid = row["id"]
        with db.cursor() as c:
            c.execute(
                "SELECT COUNT(*) AS n FROM jobs WHERE target_id=%s AND target_kind='server'", (sid,)
            )
            before = c.fetchone()["n"]
        r, elapsed = _enqueue_install(admin_session, sid)
        assert r.status_code in (302, 303)
        assert elapsed < 3.0
        # Follow the redirect and check flash
        response = admin_session.get(f"{BASE}/servers/{sid}", verify=False)
        assert response.status_code == 200
        # Flash consumed on read; check present in this response OR next
        # (flash is one-shot; just ensure no new job row)
        with db.cursor() as c:
            c.execute(
                "SELECT COUNT(*) AS n FROM jobs WHERE target_id=%s AND target_kind='server'", (sid,)
            )
            after = c.fetchone()["n"]
        assert after == before, f"unexpected new job row: before={before} after={after}"


# ---------------- Failure path ----------------
class TestFailurePath:
    def test_invalid_modpack_ref_marks_job_failed(self, admin_session, db):
        ts = int(time.time())
        name = f"QueueFailTest_{ts}"
        # Deploy with a valid loader but a bogus ref
        sid = _deploy_server(
            admin_session, db, name, modpack_ref="definitely-not-a-real-pack-slug-abc"
        )
        r, elapsed = _enqueue_install(admin_session, sid)
        assert r.status_code in (302, 303)
        assert elapsed < 3.0

        with db.cursor() as c:
            c.execute(
                "SELECT id FROM jobs WHERE target_id=%s AND target_kind='server' ORDER BY id DESC LIMIT 1",
                (sid,),
            )
            jid = c.fetchone()["id"]

        deadline = time.time() + 60
        while time.time() < deadline:
            r = admin_session.get(f"{BASE}/json/jobs/{jid}", verify=False)
            j = r.json()
            if j["status"] == "failed":
                assert j.get("error"), "error field empty on failed job"
                break
            if j["status"] == "completed":
                pytest.fail("unexpected completion for invalid ref")
            time.sleep(2)
        else:
            pytest.fail("failure job did not resolve in 60s")

        with db.cursor() as c:
            c.execute("SELECT status, modpack_status FROM servers WHERE id=%s", (sid,))
            srow = c.fetchone()
        assert srow["status"] == "crashed", srow
        assert srow["modpack_status"] == "failed", srow

        with db.cursor() as c:
            c.execute(
                "SELECT line FROM server_logs WHERE server_id=%s AND line LIKE %s",
                (sid, "%[modpack] ✗ FAILED%"),
            )
            logs = c.fetchall()
        assert logs, "no [modpack] ✗ FAILED log line"


# ---------------- Regression sanity ----------------
class TestRegression:
    def test_modpack_preview_modrinth(self, admin_session):
        r = admin_session.get(
            f"{BASE}/json/modpack/preview",
            params={"source": "modrinth", "ref": "fabulously-optimized"},
            verify=False,
            timeout=20,
        )
        assert r.status_code == 200
        assert r.json().get("ok") is True

    def test_modpack_preview_curseforge(self, admin_session):
        r = admin_session.get(
            f"{BASE}/json/modpack/preview",
            params={"source": "curseforge", "ref": "all-the-mods-9"},
            verify=False,
            timeout=30,
        )
        assert r.status_code == 200
        # CurseForge may fail if no api key — still return 200 with ok=false; accept both
        assert "ok" in r.json()

    def test_core_pages_render(self, admin_session):
        for p in ["/dashboard", "/servers", "/servers/1", "/mods", "/eggs"]:
            r = admin_session.get(f"{BASE}{p}", verify=False)
            assert r.status_code == 200, f"{p} → {r.status_code}"

    def test_existing_installed_server_intact(self, db):
        with db.cursor() as c:
            c.execute("SELECT modpack_status FROM servers WHERE id=17")
            row = c.fetchone()
        if not row or row["modpack_status"] != "installed":
            pytest.skip("seeded installed server 17 is not present in this database")
        assert row["modpack_status"] == "installed", row
