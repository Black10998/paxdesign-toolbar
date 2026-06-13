const { test, expect } = require('@playwright/test');
const fs = require('node:fs');
const path = require('node:path');
const http = require('node:http');

const ROOT = path.resolve(__dirname, '..');
const PORT = 9988;
const MIME = {
  '.html': 'text/html; charset=utf-8',
  '.js': 'application/javascript; charset=utf-8',
  '.css': 'text/css; charset=utf-8',
  '.svg': 'image/svg+xml',
};

let server;

test.beforeAll(async () => {
  server = http.createServer((req, res) => {
    let requestPath = (req.url || '/').split('?')[0];
    if (requestPath === '/') requestPath = '/scripts/dock-test.html';
    const filePath = path.join(ROOT, requestPath.replace(/^\//, ''));
    fs.readFile(filePath, (err, body) => {
      if (err) {
        res.writeHead(404);
        res.end('Not found');
        return;
      }
      const ext = path.extname(filePath);
      res.writeHead(200, { 'Content-Type': MIME[ext] || 'application/octet-stream' });
      res.end(body);
    });
  });

  await new Promise((resolve) => server.listen(PORT, '127.0.0.1', resolve));
});

test.afterAll(async () => {
  if (!server) return;
  await new Promise((resolve) => server.close(resolve));
});

async function collectLayoutMetrics(page) {
  return page.evaluate(async () => {
    const dock = document.getElementById('pdx-dock');
    const buttons = Array.from(document.querySelectorAll('#pdx-dock .pdx-btn[data-module]'));
    const hidden = buttons.filter((btn) => {
      const rect = btn.getBoundingClientRect();
      const style = getComputedStyle(btn);
      return (
        style.display === 'none' ||
        style.visibility === 'hidden' ||
        style.opacity === '0' ||
        rect.width < 8 ||
        rect.height < 8
      );
    }).map((btn) => btn.dataset.module);

    if (dock) {
      dock.style.scrollBehavior = 'auto';
      dock.scrollLeft = dock.scrollWidth;
      dock.scrollTop = dock.scrollHeight;
    }
    await new Promise((resolve) => setTimeout(resolve, 80));

    const last = buttons[buttons.length - 1];
    const direction = dock ? getComputedStyle(dock).flexDirection : '';
    const viewportW = window.innerWidth;
    const viewportH = window.innerHeight;
    const lastReachable = !!dock && !!last && (
      direction === 'row'
        ? (dock.scrollWidth <= dock.clientWidth + 1) ||
          (last.offsetLeft + last.offsetWidth <= dock.scrollLeft + dock.clientWidth + 1)
        : (dock.scrollHeight <= dock.clientHeight + 1) ||
          (last.offsetTop + last.offsetHeight <= dock.scrollTop + dock.clientHeight + 1)
    );

    if (buttons[0]) buttons[0].click();
    await new Promise((resolve) => setTimeout(resolve, 320));

    const panel = document.getElementById('pdx-panel');
    const panelRect = panel ? panel.getBoundingClientRect() : null;
    const panelOpen = !!panel && panel.classList.contains('is-open');
    const panelInViewport = !!panelRect &&
      panelRect.top >= -1 &&
      panelRect.left >= -1 &&
      panelRect.bottom <= viewportH + 1 &&
      panelRect.right <= viewportW + 1;

    if (buttons[0]) buttons[0].click();

    return {
      totalButtons: buttons.length,
      hiddenButtons: hidden,
      lastReachable,
      panelOpen,
      panelInViewport,
      dockOverflowX: dock ? getComputedStyle(dock).overflowX : '',
      dockFlexDirection: direction,
    };
  });
}

[
  { name: 'desktop', width: 1440, height: 900, expectedDirection: 'column' },
  { name: 'tablet', width: 900, height: 1100, expectedDirection: 'column' },
  { name: 'mobile', width: 375, height: 812, expectedDirection: 'row' },
  { name: 'narrow-mobile', width: 300, height: 760, expectedDirection: 'row' },
].forEach((viewport) => {
  test(`dock shell stays visible at ${viewport.name}`, async ({ page }) => {
    await page.setViewportSize({ width: viewport.width, height: viewport.height });
    await page.goto(`http://127.0.0.1:${PORT}/scripts/dock-test.html`, { waitUntil: 'networkidle' });
    await page.waitForTimeout(300);

    const metrics = await collectLayoutMetrics(page);

    expect(metrics.totalButtons).toBeGreaterThan(10);
    expect(metrics.hiddenButtons).toEqual([]);
    expect(metrics.lastReachable).toBeTruthy();
    expect(metrics.panelOpen).toBeTruthy();
    expect(metrics.panelInViewport).toBeTruthy();
    expect(metrics.dockFlexDirection).toBe(viewport.expectedDirection);
    if (viewport.expectedDirection === 'row') {
      expect(['auto', 'scroll']).toContain(metrics.dockOverflowX);
    }
  });
});
