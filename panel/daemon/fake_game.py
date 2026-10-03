#!/usr/bin/env python3
"""
Fake game process — simulates a game server for local demos.
Emits realistic-looking logs, accepts stdin commands, sleeps otherwise.
"""

import random
import sys
import threading
import time

game = sys.argv[1] if len(sys.argv) > 1 else "minecraft-java"

BANNERS = {
    "minecraft-java": "[Server] Starting minecraft server version 1.20.4",
    "minecraft-bedrock": "[INFO] Starting Server (bedrock 1.20)",
    "cs2": "L 00:00:00: -------- Mapchange to de_dust2 --------",
    "rust": "[Rust] Server initialized (procedural map, seed 20260921)",
}
TICKS = {
    "minecraft-java": [
        "[Server] Saved the game",
        "[Server] Chunk generated",
        "[Server] {p} players online",
    ],
    "minecraft-bedrock": ["[INFO] Level saved", "[INFO] Autosave OK", "[INFO] Players: {p}"],
    "cs2": [
        'L 00:00:00: World triggered "Round_Start"',
        "L 00:00:00: score {r}:{b}",
        "L 00:00:00: Player connected",
    ],
    "rust": ["[Rust] Net traffic: {n}KB/s", "[Rust] Save complete in {s}s", "[Rust] Player joined"],
}


def emit(line):
    print(line, flush=True)


def reader():
    while True:
        line = sys.stdin.readline()
        if not line:
            return
        c = line.strip()
        if not c:
            continue
        if c == "stop":
            emit("[Server] Stopping server on request…")
            time.sleep(0.4)
            sys.exit(0)
        elif c == "list":
            emit(f"[Server] There are {random.randint(0, 20)}/20 players online.")
        elif c == "save" or c.startswith("save-all"):
            emit("[Server] Saving game.")
        elif c.startswith("say "):
            emit(f"[Server] <SERVER> {c[4:]}")
        elif c == "help":
            emit("[Server] Commands: say <msg>, list, save, stop, help")
        else:
            emit(f"[Server] Unknown command: {c}")


emit(BANNERS.get(game, f"[Server] Starting {game}"))
emit("[Server] Ready. Listening on port :25565")
threading.Thread(target=reader, daemon=True).start()

while True:
    tpl = random.choice(TICKS.get(game, ["[Server] tick"]))
    emit(
        tpl.format(
            p=random.randint(0, 20),
            r=random.randint(0, 16),
            b=random.randint(0, 16),
            n=random.randint(10, 300),
            s=random.randint(1, 3),
        )
    )
    time.sleep(random.uniform(2.0, 4.0))
