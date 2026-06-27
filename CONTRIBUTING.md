<!-- SPDX-FileCopyrightText: 2026 Touchpoint Contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Contributing

Thanks for helping improve Touchpoint!

## Development setup

```bash
make deps        # composer install + npm ci
make build       # build the frontend (src/ -> js/ + css/)
make deploy NEXTCLOUD_APPS=/path/to/nextcloud/apps   # install into a local Nextcloud
```

`js/` and `css/` are build outputs and are **not** committed — run `make build`
(or `npm run build`) after editing anything under `src/`, then `make deploy` to
push the change to your local Nextcloud.

## Before opening a PR

Run the quality gates (CI runs the same):

```bash
make lint        # eslint + phpstan
make test        # phpunit
make test-e2e    # playwright (needs a running Nextcloud at localhost)
```

## Conventions

- Backend: PHP 8.1+, only public `OCP\` APIs, parameterized QueryBuilder, and
  schema changes via a **new** `lib/Migration/VersionXXXXDate*.php` step (never
  edit a shipped migration).
- Frontend: Vue 3 + Pinia, `@nextcloud/vue` components and NC CSS variables,
  `vue-material-design-icons` (no emoji), and wrap user-facing strings in
  `t('touchpoint', ...)`.
- Keep Nextcloud 32–34 compatibility (see `CLAUDE.md` for the details and the
  app's intentional design decisions).
- Every source file carries an SPDX header; the project is
  [REUSE](https://reuse.software/)-compliant (`reuse lint` must pass).
- **Update docs in the same change.** Behaviour, structure, or contract changes
  must update the relevant docs (`README.md`, `CHANGELOG.md`, `docs/API.md`,
  `docs/ROADMAP.md`, `CLAUDE.md`) alongside the code — never as a follow-up.
  **API changes** (HTTP routes in `appinfo/routes.php`, OCS/REST routes,
  `OCA\Touchpoint\Event\*` events, the `window.OCA.Touchpoint` JS API, capability
  keys, webhook payloads, request/response shapes) must be reflected in
  [`docs/API.md`](docs/API.md); public-API contracts are versioned, never changed
  silently. See the "Keep documentation in sync" section in `CLAUDE.md`.

## Continuous integration

Three GitHub Actions workflows (`.github/workflows/`):

- **CI** (`ci.yml`) — on every push/PR: phpstan + phpunit across PHP 8.1/8.2/8.3/8.4,
  plus eslint + the production build.
- **Nightly** (`nightly.yml`) — daily (and on demand): builds the app and
  publishes a rolling `nightly` pre-release on GitHub.
- **Release** (`release.yml`) — on a `vX.Y.Z` tag: builds the tarball and creates
  a GitHub release.

Both Nightly and Release additionally publish to the **Nextcloud App Store**
*only if* these repository secrets are set (otherwise that step is skipped):

- `APP_PRIVATE_KEY` — the app signing certificate's **private key** (PEM). See
  the README's signing notes; never commit it.
- `APPSTORE_TOKEN` — your apps.nextcloud.com API token.

## Releasing

1. Bump the version in **`appinfo/info.xml`**, **`package.json`**, and the
   lockfile (`npm install --package-lock-only`), and update `CHANGELOG.md`.
2. Tag `vX.Y.Z` and push the tag — the Release workflow does the rest.
