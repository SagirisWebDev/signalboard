=== Signalboard ===
Contributors: sagiris
Tags: feedback, roadmap, feature-requests, voting, headless
Requires at least: 6.4
Tested up to: 6.8
Requires PHP: 8.1
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Headless-ready feature voting, feedback, and product roadmap for WordPress, exposed over the REST API and WPGraphQL.

== Description ==

Signalboard collects feature requests, lets visitors upvote them, and publishes a public product roadmap — exposed over **both** the WordPress REST API and **WPGraphQL**, so it works equally well inside a classic WordPress theme or as the backend for a headless frontend.

It is built as a set of small, single-responsibility modules behind one shared read model, so the REST controller, the GraphQL resolvers, and the editor block all serve identical data and can never drift.

**Highlights**

* **REST API backbone** — versioned, permission-guarded, validated routes under `signalboard/v1`, with zero third-party dependencies.
* **WPGraphQL add-on layer** — types, connections, and mutations that auto-activate only when WPGraphQL is installed.
* **Hybrid identity** — anonymous, deduplicated, rate-limited upvoting; authenticated submission via a self-contained JWT register / login / refresh flow (core Application Passwords keep working for machine access).
* **Moderation queue** — a native admin list table where pending submissions are approved, rejected, or trashed and roadmap statuses are set, all capability-guarded and nonce-protected.
* **Interactive block + shortcode** — render and interact with the board on any WordPress site via the Interactivity API.
* **Webhook revalidation** — an HMAC-SHA256-signed POST fires when a request's status or publish state changes, so a headless frontend can revalidate the affected pages on demand.
* **Headless demo** — a Next.js reference frontend consumes the GraphQL layer with typed queries. A reference revalidation route ships in `examples/nextjs/`.

Architecture notes, a data-flow diagram, and the engineering-decision rationale live in `docs/ARCHITECTURE.md`.

== Installation ==

1. Upload the `signalboard` folder to `/wp-content/plugins/`, or install the plugin through the **Plugins** screen in WordPress.
2. Activate the plugin through the **Plugins** screen. Activation registers the `signalboard_request` post type, seeds the default roadmap statuses, and creates the votes table.
3. Add the **Signalboard Board** block (or the `[signalboard_board]` shortcode) to any page to render the board.
4. (Optional) Install and activate **WPGraphQL** to expose the GraphQL types and mutations automatically.
5. (Optional) Visit **Signalboard → Webhooks** to point a headless frontend at the signed revalidation webhook.

== Frequently Asked Questions ==

= Does it require WPGraphQL? =

No. The REST API works on its own with zero third-party dependencies. The GraphQL layer activates automatically only when WPGraphQL is installed, so the plugin is fully functional either way.

= Do visitors need an account to vote? =

No. Upvoting is open to anonymous visitors, with per-visitor deduplication and per-IP rate limiting to control abuse. Submitting a new request does require a logged-in user; new submissions enter a pending state for moderation.

= How does authentication work for a headless frontend? =

Signalboard ships a self-contained JWT flow (register / login / refresh) over both REST and WPGraphQL, so a headless app can authenticate without a companion plugin. Tokens are HS256-signed with a per-site secret and always decoded with the algorithm pinned. Core Application Passwords and cookie auth continue to work for machine and admin access.

= How does the webhook stay secure? =

Each webhook body is signed with HMAC-SHA256 using a shared secret you configure. The receiver recomputes the signature over the raw body and rejects any mismatch. A reference Next.js route that does exactly this ships in `examples/nextjs/`.

= Where is the roadmap status set? =

In the admin **Moderation** queue. Approving a pending request publishes it to the board; changing its status (for example Planned or Complete) drives the public roadmap and fires the revalidation webhook.

== Screenshots ==

1. The public board rendered by the Signalboard block, with live filter, sort, and upvoting.
2. The status-grouped roadmap view.
3. The admin moderation queue (approve / reject / trash and status management).
4. The Webhooks settings page (endpoint, shared secret, and triggering events).
5. The headless Next.js demo consuming the WPGraphQL layer.

== Changelog ==

= 0.1.0 =
Initial release. Shipped as vertical slices:

* Plugin skeleton and browse requests over REST and WPGraphQL.
* Read-only board block and shortcode via the Interactivity API.
* Anonymous upvoting with per-visitor dedup and per-IP rate limiting.
* Self-contained JWT authentication over REST and WPGraphQL.
* Authenticated submission plus a "my submissions" read.
* Admin moderation queue and roadmap status management.
* Minimal webhook revalidation — a signed POST on request change, with a reference Next.js receiver.

== Upgrade Notice ==

= 0.1.0 =
Initial release.
