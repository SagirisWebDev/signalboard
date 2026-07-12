/**
 * Unit tests for the pure buildVoteUrl helper in query.js.
 *
 * Runs under wp-scripts test-unit-js (Jest). No browser or store required.
 */
import { buildVoteUrl } from './query';

const BASE = 'https://example.test/wp-json/signalboard/v1/requests';

describe( 'buildVoteUrl', () => {
	it( 'appends /{id}/vote to the base', () => {
		expect( buildVoteUrl( BASE, 42 ) ).toBe( `${ BASE }/42/vote` );
	} );

	it( 'strips a single trailing slash on the base', () => {
		expect( buildVoteUrl( `${ BASE }/`, 42 ) ).toBe( `${ BASE }/42/vote` );
	} );

	it( 'handles a numeric id', () => {
		expect( buildVoteUrl( BASE, 7 ) ).toBe( `${ BASE }/7/vote` );
	} );

	it( 'handles a string id', () => {
		expect( buildVoteUrl( BASE, '7' ) ).toBe( `${ BASE }/7/vote` );
	} );
} );
