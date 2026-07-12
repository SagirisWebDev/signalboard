# Signalboard → Next.js webhook revalidation (reference)

A minimal reference for the receiving end of Signalboard's webhook revalidation
loop. Drop `app/api/revalidate/route.ts` into a Next.js (App Router) project as
the endpoint you configure on the plugin's **Signalboard → Webhooks** settings
page.

> This is a reference handler, not a full demo app. The complete deployed
> Next.js frontend is tracked separately (issue #8). The signing contract this
> handler relies on is unit-tested on the WordPress side in
> `tests/WebhookDispatcherTest.php`.

## How the loop works

1. In wp-admin, a request's roadmap status or publish state changes (e.g. an
   editor approves a pending submission, or moves it Planned → In Progress).
2. `WebhookDispatcher` POSTs a signed JSON payload to your configured endpoint.
3. This route recomputes the HMAC-SHA256 of the **raw** body and compares it
   (constant-time) to the `X-Signalboard-Signature` header. Mismatches → `401`.
4. On success it calls `revalidatePath()` for the affected pages, so the live
   site reflects the change immediately instead of waiting for the ISR interval.

## Payload

```json
{
  "event": "status_changed",
  "entity": { "id": 123, "type": "signalboard_request", "slug": "dark-mode" },
  "oldStatus": "planned",
  "newStatus": "in-progress"
}
```

| Header | Meaning |
| --- | --- |
| `X-Signalboard-Signature` | hex HMAC-SHA256 of the raw body (no `sha256=` prefix) |
| `X-Signalboard-Event` | `status_changed` or `publish_state_changed` |

## Setup

1. Copy `app/api/revalidate/route.ts` into your Next.js app (same path).
2. Set `SIGNALBOARD_WEBHOOK_SECRET` in the frontend's environment to the exact
   **Shared secret** from the plugin's Webhooks settings page.
3. On that settings page, set:
   - **Endpoint URL** → `https://your-frontend.vercel.app/api/revalidate`
   - **Allowed CORS origin** → `https://your-frontend.vercel.app` (lets the
     browser call the WordPress REST API cross-origin)
   - **Trigger events** → enable `status_changed` and/or `publish_state_changed`
   - **Enable webhooks** → on
4. Adjust `affectedPaths()` in the route to match your URL structure.

## Verifying the signature yourself

```js
import crypto from 'node:crypto';

const expected = crypto
  .createHmac( 'sha256', process.env.SIGNALBOARD_WEBHOOK_SECRET )
  .update( rawBody, 'utf8' )
  .digest( 'hex' );

// timing-safe compare `expected` to the X-Signalboard-Signature header
```

This is exactly what the plugin computes (`hash_hmac( 'sha256', $body, $secret )`),
so a matching secret yields a matching signature over an identical body.
