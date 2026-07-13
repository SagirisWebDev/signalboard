# Signalboard

**Feature Voting, Feedback & Roadmap for WordPress** — a headless-ready plugin that collects feature requests, lets visitors upvote them, and publishes a public product roadmap over both the **WP REST API** and **WPGraphQL**, with webhook-driven revalidation for headless frontends.

A [Sagiris](https://sagirisdev.com) plugin. GPLv2-or-later.

> 🚧 **Status:** in development. The v1.0 product spec lives in the [`ready-for-agent` PRD issue](../../issues).

## Overview

- **REST API backbone** — versioned, permission-guarded, validated routes under `signalboard/v1`; zero third-party dependencies.
- **WPGraphQL add-on layer** — types, connections, and mutations that auto-activate only when WPGraphQL is installed.
- **Hybrid identity** — anonymous, deduplicated, rate-limited upvoting; authenticated submission via a self-contained JWT register/login/refresh flow (plus core Application Passwords for machine access).
- **Interactive block + shortcode** — render and interact with the board on any WordPress site.
- **Headless demo** — a Next.js 15 (App Router) reference frontend consuming the GraphQL layer with typed queries, updated instantly via HMAC-signed webhooks and on-demand revalidation.

## Authentication

Signalboard ships a **self-contained JWT flow** so a headless frontend can register, log in, and refresh without any companion plugin:

- `POST /wp-json/signalboard/v1/auth/register` — `{ username, email, password }` → `{ accessToken, refreshToken, tokenType, expiresIn }`
- `POST /wp-json/signalboard/v1/auth/login` — `{ username, password }` → tokens (per-IP rate-limited to throttle brute force)
- `POST /wp-json/signalboard/v1/auth/refresh` — `{ refreshToken }` → a fresh token pair

The same operations exist as WPGraphQL mutations (`login`, `register`, `refreshToken`) when WPGraphQL is active. Send the access token as `Authorization: Bearer <token>`; it resolves the current user for **both** REST and GraphQL via a late `determine_current_user` filter that never overrides an already-authenticated request — so **core [Application Passwords](https://make.wordpress.org/core/2020/11/05/application-passwords-integration-guide/)** and cookie auth keep working for machine/admin access.

Tokens are HS256-signed with a per-site secret (auto-generated, stored in the `signalboard_jwt_secret` option, overridable via the `signalboard_jwt_secret` filter — e.g. to source it from `wp-config.php`). Decoding always pins the algorithm, which is the correct usage that avoids JWT algorithm-confusion pitfalls.

### When to use a dedicated auth plugin instead

The built-in flow keeps this plugin dependency-free and is ideal for demos and single-app frontends. For richer needs, prefer a purpose-built solution:

- **[`wp-graphql-jwt-authentication`](https://github.com/wp-graphql/wp-graphql-jwt-authentication)** — if you're all-in on WPGraphQL and want JWT auth wired into the GraphQL schema with token revocation via a per-user secret.
- **[`wp-graphql-headless-login`](https://github.com/AxeWP/wp-graphql-headless-login)** — if you need **social / OAuth2 / OIDC** providers (Google, GitHub, etc.) or SIWE, with a configurable login UI and multiple auth strategies.
- **The WordPress 7.0 [Abilities API](https://make.wordpress.org/core/tag/abilities-api/)** — once available, for capability-style authorization that MCP/AI agents and core can introspect; pair it with an auth transport rather than reimplementing permissions.

## Documentation

- **[Architecture](docs/ARCHITECTURE.md)** — the layered overview, a Mermaid data-flow diagram, and the "why I built it this way" engineering-decision rationale.
- **[`readme.txt`](readme.txt)** — the wp.org-format plugin readme (description, FAQ, screenshots, changelog).
- **[`examples/nextjs/`](examples/nextjs/)** — a reference Next.js revalidation route that verifies the HMAC-signed webhook.

The public board and status-grouped roadmap are consumed by a headless **Next.js** demo over the **WPGraphQL** layer; every surface reads through the same model exposed over the **REST API** and WPGraphQL.
