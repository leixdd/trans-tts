import { execSync } from 'node:child_process';
import { mkdirSync, writeFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const fixturePath = path.join(__dirname, '.playback-fixture.json');

export default function globalSetup() {
    const output = execSync('php tests/e2e/seed-playback-fixture.php', {
        cwd: path.join(__dirname, '../..'),
        encoding: 'utf8',
    }).trim();

    mkdirSync(path.dirname(fixturePath), { recursive: true });
    writeFileSync(fixturePath, output, 'utf8');
}
