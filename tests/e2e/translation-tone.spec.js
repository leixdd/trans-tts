import { expect, test } from '@playwright/test';

test.describe('Translation tone selector', () => {
    test.beforeEach(async ({ page }) => {
        await page.goto('/');
        await expect(page.locator('[data-translation-tone]')).toBeVisible({ timeout: 15_000 });
    });

    test('defaults to Normal Mode beside the language selector', async ({ page }) => {
        const toneSelect = page.locator('[data-translation-tone]');
        const languageSelect = page.locator('#target-language');

        await expect(languageSelect).toBeVisible();
        await expect(toneSelect).toBeVisible();
        await expect(toneSelect).toHaveAttribute('id', 'translation-tone');
        await expect(toneSelect).toHaveValue('normal');
        await expect(toneSelect.locator('option:checked')).toHaveText('Normal Mode');
    });

    test('has an accessible label via sr-only text and data hook', async ({ page }) => {
        const toneSelect = page.locator('[data-translation-tone]');

        await expect(page.locator('label[for="translation-tone"]')).toHaveClass(/sr-only/);
        await expect(page.locator('label[for="translation-tone"]')).toHaveText('Translation tone');
        await expect(toneSelect).toHaveAttribute('data-translation-tone', '');
    });

    test('persists Business Mode after reload in the same browser context', async ({ page }) => {
        const toneSelect = page.locator('[data-translation-tone]');

        await toneSelect.selectOption('business');
        await expect(toneSelect).toHaveValue('business');
        await page.waitForTimeout(400);

        await page.reload();
        await expect(toneSelect).toBeVisible({ timeout: 15_000 });
        await expect(toneSelect).toHaveValue('business');
        await expect(toneSelect.locator('option:checked')).toHaveText('Business Mode');
    });

    test('persists Academic Mode after reload in the same browser context', async ({ page }) => {
        const toneSelect = page.locator('[data-translation-tone]');

        await toneSelect.selectOption('academic');
        await expect(toneSelect).toHaveValue('academic');
        await page.waitForTimeout(400);

        await page.reload();
        await expect(toneSelect).toBeVisible({ timeout: 15_000 });
        await expect(toneSelect).toHaveValue('academic');
        await expect(toneSelect.locator('option:checked')).toHaveText('Academic Mode');
    });

    test('allows submit with Business tone while language and speaker controls remain usable', async ({ page }) => {
        const toneSelect = page.locator('[data-translation-tone]');
        const languageSelect = page.locator('#target-language');
        const speakerToggle = page.locator('[data-speaker-settings-toggle]');

        await toneSelect.selectOption('business');
        await languageSelect.selectOption('en');
        await expect(speakerToggle).toBeVisible();

        await page.locator('#source-text').fill('Hello from tone e2e');
        await page.getByRole('button', { name: 'Translate & Speak' }).click();

        await expect(page.locator('[data-translation-chat]')).toContainText('Hello from tone e2e', {
            timeout: 15_000,
        });
        await expect(toneSelect).toHaveValue('business');
        await expect(languageSelect).toHaveValue('en');
    });

    test('supports keyboard focus and tab reachability on the tone selector', async ({ page }) => {
        const toneSelect = page.locator('[data-translation-tone]');
        const languageSelect = page.locator('#target-language');

        await languageSelect.focus();
        await page.keyboard.press('Tab');
        await expect(toneSelect).toBeFocused();

        await toneSelect.selectOption('business');
        await expect(toneSelect).toHaveValue('business');
    });

    test('remains usable on a narrow mobile viewport', async ({ page }) => {
        await page.setViewportSize({ width: 390, height: 844 });

        const toneSelect = page.locator('[data-translation-tone]');
        const languageSelect = page.locator('#target-language');
        const speakerToggle = page.locator('[data-speaker-settings-toggle]');

        await expect(toneSelect).toBeVisible();
        await expect(languageSelect).toBeVisible();
        await expect(speakerToggle).toBeVisible();

        await toneSelect.scrollIntoViewIfNeeded();
        await toneSelect.selectOption('academic');
        await expect(toneSelect).toHaveValue('academic');
    });
});
