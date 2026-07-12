// @ts-check
const { test, expect } = require( '@playwright/test' );

/**
 * Slice 2 acceptance: read-only board block + shortcode.
 *
 * These exercise the WordPress Interactivity API enhancement in a real browser:
 * the list is server-rendered, then filter/sort/pagination update it live
 * against the signalboard/v1 REST route WITHOUT a page reload.
 *
 * DOM contract (from BoardRenderer::render):
 *   root:   .signalboard-board  ([data-wp-interactive="signalboard/board"])
 *   items:  ul.signalboard-board__list > li.signalboard-board__item
 *           (the <template data-wp-each> contents are inert, so a locator on
 *            .signalboard-board__item counts only real rendered <li>s)
 *   title:  .signalboard-board__title
 *   status filter: select[data-sb-filter="status"]
 *   sort filter:   select[data-sb-filter="sort"]
 *   empty:  .signalboard-board__empty
 *   pager:  .signalboard-board__pagination, buttons [data-sb-page="prev|next"],
 *           current page in .signalboard-board__page
 */

const DEMO = '/signalboard-demo/';
const SHORTCODE = '/signalboard-shortcode/';
const EMPTY = '/signalboard-empty/';

/**
 * Convenience locators bound to a page's board root.
 *
 * @param {import('@playwright/test').Page} page Playwright page.
 * @return {Object} Named locators.
 */
function board( page ) {
	const root = page.locator( '.signalboard-board' );
	return {
		root,
		items: root.locator( 'li.signalboard-board__item' ),
		titles: root.locator( '.signalboard-board__title' ),
		statusFilter: root.locator( 'select[data-sb-filter="status"]' ),
		sortFilter: root.locator( 'select[data-sb-filter="sort"]' ),
		empty: root.locator( '.signalboard-board__empty' ),
		pagination: root.locator( '.signalboard-board__pagination' ),
		pageNum: root.locator( '.signalboard-board__page' ),
		prev: root.locator( '[data-sb-page="prev"]' ),
		next: root.locator( '[data-sb-page="next"]' ),
	};
}

/**
 * Mark the current document so a later assertion can prove no reload happened.
 * A real navigation replaces the document and wipes the flag.
 *
 * @param {import('@playwright/test').Page} page Playwright page.
 */
async function stampNoReload( page ) {
	await page.evaluate( () => {
		// @ts-ignore - test-only marker on window.
		window.__sbNoReload = true;
	} );
}

/**
 * Assert the no-reload marker survived (i.e. the document was never replaced).
 *
 * @param {import('@playwright/test').Page} page Playwright page.
 */
async function expectNoReload( page ) {
	const survived = await page.evaluate(
		// @ts-ignore - test-only marker on window.
		() => window.__sbNoReload === true
	);
	expect( survived, 'page must not have reloaded during interaction' ).toBe(
		true
	);
}

test.describe( 'Signalboard board block (demo page)', () => {
	test( 'initial render: 10 items, first is Two-factor authentication, pager visible', async ( {
		page,
	} ) => {
		await page.goto( DEMO );
		const b = board( page );

		await expect( b.root ).toBeVisible();
		await expect( b.items ).toHaveCount( 10 );
		await expect( b.titles.first() ).toHaveText( 'Two-factor authentication' );
		// 12 requests / 10 per page => 2 pages, so the pager must be visible.
		await expect( b.pagination ).toBeVisible();
	} );

	test( 'sort by title (no reload): first item becomes Add audit log export', async ( {
		page,
	} ) => {
		await page.goto( DEMO );
		const b = board( page );
		await expect( b.titles.first() ).toHaveText( 'Two-factor authentication' );

		await stampNoReload( page );
		await b.sortFilter.selectOption( 'title' );

		await expect( b.titles.first() ).toHaveText( 'Add audit log export' );
		await expectNoReload( page );
		// URL must be unchanged (interactivity, not a navigation).
		expect( new URL( page.url() ).pathname ).toBe( DEMO );
	} );

	test( 'filter by status=planned (no reload): exactly 2 known items', async ( {
		page,
	} ) => {
		await page.goto( DEMO );
		const b = board( page );

		await stampNoReload( page );
		await b.statusFilter.selectOption( 'planned' );

		await expect( b.items ).toHaveCount( 2 );
		await expect( b.titles ).toHaveText( [
			'Dark mode for the dashboard',
			'Keyboard shortcuts',
		] );
		await expectNoReload( page );
	} );

	test( 'pagination (no reload): next -> page 2 with 2 items, prev -> back to 10', async ( {
		page,
	} ) => {
		await page.goto( DEMO );
		const b = board( page );
		await expect( b.items ).toHaveCount( 10 );

		await stampNoReload( page );
		await b.next.click();

		await expect( b.pageNum ).toHaveText( '2' );
		await expect( b.items ).toHaveCount( 2 );
		await expect( b.titles ).toHaveText( [
			'Onboarding checklist',
			'Team activity digest',
		] );

		await b.prev.click();
		await expect( b.pageNum ).toHaveText( '1' );
		await expect( b.items ).toHaveCount( 10 );
		await expect( b.titles.first() ).toHaveText( 'Two-factor authentication' );

		await expectNoReload( page );
	} );
} );

test.describe( 'Empty state', () => {
	test( 'empty board shows the empty message and zero items', async ( {
		page,
	} ) => {
		await page.goto( EMPTY );
		const b = board( page );

		await expect( b.root ).toBeVisible();
		await expect( b.empty ).toBeVisible();
		await expect( b.items ).toHaveCount( 0 );
	} );
} );

test.describe( 'Shortcode parity', () => {
	test( 'shortcode renders the same board; first item Two-factor authentication', async ( {
		page,
	} ) => {
		await page.goto( SHORTCODE );
		const b = board( page );

		await expect( b.root ).toBeVisible();
		await expect( b.titles.first() ).toHaveText( 'Two-factor authentication' );
	} );
} );
