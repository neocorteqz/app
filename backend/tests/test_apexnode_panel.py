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


class TestOperationalAlerts:
    def test_dashboard_has_alert_panel(self, admin_session):
        r = admin_session.get(f"{BASE}/dashboard", verify=False)
        assert r.status_code == 200
        assert 'data-testid="operational-alerts"' in r.text
        assert 'data-testid="alert-count"' in r.text


class TestServerAccess:
    def test_viewer_file_access_is_server_scoped(self, admin_session):
        unique = int(time.time())
        username = f"acl_{unique}"
        password = "ViewerPass123!"
        page = admin_session.get(f"{BASE}/users", verify=False)
        csrf = _get_csrf(page.text)
        created = admin_session.post(f"{BASE}/users", data={
            "_csrf": csrf, "username": username, "email": f"{username}@example.test",
            "password": password, "role": "viewer",
        }, allow_redirects=False, verify=False)
        assert created.status_code in (302, 303)

        try:
            access_page = admin_session.get(f"{BASE}/servers/1/access", verify=False)
            assert access_page.status_code == 200
            assert username in access_page.text
            csrf = _get_csrf(access_page.text)
            viewer_id = re.search(r'data-testid="access-user-(\d+)"', access_page.text).group(1)
            granted = admin_session.post(f"{BASE}/servers/1/access", data={
                "_csrf": csrf, "user_id": viewer_id,
                "view_files": "1",
            }, allow_redirects=False, verify=False)
            assert granted.status_code in (302, 303)

            viewer = requests.Session()
            login = viewer.get(f"{BASE}/login", verify=False)
            csrf = _get_csrf(login.text)
            viewer.post(f"{BASE}/login", data={
                "_csrf": csrf, "email": f"{username}@example.test", "password": password,
            }, allow_redirects=False, verify=False)
            assert viewer.get(f"{BASE}/servers/1/files", verify=False).status_code == 200
            assert viewer.get(f"{BASE}/json/servers/1/jobs", verify=False).status_code == 403
            server_page = viewer.get(f"{BASE}/servers/1", verify=False, allow_redirects=False)
            assert server_page.status_code == 200
            assert 'data-testid="console-panel"' not in server_page.text
            csrf = _get_csrf(viewer.get(f"{BASE}/servers/1/files", verify=False).text)
            denied = viewer.post(f"{BASE}/servers/1/files/save", data={
                "_csrf": csrf, "path": "server.properties", "content": "nope",
            }, allow_redirects=False, verify=False)
            assert denied.status_code == 403
        finally:
            page = admin_session.get(f"{BASE}/users", verify=False)
            match = re.search(rf'<tr>.*?{re.escape(username)}.*?name="id" value="(\d+)".*?</tr>', page.text, re.S)
            if match:
                admin_session.post(f"{BASE}/users/delete", data={
                    "_csrf": _get_csrf(page.text), "id": match.group(1),
                }, allow_redirects=False, verify=False)


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
        if 'data-testid="credential-reveal"' not in follow.text:
            assert 'data-testid="db-provisioning-unconfigured"' in follow.text
            pytest.skip("database provisioning credentials are not configured in this local environment")
        assert name in follow.text or f"apexnode_{db_name}" in follow.text
        assert 'data-testid="credential-reveal"' in follow.text
        assert password in follow.text
        row_html = re.search(r'<tr data-testid="db-user-(\d+)"[^>]*>.*?' + re.escape(name) + r'.*?</tr>', follow.text, re.S)
        assert row_html, "created database-user row not found"
        db_user_id = row_html.group(1)
        assert f'data-testid="rotate-db-password-{db_user_id}"' in follow.text

        hidden = admin_session.get(f"{BASE}/database-users", verify=False)
        assert password not in hidden.text

        csrf = _get_csrf(hidden.text)
        rotated = admin_session.post(
            f"{BASE}/database-users/rotate",
            data={"_csrf": csrf, "id": db_user_id},
            allow_redirects=False,
            verify=False,
        )
        assert rotated.status_code in (200, 302, 303)
        reveal = admin_session.get(f"{BASE}/database-users", verify=False)
        assert 'data-testid="credential-reveal"' in reveal.text
        assert password not in reveal.text

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
            f"{BASE}/eggs/preview",
            data={"_csrf": csrf, "egg_json": json.dumps(payload)},
            allow_redirects=False,
            verify=False,
        )
        assert r.status_code in (200, 302, 303), r.status_code
        assert 'data-testid="egg-import-preview"' in r.text
        csrf = _get_csrf(r.text)
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

        changed = dict(payload, startup="java -jar updated-server.jar nogui")
        csrf = _get_csrf(follow.text)
        preview = admin_session.post(f"{BASE}/eggs/preview", data={
            "_csrf": csrf, "egg_json": json.dumps(changed),
        }, allow_redirects=False, verify=False)
        assert preview.status_code == 200
        assert 'data-testid="egg-update-state"' in preview.text
        assert "Update available" in preview.text
        csrf = _get_csrf(preview.text)
        updated = admin_session.post(f"{BASE}/eggs/import", data={
            "_csrf": csrf, "egg_json": json.dumps(changed),
        }, allow_redirects=False, verify=False)
        assert updated.status_code in (200, 302, 303)
        confirmed = admin_session.get(f"{BASE}/eggs", verify=False)
        egg_name_position = confirmed.text.find(f"<h3>{egg_name}</h3>")
        details_link = re.search(r'data-testid="egg-details-(\d+)"', confirmed.text[egg_name_position:]) if egg_name_position >= 0 else None
        assert details_link, f"updated egg details link not found: {egg_name}"
        details = admin_session.get(f"{BASE}/eggs/{details_link.group(1)}", verify=False)
        assert "updated-server.jar" in details.text


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

    def test_sibling_prefix_path_is_blocked(self, admin_session):
        sibling_id = f"1{int(time.time())}"
        sibling_dir = f"/var/lib/apexnode/servers/{sibling_id}"
        os.mkdir(sibling_dir)
        try:
            r = admin_session.get(
                f"{BASE}/servers/1/files",
                params={"path": f"../{sibling_id}"},
                allow_redirects=False,
                verify=False,
            )
            assert r.status_code in (302, 303, 400, 403)
        finally:
            os.rmdir(sibling_dir)
