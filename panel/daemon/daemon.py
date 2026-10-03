"""
ApexNode Node Daemon — real process manager
Runs game server subprocesses, streams stdout/stderr to server_logs,
persists PIDs, exposes HTTP control API on 127.0.0.1:8001.

Endpoints (all via /api prefix - matches K8s ingress; but also exposed locally):
  POST /daemon/start/{id}
  POST /daemon/stop/{id}
  POST /daemon/restart/{id}
  GET  /daemon/status/{id}
  POST /daemon/console/{id}  {"cmd": "..."}
"""

import asyncio
import json
import os
import shlex
import signal
import subprocess
import sys
from pathlib import Path

import pack_resolver
import pymysql
import runtime as loader_runtime
from fastapi import FastAPI, HTTPException
from pydantic import BaseModel

# ---- job cancellation registry ----
_cancel_tokens: dict[int, pack_resolver.CancelToken] = {}


def _job_exec(sql: str, args: tuple = (), *, lastrowid: bool = False):
    conn = db()
    try:
        with conn.cursor() as c:
            c.execute(sql, args)
            return c.lastrowid if lastrowid else None
    finally:
        conn.close()


def create_job(kind: str, target_kind: str, target_id: int, message: str = "") -> int:
    return _job_exec(
        "INSERT INTO jobs (kind, target_kind, target_id, status, message) VALUES (%s,%s,%s,'queued',%s)",
        (kind, target_kind, target_id, message[:255]),
        lastrowid=True,
    )


def job_running(job_id: int):
    _job_exec("UPDATE jobs SET status='running', started_at=NOW() WHERE id=%s", (job_id,))


def job_progress(job_id: int, done: int, total: int, message: str = ""):
    _job_exec(
        "UPDATE jobs SET progress=%s, total=%s, message=%s WHERE id=%s",
        (done, total, message[:255], job_id),
    )


def job_complete(job_id: int):
    _job_exec(
        "UPDATE jobs SET status='completed', progress=total, completed_at=NOW() WHERE id=%s",
        (job_id,),
    )


def job_fail(job_id: int, error: str):
    _job_exec(
        "UPDATE jobs SET status='failed', error=%s, completed_at=NOW() WHERE id=%s",
        (error[:4000], job_id),
    )


DB_PORT = int(os.environ.get("DB_PORT", "3306"))
DB_HOST = os.environ.get("DB_HOST", "127.0.0.1")
DB_USER = os.environ.get("DB_USER", "apexnode")
DB_PASS = os.environ.get("DB_PASS", "apex_local_dev")
DB_NAME = os.environ.get("DB_NAME", "apexnode")

STATE_ROOT = Path(os.environ.get("APEX_STATE", "/var/lib/apexnode"))
STATE_ROOT.mkdir(parents=True, exist_ok=True)
(STATE_ROOT / "servers").mkdir(exist_ok=True)
(STATE_ROOT / "backups").mkdir(exist_ok=True)

app = FastAPI(title="ApexNode Daemon", docs_url=None, redoc_url=None)

# In-memory process registry
processes: dict[int, subprocess.Popen] = {}
stdin_streams: dict[int, any] = {}


def db():
    return pymysql.connect(
        host=DB_HOST,
        port=DB_PORT,
        user=DB_USER,
        password=DB_PASS,
        database=DB_NAME,
        cursorclass=pymysql.cursors.DictCursor,
        autocommit=True,
    )


def log_line(server_id: int, line: str, level: str = "info"):
    try:
        conn = db()
        try:
            with conn.cursor() as c:
                c.execute(
                    "INSERT INTO server_logs (server_id, line, level) VALUES (%s, %s, %s)",
                    (server_id, line[:2000], level),
                )
        finally:
            conn.close()
    except Exception as e:
        print(f"log_line err {e}", file=sys.stderr)


def get_server(sid: int):
    conn = db()
    try:
        with conn.cursor() as c:
            c.execute("SELECT * FROM servers WHERE id=%s", (sid,))
            return c.fetchone()
    finally:
        conn.close()


def get_egg(egg_id):
    if not egg_id:
        return None
    conn = db()
    try:
        with conn.cursor() as c:
            c.execute("SELECT * FROM eggs WHERE id=%s", (egg_id,))
            return c.fetchone()
    finally:
        conn.close()


def get_loader(loader_id):
    if not loader_id:
        return None
    conn = db()
    try:
        with conn.cursor() as c:
            c.execute("SELECT * FROM mod_loaders WHERE id=%s", (loader_id,))
            return c.fetchone()
    finally:
        conn.close()


def get_setting(key: str) -> str:
    conn = db()
    try:
        with conn.cursor() as c:
            c.execute("SELECT v FROM settings WHERE k=%s", (key,))
            row = c.fetchone()
            return row["v"] if row else ""
    finally:
        conn.close()


def set_status(sid: int, status: str, **fields):
    parts = ["status=%s"]
    args = [status]
    for k, v in fields.items():
        parts.append(f"{k}=%s")
        args.append(v)
    args.append(sid)
    conn = db()
    try:
        with conn.cursor() as c:
            c.execute(f"UPDATE servers SET {', '.join(parts)} WHERE id=%s", args)
    finally:
        conn.close()


MODPACK_SOURCES = {
    "curseforge": "curseforge",
    "modrinth": "modrinth",
    "ftb": "curseforge",  # FTB is delivered as a CurseForge pack
    "workshop": None,  # CS2 workshop — handled elsewhere
}


def install_modpack_if_needed(server) -> None:
    """If server has a modpack-source loader + a ref and no install yet, run resolver."""
    if not server.get("loader_id") or not server.get("modpack_ref"):
        return
    if server.get("modpack_status") == "installed":
        return
    loader = get_loader(server["loader_id"])
    if not loader or loader["category"] != "modpack_source":
        return
    source = MODPACK_SOURCES.get(loader["slug"])
    if not source:
        return
    sid = server["id"]
    ref = server["modpack_ref"]
    prior_status = server.get("status", "offline")
    log_line(sid, f"[modpack] Installing '{ref}' from {source}…", "system")
    set_status(sid, "installing")
    _job_exec("UPDATE servers SET modpack_status='installing' WHERE id=%s", (sid,))
    wd = STATE_ROOT / "servers" / str(sid)
    wd.mkdir(parents=True, exist_ok=True)
    cf_key = get_setting("curseforge_api_key")
    try:
        pack_resolver.install(
            source, ref, wd, lambda line, lv: log_line(sid, line, lv), cf_api_key=cf_key
        )
        _job_exec("UPDATE servers SET modpack_status='installed' WHERE id=%s", (sid,))
        set_status(
            sid, prior_status if prior_status not in ("installing", "starting") else "offline"
        )
        log_line(sid, "[modpack] ✓ Ready to boot", "system")
    except Exception as e:
        _job_exec("UPDATE servers SET modpack_status='failed' WHERE id=%s", (sid,))
        set_status(sid, "crashed")
        log_line(sid, f"[modpack] ✗ FAILED: {e}", "error")
        raise


def ensure_workdir(server) -> Path:
    managed_root = (STATE_ROOT / "servers").resolve()
    wd = (managed_root / str(server["id"])).resolve()
    if wd == managed_root or not wd.is_relative_to(managed_root):
        raise ValueError("server directory is outside the managed root")
    wd.mkdir(parents=True, exist_ok=True)
    egg = get_egg(server.get("egg_id"))
    # Seed default files from egg if empty
    if not any(wd.iterdir()):
        default_files = {}
        if egg and egg.get("default_files"):
            try:
                default_files = json.loads(egg["default_files"])
            except Exception:
                default_files = {}
        # Add a generic fallback file
        if not default_files:
            default_files = {
                "server.properties": f"# {server['name']}\nserver-port={server['port']}\nmax-players={server['players_max']}\nmotd=Powered by ApexNode\n",
                "README.md": f"# {server['name']}\n\nProvisioned by ApexNode. Edit files in this folder, then Restart the server.\n",
            }
        for fname, content in default_files.items():
            target = pack_resolver._safe_work_path(wd, fname)
            target.parent.mkdir(parents=True, exist_ok=True)
            rendered = str(content)
            for key, value in {
                "port": server["port"],
                "players": server["players_max"],
                "name": server["name"],
            }.items():
                rendered = rendered.replace("{" + key + "}", str(value))
            target.write_text(rendered, encoding="utf-8")
    # Persist work_dir
    _job_exec("UPDATE servers SET work_dir=%s WHERE id=%s", (str(wd), server["id"]))
    return wd


async def stream_output(sid: int, stream, level: str = "info"):
    """Read stdout/stderr line by line, persist to DB."""
    loop = asyncio.get_event_loop()
    while True:
        line = await loop.run_in_executor(None, stream.readline)
        if not line:
            break
        try:
            text = line.decode("utf-8", errors="replace").rstrip()
        except Exception:
            text = str(line)
        if text:
            log_line(sid, text, level)
    # process ended
    log_line(sid, "[daemon] stream closed", "system")


def build_start_cmd(server, egg) -> str:
    """Delegate to runtime.py which knows how to spin up Paper/Purpur/Fabric etc.
    For non-Java games or unknown combos, runtime.py returns the fake_game.py fallback."""
    loader = get_loader(server.get("loader_id"))
    return loader_runtime.resolve(server, loader, lambda line, lv: log_line(server["id"], line, lv))


class ConsoleIn(BaseModel):
    cmd: str


@app.post("/api/daemon/start/{sid}")
async def start(sid: int):
    s = get_server(sid)
    if not s:
        raise HTTPException(404, "server not found")
    if sid in processes and processes[sid].poll() is None:
        return {"status": "already_running", "pid": processes[sid].pid}

    wd = ensure_workdir(s)
    # Real modpack install step (Modrinth / CurseForge / FTB) — blocking, runs in threadpool
    try:
        await asyncio.get_event_loop().run_in_executor(None, install_modpack_if_needed, s)
    except Exception as e:
        raise HTTPException(500, f"modpack install failed: {e}")
    # Re-fetch to pick up any updated fields
    s = get_server(sid)
    egg = get_egg(s.get("egg_id"))
    try:
        cmd = await asyncio.to_thread(build_start_cmd, s, egg)
    except Exception as e:
        set_status(sid, "crashed")
        log_line(sid, f"[runtime] loader bootstrap failed: {e}", "error")
        raise HTTPException(500, f"loader bootstrap failed: {e}")
    log_line(sid, f"[daemon] boot: {cmd}", "system")
    set_status(sid, "starting")

    try:
        proc = subprocess.Popen(
            shlex.split(cmd),
            cwd=str(wd),
            stdin=subprocess.PIPE,
            stdout=subprocess.PIPE,
            stderr=subprocess.STDOUT,
            start_new_session=True,
        )
    except Exception as e:
        log_line(sid, f"[daemon] failed to spawn: {e}", "error")
        set_status(sid, "crashed")
        raise HTTPException(500, str(e))

    processes[sid] = proc
    stdin_streams[sid] = proc.stdin
    asyncio.create_task(stream_output(sid, proc.stdout))

    async def watchdog():
        loop = asyncio.get_event_loop()
        await loop.run_in_executor(None, proc.wait)
        # Only mark offline if process exited on its own
        if sid in processes and processes[sid].pid == proc.pid:
            set_status(sid, "offline", cpu_usage=0, ram_usage_mb=0, players_online=0)
            log_line(sid, f"[daemon] process exited (code={proc.returncode})", "system")
            processes.pop(sid, None)
            stdin_streams.pop(sid, None)

    asyncio.create_task(watchdog())
    # Small grace to transition from starting → online
    await asyncio.sleep(0.6)
    if proc.poll() is not None:
        set_status(sid, "crashed", cpu_usage=0, ram_usage_mb=0, players_online=0)
        raise HTTPException(500, "server process exited during startup; inspect server logs")
    set_status(sid, "online", cpu_usage=0, ram_usage_mb=0)
    return {"status": "started", "pid": proc.pid}


@app.post("/api/daemon/stop/{sid}")
async def stop(sid: int):
    s = get_server(sid)
    if not s:
        raise HTTPException(404, "server not found")
    proc = processes.get(sid)
    if not proc or proc.poll() is not None:
        set_status(sid, "offline", cpu_usage=0, ram_usage_mb=0, players_online=0)
        return {"status": "not_running"}
    set_status(sid, "stopping")
    log_line(sid, "[daemon] stopping…", "system")
    try:
        # Send graceful signal
        os.killpg(os.getpgid(proc.pid), signal.SIGTERM)
        for _ in range(20):
            if proc.poll() is not None:
                break
            await asyncio.sleep(0.25)
        if proc.poll() is None:
            os.killpg(os.getpgid(proc.pid), signal.SIGKILL)
    except ProcessLookupError:
        pass
    processes.pop(sid, None)
    stdin_streams.pop(sid, None)
    set_status(sid, "offline", cpu_usage=0, ram_usage_mb=0, players_online=0)
    log_line(sid, "[daemon] stopped.", "system")
    return {"status": "stopped"}


@app.post("/api/daemon/restart/{sid}")
async def restart(sid: int):
    await stop(sid)
    return await start(sid)


@app.get("/api/daemon/status/{sid}")
async def status(sid: int):
    proc = processes.get(sid)
    running = bool(proc and proc.poll() is None)
    return {"id": sid, "running": running, "pid": proc.pid if running else None}


@app.post("/api/daemon/console/{sid}")
async def console(sid: int, payload: ConsoleIn):
    stream = stdin_streams.get(sid)
    if not stream:
        log_line(sid, f"> {payload.cmd}", "system")
        log_line(sid, "[daemon] server not running — command not delivered", "warn")
        return {"ok": False, "reason": "not_running"}
    try:
        stream.write((payload.cmd + "\n").encode())
        stream.flush()
        log_line(sid, f"> {payload.cmd}", "system")
        return {"ok": True}
    except Exception as e:
        return {"ok": False, "reason": str(e)}


@app.get("/api/daemon/health")
async def health():
    return {
        "status": "ok",
        "running_servers": len([p for p in processes.values() if p.poll() is None]),
    }


@app.get("/api/daemon/modpack/preview")
async def modpack_preview(source: str, ref: str):
    """Preview a Modrinth or CurseForge modpack by slug/ID."""
    cf_key = get_setting("curseforge_api_key")
    loop = asyncio.get_event_loop()
    try:
        data = await loop.run_in_executor(None, pack_resolver.preview, source, ref, cf_key)
        return {"ok": True, "data": data}
    except pack_resolver.ResolveError as e:
        return {"ok": False, "error": str(e)}
    except Exception as e:
        return {"ok": False, "error": f"{type(e).__name__}: {e}"}


@app.post("/api/daemon/modpack/install/{sid}")
async def modpack_install(sid: int):
    """Synchronous install (kept for tests / small packs)."""
    s = get_server(sid)
    if not s:
        raise HTTPException(404, "server not found")
    if s.get("modpack_status") == "installed":
        return {"ok": True, "already_installed": True}
    loop = asyncio.get_event_loop()
    try:
        await loop.run_in_executor(None, install_modpack_if_needed, s)
        return {"ok": True}
    except Exception as e:
        return {"ok": False, "error": str(e)}


@app.post("/api/daemon/modpack/install-async/{sid}")
async def modpack_install_async(sid: int):
    """Queue a modpack install job and return immediately with a job_id.
    Concurrency guard: if an install is already queued or running for this server,
    return the existing job_id with `already_running=true` instead of enqueuing again."""
    s = get_server(sid)
    if not s:
        raise HTTPException(404, "server not found")
    if not s.get("loader_id") or not s.get("modpack_ref"):
        raise HTTPException(400, "server has no modpack ref configured")
    if s.get("modpack_status") == "installed":
        return {"ok": True, "already_installed": True, "job_id": None}

    # Concurrency guard — one modpack install per server at a time
    conn = db()
    try:
        with conn.cursor() as c:
            c.execute(
                "SELECT id, status FROM jobs WHERE kind='modpack_install' AND target_kind='server' "
                "AND target_id=%s AND status IN ('queued','running') ORDER BY id DESC LIMIT 1",
                (sid,),
            )
            existing = c.fetchone()
    finally:
        conn.close()
    if existing:
        return {
            "ok": True,
            "already_running": True,
            "job_id": existing["id"],
            "status": existing["status"],
        }

    job_id = create_job("modpack_install", "server", sid, f"Install '{s['modpack_ref']}'")
    asyncio.create_task(_run_modpack_job(job_id, sid))
    return {"ok": True, "job_id": job_id}


@app.post("/api/daemon/jobs/{job_id}/cancel")
async def cancel_job(job_id: int):
    """Cooperative cancellation — flips the DB flag and signals the in-flight resolver."""
    conn = db()
    try:
        with conn.cursor() as c:
            c.execute("SELECT status, cancel_requested FROM jobs WHERE id=%s", (job_id,))
            row = c.fetchone()
            if not row:
                raise HTTPException(404, "job not found")
            if row["status"] in ("completed", "failed", "cancelled"):
                return {"ok": True, "already_finished": True, "status": row["status"]}
            c.execute(
                "UPDATE jobs SET cancel_requested=1, message=CONCAT('cancel-requested · ', COALESCE(message,'')) WHERE id=%s",
                (job_id,),
            )
    finally:
        conn.close()
    token = _cancel_tokens.get(job_id)
    if token:
        token.set()
    return {"ok": True, "cancelled": True}


async def _run_modpack_job(job_id: int, sid: int) -> None:
    """Background task — runs the resolver and updates job + server state.
    Registers a CancelToken so `POST /jobs/{id}/cancel` can signal the running resolver."""
    loop = asyncio.get_event_loop()
    token = pack_resolver.CancelToken()
    _cancel_tokens[job_id] = token

    def blocking():
        server = get_server(sid)
        if not server or not server.get("loader_id") or not server.get("modpack_ref"):
            raise pack_resolver.ResolveError("server misconfigured")
        loader = get_loader(server["loader_id"])
        if not loader or loader["category"] != "modpack_source":
            raise pack_resolver.ResolveError("loader is not a modpack source")
        source = MODPACK_SOURCES.get(loader["slug"])
        if not source:
            raise pack_resolver.ResolveError(f"unsupported source: {loader['slug']}")
        prior = server.get("status", "offline")
        set_status(sid, "installing")
        _job_exec("UPDATE servers SET modpack_status='installing' WHERE id=%s", (sid,))
        wd = STATE_ROOT / "servers" / str(sid)
        wd.mkdir(parents=True, exist_ok=True)
        cf_key = get_setting("curseforge_api_key")
        pack_resolver.install(
            source,
            server["modpack_ref"],
            wd,
            log=lambda line, lv: log_line(sid, line, lv),
            cf_api_key=cf_key,
            progress=lambda done, total, msg: job_progress(job_id, done, total, msg),
            cancel=token,
        )
        _job_exec("UPDATE servers SET modpack_status='installed' WHERE id=%s", (sid,))
        set_status(sid, prior if prior not in ("installing", "starting") else "offline")
        log_line(sid, "[modpack] ✓ Ready to boot", "system")

    job_running(job_id)
    try:
        await loop.run_in_executor(None, blocking)
        job_complete(job_id)
    except pack_resolver.Cancelled as e:
        _job_exec("UPDATE servers SET modpack_status='none' WHERE id=%s", (sid,))
        set_status(sid, "offline")
        log_line(sid, "[modpack] ✗ cancelled by operator", "warn")
        _job_exec(
            "UPDATE jobs SET status='cancelled', completed_at=NOW(), message=%s WHERE id=%s",
            (f"cancelled: {e}", job_id),
        )
    except Exception as e:
        _job_exec("UPDATE servers SET modpack_status='failed' WHERE id=%s", (sid,))
        set_status(sid, "crashed")
        log_line(sid, f"[modpack] ✗ FAILED: {e}", "error")
        job_fail(job_id, str(e))
    finally:
        _cancel_tokens.pop(job_id, None)


@app.get("/api/daemon/jobs/{job_id}")
async def get_job(job_id: int):
    conn = db()
    try:
        with conn.cursor() as c:
            c.execute("SELECT * FROM jobs WHERE id=%s", (job_id,))
            row = c.fetchone()
    finally:
        conn.close()
    if not row:
        raise HTTPException(404, "job not found")
    return row


if __name__ == "__main__":
    import uvicorn

    uvicorn.run(app, host="127.0.0.1", port=int(os.environ.get("PORT", "8001")))
