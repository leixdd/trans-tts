import { defineConfig, devices } from '@playwright/test';

const baseURL = process.env.PLAYWRIGHT_BASE_URL ?? 'http://127.0.0.1:8765';

export default defineConfig({
    testDir: 'tests/e2e',
    fullyParallel: false,
    forbidOnly: Boolean(process.env.CI),
    retries: process.env.CI ? 1 : 0,
    workers: 1,
    reporter: [['list'], ['html', { open: 'never', outputFolder: 'tests/e2e/playwright-report' }]],
    outputDir: 'tests/e2e/test-results',
    globalSetup: './tests/e2e/global-setup.js',
    use: {
        baseURL,
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
        ...devices['Desktop Chrome'],
        viewport: { width: 1280, height: 720 },
    },
    webServer: {
        command: 'php artisan serve --host=127.0.0.1 --port=8765',
        url: baseURL,
        reuseExistingServer: !process.env.CI,
        timeout: 120_000,
    },
});
