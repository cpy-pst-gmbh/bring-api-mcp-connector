import { expect, test, type Page } from '@playwright/test';

/**
 * Everything past the login form needs an account, and an account only exists
 * once Bring! has confirmed a password — there is no local one to fake. So
 * these run against a real Bring! account supplied through the environment, and
 * skip when it is not configured rather than failing.
 */
const EMAIL = process.env.BRING_TEST_EMAIL;
const PASSWORD = process.env.BRING_TEST_PASSWORD;

test.skip(
    !EMAIL || !PASSWORD,
    'Set BRING_TEST_EMAIL and BRING_TEST_PASSWORD to run the authenticated tests.',
);

async function signIn(page: Page): Promise<void> {
    await page.goto('/login');
    await page.locator('input[name="_username"]').fill(EMAIL!);
    await page.locator('input[name="_password"]').fill(PASSWORD!);
    await page.getByRole('button', { name: 'Sign in' }).click();
    await expect(page).toHaveURL(/\/account/);
}

/** The step the wizard considers current, read off the highlighted entry. */
async function currentStep(page: Page): Promise<string> {
    return (await page.locator('li[aria-current="step"]').innerText()).trim();
}

/**
 * Adds one connector through whichever form the current step offers, and
 * returns its client ID.
 *
 * The ID is read back off the page rather than out of the flash message: Turbo
 * swaps the body asynchronously, and two consecutive creations produce flashes
 * that look alike, so reading the flash can return the previous one.
 */
async function addConnector(page: Page, name: string): Promise<string> {
    const inModal = (await page.getByRole('button', { name: 'Add another connector' }).count()) > 0;

    if (inModal) {
        await page.getByRole('button', { name: 'Add another connector' }).click();
    }

    const scope = inModal ? page.locator('#connector-dialog') : page.locator('form[action$="/account/connectors"]');

    await scope.locator('input[name="label"]').fill(name);
    await scope.getByRole('button', { name: 'Create', exact: true }).click();

    return clientIdFor(page, name);
}

/** The client ID currently on screen for the named connector. */
async function clientIdFor(page: Page, name: string): Promise<string> {
    await page.goto('/account');

    const chooser = page.locator('#connector-select');

    if ((await chooser.count()) > 0) {
        await chooser.selectOption({ label: name });
        // Changing it navigates, so wait for the page that answers.
        await expect(chooser).toHaveValue(/claude-/);
        await expect(page.locator('#client-id')).toBeVisible();
    }

    return (await page.locator('#client-id').innerText()).trim();
}

/** Leaves no connectors behind, so a rerun starts from the same state. */
async function revokeAll(page: Page): Promise<void> {
    for (;;) {
        await page.goto('/account');

        const revoke = page.getByRole('button', { name: 'Revoke' });

        if ((await revoke.count()) === 0) {
            return;
        }

        await revoke.first().click();
        await expect(page.getByText(/connector revoked/i)).toBeVisible();
        // Toasts overlay the page; dismiss so the next click is not blocked.
        await page.getByText(/connector revoked/i).click();
    }
}

test.beforeEach(async ({ page }) => {
    await signIn(page);
    await revokeAll(page);
});

test.afterEach(async ({ page }) => {
    if (page.url().includes('/account')) {
        await revokeAll(page);
    }
});

test.describe('the wizard', () => {
    test('shows all three steps and dims the ones not current', async ({ page }) => {
        const steps = page.locator('aside li');
        await expect(steps).toHaveCount(3);

        await expect(steps.nth(0)).toContainText('Sign in with Bring!');
        await expect(steps.nth(1)).toContainText('Add a connector');
        await expect(steps.nth(2)).toContainText('Connect Claude');

        // Exactly one is current, and the others are visibly held back.
        await expect(page.locator('li[aria-current="step"]')).toHaveCount(1);
        await expect(steps.nth(0)).toHaveClass(/opacity-50/);
        await expect(steps.nth(1)).not.toHaveClass(/opacity-50/);
        await expect(steps.nth(2)).toHaveClass(/opacity-50/);
    });

    test('starts at step 2 while no connector exists', async ({ page }) => {
        expect(await currentStep(page)).toContain('Add a connector');

        // The step is the form, so it is on the page rather than behind a button.
        await expect(page.locator('form[action$="/account/connectors"] input[name="label"]')).toBeVisible();

        // The address belongs in the header, and only there.
        await expect(page.locator('header')).toContainText(EMAIL!);
        await expect(page.locator('main')).not.toContainText(`Signed in as`);
    });

    test('marks the steps behind it as done', async ({ page }) => {
        await addConnector(page, 'Playwright');

        const steps = page.locator('aside li');

        // Steps 1 and 2 are behind us, so they carry the check glyph rather
        // than their number; step 3 still shows its own.
        await expect(steps.nth(0).locator('svg')).toBeVisible();
        await expect(steps.nth(1).locator('svg')).toBeVisible();
        await expect(steps.nth(2).locator('svg')).toHaveCount(0);
        await expect(steps.nth(2)).toContainText('3');
    });

    test('moves to step 3 once a connector exists', async ({ page }) => {
        const identifier = await addConnector(page, 'Playwright');

        expect(await currentStep(page)).toContain('Connect Claude');
        await expect(page.getByRole('heading', { name: 'Connect Claude' })).toBeVisible();

        // Both halves of what has to be typed into Claude.
        await expect(page.locator('#client-id')).toHaveText(identifier);
        await expect(page.locator('code').filter({ hasText: '/mcp' })).toBeVisible();
    });

    test('returns a set-up account straight to step 3', async ({ page }) => {
        await addConnector(page, 'Playwright');

        await page.getByRole('link', { name: 'Sign out' }).click();
        await signIn(page);

        expect(await currentStep(page)).toContain('Connect Claude');
    });
});

test.describe('several connectors', () => {
    test('are chosen from a select that swaps the credentials', async ({ page }) => {
        const first = await addConnector(page, 'Laptop');
        const second = await addConnector(page, 'Phone');

        expect(first).not.toBe(second);

        const select = page.locator('#connector-select');
        await expect(select).toBeVisible();
        await expect(select.locator('option')).toHaveCount(2);

        // Changing it is enough — no button to press.
        await select.selectOption({ label: 'Laptop' });
        await expect(page.locator('#client-id')).toHaveText(first);

        await select.selectOption({ label: 'Phone' });
        await expect(page.locator('#client-id')).toHaveText(second);
    });

    test('hide the no-JavaScript submit button once the controller runs', async ({ page }) => {
        await addConnector(page, 'Laptop');
        await addConnector(page, 'Phone');

        // Present in the markup so the form works without JavaScript, hidden
        // by the controller because a change already submits.
        const fallback = page.locator('[data-auto-submit-target="fallback"]');
        await expect(fallback).toHaveCount(1);
        await expect(fallback).toBeHidden();
    });

    test('an unknown selection falls back instead of erroring', async ({ page }) => {
        await addConnector(page, 'Playwright');

        const response = await page.goto('/account?connector=claude-0000000000000000');

        expect(response?.status()).toBe(200);
        expect(await currentStep(page)).toContain('Connect Claude');
        await expect(page.locator('#client-id')).not.toBeEmpty();
    });
});

test.describe('the connector dialog', () => {
    test.beforeEach(async ({ page }) => {
        // The dialog only exists at step 3; step 2 shows the form inline.
        await addConnector(page, 'Playwright');
    });

    test('keeps its own Cancel alongside the shared close button', async ({ page }) => {
        const dialog = page.locator('#connector-dialog');

        await page.getByRole('button', { name: 'Add another connector' }).click();
        await dialog.getByRole('button', { name: 'Cancel' }).click();
        await expect(dialog).toBeHidden();
    });

    test('offers the real Bring! lists as the default', async ({ page }) => {
        await page.getByRole('button', { name: 'Add another connector' }).click();

        const select = page.locator('#connector-dialog select[name="default_list"]');
        const options = await select.locator('option').allTextContents();

        expect(options[0]).toBe('First list of the account');
        expect(options.length).toBeGreaterThan(1);
    });
});

test.describe('flash messages', () => {
    test('arrive as a toast that leaves on its own', async ({ page }) => {
        await addConnector(page, 'Playwright');

        const toast = page.locator('[data-controller="toast"]').first();
        await expect(toast).toBeVisible();

        // Out of the document flow, so the card below it does not move.
        expect(await toast.evaluate((el) => getComputedStyle(el.parentElement!).position)).toBe('fixed');

        // Gone without anyone touching it. The success delay is 5s.
        await expect(toast).toHaveCount(0, { timeout: 15_000 });
    });

    test('can be dismissed by clicking', async ({ page }) => {
        await addConnector(page, 'Playwright');

        const toast = page.locator('[data-controller="toast"]').first();
        await expect(toast).toBeVisible();

        await toast.click();
        await expect(toast).toHaveCount(0, { timeout: 5000 });
    });
});

test.describe('every dialog', () => {
    /**
     * Each entry is a way in and the dialog it opens. Checked together because
     * the close button went missing from the newer dialogs twice — one shared
     * partial now renders it, and this is what keeps it there.
     */
    const dialogs = [
        { open: 'Add another connector', id: '#connector-dialog', needsConnector: true },
        { open: 'Change password', id: '#password-dialog', needsConnector: false },
        { open: 'Delete account', id: '#delete-account-dialog', needsConnector: false },
    ];

    for (const { open, id, needsConnector } of dialogs) {
        test(`${id} closes by X, Escape and backdrop`, async ({ page }) => {
            if (needsConnector) {
                await addConnector(page, 'Playwright');
            }

            const dialog = page.locator(id);

            await page.getByRole('button', { name: open }).click();
            await expect(dialog).toBeVisible();

            // The X, which is the part that kept getting forgotten.
            await dialog.getByRole('button', { name: 'Close' }).click();
            await expect(dialog).toBeHidden();

            await page.getByRole('button', { name: open }).click();
            await page.keyboard.press('Escape');
            await expect(dialog).toBeHidden();

            await page.getByRole('button', { name: open }).click();
            // A click on the backdrop reports the dialog itself as the target.
            await dialog.dispatchEvent('click');
            await expect(dialog).toBeHidden();
        });
    }
});

test.describe('settings', () => {
    test('are one section of text and buttons', async ({ page }) => {
        const settings = page.locator('section').filter({ hasText: 'Settings' });

        await expect(settings.getByRole('heading', { name: 'Settings' })).toBeVisible();
        // The gear rides in the heading rather than as a separate element.
        await expect(settings.getByRole('heading', { name: 'Settings' }).locator('svg')).toBeVisible();

        // Both entries live in that one section, each a description and a button.
        await expect(settings.getByRole('button', { name: 'Change password' })).toBeVisible();
        await expect(settings.getByRole('button', { name: 'Delete account' })).toBeVisible();

        // Nothing to fill in until a dialog is opened.
        await expect(page.locator('#bring_credential_plainPassword')).toBeHidden();
    });

    test('change the password through a dialog', async ({ page }) => {
        const dialog = page.locator('#password-dialog');

        await expect(dialog).toBeHidden();
        await page.getByRole('button', { name: 'Change password' }).click();
        await expect(dialog).toBeVisible();

        await dialog.getByRole('button', { name: 'Cancel' }).click();
        await expect(dialog).toBeHidden();
    });

    test('refuse a password Bring! does not accept, and say so in the dialog', async ({ page }) => {
        await page.getByRole('button', { name: 'Change password' }).click();

        const dialog = page.locator('#password-dialog');
        await dialog.locator('#bring_credential_plainPassword').fill('not-the-right-password');
        await dialog.getByRole('button', { name: 'Update password' }).click();

        // The error would be lost behind a closed dialog, so it reopens.
        await expect(dialog).toBeVisible();
        await expect(dialog.getByText(/bring! rejected this password|could not confirm/i)).toBeVisible();
    });

    test('ask before deleting the account, and then really delete it', async ({ page }) => {
        await addConnector(page, 'Playwright');
        await page.goto('/account');

        const dialog = page.locator('#delete-account-dialog');

        await page.getByRole('button', { name: 'Delete account' }).click();
        await expect(dialog).toBeVisible();

        // Backing out has to leave everything alone.
        await dialog.getByRole('button', { name: 'Keep it' }).click();
        await expect(dialog).toBeHidden();
        await expect(page).toHaveURL(/\/account/);

        await page.getByRole('button', { name: 'Delete account' }).click();
        await dialog.getByRole('button', { name: 'Delete permanently' }).click();

        // Deleting signs the user out, so the account page is gone with them.
        await expect(page.getByText(/account and everything stored with it is gone/i)).toBeVisible();
        await page.goto('/account');
        await expect(page).toHaveURL(/\/login$/);

        // Signing in again builds a fresh, empty account: back to step 2.
        await signIn(page);
        expect(await currentStep(page)).toContain('Add a connector');
    });
});

test('signing out ends the session', async ({ page }) => {
    await page.getByRole('link', { name: 'Sign out' }).click();

    // Logging out targets the root, and the root is the login.
    await expect(page).toHaveURL(/\/login$/);
    await page.goto('/account');
    await expect(page).toHaveURL(/\/login$/);
});
