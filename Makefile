# SPDX-FileCopyrightText: 2026 CRM Notes Contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# Build / test / package / deploy for the CRM Notes Nextcloud app.

app_name = crm_notes
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

.PHONY: test
test:
	./vendor/bin/phpunit

.PHONY: test-e2e
test-e2e:
	npx playwright test

.PHONY: check
check: lint test

# --- packaging (App Store tarball) ----------------------------------------
# Produces build/appstore/crm_notes.tar.gz containing only the runnable app
# (no node_modules/.git/tests/src/dev configs — see .nextcloudignore).
.PHONY: appstore
appstore: build
	rm -rf $(appstore_dir)
	mkdir -p $(package_name)
	rsync -a $(rsync_exclude) $(project_dir)/ $(package_name)/
	tar -czf $(package_name).tar.gz -C $(appstore_dir) $(app_name)
	@echo "Built $(package_name).tar.gz"

# --- deploy to a local Nextcloud --------------------------------------------
# Syncs the runnable app into $(NEXTCLOUD_APPS)/crm_notes as a REAL directory
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
