import { expect, test } from '@playwright/test';

const MCP_BASE_URL = process.env.MCP_BASE_URL ?? 'http://127.0.0.1:8080';

test.describe('the root', () => {
    test('is the login itself, not a page about it', async ({ page }) => {
        await page.goto('/');

        await expect(page).toHaveURL(/\/login$/);
        await expect(page.locator('input[name="_username"]')).toBeVisible();

        // Nothing to sign out of, and no sign-up to offer.
        await expect(page.getByRole('link', { name: /sign out/i })).toHaveCount(0);
    });

    test('puts the steps beside the form on a wide screen', async ({ page }) => {
        await page.setViewportSize({ width: 1280, height: 900 });
        await page.goto('/login');

        const form = await page.locator('input[name="_username"]').boundingBox();
        const steps = await page.locator('aside').boundingBox();

        // Beside, not below: the steps start to the right of the form and their
        // tops line up rather than stacking.
        expect(steps!.x).toBeGreaterThan(form!.x + form!.width);
    });

    test('puts the steps above the form on a narrow screen', async ({ page }) => {
        await page.setViewportSize({ width: 390, height: 900 });
        await page.goto('/login');

        const form = await page.locator('input[name="_username"]').boundingBox();
        const steps = await page.locator('aside').boundingBox();
        const header = await page.locator('header').boundingBox();

        // Between the header and the form.
        expect(steps!.y).toBeGreaterThan(header!.y);
        expect(steps!.y + steps!.height).toBeLessThanOrEqual(form!.y);
    });

    test('centres the card with the header above and the footer below', async ({ page }) => {
        await page.setViewportSize({ width: 1280, height: 900 });
        await page.goto('/login');

        const header = await page.locator('header').boundingBox();
        const card = await page.locator('main').boundingBox();
        const footer = await page.locator('footer').boundingBox();

        expect(header!.y + header!.height).toBeLessThanOrEqual(card!.y);
        expect(footer!.y).toBeGreaterThanOrEqual(card!.y + card!.height);

        // Horizontally centred, and the header sits over the same column.
        const cardCentre = card!.x + card!.width / 2;
        expect(Math.abs(cardCentre - 1280 / 2)).toBeLessThan(4);
        expect(Math.abs(header!.x + header!.width / 2 - cardCentre)).toBeLessThan(4);
    });
});

/**
 * One attempt, waited out.
 *
 * Turbo swaps the body after the submission resolves, and the URL is /login
 * before and after — so waiting on the URL reads the previous page. Waiting for
 * the message box is the only reliable signal that the answer has arrived.
 */
async function failLogin(page: import('@playwright/test').Page, email: string): Promise<string> {
    await page.goto('/login');
    await page.locator('input[name="_username"]').fill(email);
    await page.locator('input[name="_password"]').fill('definitely-not-a-password');
    await page.getByRole('button', { name: 'Sign in' }).click();

    const message = page.locator('.bg-red-50').first();
    await expect(message).toBeVisible();

    return (await message.innerText()).trim();
}

test.describe('login page', () => {
    test('is step 1 of the same wizard the account page continues', async ({ page }) => {
        await page.goto('/login');

        const steps = page.locator('aside li');
        await expect(steps).toHaveCount(3);
        await expect(page.locator('li[aria-current="step"]')).toContainText('Sign in with Bring!');

        // The two steps still to come are visible but held back.
        await expect(steps.nth(1)).toHaveClass(/opacity-45/);
        await expect(steps.nth(2)).toHaveClass(/opacity-45/);
    });

    test('offers both the password form and the link fallback', async ({ page }) => {
        await page.goto('/login');

        await expect(page.locator('input[name="_username"]')).toBeVisible();
        await expect(page.locator('input[name="_password"]')).toBeVisible();

        // The fallback sits behind a disclosure so it does not compete with the
        // normal path.
        const fallback = page.locator('details');
        await expect(fallback).toBeVisible();
        await expect(page.locator('form[action$="/login/link"]')).toBeHidden();

        await fallback.locator('summary').click();
        await expect(page.locator('form[action$="/login/link"] input[name="email"]')).toBeVisible();
    });

    test('rejects credentials Bring! does not know', async ({ page }) => {
        // A fresh address each run: login throttling counts per username and
        // would otherwise start answering differently after five runs.
        const email = `nobody-${Date.now()}@example.invalid`;

        // Bring! answers 400 for an address it does not have, which the client
        // treats as a rejection — not as the service being down.
        expect(await failLogin(page, email)).toMatch(/invalid credentials/i);
    });

    /**
     * Consumes 6 of the per-IP budget, which is five times the per-username
     * limit. A fresh container has room to spare; after repeated local runs it
     * can run dry and start blocking valid sign-ins too:
     *
     *   docker compose exec app php bin/console cache:pool:clear cache.rate_limiter
     */
    test('stops answering after too many attempts', async ({ page }) => {
        const email = `throttle-${Date.now()}@example.invalid`;

        // The limit is five per username and IP, so five are still answered on
        // their merits.
        for (let attempt = 1; attempt <= 5; attempt += 1) {
            expect(await failLogin(page, email)).toMatch(/invalid credentials/i);
        }

        // The sixth is not asked of Bring! at all.
        expect(await failLogin(page, email)).toMatch(/too many failed login attempts/i);
    });

    test('answers a link request the same way for any address', async ({ page }) => {
        const messages: string[] = [];

        for (const email of ['nobody@example.invalid', 'someone-else@example.invalid']) {
            await page.goto('/login');
            await page.locator('details summary').click();
            await page.locator('form[action$="/login/link"] input[name="email"]').fill(email);
            await page.getByRole('button', { name: 'Email me a sign-in link' }).click();

            const flash = page.getByText(/sign-in link is on its way/i);
            await expect(flash).toBeVisible();
            messages.push((await flash.textContent())?.trim() ?? '');
        }

        // Telling the two apart would turn this into an account-existence oracle.
        expect(messages[0]).toBe(messages[1]);
    });
});

test.describe('access control', () => {
    for (const path of ['/account', '/consent']) {
        test(`sends an anonymous visitor from ${path} to the login form`, async ({ page }) => {
            await page.goto(path);
            await expect(page).toHaveURL(/\/login$/);
        });
    }

    test('refuses the internal credential endpoint without a token', async ({ request }) => {
        const response = await request.get('/internal/bring-credentials');
        expect(response.status()).toBe(401);
    });
});

test.describe('health', () => {
    test('renders a page a person can read', async ({ page }) => {
        await page.goto('/health');

        await expect(page.getByRole('heading', { name: 'All checks passed' })).toBeVisible();

        for (const check of ['Database', 'MCP server', 'OAuth keypair', 'Credential encryption']) {
            await expect(page.getByText(check, { exact: true })).toBeVisible();
        }

        await expect(page.getByRole('link', { name: '/health.json' })).toBeVisible();
    });

    test('answers the same as JSON, and on the status code alone', async ({ request }) => {
        const response = await request.get('/health.json');

        expect(response.status()).toBe(200);
        expect(response.headers()['content-type']).toContain('application/json');

        const body = await response.json();
        expect(body.status).toBe('ok');
        expect(Object.keys(body.checks).sort()).toEqual([
            'credential_cipher',
            'database',
            'mcp_server',
            'oauth_keypair',
        ]);
        expect(body).toHaveProperty('checked_at');
    });

    test('has no other format', async ({ request }) => {
        expect((await request.get('/health.xml')).status()).toBe(404);
    });

    test('starts no session', async ({ request }) => {
        for (const path of ['/health', '/health.json']) {
            const response = await request.get(path);
            expect(response.headers()['set-cookie'], `${path} set a cookie`).toBeUndefined();
        }
    });
});

test.describe('OAuth discovery', () => {
    test('publishes authorization server metadata', async ({ request }) => {
        const body = await (await request.get('/.well-known/oauth-authorization-server')).json();

        expect(body.authorization_endpoint).toMatch(/\/authorize$/);
        expect(body.token_endpoint).toMatch(/\/token$/);
        expect(body.jwks_uri).toMatch(/\/\.well-known\/jwks\.json$/);
        expect(body.scopes_supported).toContain('bring');
        // PKCE is the whole protection for a public client.
        expect(body.code_challenge_methods_supported).toContain('S256');
        expect(body.grant_types_supported).toEqual(['authorization_code', 'refresh_token']);
    });

    test('publishes exactly one RSA key', async ({ request }) => {
        const body = await (await request.get('/.well-known/jwks.json')).json();

        // The tokens carry no `kid`, so a verifier can only pick the key if
        // there is precisely one.
        expect(body.keys).toHaveLength(1);
        expect(body.keys[0]).toMatchObject({ kty: 'RSA', alg: 'RS256', use: 'sig' });
        expect(body.keys[0].n.length).toBeGreaterThan(300);
    });
});

test.describe('MCP server', () => {
    test('refuses an unauthenticated call and says where to authenticate', async ({ request }) => {
        const response = await request.post(`${MCP_BASE_URL}/mcp`, {
            headers: { Accept: 'application/json, text/event-stream' },
            data: {
                jsonrpc: '2.0',
                id: 1,
                method: 'initialize',
                params: {
                    protocolVersion: '2025-06-18',
                    capabilities: {},
                    clientInfo: { name: 'playwright', version: '0' },
                },
            },
        });

        expect(response.status()).toBe(401);
        expect(response.headers()['www-authenticate']).toContain('resource_metadata=');
    });

    test('points at this authorization server', async ({ request }) => {
        const response = await request.get(
            `${MCP_BASE_URL}/.well-known/oauth-protected-resource/mcp`,
        );

        expect(response.status()).toBe(200);

        const body = await response.json();
        expect(body.resource).toMatch(/\/mcp$/);
        expect(body.scopes_supported).toContain('bring');
        expect(body.authorization_servers).toHaveLength(1);
    });
});
