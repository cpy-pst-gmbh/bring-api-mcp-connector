import { defineConfig, devices } from '@playwright/test';

/**
 * Points at whatever is already running — locally `docker compose up`, in CI the
 * same containers started by the workflow. The tests never start the app
 * themselves, so what they exercise is the real image rather than a dev server.
 */
export default defineConfig({
    testDir: './e2e',
    fullyParallel: false, // The suite writes to one shared account and database.
    forbidOnly: !!process.env.CI,
    retries: process.env.CI ? 1 : 0,
    workers: 1,
    reporter: process.env.CI ? [['github'], ['html', { open: 'never' }]] : 'list',

    use: {
        baseURL: process.env.APP_BASE_URL ?? 'http://127.0.0.1:8000',
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
    },

    projects: [
        {
            name: 'chromium',
            use: { ...devices['Desktop Chrome'] },
        },
    ],
});
