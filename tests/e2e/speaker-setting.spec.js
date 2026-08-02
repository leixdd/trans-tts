import { expect, test } from '@playwright/test';

/** @param {import('@playwright/test').Locator} locator */
async function expectDisplayNone(locator) {
    await expect(locator).toHaveCSS('display', 'none');
}

/** @param {import('@playwright/test').Locator} locator */
async function expectDisplayNotNone(locator) {
    await expect(locator).not.toHaveCSS('display', 'none');
}

async function openSpeakerPanel(page) {
    const toggle = page.locator('[data-speaker-settings-toggle]');
    await toggle.click();
    await expectDisplayNotNone(page.locator('[data-speaker-settings-panel]'));
}

test.describe('Speaker settings control', () => {
    test.beforeEach(async ({ page }) => {
        await page.goto('/');
        await expect(page.locator('[data-speaker-settings-toggle]')).toBeVisible({ timeout: 15_000 });
    });

    test('opens and closes the panel beside the language selector', async ({ page }) => {
        const toggle = page.locator('[data-speaker-settings-toggle]');
        const panel = page.locator('[data-speaker-settings-panel]');
        const languageSelect = page.locator('#target-language');

        await expect(languageSelect).toBeVisible();
        await expect(toggle).toBeVisible();
        await expectDisplayNone(panel);

        await toggle.click();
        await expect(toggle).toHaveAttribute('aria-expanded', 'true');
        await expectDisplayNotNone(panel);
        await expect(panel).toHaveAttribute('role', 'dialog');
        await expect(panel.getByText('Default speaker')).toBeVisible();

        await toggle.click();
        await expect(toggle).toHaveAttribute('aria-expanded', 'false');
        await expectDisplayNone(panel);
    });

    test('selects system default speaker mode', async ({ page }) => {
        await openSpeakerPanel(page);

        const systemOption = page.locator('input[name="speaker-mode"][value="system"]');
        await systemOption.check();
        await expect(systemOption).toBeChecked();
        await expect(page.locator('[data-speaker-custom-input]')).toHaveCount(0);
    });

    test('shows validation when custom mode is selected with empty id on submit', async ({ page }) => {
        await openSpeakerPanel(page);

        await page.locator('input[name="speaker-mode"][value="custom"]').check();
        await expect(page.locator('[data-speaker-custom-input]')).toBeVisible();

        await page.locator('#custom-reference-id').fill('');
        await page.getByRole('button', { name: 'Translate & Speak' }).click();

        await expect(
            page.locator('form').getByText('A custom speaker reference ID is required.').first(),
        ).toBeVisible();
    });

    test('shows validation for invalid custom reference id format', async ({ page }) => {
        await openSpeakerPanel(page);

        await page.locator('input[name="speaker-mode"][value="custom"]').check();
        await page.locator('#custom-reference-id').fill('bad id with spaces');
        await page.getByRole('button', { name: 'Translate & Speak' }).click();

        await expect(
            page.locator('form').getByText('The custom speaker reference ID format is invalid.').first(),
        ).toBeVisible();
    });

    test('persists valid custom reference id after reload in the same browser context', async ({ page }) => {
        const customId = 'e2e-voice-persist-01';

        await openSpeakerPanel(page);
        await page.locator('input[name="speaker-mode"][value="custom"]').check();
        await page.locator('#custom-reference-id').fill(customId);
        await page.locator('#custom-reference-id').blur();
        await page.waitForTimeout(400);

        await page.reload();
        await expect(page.locator('[data-speaker-settings-toggle]')).toBeVisible({ timeout: 15_000 });

        await openSpeakerPanel(page);
        await expect(page.locator('input[name="speaker-mode"][value="custom"]')).toBeChecked();
        await expect(page.locator('#custom-reference-id')).toHaveValue(customId);
    });

    test('supports keyboard focus toggle and escape to close', async ({ page }) => {
        const toggle = page.locator('[data-speaker-settings-toggle]');
        const panel = page.locator('[data-speaker-settings-panel]');

        await toggle.focus();
        await page.keyboard.press('Enter');
        await expect(toggle).toHaveAttribute('aria-expanded', 'true');
        await expectDisplayNotNone(panel);

        await page.keyboard.press('Escape');
        await expect(toggle).toHaveAttribute('aria-expanded', 'false');
        await expectDisplayNone(panel);
    });

    test('remains usable on a narrow mobile viewport', async ({ page }) => {
        await page.setViewportSize({ width: 390, height: 844 });

        const toggle = page.locator('[data-speaker-settings-toggle]');
        const languageSelect = page.locator('#target-language');

        await expect(toggle).toBeVisible();
        await expect(languageSelect).toBeVisible();

        await openSpeakerPanel(page);

        const customLabel = page.locator('label').filter({ hasText: 'Custom reference ID' });
        await customLabel.scrollIntoViewIfNeeded();
        await customLabel.click();
        await expect(page.locator('[data-speaker-custom-input]')).toBeVisible();
        await page.locator('#custom-reference-id').scrollIntoViewIfNeeded();
        await page.locator('#custom-reference-id').fill('mobile-voice-01');
        await expect(page.locator('#custom-reference-id')).toHaveValue('mobile-voice-01');
    });
});
