// @ts-check
const { defineConfig, devices } = require( '@playwright/test' );

module.exports = defineConfig( {
	testDir: './tests/frontend/ui',
	timeout: 30000,
	expect: { timeout: 10000 },
	fullyParallel: true,
	use: {
		baseURL: process.env.WP_BASE_URL || 'http://localhost:10043',
		headless: true,
		trace: 'on-first-retry',
	},
	projects: [
		{
			name: 'chromium',
			use: { ...devices[ 'Desktop Chrome' ] },
		},
	],
} );
