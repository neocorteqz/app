"""ApexNode Panel end-to-end backend tests (PHP panel + FastAPI daemon)."""
import json
import os
import re
import time
import pytest
import requests

BASE = os.environ.get("REACT_APP_BACKEND_URL", "http://127.0.0.1:3001").rstrip("/")

CSRF_RE = re.compile(r'name="_csrf"\s+value="([^"]+)"')
META_CSRF_RE = re.compile(r'<meta\s+name="csrf"\s+content="([^"]+)"')


def _get_csrf(html: str) -> str:
    m = CSRF_RE.search(html) or META_CSRF_RE.search(html)
    assert m, "csrf token not found"
    return m.group(1)


@pytest.fixture(scope="session")
def admin_session():
    s = requests.Session()
    r = s.get(f"{BASE}/login", verify=False)
    assert r.status_code == 200
    csrf = _get_csrf(r.text)
    r = s.post(
        f"{BASE}/login",
        data={"_csrf": csrf, "email": "admin", "password": "admin123"},
        allow_redirects=False,
        verify=False,
    )
    assert r.status_code in (302, 303), f"login failed: {r.status_code}"
    # verify dashboard
    r = s.get(f"{BASE}/dashboard", verify=False)
    assert r.status_code == 200
    assert "ApexNode" in r.text or "Servers" in r.text
    return s


# --- PWA / public endpoints -------------------------------------------------
class TestPublic:
    def test_login_page(self):
        r = requests.get(f"{BASE}/login", verify=False)
        assert r.status_code == 200
        assert "sign" in r.text.lower() or "login" in r.text.lower()

    def test_manifest(self):
        r = requests.get(f"{BASE}/manifest.webmanifest", verify=False)
        assert r.status_code == 200
        j = r.json()
        assert j["name"] == "ApexNode Panel"

    def test_service_worker(self):
        r = requests.get(f"{BASE}/service-worker.js", verify=False)
        assert r.status_code == 200
        assert "self.addEventListener" in r.text

    def test_install_sh(self):
        r = requests.get(f"{BASE}/install.sh", verify=False)
        assert r.status_code == 200
        assert r.text.startswith("#!/usr/bin/env bash")

    def test_dashboard_redirects_when_logged_out(self):
        r = requests.get(f"{BASE}/dashboard", allow_redirects=False, verify=False)
        assert r.status_code in (301, 302, 303)
        assert "/login" in r.headers.get("Location", "")

    def test_404(self, admin_session):
        r = admin_session.get(f"{BASE}/some-nonexistent-page", verify=False)
        assert r.status_code == 404


# --- sidebar nav ------------------------------------------------------------
class TestSidebarNav:
    @pytest.mark.parametrize(
        "path",
        ["/dashboard", "/servers", "/eggs", "/mods", "/nodes",
         "/activity", "/theme", "/discord", "/users", "/install"],
    )
    def test_nav(self, admin_session, path):
        r = admin_session.get(f"{BASE}{path}", verify=False)
        assert r.status_code == 200, f"{path} -> {r.status_code}"


# --- servers ----------------------------------------------------------------
class TestServers:
    def test_list_shows_seeded(self, admin_session):
        r = admin_session.get(f"{BASE}/servers", verify=False)
        assert r.status_code == 200
        for name in ["Survival SMP", "Bedrock Realm", "CS2 5v5 EU", "Rust Vanilla", "Creative Build"]:
            assert name in r.text, f"missing seeded server: {name}"

    def test_detail(self, admin_session):
        r = admin_session.get(f"{BASE}/servers/1", verify=False)
        assert r.status_code == 200
        assert "console" in r.text.lower()


# --- daemon bridge ---------------------------------------------------------
class TestDaemon:
    def test_status_endpoint(self):
        r = requests.get(f"{BASE}/api/daemon/status/1", verify=False)
        assert r.status_code == 200
        data = r.json()
        assert "running" in data

    def test_start_stop_cycle(self, admin_session):
        # get csrf from server detail page
        r = admin_session.get(f"{BASE}/servers/1", verify=False)
        csrf = _get_csrf(r.text)

        r = admin_session.post(
            f"{BASE}/servers/action",
            data={"_csrf": csrf, "id": 1, "action": "start"},
            allow_redirects=False,
            verify=False,
        )
        assert r.status_code in (200, 302, 303), r.status_code
        time.sleep(4)

        st = requests.get(f"{BASE}/api/daemon/status/1", verify=False).json()
        assert st.get("running") is True, f"server not running: {st}"

        # stop
        r = admin_session.post(
            f"{BASE}/servers/action",
            data={"_csrf": csrf, "id": 1, "action": "stop"},
            allow_redirects=False,
            verify=False,
        )
        assert r.status_code in (200, 302, 303)
        time.sleep(2)
        st = requests.get(f"{BASE}/api/daemon/status/1", verify=False).json()
        assert st.get("running") is False


# --- mods / eggs / loaders json --------------------------------------------
class TestModsEggs:
    def test_eggs_list(self, admin_session):
        r = admin_session.get(f"{BASE}/eggs", verify=False)
        assert r.status_code == 200
        # count deploy buttons as a rough egg count
        assert r.text.count("egg-deploy-") >= 9, f"expected 9+ eggs, got {r.text.count('egg-deploy-')}"

    def test_egg_detail(self, admin_session):
        r = admin_session.get(f"{BASE}/eggs/1", verify=False)
        assert r.status_code == 200

    def test_egg_deploy_form(self, admin_session):
        r = admin_session.get(f"{BASE}/eggs/1/deploy", verify=False)
        assert r.status_code == 200

    def test_mods_index(self, admin_session):
        r = admin_session.get(f"{BASE}/mods", verify=False)
        assert r.status_code == 200
        # count mod detail links
        c = r.text.count("mod-details-")
        assert c >= 20, f"expected 20+ loaders, got {c}"

    def test_mods_filter_java(self, admin_session):
        r = admin_session.get(f"{BASE}/mods?game=minecraft-java", verify=False)
        assert r.status_code == 200
        for name in ["Paper", "Forge", "Fabric"]:
            assert name in r.text

    def test_json_loaders(self, admin_session):
        r = admin_session.get(f"{BASE}/json/loaders?game=cs2", verify=False)
        assert r.status_code == 200
        j = r.json()
        assert isinstance(j, list) and len(j) > 0

    def test_database_user_create_flow(self, admin_session):
        unique = int(time.time())
        name = f"app_{unique}"
        db_name = f"db_{unique}"
        password = "StrongPass123!"

        page = admin_session.get(f"{BASE}/database-users", verify=False)
        assert page.status_code == 200
        csrf = _get_csrf(page.text)

        r = admin_session.post(
            f"{BASE}/database-users",
            data={
                "_csrf": csrf,
                "name": name,
                "database_name": db_name,
                "host": "localhost",
                "password": password,
            },
            allow_redirects=False,
            verify=False,
        )
        assert r.status_code in (200, 302, 303), r.status_code

        follow = admin_session.get(f"{BASE}/database-users", verify=False)
        assert follow.status_code == 200
        assert name in follow.text or db_name in follow.text

    def test_pterodactyl_egg_import_flow(self, admin_session):
        unique = int(time.time())
        egg_name = f"Ptero Test Egg {unique}"
        payload = {
            "name": egg_name,
            "description": "Imported for regression testing",
            "startup": "java -Xms128M -Xmx512M -jar server.jar nogui",
            "docker_images": {"java": "ghcr.io/pterodactyl/yolks:java_21"},
            "variables": [{"env_variable": "JAVA_VERSION", "default_value": "21"}],
        }

        page = admin_session.get(f"{BASE}/eggs", verify=False)
        assert page.status_code == 200
        csrf = _get_csrf(page.text)

        r = admin_session.post(
            f"{BASE}/eggs/import",
            data={"_csrf": csrf, "egg_json": json.dumps(payload)},
            allow_redirects=False,
            verify=False,
        )
        assert r.status_code in (200, 302, 303), r.status_code

        follow = admin_session.get(f"{BASE}/eggs", verify=False)
        assert follow.status_code == 200
        assert egg_name in follow.text


# --- theme -----------------------------------------------------------------
class TestTheme:
    def test_theme_css_endpoint(self):
        r = requests.get(f"{BASE}/theme.css", verify=False)
        assert r.status_code == 200
        assert "--accent" in r.text

    def test_theme_save(self, admin_session):
        r = admin_session.get(f"{BASE}/theme", verify=False)
        csrf = _get_csrf(r.text)
        r = admin_session.post(
            f"{BASE}/theme",
            data={
                "_csrf": csrf,
                "accent": "#A855F7",
                "radius": "12px",
                "density": "comfortable",
                "mode": "dark",
                "font": "Inter",
            },
            allow_redirects=False,
            verify=False,
        )
        assert r.status_code in (200, 302, 303)
        r = admin_session.get(f"{BASE}/theme.css", verify=False)
        assert "#A855F7" in r.text or "#a855f7" in r.text.lower()


# --- discord (bogus token, should not crash) --------------------------------
class TestDiscord:
    def test_discord_page(self, admin_session):
        r = admin_session.get(f"{BASE}/discord", verify=False)
        assert r.status_code == 200

    def test_discord_test_bogus(self, admin_session):
        r = admin_session.get(f"{BASE}/discord", verify=False)
        csrf = _get_csrf(r.text)
        r = admin_session.post(
            f"{BASE}/discord/test",
            data={"_csrf": csrf, "token": "bogus.invalid.token"},
            allow_redirects=True,
            verify=False,
        )
        assert r.status_code == 200
        assert ("FAILED" in r.text) or ("failed" in r.text.lower()) or ("error" in r.text.lower())


# --- backups ---------------------------------------------------------------
class TestBackups:
    def test_backups_page(self, admin_session):
        r = admin_session.get(f"{BASE}/servers/1/backups", verify=False)
        assert r.status_code == 200

    def test_run_backup(self, admin_session):
        r = admin_session.get(f"{BASE}/servers/1/backups", verify=False)
        csrf = _get_csrf(r.text)
        r = admin_session.post(
            f"{BASE}/servers/1/backups/run",
            data={"_csrf": csrf},
            allow_redirects=False,
            verify=False,
        )
        assert r.status_code in (200, 302, 303)
        time.sleep(3)
        r = admin_session.get(f"{BASE}/servers/1/backups", verify=False)
        assert "COMPLETED" in r.text or "completed" in r.text.lower()


# --- file manager ----------------------------------------------------------
class TestFiles:
    def test_files_index(self, admin_session):
        r = admin_session.get(f"{BASE}/servers/1/files", verify=False)
        assert r.status_code == 200

    def test_path_traversal_blocked(self, admin_session):
        r = admin_session.get(
            f"{BASE}/servers/1/files?path=../../../../etc",
            allow_redirects=False,
            verify=False,
        )
        # should either redirect or 400/403
        assert r.status_code in (200, 302, 303, 400, 403)
        if r.status_code == 200:
            assert "passwd" not in r.text and "root:" not in r.text
