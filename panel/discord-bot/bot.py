"""
ApexNode Discord Bot
Slash commands: /status /start /stop /restart
Reads token + panel API endpoint from environment or panel settings via DB.
"""

import asyncio
import os
import sys

try:
    import discord
    from discord import app_commands
except ImportError:
    print("Install: pip install -r requirements.txt", file=sys.stderr)
    sys.exit(1)

import pymysql
import requests

DB_PORT = int(os.environ.get("DB_PORT", "3306"))
DB_HOST = os.environ.get("DB_HOST", "127.0.0.1")
DB_USER = os.environ.get("DB_USER", "apexnode")
DB_PASS = os.environ.get("DB_PASS", "")
DB_NAME = os.environ.get("DB_NAME", "apexnode")
TOKEN = os.environ.get("DISCORD_BOT_TOKEN")
GUILD = os.environ.get("DISCORD_GUILD_ID", "")


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


def load_settings():
    global TOKEN, GUILD
    try:
        with db() as conn, conn.cursor() as c:
            c.execute("SELECT k, v FROM settings WHERE k IN ('discord_token','discord_guild')")
            for row in c.fetchall():
                if row["k"] == "discord_token" and not TOKEN:
                    TOKEN = row["v"]
                if row["k"] == "discord_guild" and not GUILD:
                    GUILD = row["v"]
    except Exception as e:
        print("settings load error:", e)


def find_server(name: str):
    with db() as conn, conn.cursor() as c:
        c.execute("SELECT * FROM servers WHERE name=%s LIMIT 1", (name,))
        return c.fetchone()


def dispatch_control(server_id: int, action: str) -> dict:
    response = requests.post(f"http://127.0.0.1:8001/api/daemon/{action}/{server_id}", timeout=10)
    response.raise_for_status()
    return response.json()


intents = discord.Intents.default()
bot = discord.Client(intents=intents)
tree = app_commands.CommandTree(bot)


@tree.command(name="status", description="Show status of a game server")
@app_commands.describe(server="Server name")
async def status(interaction: discord.Interaction, server: str):
    s = await asyncio.to_thread(find_server, server)
    if not s:
        await interaction.response.send_message(f"❌ Server `{server}` not found.", ephemeral=True)
        return
    e = discord.Embed(title=f"▶ {s['name']}", color=0x00F0FF)
    e.add_field(name="Game", value=s["game"])
    e.add_field(name="Status", value=s["status"].upper())
    e.add_field(name="Players", value=f"{s['players_online']}/{s['players_max']}")
    e.add_field(name="CPU", value=f"{round(float(s['cpu_usage']))}%")
    e.add_field(name="RAM", value=f"{s['ram_usage_mb']} / {s['ram_mb']} MB")
    e.add_field(name="Port", value=str(s["port"]))
    await interaction.response.send_message(embed=e)


async def lifecycle(interaction: discord.Interaction, server: str, action: str):
    permissions = getattr(interaction.user, "guild_permissions", None)
    if (
        not GUILD
        or str(interaction.guild_id) != GUILD
        or not permissions
        or not permissions.manage_guild
    ):
        await interaction.response.send_message(
            "Server control requires Manage Server permission in the configured guild.",
            ephemeral=True,
        )
        return
    await interaction.response.defer(ephemeral=True)
    found = await asyncio.to_thread(find_server, server)
    if not found:
        await interaction.followup.send("Server not found.", ephemeral=True)
        return
    try:
        result = await asyncio.to_thread(dispatch_control, found["id"], action)
    except (requests.RequestException, ValueError):
        await interaction.followup.send(
            "Daemon control failed; server status was not changed.", ephemeral=True
        )
        return
    await interaction.followup.send(
        f"{action.title()} dispatched to {found['name']}: {result.get('status', 'accepted')}",
        ephemeral=True,
        allowed_mentions=discord.AllowedMentions.none(),
    )


@app_commands.default_permissions(manage_guild=True)
@tree.command(name="start", description="Start a game server")
async def start_cmd(interaction: discord.Interaction, server: str):
    await lifecycle(interaction, server, "start")


@app_commands.default_permissions(manage_guild=True)
@tree.command(name="stop", description="Stop a game server")
async def stop_cmd(interaction: discord.Interaction, server: str):
    await lifecycle(interaction, server, "stop")


@app_commands.default_permissions(manage_guild=True)
@tree.command(name="restart", description="Restart a game server")
async def restart_cmd(interaction: discord.Interaction, server: str):
    await lifecycle(interaction, server, "restart")


@bot.event
async def on_ready():
    await tree.sync()
    print(f"◈ ApexNode bot logged in as {bot.user}")


def main():
    load_settings()
    if not TOKEN:
        print("No DISCORD_BOT_TOKEN set (env or settings table). Exiting.")
        sys.exit(0)
    bot.run(TOKEN)


if __name__ == "__main__":
    main()
