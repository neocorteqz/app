"""
ApexNode loader runtimes — real bootstraps for Minecraft Java.

Given a server row (from DB) it:
  - ensures the correct server JAR is downloaded to the work_dir
  - accepts EULA (writes eula.txt)
  - returns the exact shell command to launch the process

Paper and Purpur launch Java processes. The Vanilla option currently reuses Paper.
Modded runtime bootstraps are unsupported and fail explicitly. Bedrock, CS2 and
Rust use the bundled simulator; editing egg start_command does not install games.
"""

from __future__ import annotations

import hashlib
import os
import shlex
import shutil
import sys
import tempfile
import urllib.request
from collections.abc import Callable
from pathlib import Path

LogFn = Callable[[str, str], None]

# ---- Java Minecraft versions (kept current-ish) ----
PAPER_VERSION = "1.20.4"
PAPER_BUILDS_API = "https://fill.papermc.io/v3/projects/paper/versions/{ver}/builds"
PURPUR_JAR_URL = "https://api.purpurmc.org/v2/purpur/{ver}/latest/download"


def _download(url: str, dst: Path, log: LogFn, label: str) -> None:
    log(f"[runtime] ⬇ {label}", "info")
    dst.parent.mkdir(parents=True, exist_ok=True)
    req = urllib.request.Request(
        url, headers={"User-Agent": "ApexNode/1.0 (+https://apexnode.dev)"}
    )
    with urllib.request.urlopen(req, timeout=120) as r, open(dst, "wb") as f:
        shutil.copyfileobj(r, f)


def _sha256(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as jar_file:
        for chunk in iter(lambda: jar_file.read(1 << 20), b""):
            digest.update(chunk)
    return digest.hexdigest()


def _ensure_paper(work_dir: Path, log: LogFn, version: str = PAPER_VERSION) -> Path:
    """Download the newest Paper build for `version` that still resolves. The very
    latest build sometimes 404s on the CDN, so walk the list until one succeeds."""
    jar = work_dir / "server.jar"
    import json as _json

    req = urllib.request.Request(
        PAPER_BUILDS_API.format(ver=version),
        headers={"User-Agent": "ApexNode/1.0 (+https://apexnode.dev)"},
    )
    with urllib.request.urlopen(req, timeout=30) as r:
        builds = _json.loads(r.read().decode("utf-8"))
    # v3 returns newest first, but we still try a few in case the CDN 404s
    last_err = None
    for build in builds[:5]:
        try:
            dl = build["downloads"].get("server:default") or build["downloads"].get("server:mojang")
            if not dl:
                continue
            expected = (dl.get("checksums") or {}).get("sha256")
            if not expected or len(expected) != 64:
                raise RuntimeError(
                    f"Paper {version} build {build['id']} has no valid SHA256 checksum"
                )
            expected = expected.lower()
            if jar.is_file() and _sha256(jar) == expected:
                return jar

            fd, temp_name = tempfile.mkstemp(prefix=".paper-", suffix=".jar", dir=work_dir)
            os.close(fd)
            temp_jar = Path(temp_name)
            try:
                _download(
                    dl["url"], temp_jar, log, f"Paper {version} build {build['id']} ({dl['name']})"
                )
                actual = _sha256(temp_jar)
                if actual != expected:
                    raise RuntimeError(
                        f"Paper {version} build {build['id']} SHA256 mismatch "
                        f"(expected {expected}, received {actual})"
                    )
                os.replace(temp_jar, jar)
            finally:
                temp_jar.unlink(missing_ok=True)
            return jar
        except Exception as e:
            last_err = e
            if isinstance(e, RuntimeError) and "SHA256 mismatch" in str(e):
                log(f"[runtime] ! {e}", "error")
                raise
            continue
    raise RuntimeError(f"could not download any Paper build: {last_err}")


def _ensure_purpur(work_dir: Path, log: LogFn, version: str = PAPER_VERSION) -> Path:
    jar = work_dir / "server.jar"
    if jar.exists():
        return jar
    _download(PURPUR_JAR_URL.format(ver=version), jar, log, f"Purpur {version}")
    return jar


def _ensure_vanilla(work_dir: Path, log: LogFn, version: str = PAPER_VERSION) -> Path:
    # Mojang requires a manifest hop; for the demo we reuse Paper's vanilla-compatible JAR
    return _ensure_paper(work_dir, log, version)


def _write_eula(work_dir: Path) -> None:
    (work_dir / "eula.txt").write_text("# Accepted via ApexNode panel on first boot\neula=true\n")


def _java_cmd(work_dir: Path, ram_mb: int) -> str:
    heap = max(512, int(ram_mb) - 256)
    return f"java -Xms256M -Xmx{heap}M -jar server.jar nogui"


def resolve(server: dict, loader: dict | None, log: LogFn) -> str:
    """Return the shell command to launch this server. Downloads the runtime JAR if needed.

    Fallback: if the game / loader combination isn't wired to a real runtime,
    return the fake_game.py command so the panel remains functional.
    """
    game = server["game"]
    state_root = Path(os.environ.get("APEX_STATE", "/var/lib/apexnode"))
    work_dir = Path(server["work_dir"] or state_root / "servers" / str(server["id"]))
    work_dir.mkdir(parents=True, exist_ok=True)
    ram = int(server.get("ram_mb") or 2048)
    version = str(server.get("minecraft_version") or PAPER_VERSION)

    if game == "minecraft-java":
        loader_slug = (loader or {}).get("slug", "vanilla")
        if loader_slug in ("paper", "modrinth", "curseforge", "ftb", "matchzy"):
            if loader_slug in ("modrinth", "curseforge", "ftb"):
                raise RuntimeError(
                    "Modpack runtime bootstrap is not implemented; choose a supported game runtime"
                )
            _ensure_paper(work_dir, log, version)
        elif loader_slug == "purpur":
            _ensure_purpur(work_dir, log, version)
        elif loader_slug in ("forge", "neoforge", "fabric", "quilt"):
            raise RuntimeError(
                f"{loader_slug} runtime bootstrap is not implemented; Paper cannot run these mods"
            )
        else:  # vanilla or unknown
            _ensure_vanilla(work_dir, log, version)
        _write_eula(work_dir)
        return _java_cmd(work_dir, ram)

    # Non-Java games — keep the simulated runtime (real installs require SteamCMD)
    script = Path(__file__).with_name("fake_game.py")
    log(f"[runtime] {game} uses the bundled simulator; no real game binary is installed", "warn")
    return f"{shlex.quote(sys.executable)} -u {shlex.quote(str(script))} {shlex.quote(game)}"
