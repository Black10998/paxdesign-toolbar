import { createServer } from 'http';
import { readFile } from 'fs/promises';
import { join, dirname, extname } from 'path';
import { fileURLToPath } from 'url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const root = join(__dirname, '..');
const port = Number(process.env.PORT) || 9876;

const MIME = {
  '.html': 'text/html; charset=utf-8',
  '.js': 'application/javascript; charset=utf-8',
  '.css': 'text/css; charset=utf-8',
};

createServer(async (req, res) => {
  try {
    let path = (req.url || '/').split('?')[0];
    if (path === '/') path = '/dock-test.html';
    const filePath = path.startsWith('/paxdesign-toolbar/')
      ? join(root, path.slice(1))
      : join(__dirname, path.replace(/^\//, ''));
    const body = await readFile(filePath);
    res.writeHead(200, { 'Content-Type': MIME[extname(filePath)] || 'application/octet-stream' });
    res.end(body);
  } catch (e) {
    res.writeHead(404);
    res.end('Not found: ' + (req.url || ''));
  }
}).listen(port, '127.0.0.1', () => {
  console.log(`http://127.0.0.1:${port}/dock-test.html`);
});
