/**
 * Unit tests for the pure buildLoginUrl helper in query.js.
 *
 * Runs under wp-scripts test-unit-js (Jest). No browser or store required.
 */
import { buildLoginUrl } from './query';

const BASE = 'https://example.test/wp-json/signalboard/v1/requests';

describe( 'buildLoginUrl', () => {
	it( 'derives the sibling auth/login route from the requests base', () => {
		expect( buildLoginUrl( BASE ) ).toBe(
			'https://example.test/wp-json/signalboard/v1/auth/login'
		);
	} );

	it( 'strips a trailing slash before deriving the login route', () => {
		expect( buildLoginUrl( `${ BASE }/` ) ).toBe(
			'https://example.test/wp-json/signalboard/v1/auth/login'
		);
	} );

	it( 'only rewrites a trailing /requests segment', () => {
		const nested =
			'https://example.test/requests-site/wp-json/signalboard/v1/requests';
		expect( buildLoginUrl( nested ) ).toBe(
			'https://example.test/requests-site/wp-json/signalboard/v1/auth/login'
		);
	} );
} );
