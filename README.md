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

## Documentation

Architecture notes, data-flow diagram, and engineering-decision rationale will live in `docs/` as the build progresses.
