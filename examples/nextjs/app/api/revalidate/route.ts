/**
 * Reference Signalboard webhook receiver for a Next.js (App Router) frontend.
 *
 * Signalboard's WebhookDispatcher POSTs a signed JSON payload here whenever a
 * feedback request's roadmap status or publish state changes. This route
 * verifies the HMAC-SHA256 signature, then triggers on-demand revalidation of
 * the affected pages so the live site updates immediately — without waiting for
 * the ISR interval.
 *
 * Contract (must match the plugin's WebhookDispatcher):
 *   - Header `X-Signalboard-Signature`: hex HMAC-SHA256 of the RAW request body,
 *     keyed with the shared secret (no `sha256=` prefix).
 *   - Header `X-Signalboard-Event`: the event key (`status_changed` | `publish_state_changed`).
 *   - Body:
 *       {
 *         "event": "status_changed",
 *         "entity": { "id": 123, "type": "signalboard_request", "slug": "dark-mode" },
 *         "oldStatus": "planned",
 *         "newStatus": "in-progress"
 *       }
 *
 * Env: SIGNALBOARD_WEBHOOK_SECRET must equal the "Shared secret" configured on
 * the plugin's Webhooks settings page.
 */
import crypto from 'node:crypto';
import { revalidatePath } from 'next/cache';
import { NextResponse } from 'next/server';

const SIGNATURE_HEADER = 'x-signalboard-signature';

type WebhookPayload = {
	event: string;
	entity: { id: number; type: string; slug: string };
	oldStatus: string | null;
	newStatus: string | null;
};

/**
 * Constant-time comparison of the received signature against a fresh HMAC of
 * the raw body. Returns false on any length/format mismatch rather than throwing.
 */
function isValidSignature( rawBody: string, received: string, secret: string ): boolean {
	const expected = crypto
		.createHmac( 'sha256', secret )
		.update( rawBody, 'utf8' )
		.digest( 'hex' );

	const a = Buffer.from( expected, 'utf8' );
	const b = Buffer.from( received, 'utf8' );

	return a.length === b.length && crypto.timingSafeEqual( a, b );
}

/**
 * The paths a given request change should revalidate. Adjust to match your
 * route structure (board index, roadmap, and the request's own page).
 */
function affectedPaths( payload: WebhookPayload ): string[] {
	const paths = [ '/', '/roadmap' ];
	if ( payload.entity?.slug ) {
		paths.push( `/requests/${ payload.entity.slug }` );
	}
	return paths;
}

export async function POST( request: Request ): Promise< NextResponse > {
	const secret = process.env.SIGNALBOARD_WEBHOOK_SECRET;
	if ( ! secret ) {
		return NextResponse.json( { error: 'Webhook secret not configured.' }, { status: 500 } );
	}

	// The signature is computed over the RAW body, so read text (not json()).
	const rawBody = await request.text();
	const signature = request.headers.get( SIGNATURE_HEADER ) ?? '';

	if ( ! signature || ! isValidSignature( rawBody, signature, secret ) ) {
		return NextResponse.json( { error: 'Invalid signature.' }, { status: 401 } );
	}

	let payload: WebhookPayload;
	try {
		payload = JSON.parse( rawBody ) as WebhookPayload;
	} catch {
		return NextResponse.json( { error: 'Malformed payload.' }, { status: 400 } );
	}

	const revalidated = affectedPaths( payload );
	revalidated.forEach( ( path ) => revalidatePath( path ) );

	return NextResponse.json( { revalidated, event: payload.event } );
}
