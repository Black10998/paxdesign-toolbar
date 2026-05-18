import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const file = path.join(path.dirname(fileURLToPath(import.meta.url)), '..', 'paxdesign-toolbar', 'assets', 'js', 'dock.js');
let c = fs.readFileSync(file, 'utf8');
const i = c.indexOf('return html.replace(/<motion');
if (i === -1) {
  console.log('not found');
  process.exit(1);
}
const end = c.indexOf(');', i) + 2;
const snippet = c.slice(i, end);
console.log('snippet:', snippet);
c = c.slice(0, i) + 'return html;' + c.slice(end);
fs.writeFileSync(file, c);
console.log('fixed');
