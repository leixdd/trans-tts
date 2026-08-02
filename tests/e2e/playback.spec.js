import { expect, test } from '@playwright/test';
import { readFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const fixture = JSON.parse(readFileSync(path.join(__dirname, '.playback-fixture.json'), 'utf8'));

/** @param {import('@playwright/test').Locator} locator */
async function expectDisplayNone(locator) {
    await expect(locator).toHaveCSS('display', 'none');
}

/** @param {import('@playwright/test').Locator} locator */
async function expectDisplayNotNone(locator) {
    await expect(locator).not.toHaveCSS('display', 'none');
}

const mockAudioInitScript = () => {
    class MockAudio {
        static instances = [];

        constructor(url) {
            this.src = url;
            this.paused = true;
            this.currentTime = 0;
            this.listeners = {};
            MockAudio.instances.push(this);
        }

        addEventListener(event, handler) {
            this.listeners[event] ??= [];
            this.listeners[event].push(handler);
        }

        play() {
            this.paused = false;
            return Promise.resolve();
        }

        pause() {
            this.paused = true;
        }

        emit(event) {
            for (const handler of this.listeners[event] ?? []) {
                handler();
            }
        }
    }

    window.__mockAudioInstances = MockAudio.instances;
    window.Audio = MockAudio;
};

test.describe('Play/Stop playback control', () => {
    test.beforeEach(async ({ context }) => {
        await context.addCookies([
            {
                name: fixture.cookieName,
                value: fixture.cookieValue,
                url: process.env.PLAYWRIGHT_BASE_URL ?? 'http://127.0.0.1:8765',
            },
        ]);
    });

    test('idle shows Play only with Play speech labels', async ({ page }) => {
        const [turnId] = fixture.turnIds;

        await page.addInitScript(mockAudioInitScript);
        await page.goto('/');

        const shell = page.locator(`[data-playback-shell="${turnId}"]`);
        const toggle = page.locator(`[data-playback-toggle="${turnId}"]`);
        const playIcon = shell.locator('[data-playback-icon-play]');
        const stopIcon = shell.locator('[data-playback-icon-stop]');

        await expect(shell).toBeVisible({ timeout: 15_000 });
        await expect(shell).toHaveAttribute('data-playback-state', 'idle');
        await expect(toggle).toHaveAttribute('title', 'Play speech');
        await expect(toggle).toHaveAttribute('aria-label', 'Play speech');
        await expectDisplayNotNone(playIcon);
        await expectDisplayNone(stopIcon);
        await expect(page.locator('[data-playback-icon-pause]')).toHaveCount(0);
    });

    test('Play activates Stop-only presentation with Stop speech labels', async ({ page }) => {
        const [turnId] = fixture.turnIds;

        await page.addInitScript(mockAudioInitScript);
        await page.goto('/');

        const shell = page.locator(`[data-playback-shell="${turnId}"]`);
        const toggle = page.locator(`[data-playback-toggle="${turnId}"]`);
        const playIcon = shell.locator('[data-playback-icon-play]');
        const stopIcon = shell.locator('[data-playback-icon-stop]');

        await expect(shell).toBeVisible({ timeout: 15_000 });
        await toggle.click();

        await expect(shell).toHaveAttribute('data-playback-state', 'playing');
        await expect(toggle).toHaveAttribute('title', 'Stop speech');
        await expect(toggle).toHaveAttribute('aria-label', 'Stop speech');
        await expectDisplayNone(playIcon);
        await expectDisplayNotNone(stopIcon);
    });

    test('Stop settles the clip and advances to the next FIFO turn', async ({ page }) => {
        const [firstId, secondId] = fixture.turnIds;

        await page.addInitScript(mockAudioInitScript);
        await page.goto('/');

        await page.waitForFunction(() => window.TranslationPlayback !== undefined);

        await page.evaluate(({ turnIds }) => {
            window.TranslationPlayback.reset();
            window.TranslationPlayback.syncOrder(turnIds);
            for (const id of turnIds) {
                const shell = document.querySelector(`[data-playback-shell="${id}"]`);
                const url = shell?.getAttribute('data-audio-src');
                if (url) {
                    window.TranslationPlayback.markReady(id, url);
                }
            }
        }, { turnIds: fixture.turnIds });

        const firstShell = page.locator(`[data-playback-shell="${firstId}"]`);
        const firstToggle = page.locator(`[data-playback-toggle="${firstId}"]`);
        const secondShell = page.locator(`[data-playback-shell="${secondId}"]`);

        await expect(firstShell).toHaveAttribute('data-playback-state', 'playing', { timeout: 10_000 });

        await firstToggle.click();

        await expect(firstShell).toHaveAttribute('data-playback-state', 'idle');
        await expect(firstToggle).toHaveAttribute('aria-label', 'Play speech');
        await expect(secondShell).toHaveAttribute('data-playback-state', 'playing', { timeout: 10_000 });

        const state = await page.evaluate(() => window.TranslationPlayback.getState());
        expect(state.played).toContain(firstId);
        expect(state.playingId).toBe(secondId);
        expect(state).not.toHaveProperty('paused');
    });

    test('keyboard activation toggles Play and Stop on the focused control', async ({ page }) => {
        const [turnId] = fixture.turnIds;

        await page.addInitScript(mockAudioInitScript);
        await page.goto('/');

        const shell = page.locator(`[data-playback-shell="${turnId}"]`);
        const toggle = page.locator(`[data-playback-toggle="${turnId}"]`);

        await expect(shell).toBeVisible({ timeout: 15_000 });
        await toggle.focus();
        await page.keyboard.press('Enter');

        await expect(shell).toHaveAttribute('data-playback-state', 'playing');
        await expect(toggle).toHaveAttribute('aria-label', 'Stop speech');

        await toggle.focus();
        await page.keyboard.press('Space');

        await expect(shell).toHaveAttribute('data-playback-state', 'idle');
        await expect(toggle).toHaveAttribute('aria-label', 'Play speech');
    });
});
