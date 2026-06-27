<!-- SPDX-FileCopyrightText: 2026 CRM Notes Contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Contributing

Thanks for helping improve CRM Notes!

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

- Backend: PHP 8.2+, only public `OCP\` APIs, parameterized QueryBuilder, and
  schema changes via a **new** `lib/Migration/VersionXXXXDate*.php` step (never
  edit a shipped migration).
- Frontend: Vue 3 + Pinia, `@nextcloud/vue` components and NC CSS variables,
  `vue-material-design-icons` (no emoji), and wrap user-facing strings in
  `t('crm_notes', ...)`.
- Keep Nextcloud 32–34 compatibility (see `CLAUDE.md` for the details and the
  app's intentional design decisions).
- Every source file carries an SPDX header; the project is
  [REUSE](https://reuse.software/)-compliant (`reuse lint` must pass).

## Releasing

Tag a version (`vX.Y.Z`) and push it; the release workflow builds and attaches a
clean app tarball. Bump the version in **both** `appinfo/info.xml` and
`package.json` first, and update `CHANGELOG.md`.
