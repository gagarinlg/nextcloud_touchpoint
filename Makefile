# SPDX-FileCopyrightText: 2026 Touchpoint Contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# Build / test / package / deploy for the Touchpoint Nextcloud app.

app_name = touchpoint
project_dir = $(CURDIR)
build_dir = $(project_dir)/build
appstore_dir = $(build_dir)/appstore
package_name = $(appstore_dir)/$(app_name)
# Where to deploy the runnable app. Override on the CLI, e.g.:
#   make deploy NEXTCLOUD_APPS=/var/www/html/apps
NEXTCLOUD_APPS ?= /var/www/html/apps

# Files/dirs that make up the *runnable* app (everything else is dev tooling).
# Mirrors .nextcloudignore (used as the rsync exclude list below).
rsync_exclude = --exclude-from=$(project_dir)/.nextcloudignore

.PHONY: all
all: build

# --- dependencies ---------------------------------------------------------
.PHONY: deps
deps:
	composer install --prefer-dist
	npm ci

.PHONY: deps-prod
deps-prod:
	npm ci

# --- build ----------------------------------------------------------------
.PHONY: build
build:
	npm run build

# --- quality gates --------------------------------------------------------
.PHONY: lint
lint:
	npm run lint
	composer exec -- phpstan analyse --no-progress

# Fails if any l10n/<lang>.json and l10n/<lang>.js pair disagree on key set or
# values — .json is what Nextcloud's server-side IL10N actually loads at
# runtime, .js is a separate client-side-only artifact; the two are
# hand-maintained with no shared generation step, so drift between them has
# repeatedly shipped silently untranslated strings (see CLAUDE.md).
.PHONY: check-l10n
check-l10n:
	node scripts/check-l10n.js

.PHONY: test
test:
	./vendor/bin/phpunit

.PHONY: test-e2e
test-e2e:
	npx playwright test

.PHONY: check
check: lint check-l10n test

# --- documentation --------------------------------------------------------
# Regenerates the auto-generated inventory block in docs/ARCHITECTURE.md from
# the source tree. CI runs `docs-check` and fails if the committed file drifts.
.PHONY: docs
docs:
	php scripts/gen-docs.php

.PHONY: docs-check
docs-check:
	php scripts/gen-docs.php --check

# --- packaging (App Store tarball) ----------------------------------------
# Produces build/appstore/touchpoint.tar.gz containing only the runnable app
# (no node_modules/.git/tests/src/dev configs — see .nextcloudignore).
.PHONY: appstore
appstore: build
	rm -rf $(appstore_dir)
	mkdir -p $(package_name)
	rsync -a $(rsync_exclude) $(project_dir)/ $(package_name)/
	tar -czf $(package_name).tar.gz -C $(appstore_dir) $(app_name)
	@echo "Built $(package_name).tar.gz"

# --- deploy to a local Nextcloud --------------------------------------------
# Syncs the runnable app into $(NEXTCLOUD_APPS)/touchpoint as a REAL directory
# (no symlink to the dev tree, so the Nextcloud updater's backup stays small).
# Run with privileges that can write there + chown, e.g.  sudo make deploy
.PHONY: deploy
deploy: build
	rm -rf $(build_dir)/$(app_name)
	mkdir -p $(build_dir)/$(app_name)
	rsync -a $(rsync_exclude) $(project_dir)/ $(build_dir)/$(app_name)/
	mkdir -p $(NEXTCLOUD_APPS)
	rm -rf $(NEXTCLOUD_APPS)/$(app_name)
	cp -a $(build_dir)/$(app_name) $(NEXTCLOUD_APPS)/$(app_name)
	@echo "Deployed $(app_name) to $(NEXTCLOUD_APPS)/$(app_name)"
	@echo "If the web-server user differs, run: chown -R www-data:www-data $(NEXTCLOUD_APPS)/$(app_name)"

# --- housekeeping ---------------------------------------------------------
.PHONY: clean
clean:
	rm -rf $(build_dir) js css

.PHONY: distclean
distclean: clean
	rm -rf node_modules vendor
