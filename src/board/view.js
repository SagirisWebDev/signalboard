/**
 * Frontend interactivity for the Signalboard board block.
 *
 * Server renders the initial page (SEO / no-JS); this store enhances it with
 * live filter, sort and pagination by consuming the signalboard/v1 REST route.
 */
import { store, getContext } from '@wordpress/interactivity';
import { buildBoardQuery } from './query';

const { state, actions } = store( 'signalboard/board', {
	actions: {
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
				ctx.items = items.map( ( item ) => ( {
					id: item.id,
					title: item.title,
					voteCount: item.voteCount,
					status: item.status,
					statusLabel: item.statusLabel,
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
