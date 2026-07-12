/**
 * Pure helper: build the REST query URL for the board.
 *
 * Extracted so it can be unit-tested without a browser or store.
 *
 * @param {string} restUrl Base REST URL for the requests collection.
 * @param {Object} params  Query parameters.
 * @param {string} [params.board]   Board slug (empty = all boards).
 * @param {string} [params.sort]    Sort key: date | votes | title.
 * @param {string} [params.status]  Status slug (empty = all statuses).
 * @param {number} [params.page]    1-based page number.
 * @param {number} [params.perPage] Page size.
 * @return {string} The fully-qualified REST URL.
 */
export function buildBoardQuery(
	restUrl,
	{ board = '', sort = 'date', status = '', page = 1, perPage = 10 } = {}
) {
	const url = new URL( restUrl );
	url.searchParams.set( 'orderby', sort || 'date' );
	url.searchParams.set( 'order', sort === 'title' ? 'asc' : 'desc' );
	url.searchParams.set( 'page', String( page > 0 ? page : 1 ) );
	url.searchParams.set( 'per_page', String( perPage > 0 ? perPage : 10 ) );
	if ( board ) {
		url.searchParams.set( 'board', board );
	}
	if ( status ) {
		url.searchParams.set( 'status', status );
	}
	return url.toString();
}

/**
 * Pure helper: build the vote endpoint URL for a single request.
 *
 * @param {string}        restUrl Base REST URL for the requests collection.
 * @param {number|string} id      Request ID.
 * @return {string} The `${base}/${id}/vote` URL.
 */
export function buildVoteUrl( restUrl, id ) {
	const base = String( restUrl ).replace( /\/+$/, '' );
	return `${ base }/${ id }/vote`;
}
