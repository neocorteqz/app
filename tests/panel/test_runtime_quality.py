"""Offline regression tests for process startup, custom paths and bot authorization."""

import asyncio
import importlib.util
import os
import shlex
import sys
import tempfile
import unittest
from pathlib import Path
from unittest.mock import AsyncMock, MagicMock, patch

DAEMON_DIR = Path(__file__).resolve().parents[2] / "panel/daemon"
sys.path.insert(0, str(DAEMON_DIR))


def load_module(name, path):
    spec = importlib.util.spec_from_file_location(name, path)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


class RuntimeQuality(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.state = Path(self.temp.name)
        with patch.dict(os.environ, {"APEX_STATE": str(self.state)}):
            self.daemon = load_module("quality_daemon", DAEMON_DIR / "daemon.py")
        self.runtime = load_module("quality_runtime", DAEMON_DIR / "runtime.py")
        self.server = {
            "id": 1,
            "name": "Test",
            "port": 25565,
            "players_max": 20,
            "work_dir": str(self.state / "servers/1"),
            "ram_mb": 2048,
            "game": "minecraft-java",
            "egg_id": None,
            "loader_id": None,
        }

    def test_simulator_uses_installed_file(self):
        command = self.runtime.resolve(dict(self.server, game="rust"), None, lambda *_: None)
        self.assertEqual(Path(shlex.split(command)[2]), DAEMON_DIR / "fake_game.py")
        self.assertTrue(Path(shlex.split(command)[2]).is_file())

    def test_unsupported_loader_does_not_silently_launch_paper(self):
        for loader in ("forge", "fabric", "neoforge", "quilt", "modrinth", "curseforge", "ftb"):
            with self.subTest(loader=loader), patch.object(self.runtime, "_ensure_paper") as paper:
                with self.assertRaises(RuntimeError):
                    self.runtime.resolve(self.server, {"slug": loader}, lambda *_: None)
                paper.assert_not_called()

    def test_egg_file_paths_and_placeholders(self):
        egg = {
            "default_files": '{"server.properties":"server-port={port}\\nmax-players={players}"}'
        }
        with (
            patch.object(self.daemon, "get_egg", return_value=egg),
            patch.object(self.daemon, "_job_exec"),
        ):
            directory = self.daemon.ensure_workdir(self.server)
        self.assertEqual(
            (directory / "server.properties").read_text(), "server-port=25565\nmax-players=20"
        )

    def test_egg_traversal_is_rejected(self):
        egg = {"default_files": '{"../../outside":"unsafe"}'}
        with (
            patch.object(self.daemon, "get_egg", return_value=egg),
            patch.object(self.daemon, "_job_exec"),
        ):
            with self.assertRaises(self.daemon.pack_resolver.ResolveError):
                self.daemon.ensure_workdir(self.server)
        self.assertFalse((self.state / "outside").exists())

    def test_symlinked_server_directory_is_rejected(self):
        outside = self.state / "outside"
        outside.mkdir()
        (self.state / "servers").mkdir(exist_ok=True)
        (self.state / "servers/1").symlink_to(outside, target_is_directory=True)
        with self.assertRaises(ValueError):
            self.daemon.ensure_workdir(self.server)

    def test_failed_start_is_not_marked_online(self):
        process = MagicMock()
        process.poll.return_value = 1
        process.pid = 100

        async def ignore_output(*_):
            return None

        with (
            patch.object(self.daemon, "get_server", return_value=self.server),
            patch.object(self.daemon, "ensure_workdir", return_value=self.state),
            patch.object(self.daemon, "install_modpack_if_needed"),
            patch.object(self.daemon, "get_egg", return_value=None),
            patch.object(self.daemon, "build_start_cmd", return_value="test-command"),
            patch.object(self.daemon, "log_line"),
            patch.object(self.daemon, "set_status") as status,
            patch.object(self.daemon, "stream_output", side_effect=ignore_output),
            patch.object(self.daemon.subprocess, "Popen", return_value=process) as spawn,
        ):
            with self.assertRaises(self.daemon.HTTPException):
                asyncio.run(self.daemon.start(1))
            self.assertNotIn("online", [call.args[1] for call in status.call_args_list])
            self.assertTrue(spawn.call_args.kwargs["start_new_session"])
            self.assertNotIn("preexec_fn", spawn.call_args.kwargs)

    def test_bot_denies_unconfigured_guild(self):
        bot = load_module("quality_bot", DAEMON_DIR.parent / "discord-bot/bot.py")
        interaction = MagicMock()
        interaction.guild_id = 42
        interaction.response.send_message = AsyncMock()
        with patch.object(bot, "GUILD", ""), patch.object(bot, "dispatch_control") as dispatch:
            asyncio.run(bot.lifecycle(interaction, "Test", "start"))
            dispatch.assert_not_called()
            interaction.response.send_message.assert_awaited_once()

    def test_bot_dispatches_real_control_for_authorized_guild(self):
        bot = load_module("quality_bot", DAEMON_DIR.parent / "discord-bot/bot.py")
        interaction = MagicMock()
        interaction.guild_id = 42
        interaction.user.guild_permissions.manage_guild = True
        interaction.response.defer = AsyncMock()
        interaction.followup.send = AsyncMock()
        with (
            patch.object(bot, "GUILD", "42"),
            patch.object(bot, "find_server", return_value={"id": 1, "name": "Test"}),
            patch.object(bot, "dispatch_control", return_value={"status": "started"}) as dispatch,
        ):
            asyncio.run(bot.lifecycle(interaction, "Test", "start"))
            dispatch.assert_called_once_with(1, "start")
            interaction.followup.send.assert_awaited_once()


if __name__ == "__main__":
    unittest.main()
