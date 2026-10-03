// ApexNode Panel — client bootstrap
(function () {
  // Sidebar toggle
  document.addEventListener("click", function (e) {
    const t = e.target.closest("[data-toggle-sidebar]");
    if (t) {
      document.querySelector(".sidebar")?.classList.toggle("open");
    }
    const copy = e.target.closest("[data-copy-value]");
    if (copy && navigator.clipboard) {
      navigator.clipboard
        .writeText(copy.dataset.copyValue)
        .then(() => {
          const oldTitle = copy.title;
          copy.title = "Copied";
          setTimeout(() => {
            copy.title = oldTitle;
          }, 1500);
        })
        .catch(() => {});
    }
  });

  // Confirm dialogs on form buttons
  document.addEventListener("submit", function (e) {
    const msg = e.target.getAttribute("data-confirm");
    if (msg && !confirm(msg)) e.preventDefault();
  });

  // Live theme preview
  window.applyTheme = function (theme) {
    const r = document.documentElement.style;
    if (theme.accent) {
      r.setProperty("--accent", theme.accent);
      r.setProperty("--accent-glow", hexToGlow(theme.accent));
    }
    if (theme.radius) r.setProperty("--radius", theme.radius);
    if (theme.font) r.setProperty("--font-head", `'${theme.font}', sans-serif`);
    if (theme.density) {
      const pad =
        { compact: "10px", comfortable: "16px", spacious: "22px" }[theme.density] || "16px";
      r.setProperty("--pad", pad);
    }
  };
  function hexToGlow(hex) {
    hex = hex.replace("#", "");
    const r = parseInt(hex.substr(0, 2), 16);
    const g = parseInt(hex.substr(2, 2), 16);
    const b = parseInt(hex.substr(4, 2), 16);
    return `rgba(${r}, ${g}, ${b}, 0.4)`;
  }

  // PWA
  if ("serviceWorker" in navigator) {
    navigator.serviceWorker.register(apexUrl("/service-worker.js")).catch(() => {});
  }
  let deferredPrompt;
  window.addEventListener("beforeinstallprompt", (e) => {
    e.preventDefault();
    deferredPrompt = e;
    const b = document.getElementById("install-banner");
    if (b) b.classList.add("show");
  });
  document.addEventListener("click", function (e) {
    if (e.target.closest("#install-pwa-btn")) {
      deferredPrompt?.prompt();
    }
    if (e.target.closest("#install-dismiss-btn")) {
      document.getElementById("install-banner")?.classList.remove("show");
    }
  });

  // Live server status refresh (dashboard/servers list)
  const list = document.getElementById("server-list-mount");
  if (list) {
    let refreshing = false;
    async function refresh() {
      if (document.hidden || refreshing) return;
      refreshing = true;
      try {
        const r = await fetch(apexUrl("/json/servers"), { signal: AbortSignal.timeout(10000) });
        const data = await r.json();
        data.forEach((s) => {
          const el = document.querySelector(`[data-server-id="${s.id}"]`);
          if (el) {
            const st = el.querySelector("[data-status]");
            if (st) {
              st.textContent = s.status.toUpperCase();
              st.className = "status status-" + s.status;
            }
            const cpu = el.querySelector("[data-cpu-bar]");
            if (cpu) cpu.style.width = Math.min(100, s.cpu_usage) + "%";
            const ram = el.querySelector("[data-ram-bar]");
            if (ram) ram.style.width = Math.min(100, (s.ram_usage_mb / s.ram_mb) * 100) + "%";
            const players = el.querySelector("[data-players]");
            if (players) players.textContent = s.players_online + "/" + s.players_max;
          }
        });
      } catch {
      } finally {
        refreshing = false;
      }
    }
    setInterval(refresh, 4000);
  }

  // Console live stream
  const con = document.getElementById("console-mount");
  if (con) {
    const sid = con.getAttribute("data-server-id");
    const logEl = con.querySelector(".log");
    let lastId = 0;
    let polling = false;
    async function tick() {
      if (document.hidden || polling) return;
      polling = true;
      try {
        const r = await fetch(apexUrl(`/json/servers/logs?id=${sid}&after=${lastId}`), {
          signal: AbortSignal.timeout(10000),
        });
        const data = await r.json();
        data.lines.forEach((l) => {
          const div = document.createElement("div");
          div.className = "line " + l.level;
          div.innerHTML = `<span class="ts">${l.ts}</span>${escapeHtml(l.line)}`;
          logEl.appendChild(div);
          lastId = Math.max(lastId, l.id);
        });
        if (data.lines.length) logEl.scrollTop = logEl.scrollHeight;
      } catch {
      } finally {
        polling = false;
      }
    }
    function escapeHtml(s) {
      return s.replace(
        /[&<>"']/g,
        (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" })[c],
      );
    }
    setInterval(tick, 1500);
    tick();

    const input = con.querySelector("input");
    input?.addEventListener("keydown", async (e) => {
      if (e.key === "Enter" && input.value.trim()) {
        const cmd = input.value.trim();
        input.value = "";
        await fetch(apexUrl("/json/servers/console"), {
          method: "POST",
          headers: {
            "Content-Type": "application/json",
            "X-CSRF": document.querySelector("meta[name=csrf]")?.content || "",
          },
          body: JSON.stringify({ id: sid, cmd }),
        });
      }
    });
  }
})();
