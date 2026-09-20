import { mkdtempSync, writeFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { execFileSync } from 'node:child_process';
import { chromium } from '../website/node_modules/playwright/index.mjs';

// Use one renderer version and font for stable diagram geometry.
const root = fileURLToPath(new URL('../', import.meta.url));
const temporary = mkdtempSync(join(tmpdir(), 'greenlight-diagram-'));

try {
    const browserConfig = join(temporary, 'browser.json');
    writeFileSync(browserConfig, JSON.stringify({ executablePath: chromium.executablePath() }));

    for (const mode of ['light', 'dark']) {
        const dark = mode === 'dark';
        const config = join(temporary, `${mode}.json`);
        writeFileSync(config, JSON.stringify({
            layout: 'elk',
            elk: { nodePlacementStrategy: 'NETWORK_SIMPLEX' },
            theme: 'base',
            securityLevel: 'loose',
            htmlLabels: false,
            fontFamily: 'Arial, sans-serif',
            themeVariables: {
                darkMode: dark,
                fontFamily: 'Arial, sans-serif',
                fontSize: '16px',
                primaryColor: dark ? '#161b22' : '#ffffff',
                primaryTextColor: dark ? '#e6edf3' : '#1f2328',
                primaryBorderColor: dark ? '#8b949e' : '#57606a',
                lineColor: dark ? '#8b949e' : '#57606a',
                secondaryColor: dark ? '#21262d' : '#f6f8fa',
                tertiaryColor: dark ? '#21262d' : '#f6f8fa',
                clusterBkg: dark ? '#21262d' : '#f6f8fa',
                clusterBorder: dark ? '#484f58' : '#d0d7de',
                edgeLabelBackground: dark ? '#0d1117' : '#ffffff',
            },
            flowchart: { htmlLabels: false, curve: 'linear', nodeSpacing: 30, rankSpacing: 50 },
        }));
        execFileSync('npx', [
            '--yes', '@mermaid-js/mermaid-cli@11.17.0',
            '-i', 'docs/architecture/runtime-flow.mmd',
            '-o', `docs/architecture/runtime-flow-${mode}.svg`,
            '-c', config, '-p', browserConfig,
            '-b', dark ? '#0d1117' : '#ffffff',
            '--svgId', 'runtime-flow',
        ], { cwd: root, stdio: 'inherit', env: { ...process.env, PUPPETEER_SKIP_DOWNLOAD: 'true' } });
    }
} finally {
    rmSync(temporary, { recursive: true, force: true });
}
