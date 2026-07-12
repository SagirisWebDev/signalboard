/**
 * Frontend interactivity for the Signalboard board block.
 *
 * Server renders the initial page (SEO / no-JS); this store enhances it with
 * live filter, sort and pagination by consuming the signalboard/v1 REST route.
 */
import { store, getContext } from '@wordpress/interactivity';
import { buildBoardQuery, buildVoteUrl } from './query';

const TOKEN_KEY = 'signalboardVoteToken';
const VOTED_KEY = 'signalboardVotedIds';

/**
 * Persistent per-visitor token, sent as X-Signalboard-Token so the server can
 * fold it into the voter fingerprint alongside the IP.
 *
 * @return {string} The token.
 */
function voteToken() {
	try {
		let token = window.localStorage.getItem( TOKEN_KEY );
		if ( ! token ) {
			token =
				typeof window.crypto?.randomUUID === 'function'
					? window.crypto.randomUUID()
					: `sb-${ Date.now() }-${ Math.round(
							Math.random() * 1e9
					  ) }`;
			window.localStorage.setItem( TOKEN_KEY, token );
		}
		return token;
	} catch ( error ) {
		return '';
	}
}

/**
 * Read the set of request ids this visitor has voted on.
 *
 * @return {Set<number>} Voted request ids.
 */
function votedIds() {
	try {
		return new Set(
			JSON.parse( window.localStorage.getItem( VOTED_KEY ) || '[]' )
		);
	} catch ( error ) {
		return new Set();
	}
}

/**
 * Persist the set of voted request ids.
 *
 * @param {Set<number>} ids Voted request ids.
 */
function persistVotedIds( ids ) {
	try {
		window.localStorage.setItem( VOTED_KEY, JSON.stringify( [ ...ids ] ) );
	} catch ( error ) {
		// Storage unavailable (private mode); optimistic UI still works in-session.
	}
}

const { state, actions } = store( 'signalboard/board', {
	actions: {
		*upvote() {
			const ctx = getContext();
			const item = ctx.item;
			const voted = votedIds();
			const wasVoted = voted.has( item.id );

			// Optimistic update: flip the button and adjust the visible count.
			item.voted = ! wasVoted;
			item.voteCount += wasVoted ? -1 : 1;

			const url = buildVoteUrl( state.restUrl, item.id );
			try {
				const response = yield fetch( url, {
					method: wasVoted ? 'DELETE' : 'POST',
					headers: { 'X-Signalboard-Token': voteToken() },
				} );
				if ( ! response.ok ) {
					throw new Error( 'vote request failed' );
				}
				const data = yield response.json();
				// Reconcile with the authoritative server count/state.
				item.voteCount = data.voteCount;
				item.voted = data.voted;
				if ( data.voted ) {
					voted.add( item.id );
				} else {
					voted.delete( item.id );
				}
				persistVotedIds( voted );
			} catch ( error ) {
				// Revert the optimistic change on failure.
				item.voted = wasVoted;
				item.voteCount += wasVoted ? 1 : -1;
			}
		},
		*setStatus( event ) {
			const ctx = getContext();
			ctx.status = event.target.value;
			ctx.page = 1;
			yield actions.load();
		},
		*setSort( event ) {
			const ctx = getContext();
			ctx.sort = event.target.value;
			ctx.page = 1;
			yield actions.load();
		},
		*prevPage() {
			const ctx = getContext();
			if ( ctx.page > 1 ) {
				ctx.page -= 1;
				yield actions.load();
			}
		},
		*nextPage() {
			const ctx = getContext();
			if ( ctx.page < ctx.totalPages ) {
				ctx.page += 1;
				yield actions.load();
			}
		},
		*load() {
			const ctx = getContext();
			ctx.loading = true;
			const url = buildBoardQuery( state.restUrl, {
				board: ctx.board,
				sort: ctx.sort,
				status: ctx.status,
				page: ctx.page,
				perPage: ctx.perPage,
			} );
			try {
				const response = yield fetch( url );
				const totalPages = parseInt(
					response.headers.get( 'X-WP-TotalPages' ) || '1',
					10
				);
				const items = yield response.json();
				const voted = votedIds();
				ctx.items = items.map( ( item ) => ( {
					id: item.id,
					title: item.title,
					voteCount: item.voteCount,
					status: item.status,
					statusLabel: item.statusLabel,
					voted: voted.has( item.id ),
				} ) );
				ctx.totalPages = totalPages > 0 ? totalPages : 1;
				ctx.hasItems = ctx.items.length > 0;
				ctx.singlePage = ctx.totalPages <= 1;
				ctx.hasPrev = ctx.page > 1;
				ctx.hasNext = ctx.page < ctx.totalPages;
			} catch ( error ) {
				// Keep the previously rendered items on a failed request.
			}
			ctx.loading = false;
		},
	},
} );
