// Copies the static export (./out) into Laravel's public/app folder.
import { cpSync, rmSync, existsSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

const here = path.dirname(fileURLToPath(import.meta.url));
const out = path.resolve(here, '..', 'out');
const target = path.resolve(here, '..', '..', 'public', 'app');
if (!existsSync(out)) {
  console.error('Run "npm run build" first (no ./out folder).');
  process.exit(1);
}
rmSync(target, { recursive: true, force: true });
cpSync(out, target, { recursive: true });
console.log(`Dashboard copied to ${target}`);
