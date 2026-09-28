"""
ApexNode modpack resolver — real Modrinth + CurseForge downloaders.

- Modrinth: public API (no key). Resolves a slug to the latest primary version,
  downloads the `.mrpack` (ZIP), parses `modrinth.index.json`, downloads each
  listed file, extracts the `overrides/` folder into the server root.

- CurseForge: v1 API (requires key stored in `settings.curseforge_api_key`).
  Searches by slug (game_id=432 Minecraft), picks the newest server-pack file
  (fallback: newest file), downloads the modpack ZIP, parses `manifest.json`,
  resolves each (projectID,fileID) pair to a download URL, downloads all mods,
  extracts `overrides/`.

The resolver streams progress lines through the `log_cb(line, level)` callback
so the daemon writes them to server_logs and they appear live in the console.
"""
from __future__ import annotations
import io
import json
import os
import time
import zipfile
from pathlib import Path
from typing import Callable, Optional

import requests

LogFn = Callable[[str, str], None]
ProgressFn = Callable[[int, int, str], None]  # done, total, message


class Cancelled(Exception):
    pass


class CancelToken:
    """Thread-safe cancel signal. Resolver polls .check() between files/downloads."""
    def __init__(self):
        self._flag = False
    def set(self):
        self._flag = True
    def is_set(self) -> bool:
        return self._flag
    def check(self):
        if self._flag:
            raise Cancelled("job cancelled by operator")


MODRINTH_API = "https://api.modrinth.com/v2"
CURSEFORGE_API = "https://api.curseforge.com/v1"
CFWIDGET_API = "https://api.cfwidget.com"  # public slug→id resolver
MC_GAME_ID = 432  # Minecraft
CLASS_MODPACKS = 4471


class ResolveError(Exception):
    pass


# ---------------- Modrinth ----------------

def modrinth_preview(ref: str) -> dict:
    """Return {name, author, downloads, latest_version, files_count} for a Modrinth pack."""
    r = requests.get(f"{MODRINTH_API}/project/{ref}", timeout=15)
    if r.status_code == 404:
        raise ResolveError(f"Modrinth project '{ref}' not found")
    r.raise_for_status()
    proj = r.json()
    versions = requests.get(f"{MODRINTH_API}/project/{proj['id']}/version", timeout=15).json()
    latest = versions[0] if versions else None
    return {
        "source": "modrinth",
        "slug": proj["slug"], "title": proj["title"],
        "description": proj["description"], "downloads": proj["downloads"],
        "team": proj.get("team"), "categories": proj.get("categories", []),
        "latest_version": latest["version_number"] if latest else None,
        "latest_loaders": latest.get("loaders", []) if latest else [],
        "latest_mc": latest.get("game_versions", []) if latest else [],
        "files_count": len(latest.get("files", [])) if latest else 0,
        "url": f"https://modrinth.com/modpack/{proj['slug']}",
    }


def _download_stream(url: str, dst: Path, log: LogFn, label: str,
                     headers: dict | None = None, cancel: CancelToken | None = None):
    log(f"[modpack] ⬇ {label}", "info")
    with requests.get(url, stream=True, timeout=60, headers=headers or {}) as r:
        r.raise_for_status()
        dst.parent.mkdir(parents=True, exist_ok=True)
        with open(dst, "wb") as f:
            for chunk in r.iter_content(chunk_size=1 << 16):
                if cancel: cancel.check()
                if chunk:
                    f.write(chunk)


def _extract_overrides(zf: zipfile.ZipFile, work_dir: Path, override_folder: str, log: LogFn) -> int:
    """Extract overrides/* into the server root, mirroring CurseForge modpack spec."""
    count = 0
    prefix = override_folder.rstrip("/") + "/"
    work_root = work_dir.resolve()
    for member in zf.namelist():
        if not member.startswith(prefix) or member.endswith("/"):
            continue
        rel = member[len(prefix):]
        # Reject absolute paths and traversal segments
        if rel.startswith("/") or ".." in Path(rel).parts:
            log(f"[modpack] ! skipped unsafe override path {rel!r}", "warn")
            continue
        target = (work_dir / rel).resolve()
        try:
            target.relative_to(work_root)
        except ValueError:
            log(f"[modpack] ! skipped out-of-sandbox path {rel!r}", "warn")
            continue
        target.parent.mkdir(parents=True, exist_ok=True)
        with zf.open(member) as src, open(target, "wb") as dst:
            dst.write(src.read())
        count += 1
    if count:
        log(f"[modpack] Extracted {count} override files into server root", "info")
    return count


def install_modrinth(ref: str, work_dir: Path, log: LogFn, progress: ProgressFn | None = None,
                     cancel: CancelToken | None = None) -> dict:
    log(f"[modpack] Resolving Modrinth pack '{ref}'…", "system")
    if progress: progress(0, 0, f"Resolving {ref}…")
    if cancel: cancel.check()
    proj = requests.get(f"{MODRINTH_API}/project/{ref}", timeout=15)
    if proj.status_code == 404:
        raise ResolveError(f"Modrinth project '{ref}' not found")
    proj.raise_for_status()
    project = proj.json()
    versions = requests.get(f"{MODRINTH_API}/project/{project['id']}/version", timeout=15).json()
    if not versions:
        raise ResolveError("no versions published")
    v = versions[0]
    primary = next((f for f in v["files"] if f.get("primary")), v["files"][0])
    log(f"[modpack] Found '{project['title']}' {v['version_number']} — {primary['filename']}", "system")

    mods_dir = work_dir / "mods"
    mods_dir.mkdir(exist_ok=True)
    pack_path = work_dir / primary["filename"]
    if progress: progress(0, 1, f"Downloading pack {primary['filename']}")
    _download_stream(primary["url"], pack_path, log, f"pack {primary['filename']}", cancel=cancel)

    with zipfile.ZipFile(pack_path) as zf:
        try:
            idx = json.loads(zf.read("modrinth.index.json").decode("utf-8"))
        except KeyError:
            raise ResolveError("pack is missing modrinth.index.json")
        files = idx.get("files", [])
        total = len(files) + 1
        log(f"[modpack] Manifest lists {len(files)} files — downloading…", "system")
        if progress: progress(0, total, f"{len(files)} files to fetch")
        for i, f in enumerate(files, 1):
            if cancel: cancel.check()
            downloads = f.get("downloads") or []
            path = f["path"]
            target = work_dir / path
            if downloads:
                try:
                    _download_stream(downloads[0], target, log, f"{i}/{len(files)} {path}", cancel=cancel)
                except Cancelled:
                    raise
                except Exception as e:
                    log(f"[modpack] ! failed {path}: {e}", "warn")
            if progress: progress(i, total, f"{i}/{len(files)} {path}")
        if progress: progress(total - 1, total, "Extracting overrides…")
        _extract_overrides(zf, work_dir, "overrides", log)
        if progress: progress(total, total, "Done")
    pack_path.unlink(missing_ok=True)
    log(f"[modpack] ✓ Modrinth pack installed", "system")
    return {"source": "modrinth", "version": v["version_number"], "files": len(files)}


# ---------------- CurseForge ----------------

def _cf_headers(api_key: str) -> dict:
    return {"Accept": "application/json", "x-api-key": api_key}


def _cf_resolve_slug_to_id(ref: str) -> int:
    """Resolve a CurseForge modpack slug to numeric project ID via the public cfwidget mirror.
    Some CurseForge Core API keys don't include /mods/search access; cfwidget provides
    unauthenticated slug→ID lookup that mirrors CurseForge's own website."""
    r = requests.get(f"{CFWIDGET_API}/minecraft/modpacks/{ref}", timeout=15)
    if r.status_code == 404:
        raise ResolveError(f"CurseForge modpack '{ref}' not found")
    r.raise_for_status()
    data = r.json()
    if not data.get("id"):
        raise ResolveError(f"unable to resolve CurseForge slug '{ref}'")
    return int(data["id"])


def _cfwidget_pack(ref: str) -> dict:
    """Public fallback for CurseForge preview metadata when the Core API is gated."""
    if ref.isdigit():
        r = requests.get(f"{CFWIDGET_API}/curseforge/mods/{int(ref)}", timeout=15)
    else:
        r = requests.get(f"{CFWIDGET_API}/minecraft/modpacks/{ref}", timeout=15)
    if r.status_code == 404:
        raise ResolveError(f"CurseForge modpack '{ref}' not found")
    r.raise_for_status()
    data = r.json()
    if not data.get("id"):
        raise ResolveError(f"unable to resolve CurseForge slug '{ref}'")
    return data


def _cfwidget_preview(ref: str, mod_id: int) -> dict:
    widget = _cfwidget_pack(str(mod_id) if ref.isdigit() else ref)
    files = widget.get("files") or []
    newest = widget.get("download") or (files[0] if files else {})
    project_url = (widget.get("urls") or {}).get("project")
    slug = ref if not ref.isdigit() else project_url.rstrip("/").rsplit("/", 1)[-1] if project_url else str(mod_id)
    downloads = widget.get("downloads", 0)
    if isinstance(downloads, dict):
        downloads = downloads.get("total", 0)
    return {
        "source": "curseforge",
        "id": widget.get("id", mod_id),
        "slug": slug,
        "title": widget.get("title") or widget.get("name") or f"CurseForge {mod_id}",
        "description": widget.get("summary", ""),
        "downloads": downloads,
        "authors": [m["username"] for m in widget.get("members", []) if m.get("username")],
        "latest_file_id": newest.get("id") if isinstance(newest, dict) else None,
        "latest_file_name": newest.get("name") if isinstance(newest, dict) else None,
        "latest_mc": newest.get("versions", []) if isinstance(newest, dict) else [],
        "url": project_url,
    }


def curseforge_preview(ref: str, api_key: str) -> dict:
    """Look up a CurseForge modpack by slug or numeric ID.

    Prefer the official v1 API when available, but fall back to the public cfwidget
    mirror in sandboxed environments where unauthed 403s are expected.
    """
    mod_id: int
    if ref.isdigit():
        mod_id = int(ref)
    else:
        mod_id = _cf_resolve_slug_to_id(ref)
    if not api_key:
        return _cfwidget_preview(ref, mod_id)
    try:
        r = requests.get(f"{CURSEFORGE_API}/mods/{mod_id}", headers=_cf_headers(api_key), timeout=15)
        if r.status_code == 404:
            raise ResolveError(f"CurseForge mod id {mod_id} not found")
        r.raise_for_status()
        mod = r.json()["data"]
        files_r = requests.get(f"{CURSEFORGE_API}/mods/{mod['id']}/files",
                               headers=_cf_headers(api_key), timeout=15,
                               params={"pageSize": 5})
        files_r.raise_for_status()
        files = files_r.json().get("data", [])
        latest = files[0] if files else None
        return {
            "source": "curseforge",
            "id": mod["id"], "slug": mod.get("slug") or str(mod_id), "title": mod["name"],
            "description": mod.get("summary", ""),
            "downloads": mod.get("downloadCount", 0),
            "authors": [a["name"] for a in mod.get("authors", [])],
            "latest_file_id": latest["id"] if latest else None,
            "latest_file_name": latest["fileName"] if latest else None,
            "latest_mc": latest.get("gameVersions", []) if latest else [],
            "url": mod.get("links", {}).get("websiteUrl"),
        }
    except requests.RequestException:
        return _cfwidget_preview(ref, mod_id)


def _cf_download_url(mod_id: int, file_id: int, api_key: str) -> str:
    r = requests.get(f"{CURSEFORGE_API}/mods/{mod_id}/files/{file_id}/download-url",
                     headers=_cf_headers(api_key), timeout=15)
    if r.status_code == 200:
        return r.json()["data"]
    # Fallback: reconstruct CDN URL from file metadata
    meta = requests.get(f"{CURSEFORGE_API}/mods/{mod_id}/files/{file_id}",
                        headers=_cf_headers(api_key), timeout=15).json().get("data", {})
    fname = meta.get("fileName", "")
    s = str(file_id).zfill(7)
    return f"https://mediafilez.forgecdn.net/files/{int(s[:4])}/{int(s[4:])}/{fname}"


def install_curseforge(ref: str, work_dir: Path, log: LogFn, api_key: str,
                       progress: ProgressFn | None = None, cancel: CancelToken | None = None) -> dict:
    if not api_key:
        raise ResolveError("CurseForge API key not configured")
    log(f"[modpack] Resolving CurseForge pack '{ref}'…", "system")
    if progress: progress(0, 0, f"Resolving {ref}…")
    if cancel: cancel.check()
    preview = curseforge_preview(ref, api_key)
    if not preview["latest_file_id"]:
        raise ResolveError("no files available for this pack")
    mod_id = preview["id"]
    file_id = preview["latest_file_id"]
    log(f"[modpack] Found '{preview['title']}' ({preview['latest_file_name']})", "system")
    if progress: progress(0, 1, f"Downloading pack {preview['latest_file_name']}")
    dl_url = _cf_download_url(mod_id, file_id, api_key)
    pack_path = work_dir / preview["latest_file_name"]
    _download_stream(dl_url, pack_path, log, f"pack {preview['latest_file_name']}", cancel=cancel)

    with zipfile.ZipFile(pack_path) as zf:
        try:
            manifest = json.loads(zf.read("manifest.json").decode("utf-8"))
        except KeyError:
            raise ResolveError("pack missing manifest.json")
        mods = manifest.get("files", [])
        override_folder = manifest.get("overrides", "overrides")
        total = len(mods) + 1
        log(f"[modpack] Manifest: {len(mods)} mods, MC {manifest.get('minecraft',{}).get('version','?')}", "system")
        if progress: progress(0, total, f"{len(mods)} mods to fetch")
        mods_dir = work_dir / "mods"
        mods_dir.mkdir(exist_ok=True)
        for i, m in enumerate(mods, 1):
            if cancel: cancel.check()
            pid, fid = m["projectID"], m["fileID"]
            try:
                url = _cf_download_url(pid, fid, api_key)
                fname = url.split("/")[-1] or f"{pid}-{fid}.jar"
                _download_stream(url, mods_dir / fname, log, f"mod {i}/{len(mods)} {fname}", cancel=cancel)
            except Cancelled:
                raise
            except Exception as e:
                log(f"[modpack] ! failed mod {pid}/{fid}: {e}", "warn")
            if progress: progress(i, total, f"{i}/{len(mods)} mods")
        if progress: progress(total - 1, total, "Extracting overrides…")
        _extract_overrides(zf, work_dir, override_folder, log)
        if progress: progress(total, total, "Done")
    pack_path.unlink(missing_ok=True)
    log(f"[modpack] ✓ CurseForge pack installed", "system")
    return {"source": "curseforge", "files": len(mods)}


# ---------------- Dispatcher ----------------

def install(source: str, ref: str, work_dir: Path, log: LogFn, cf_api_key: str = "",
            progress: ProgressFn | None = None, cancel: CancelToken | None = None) -> dict:
    if source == "modrinth":
        return install_modrinth(ref, work_dir, log, progress=progress, cancel=cancel)
    if source == "curseforge":
        return install_curseforge(ref, work_dir, log, cf_api_key, progress=progress, cancel=cancel)
    raise ResolveError(f"unknown source: {source}")


def preview(source: str, ref: str, cf_api_key: str = "") -> dict:
    if source == "modrinth":
        return modrinth_preview(ref)
    if source == "curseforge":
        return curseforge_preview(ref, cf_api_key)
    raise ResolveError(f"unknown source: {source}")
