# Signalboard

Headless-ready WordPress feature-voting, feedback & roadmap plugin, exposed over the WP REST API and WPGraphQL. A Sagiris plugin. GPLv2-or-later.

- **Namespace / PSR-4:** `Sagiris\Signalboard\` → `src/`
- **Text domain / prefix:** `signalboard`
- **Tests:** PHPUnit via `composer test` (needs the WP test suite — `bin/install-wp-tests.sh`). Lint via `composer lint` (PHPCS, WordPress Coding Standards).
- **Local dev:** `wp-env` (`.wp-env.json`).

## Agent skills

### Issue tracker

Issues and PRDs live in this repo's GitHub Issues (`SagirisWebDev/signalboard`), via the `gh` CLI. See `docs/agents/issue-tracker.md`.

### Triage labels

The five canonical triage roles map to identically-named labels (`needs-triage`, `needs-info`, `ready-for-agent`, `ready-for-human`, `wontfix`). See `docs/agents/triage-labels.md`.

### Domain docs

Single-context: one `CONTEXT.md` + `docs/adr/` at the repo root. See `docs/agents/domain.md`.

### Harness context

This repo is wired to the **wordpress** harness context (`.swd-harness/context/wordpress/`). Context-aware skills (e.g. `ralph`) load their overlay from `.swd-harness/context/wordpress/<skill>/CONTEXT.md`.
