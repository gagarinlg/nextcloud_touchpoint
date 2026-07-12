#!/usr/bin/env node
// SPDX-FileCopyrightText: 2026 Touchpoint Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Fails if l10n/<lang>.json and l10n/<lang>.js disagree on their key set or
// any translated value, for every language pair present under l10n/. The two
// formats are hand-maintained (see CLAUDE.md) with no generation step tying
// them together, so nothing else catches this class of drift — Nextcloud's
// server-side IL10N::t() only ever reads the .json file (never the .js file,
// which exists solely for @nextcloud/l10n's client-side runtime), so a key
// present only in .js silently renders in English for every non-English user
// on any server-side code path (notifications, dashboard widgets, translated
// exception messages).

'use strict';

const fs = require('fs');
const path = require('path');

const l10nDir = path.join(__dirname, '..', 'l10n');

// @nextcloud/l10n's translatePlural() (and OCP\IL10N::n() server-side) never
// look up a plural-shaped entry by its flat singular key — they build a
// combined identifier "_" + textSingular + "_::_" + textPlural + "_" and look
// THAT up in the catalog (see node_modules/@nextcloud/l10n/dist/chunks/
// translation-*.mjs). An array-valued (plural) entry keyed by anything else
// is unreachable at runtime: n=1 renders correctly only by the accident of
// translate()'s array[0] fallback on the flat key; every other count falls
// through to the raw English source string. Nextcloud/gettext catalogs
// (core apps, e.g. nextcloud/activity's l10n/de.json) always use this
// combined-key format for plural entries.
function isCombinedPluralKey(key) {
	return key.startsWith('_') && key.endsWith('_') && key.includes('_::_');
}

function parseJsCatalog(file) {
	const src = fs.readFileSync(file, 'utf8');
	let translations = null;
	const OC = {
		L10N: {
			register(_app, translationsArg) {
				translations = translationsArg;
			},
		},
	};
	// eslint-disable-next-line no-new-func -- trusted, repo-local l10n file, not external input
	new Function('OC', src)(OC);
	if (translations === null) {
		throw new Error(`${file}: OC.L10N.register() was never called`);
	}
	return translations;
}

function parseJsonCatalog(file) {
	const data = JSON.parse(fs.readFileSync(file, 'utf8'));
	return data.translations || {};
}

const jsFiles = fs.readdirSync(l10nDir).filter((f) => f.endsWith('.js'));
let failed = false;

for (const jsFile of jsFiles) {
	const lang = jsFile.slice(0, -'.js'.length);
	const jsonFile = `${lang}.json`;
	const jsPath = path.join(l10nDir, jsFile);
	const jsonPath = path.join(l10nDir, jsonFile);

	if (!fs.existsSync(jsonPath)) {
		console.error(`FAIL: ${jsonFile} does not exist but ${jsFile} does`);
		failed = true;
		continue;
	}

	let jsData;
	let jsonData;
	try {
		jsData = parseJsCatalog(jsPath);
		jsonData = parseJsonCatalog(jsonPath);
	} catch (e) {
		console.error(`FAIL: could not parse ${lang} catalogs: ${e.message}`);
		failed = true;
		continue;
	}

	const jsKeys = new Set(Object.keys(jsData));
	const jsonKeys = new Set(Object.keys(jsonData));

	const missingInJson = [...jsKeys].filter((k) => !jsonKeys.has(k));
	const missingInJs = [...jsonKeys].filter((k) => !jsKeys.has(k));
	const valueDiffs = [...jsKeys].filter(
		(k) => jsonKeys.has(k) && JSON.stringify(jsData[k]) !== JSON.stringify(jsonData[k]),
	);
	const malformedPluralKeys = [...jsKeys, ...jsonKeys].filter(
		(k, i, arr) => arr.indexOf(k) === i
			&& (Array.isArray(jsData[k]) || Array.isArray(jsonData[k]))
			&& !isCombinedPluralKey(k),
	);

	if (missingInJson.length || missingInJs.length || valueDiffs.length || malformedPluralKeys.length) {
		failed = true;
		console.error(`FAIL: ${lang}.json and ${lang}.js disagree:`);
		if (missingInJson.length) {
			console.error(`  Missing in ${lang}.json (present in ${lang}.js):`);
			missingInJson.forEach((k) => console.error(`    ${JSON.stringify(k)}`));
		}
		if (missingInJs.length) {
			console.error(`  Missing in ${lang}.js (present in ${lang}.json):`);
			missingInJs.forEach((k) => console.error(`    ${JSON.stringify(k)}`));
		}
		if (valueDiffs.length) {
			console.error('  Value differs between the two files:');
			valueDiffs.forEach((k) =>
				console.error(
					`    ${JSON.stringify(k)}: js=${JSON.stringify(jsData[k])} json=${JSON.stringify(jsonData[k])}`,
				),
			);
		}
		if (malformedPluralKeys.length) {
			console.error(
				'  Plural (array-valued) entries not keyed in "_singular_::_plural_" format'
				+ ' (unreachable by translatePlural()/IL10N::n() for any count other than 1):',
			);
			malformedPluralKeys.forEach((k) => console.error(`    ${JSON.stringify(k)}`));
		}
	}
}

if (failed) {
	console.error('\nl10n/*.json and l10n/*.js must have identical key sets and values.');
	process.exit(1);
}

console.log(`OK: ${jsFiles.length} l10n language pair(s) are in sync.`);
