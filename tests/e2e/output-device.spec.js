import { expect, test } from '@playwright/test';
import { readFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const fixturePath = path.join(__dirname, '.playback-fixture.json');
/** @type {{ turnIds?: string[], cookieName?: string, cookieValue?: string } | null} */
let fixture = null;
try {
    fixture = JSON.parse(readFileSync(fixturePath, 'utf8'));
} catch {
    fixture = null;
}

const STORAGE_KEY = 'tts_audio_output_device';

/** @param {import('@playwright/test').Locator} locator */
async function expectDisplayNone(locator) {
    await expect(locator).toHaveCSS('display', 'none');
}

/** @param {import('@playwright/test').Locator} locator */
async function expectDisplayNotNone(locator) {
    await expect(locator).not.toHaveCSS('display', 'none');
}

async function openAudioSettingsPanel(page) {
    const toggle = page.locator('[data-speaker-settings-toggle]');
    await toggle.click();
    await expectDisplayNotNone(page.locator('[data-speaker-settings-panel]'));
}

async function installOutputDeviceMocks(page, options = {}) {
        await page.addInitScript((opts) => {
        const supported = opts.supported !== false;
        const pickerDevice = opts.pickerDevice ?? { deviceId: 'e2e-output-device-1', label: 'E2E Headphones' };
        const sinkBehavior = opts.sinkBehavior ?? 'resolve';

        window.__outputDeviceMock = {
            selectCalls: 0,
            sinkCalls: [],
            sinkByElement: new WeakMap(),
        };

        if (!supported) {
            return;
        }

        navigator.mediaDevices.selectAudioOutput = async () => {
            window.__outputDeviceMock.selectCalls += 1;
            return pickerDevice;
        };

        const originalSetSinkId = HTMLMediaElement.prototype.setSinkId;
        HTMLMediaElement.prototype.setSinkId = async function setSinkId(deviceId) {
            window.__outputDeviceMock.sinkCalls.push({ deviceId, src: this.src ?? null });
            window.__outputDeviceMock.sinkByElement.set(this, deviceId);

            if (sinkBehavior === 'reject') {
                throw new Error('sink unavailable');
            }

            if (typeof originalSetSinkId === 'function') {
                try {
                    return await originalSetSinkId.call(this, deviceId);
                } catch {
                    // Native setSinkId may be unavailable in the test runtime.
                }
            }
        };
    }, options);
}

async function installMockAudio(page) {
    await page.addInitScript(() => {
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

            async setSinkId(deviceId) {
                if (typeof HTMLMediaElement.prototype.setSinkId === 'function') {
                    return HTMLMediaElement.prototype.setSinkId.call(this, deviceId);
                }
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
    });
}

async function seedPlaybackQueue(page, turnIds) {
    await page.waitForFunction(() => window.TranslationPlayback !== undefined);
    await page.evaluate(({ ids }) => {
        window.TranslationPlayback.reset();
        window.TranslationPlayback.syncOrder(ids);
        for (const id of ids) {
            const shell = document.querySelector(`[data-playback-shell="${id}"]`);
            const url = shell?.getAttribute('data-audio-src');
            if (url) {
                window.TranslationPlayback.markReady(id, url);
            }
        }
    }, { ids: turnIds });
}

async function withPlaybackFixture(context, page, outputOptions = {}) {
    if (!fixture?.cookieName || !fixture?.cookieValue) {
        return false;
    }

    await context.addCookies([
        {
            name: fixture.cookieName,
            value: fixture.cookieValue,
            url: process.env.PLAYWRIGHT_BASE_URL ?? 'http://127.0.0.1:8765',
        },
    ]);

    await installOutputDeviceMocks(page, outputOptions);
    await installMockAudio(page);
    await page.goto('/');
    await expect(page.locator('[data-speaker-settings-toggle]')).toBeVisible({ timeout: 15_000 });

    return true;
}

test.describe('Output device settings', () => {
    test.beforeEach(async ({ page }) => {
        await installOutputDeviceMocks(page);
        await page.goto('/');
        await expect(page.locator('[data-speaker-settings-toggle]')).toBeVisible({ timeout: 15_000 });
    });

    test('panel shows system default state on first visit', async ({ page }) => {
        await openAudioSettingsPanel(page);

        await expect(page.locator('[data-output-device-section]')).toBeVisible();
        await expect(page.locator('[data-output-device-status]')).toHaveAttribute('data-output-device-status', 'system');
        await expect(page.locator('[data-output-device-label]')).toHaveText('System default');
        await expect(page.locator('[data-output-device-choose]')).toBeEnabled();
        await expect(page.locator('[data-output-device-reset]')).toBeDisabled();
        await expect(page.locator('[data-output-device-notice]')).toHaveClass(/hidden/);
        await expect(page.getByText('Default speaker')).toBeVisible();
    });

    test('choose output device updates label and enables reset', async ({ page }) => {
        await openAudioSettingsPanel(page);
        await page.locator('[data-output-device-choose]').click();

        await expect(page.locator('[data-output-device-status]')).toHaveAttribute('data-output-device-status', 'selected');
        await expect(page.locator('[data-output-device-label]')).toHaveText('E2E Headphones');
        await expect(page.locator('[data-output-device-reset]')).toBeEnabled();

        const stored = await page.evaluate((key) => localStorage.getItem(key), STORAGE_KEY);
        expect(stored).not.toBeNull();
        const parsed = JSON.parse(String(stored));
        expect(parsed.deviceId).toBe('e2e-output-device-1');
        expect(parsed.label).toBe('E2E Headphones');
    });

    test('selected device persists after reload', async ({ page }) => {
        await openAudioSettingsPanel(page);
        await page.locator('[data-output-device-choose]').click();
        await expect(page.locator('[data-output-device-label]')).toHaveText('E2E Headphones');

        await page.reload();
        await expect(page.locator('[data-speaker-settings-toggle]')).toBeVisible({ timeout: 15_000 });
        await openAudioSettingsPanel(page);

        await expect(page.locator('[data-output-device-status]')).toHaveAttribute('data-output-device-status', 'selected');
        await expect(page.locator('[data-output-device-label]')).toHaveText('E2E Headphones');
    });

    test('use system default clears preference and disables reset', async ({ page }) => {
        await openAudioSettingsPanel(page);
        await page.locator('[data-output-device-choose]').click();
        await expect(page.locator('[data-output-device-reset]')).toBeEnabled();

        await page.locator('[data-output-device-reset]').click();

        await expect(page.locator('[data-output-device-status]')).toHaveAttribute('data-output-device-status', 'system');
        await expect(page.locator('[data-output-device-label]')).toHaveText('System default');
        await expect(page.locator('[data-output-device-reset]')).toBeDisabled();

        const stored = await page.evaluate((key) => localStorage.getItem(key), STORAGE_KEY);
        expect(stored).toBeNull();
    });

    test('keyboard can open panel and activate choose control', async ({ page }) => {
        const toggle = page.locator('[data-speaker-settings-toggle]');
        await toggle.focus();
        await page.keyboard.press('Enter');
        await expectDisplayNotNone(page.locator('[data-speaker-settings-panel]'));

        const choose = page.locator('[data-output-device-choose]');
        await choose.focus();
        await page.keyboard.press('Enter');

        await expect(page.locator('[data-output-device-label]')).toHaveText('E2E Headphones');
    });

    test('remains usable on a narrow mobile viewport', async ({ page }) => {
        await page.setViewportSize({ width: 390, height: 844 });
        await openAudioSettingsPanel(page);

        const choose = page.locator('[data-output-device-choose]');
        await choose.scrollIntoViewIfNeeded();
        await expect(choose).toBeVisible();
        await choose.click();

        await expect(page.locator('[data-output-device-label]')).toHaveText('E2E Headphones');
        await expect(page.getByText('Default speaker')).toBeVisible();
    });
});

test.describe('Output device unsupported state', () => {
    test('shows unavailable label and disabled controls', async ({ page }) => {
        await installOutputDeviceMocks(page, { supported: false });
        await page.goto('/');
        await expect(page.locator('[data-speaker-settings-toggle]')).toBeVisible({ timeout: 15_000 });
        await openAudioSettingsPanel(page);

        await expect(page.locator('[data-output-device-status]')).toHaveAttribute('data-output-device-status', 'unsupported');
        await expect(page.locator('[data-output-device-label]')).toHaveText('Output selection unavailable in this browser');
        await expect(page.locator('[data-output-device-choose]')).toBeDisabled();
        await expect(page.locator('[data-output-device-reset]')).toBeDisabled();
    });
});

test.describe('Output device fallback notice', () => {
    test('sink failure shows fallback notice and system default label', async ({ context, page }) => {
        if (!fixture?.turnIds?.length) {
            test.skip();
        }

        const ready = await withPlaybackFixture(context, page, { sinkBehavior: 'reject' });
        if (!ready) {
            test.skip();
        }

        const [turnId] = fixture.turnIds;

        await openAudioSettingsPanel(page);
        await page.locator('[data-output-device-choose]').click();
        await expect(page.locator('[data-output-device-status]')).toHaveAttribute('data-output-device-status', 'selected');

        await seedPlaybackQueue(page, [turnId]);
        await expect(page.locator(`[data-playback-shell="${turnId}"]`)).toHaveAttribute('data-playback-state', 'playing', { timeout: 10_000 });

        await openAudioSettingsPanel(page);
        await expect(page.locator('[data-output-device-status]')).toHaveAttribute('data-output-device-status', 'fallback', { timeout: 10_000 });
        await expect(page.locator('[data-output-device-notice]')).not.toHaveClass(/hidden/);
        await expect(page.locator('[data-output-device-notice]')).toContainText('Selected output device is unavailable');
        await expect(page.locator('[data-output-device-label]')).toHaveText('System default');
    });
});

test.describe('Output device playback routing', () => {
    test('routes the next clip after device change without rerouting the active clip', async ({ context, page }) => {
        if (!fixture?.turnIds?.length) {
            test.skip();
        }

        const ready = await withPlaybackFixture(context, page);
        if (!ready) {
            test.skip();
        }

        const [firstId, secondId] = fixture.turnIds;

        await openAudioSettingsPanel(page);
        await page.locator('[data-output-device-choose]').click();

        await seedPlaybackQueue(page, fixture.turnIds);

        await expect(page.locator(`[data-playback-shell="${firstId}"]`)).toHaveAttribute('data-playback-state', 'playing', { timeout: 10_000 });

        const sinksBeforeChange = await page.evaluate(() =>
            window.__outputDeviceMock.sinkCalls.map((entry) => entry.deviceId),
        );
        expect(sinksBeforeChange).toEqual(['e2e-output-device-1']);

        await page.evaluate(() => {
            navigator.mediaDevices.selectAudioOutput = async () => ({
                deviceId: 'e2e-output-device-2',
                label: 'E2E Alternate',
            });
        });

        await openAudioSettingsPanel(page);
        await page.locator('[data-output-device-choose]').click();
        await expect(page.locator('[data-output-device-label]')).toHaveText('E2E Alternate');

        const sinksAfterChange = await page.evaluate(() =>
            window.__outputDeviceMock.sinkCalls.map((entry) => entry.deviceId),
        );
        expect(sinksAfterChange).toEqual(['e2e-output-device-1']);

        await page.locator(`[data-playback-toggle="${firstId}"]`).click();
        await expect(page.locator(`[data-playback-shell="${secondId}"]`)).toHaveAttribute('data-playback-state', 'playing', { timeout: 10_000 });

        const sinksAfterNextClip = await page.evaluate(() =>
            window.__outputDeviceMock.sinkCalls.map((entry) => entry.deviceId),
        );
        expect(sinksAfterNextClip).toEqual(['e2e-output-device-1', 'e2e-output-device-2']);
    });
});
