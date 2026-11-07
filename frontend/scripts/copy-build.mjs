// Copies the static export (./out) into Laravel's public/app folder and records the SHA-256
// hashes of the inline scripts, so Laravel can send a Content-Security-Policy that allows exactly
// these scripts and nothing else (no 'unsafe-inline' for scripts).
import { cpSync, rmSync, existsSync, readdirSync, readFileSync, statSync, writeFileSync } from 'node:fs';
import { createHash } from 'node:crypto';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

const here = path.dirname(fileURLToPath(import.meta.url));
const out = path.resolve(here, '..', 'out');
const target = path.resolve(here, '..', '..', 'public', 'app');
if (!existsSync(out)) {
  console.error('Run "npm run build" first (no ./out folder).');
  process.exit(1);
}

function htmlFiles(dir) {
  return readdirSync(dir).flatMap((name) => {
    const full = path.join(dir, name);
    if (statSync(full).isDirectory()) return htmlFiles(full);
    return name.endsWith('.html') ? [full] : [];
  });
}

const hashes = new Set();
let handlers = 0;
for (const file of htmlFiles(out)) {
  const html = readFileSync(file, 'utf8');
  for (const m of html.matchAll(/<script(?![^>]*\bsrc=)[^>]*>([\s\S]*?)<\/script>/g)) {
    hashes.add(`'sha256-${createHash('sha256').update(m[1], 'utf8').digest('base64')}'`);
  }
  handlers += (html.match(/\son[a-z]+="/g) || []).length;
}
if (handlers > 0) {
  console.error(`Found ${handlers} inline event handler(s); they would be blocked by the CSP.`);
  process.exit(1);
}

rmSync(target, { recursive: true, force: true });
cpSync(out, target, { recursive: true });
writeFileSync(path.join(target, 'csp-hashes.json'), JSON.stringify([...hashes], null, 0));
console.log(`Dashboard copied to ${target} (${hashes.size} inline script hashes recorded for the CSP)`);
