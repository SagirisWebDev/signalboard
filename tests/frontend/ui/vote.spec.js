// @ts-check
const { test, expect } = require( '@playwright/test' );

/**
 * Slice 3 acceptance: anonymous upvoting with optimistic UI.
 *
 * Exercises the Interactivity API upvote action in a real browser: clicking the
 * vote button optimistically bumps the visible count and toggles aria-pressed,
 * then reconciles with the server; clicking again retracts. No page reload.
 *
 * DOM contract (from BoardRenderer::render / slice3 contract):
 *   item:   li.signalboard-board__item
 *   button: .signalboard-board__vote  (data-wp-on--click="actions.upvote",
 *           data-wp-bind--aria-pressed="context.item.voted")
 *   count:  the vote count is rendered inside the button.
 *
 * Guarded with the same live-site markers as board.spec.js: these run against
 * the live demo page and are skipped (soft) if the board is not reachable.
 */

const DEMO = '/signalboard-demo/';

test.describe( 'Signalboard upvote button (demo page)', () => {
	test( 'clicking upvotes optimistically then reconciles; clicking again retracts', async ( {
		page,
	} ) => {
		await page.goto( DEMO );

		const root = page.locator( '.signalboard-board' );
		await expect( root ).toBeVisible();

		const firstItem = root.locator( 'li.signalboard-board__item' ).first();
		const voteButton = firstItem.locator( '.signalboard-board__vote' );

		await expect( voteButton ).toBeVisible();
		await expect( voteButton ).toHaveAttribute( 'aria-pressed', 'false' );

		// Capture the starting count from the button text.
		const startCount = await voteButton
			.textContent()
			.then( ( t ) => parseInt( ( t || '' ).replace( /\D/g, '' ), 10 ) || 0 );

		// Mark the document so we can prove no reload happened.
		await page.evaluate( () => {
			// @ts-ignore - test-only marker on window.
			window.__sbNoReload = true;
		} );

		// Act: cast the vote.
		await voteButton.click();

		await expect( voteButton ).toHaveAttribute( 'aria-pressed', 'true' );
		await expect
			.poll( async () =>
				voteButton
					.textContent()
					.then(
						( t ) =>
							parseInt( ( t || '' ).replace( /\D/g, '' ), 10 ) || 0
					)
			)
			.toBe( startCount + 1 );

		// Act: retract the vote.
		await voteButton.click();

		await expect( voteButton ).toHaveAttribute( 'aria-pressed', 'false' );
		await expect
			.poll( async () =>
				voteButton
					.textContent()
					.then(
						( t ) =>
							parseInt( ( t || '' ).replace( /\D/g, '' ), 10 ) || 0
					)
			)
			.toBe( startCount );

		// Prove no navigation replaced the document.
		const survived = await page.evaluate(
			// @ts-ignore - test-only marker on window.
			() => window.__sbNoReload === true
		);
		expect( survived, 'page must not have reloaded during voting' ).toBe(
			true
		);
	} );
} );
