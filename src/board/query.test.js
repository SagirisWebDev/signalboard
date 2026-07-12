/**
 * Unit tests for the pure buildBoardQuery helper in query.js.
 *
 * Runs under wp-scripts test-unit-js (Jest). No browser or store required.
 */
import { buildBoardQuery } from './query';

const BASE = 'https://example.test/wp-json/signalboard/v1/requests';

/**
 * Parse the query string of a built URL into a plain object for assertions.
 *
 * @param {string} url Built URL.
 * @return {Object} Query params as key => value.
 */
function params( url ) {
	const parsed = new URL( url );
	const out = {};
	parsed.searchParams.forEach( ( value, key ) => {
		out[ key ] = value;
	} );
	return out;
}

describe( 'buildBoardQuery', () => {
	it( 'produces sensible defaults (date desc, page 1, per_page 10)', () => {
		const p = params( buildBoardQuery( BASE ) );
		expect( p.orderby ).toBe( 'date' );
		expect( p.order ).toBe( 'desc' );
		expect( p.page ).toBe( '1' );
		expect( p.per_page ).toBe( '10' );
	} );

	it( 'defaults omit board and status when not provided', () => {
		const p = params( buildBoardQuery( BASE ) );
		expect( p ).not.toHaveProperty( 'board' );
		expect( p ).not.toHaveProperty( 'status' );
	} );

	it( "sort='title' yields orderby=title and order=asc", () => {
		const p = params( buildBoardQuery( BASE, { sort: 'title' } ) );
		expect( p.orderby ).toBe( 'title' );
		expect( p.order ).toBe( 'asc' );
	} );

	it( "sort='votes' yields orderby=votes and order=desc", () => {
		const p = params( buildBoardQuery( BASE, { sort: 'votes' } ) );
		expect( p.orderby ).toBe( 'votes' );
		expect( p.order ).toBe( 'desc' );
	} );

	it( "sort='date' yields orderby=date and order=desc", () => {
		const p = params( buildBoardQuery( BASE, { sort: 'date' } ) );
		expect( p.orderby ).toBe( 'date' );
		expect( p.order ).toBe( 'desc' );
	} );

	it( 'includes board only when non-empty', () => {
		const withBoard = params( buildBoardQuery( BASE, { board: 'product' } ) );
		expect( withBoard.board ).toBe( 'product' );

		const emptyBoard = params( buildBoardQuery( BASE, { board: '' } ) );
		expect( emptyBoard ).not.toHaveProperty( 'board' );
	} );

	it( 'includes status only when non-empty', () => {
		const withStatus = params( buildBoardQuery( BASE, { status: 'planned' } ) );
		expect( withStatus.status ).toBe( 'planned' );

		const emptyStatus = params( buildBoardQuery( BASE, { status: '' } ) );
		expect( emptyStatus ).not.toHaveProperty( 'status' );
	} );

	it( 'coerces numeric page and perPage to strings', () => {
		const p = params( buildBoardQuery( BASE, { page: 2, perPage: 25 } ) );
		expect( p.page ).toBe( '2' );
		expect( p.per_page ).toBe( '25' );
	} );

	it( 'falls back to page=1 for zero or negative page', () => {
		expect( params( buildBoardQuery( BASE, { page: 0 } ) ).page ).toBe( '1' );
		expect( params( buildBoardQuery( BASE, { page: -5 } ) ).page ).toBe( '1' );
	} );

	it( 'falls back to per_page=10 for zero or negative perPage', () => {
		expect( params( buildBoardQuery( BASE, { perPage: 0 } ) ).per_page ).toBe( '10' );
		expect( params( buildBoardQuery( BASE, { perPage: -3 } ) ).per_page ).toBe( '10' );
	} );

	it( 'combines board, status, sort and pagination together', () => {
		const p = params(
			buildBoardQuery( BASE, {
				board: 'product',
				status: 'planned',
				sort: 'title',
				page: 2,
				perPage: 5,
			} )
		);
		expect( p ).toEqual( {
			orderby: 'title',
			order: 'asc',
			page: '2',
			per_page: '5',
			board: 'product',
			status: 'planned',
		} );
	} );
} );
