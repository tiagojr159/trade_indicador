'use strict';
(() => {
  const key = 'cronTradeLastRun';
  const interval = 60000;
  let running = false;
  const countdowns = () => Array.from(document.querySelectorAll('[data-cron-countdown]'));
  const label = seconds => seconds >= 60 ? `${Math.ceil(seconds / 60)} min` : `${Math.max(0, seconds)} s`;

  function updateCountdown() {
    const last = Number(localStorage.getItem(key) || 0);
    const remaining = last ? Math.ceil((last + interval - Date.now()) / 1000) : Math.ceil(interval / 1000);
    countdowns().forEach(el => { el.textContent = label(remaining); });
  }

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
        updateCountdown();
        window.dispatchEvent(new CustomEvent('cron-trade:done', {detail: await response.json()}));
      }
    } catch {
      // Silent by design: pages should keep working even when the cron pulse fails.
    } finally {
      running = false;
    }
  }

  window.cronTradeRun = () => runCron(true);
  updateCountdown();
  setTimeout(() => runCron(false), 1500);
  setInterval(() => runCron(false), interval);
  setInterval(updateCountdown, 1000);
})();
