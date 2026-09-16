'use strict';
(() => {
  const key = 'cronTradeLastRun';
  const interval = 60000;
  let running = false;

  async function runCron(force = false) {
    if (running) return;
    const now = Date.now();
    const last = Number(localStorage.getItem(key) || 0);
    if (!force && now - last < interval - 1000) return;
    running = true;
    try {
      const response = await fetch(`cron_trade.php?_=${now}`, {cache: 'no-store'});
      if (response.ok) {
        localStorage.setItem(key, String(now));
        window.dispatchEvent(new CustomEvent('cron-trade:done', {detail: await response.json()}));
      }
    } catch {
      // Silent by design: pages should keep working even when the cron pulse fails.
    } finally {
      running = false;
    }
  }

  window.cronTradeRun = () => runCron(true);
  setTimeout(() => runCron(true), 1500);
  setInterval(() => runCron(false), interval);
})();
