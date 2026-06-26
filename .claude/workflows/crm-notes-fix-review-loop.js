export const meta = {
  name: 'crm-notes-fix-review-loop',
  description: 'Re-review the whole app (grumpy dev + grumpy UX + foul-mood NC app-store reviewer), fix every finding with the best fix, verify build/lint/tests/phpstan, commit each round, loop until clean',
  phases: [
    { title: 'Review' },
    { title: 'Fix' },
    { title: 'Verify' },
    { title: 'Commit' },
  ],
}

const APP_DIR = '/home/gagarin/code/notes'
const MAX_ROUNDS = 8

const COMPAT = `COMPATIBILITY CONSTRAINTS (do not break these):
- App must remain compatible with Nextcloud 32, 33 AND 34. appinfo/info.xml declares <nextcloud min-version="32" max-version="34" />.
- Do NOT use any server API introduced after NC 32. AppFramework security attributes (#[NoAdminRequired], #[NoCSRFRequired], #[PublicPage]) exist since NC 27 and ARE safe across 32-34 — migrating legacy @NoAdminRequired/@NoCSRFRequired PHPDoc to PHP attributes is encouraged. Array-based appinfo/routes.php is fine; do not break existing route names.
- PHP 8.2-8.5. Use OCP interfaces, dependency injection, parameterized QueryBuilder queries.
- MIGRATIONS: the schema baseline is a single consolidated migration lib/Migration/Version1000Date20260626120000.php. NEVER edit it (it has shipped). Any NEW schema change MUST be a NEW higher-versioned step, e.g. lib/Migration/Version1001Date<today>*.php. Do not re-add the old per-step migrations that were consolidated away.
- Markdown is rendered via marked + DOMPurify; keep sanitization. Wrap user-facing strings in t('crm_notes', ...). Prefer @nextcloud/vue components and NC CSS variables. Use vue-material-design-icons, not emoji.
- GLOBAL DEFAULT NOTE TYPES ARE INTENTIONAL — DO NOT REMOVE OR REVERT THEM. A single shared set of default note types is stored with an EMPTY user_id ('') and is_default = true. NoteTypeMapper read queries (findAll/findById) OR-match this global set via readScope(); mutations (update/delete) use owner-only findOwnedById() and can never touch globals. This is SAFE and does NOT reintroduce a cross-user IDOR: only the '' sentinel (which no real user has) is shared, alongside the caller's own user_id. Do NOT make note types strictly owner-scoped again, do NOT remove the is_default OR-match, do NOT change seedDefaults() back to per-user copies. If you think it's an IDOR, re-read NoteTypeMapper::readScope().
- CONTACT PHOTO HANDLING IS INTENTIONAL — DO NOT REGRESS IT. ContactController::extractPhotoForUid reads the embedded vCard PHOTO straight from the dav "cards" table via IDBConnection QueryBuilder, scoped with an IN filter to ONLY the address-book ids the current user can read (IManager::getUserAddressBooks()). This is deliberate and correct: IManager::search() hands an embedded photo back as a "VALUE=uri:" reference to Nextcloud's own CardDAV photo export (which must NOT be HTTP-fetched — SSRF/auth), and there is no OCP API to fetch a contact's raw vCard by UID. The accessible-addressbook-id scoping is the access control, so this is NOT an IDOR. entryHasPhoto deliberately recognises that CardDAV-export reference so the list only advertises a servable photo. Do NOT revert to a search()-based photo fetch (that breaks photos for imported contacts), and do NOT remove the address-book scoping. A reviewer MAY note the cross-app read of the dav "cards" table as a coupling trade-off, but the fix must preserve photo rendering AND the access scoping (keep the IDBConnection approach with the addressbook-id filter) — never weaken either.`

const REVIEW_SCHEMA = {
  type: 'object',
  additionalProperties: false,
  properties: {
    findings: {
      type: 'array',
      items: {
        type: 'object',
        additionalProperties: false,
        properties: {
          severity: { type: 'string', enum: ['CRITICAL', 'HIGH', 'MEDIUM', 'LOW', 'NITPICK'] },
          title: { type: 'string' },
          location: { type: 'string', description: 'file:line' },
          why: { type: 'string' },
          fix: { type: 'string' },
        },
        required: ['severity', 'title', 'location', 'why', 'fix'],
      },
    },
    verdict: { type: 'string' },
  },
  required: ['findings', 'verdict'],
}

const VERIFY_SCHEMA = {
  type: 'object',
  additionalProperties: false,
  properties: {
    buildOk: { type: 'boolean' },
    lintOk: { type: 'boolean' },
    testsOk: { type: 'boolean' },
    failures: { type: 'array', items: { type: 'string' } },
    summary: { type: 'string' },
  },
  required: ['buildOk', 'lintOk', 'testsOk', 'failures', 'summary'],
}

const renderFindings = (list) =>
  list.length === 0
    ? '(none)'
    : list.map((f, i) => `${i + 1}. [${f.severity}] ${f.title}\n   where: ${f.location}\n   why: ${f.why}\n   fix: ${f.fix}`).join('\n')

// Convergence requires ZERO findings of ANY severity, nitpicks included.
const blocking = (findings) => findings.filter((f) => ['CRITICAL', 'HIGH', 'MEDIUM', 'LOW', 'NITPICK'].includes(f.severity))

const devReviewPrompt = (round) =>
  `You are a GRUMPY, SENIOR FULL-STACK/BACKEND DEVELOPER reviewing the ENTIRE Nextcloud app at ${APP_DIR} from scratch (not just a diff). Review all PHP in lib/, appinfo/routes.php, appinfo/info.xml, and JS logic in src/services, src/stores, src/utils, src/main.js, src/contacts-integration.js, src/adminSettings.js. Hunt for: security (authz/IDOR/injection/XSS/CSRF/path traversal), correctness bugs, data-layer/migration issues, error handling, architecture. ${COMPAT}

You are still grumpy and exacting, but you ONLY report GENUINE, actionable defects backed by a concrete file:line. Do NOT invent issues, do NOT report pure stylistic preference as a defect, and do NOT re-report something that is actually handled correctly. If the code is genuinely solid, grudgingly return an EMPTY findings array and say so in the verdict. Read the real files.`

const uxReviewPrompt = (round) =>
  `You are a NITPICKY, GRUMPY, SENIOR UX DESIGNER reviewing the ENTIRE frontend of the Nextcloud app at ${APP_DIR} from scratch. Review all src/components/*.vue, src/App.vue, css/style.css, templates/*.php, src/utils/color.js, and src/contacts-integration.js. Hunt for: Nextcloud design-system violations (hardcoded colors/px vs NC CSS vars, non-native controls vs @nextcloud/vue), accessibility (aria-labels on icon-only buttons, keyboard operability, focus management, contrast), missing empty/loading/error states, destructive-action confirmation, microcopy/i18n (untranslated strings, number+fragment concatenation), responsive/overflow, consistency.

You are still nitpicky and sigh a lot, but you ONLY report GENUINE, actionable defects backed by a concrete file:line. Do NOT invent issues or report subjective taste as a defect. If it genuinely follows the Nextcloud design guidelines and is accessible, grudgingly return an EMPTY findings array and say so in the verdict. Read the real files.`

const complianceReviewPrompt = (round) =>
  `You are a SEASONED, EXPERIENCED NEXTCLOUD APP-STORE REVIEWER and you are in a FOUL mood: the last 20 apps you reviewed were garbage, your patience is gone, and you will find and point out EVERYTHING that fails to conform to the rules for getting an app accepted on apps.nextcloud.com. Review the ENTIRE app at ${APP_DIR}. Read the real files: appinfo/info.xml, appinfo/routes.php, composer.json, package.json, all of lib/**, templates/**, src/**, and check for a LICENSES/ directory and SPDX headers on source files. ${COMPAT}

Enforce these REPO-ENFORCEABLE acceptance rules and report EVERY violation as a finding (file:line or filename, the rule, and a concrete fix). Be exhaustive and brutal:

METADATA — appinfo/info.xml: id lowercase ASCII+underscore equal to the top-level folder; name(en) present; description present; summary <=256 chars; version semantic WITHOUT build metadata; licence a valid SPDX id (AGPL-3.0-or-later is fine); author present; bugs REQUIRED (bug-tracker URL); dependencies/nextcloud MUST have BOTH min-version AND max-version; namespace REQUIRED (array routes.php); category from the allowed list; screenshot/website/repository/discussion (if present) valid https URLs <=256 chars.

LICENSING / REUSE: source files SHOULD carry SPDX headers (SPDX-FileCopyrightText + SPDX-License-Identifier matching the declared licence). Flag PHP/JS/Vue/CSS files lacking them. A LICENSES/ directory with full license text (REUSE) must exist; .license sidecars for files that cannot carry headers.

SECURITY: justify every #[NoCSRFRequired]/@NoCSRFRequired; every controller method MUST have correct access-control AND additionally check the user's rights for the action; parameterized QueryBuilder only (no string-concatenated SQL); templates escape output (p()); no unsanitized v-html; no user input to include/require/eval/fopen/shell; only public OCP\\ API, never private OC\\ internals (flag any "use OC\\" non-OCP import); no hard-coded secrets.

PACKAGING: app name MUST NOT contain "Nextcloud"; migrations/repair steps handle install AND clean uninstall; app runs across NC 32-34.

IMPORTANT SCOPING (keep this convergent): Do NOT report submission-PROCESS-only items (code-signing cert, signature.json, hosting screenshots, ToS, certificate PR) as findings — list them ONLY in the 'verdict' field. Do NOT flag a .git directory, bundled node_modules/ or vendor/. Do NOT flag id-vs-folder-name mismatch: the repo dir is 'notes' but it is DEPLOYED via symlink as 'crm_notes' (/var/www/html/apps/crm_notes -> this repo), so the effective folder name equals the id 'crm_notes' — treat that rule as SATISFIED. Report ONLY violations FIXABLE in the source tree as findings. If the app genuinely conforms on every enforceable rule, grudgingly return an EMPTY findings array and say so in the verdict.`

const fixPrompt = (round, dev, ux, compliance, verifyFailures) => {
  const verifyNote = verifyFailures.length
    ? `\n\nThe build/lint/test/phpstan verification is currently FAILING with:\n- ${verifyFailures.join('\n- ')}\nFix these too.`
    : ''
  const firstRoundNote =
    round === 1
      ? `\n\nKNOWN STARTING STATE: tests/Unit/Controller/ContactControllerTest.php currently fails (ArgumentCountError) because ContactController::__construct gained a 5th dependency (IDBConnection $db) for the new photo-from-vCard path. Update that test's instantiation to inject a mocked IDBConnection, and ADD coverage for the new behavior: extractPhotoForUid reading an embedded base64 PHOTO from the cards table scoped to the user's accessible address books, and entryHasPhoto recognising a "VALUE=uri:.../remote.php/dav/....vcf?photo" CardDAV-export reference as servable while refusing arbitrary external URIs.`
      : ''
  return `You are a SENIOR FULL-STACK DEVELOPER fixing a Nextcloud app at ${APP_DIR}. Apply REAL, complete fixes for EVERY finding below — including LOW and NITPICK. Do not stub, do not leave TODOs, do not skip the hard ones. Read each cited file before editing it.

CRITICAL DIRECTIVE — ALWAYS CHOOSE THE BEST FIX, NEVER THE EASIEST. For every finding, fix the ROOT CAUSE, not the symptom. Prefer the correct, idiomatic, maintainable solution even if it is more work (push pagination into SQL rather than slicing in PHP; add a proper migration + validation rather than truncating input; use the real @nextcloud/vue / @nextcloud/dialogs API rather than a hand-rolled shim; resolve files by validated ownership rather than trusting client input). If a finding points at a shallow patch from a previous round, REPLACE it with the proper solution. Never silence a reviewer by deleting the feature, weakening a check, suppressing a warning, or narrowing a test — unless that genuinely IS the correct fix. Keep tests meaningful: update/extend them to assert the real fixed behavior, never weaken assertions just to make them pass. Quality and correctness matter more than speed; there is no time pressure.

${COMPAT}

After editing source under src/, you MUST run the production build so the shipped bundles in js/ and css/ are regenerated:
  cd ${APP_DIR} && npm run build
Then update or ADD PHPUnit unit tests under tests/Unit/ so new behavior (especially authorization: owner-vs-shared write/delete, file-ID ownership validation, note-type ownership, and the contact-photo access scoping) is covered. Run:
  cd ${APP_DIR} && ./vendor/bin/phpunit
and make them pass. Also run: cd ${APP_DIR} && npm run lint  and  cd ${APP_DIR} && vendor/bin/phpstan analyse --no-progress  and fix EVERY error from both at the root (no ignores/baseline unless the symbol is genuinely external/unresolvable).

For NITPICK cleanups that delete files: only delete clearly scratch/backup artifacts (db_backup-*.bz2, db_backup-*.zip, root-level test_*.spec.js) — do NOT delete anything under tests/ or e2e/ that is a real test.${firstRoundNote}

FINDINGS TO FIX THIS ROUND:
=== GRUMPY DEV ===
${renderFindings(dev.findings)}

=== GRUMPY UX ===
${renderFindings(ux.findings)}

=== FOUL-MOOD NEXTCLOUD APP-STORE REVIEWER ===
${renderFindings(compliance.findings)}${verifyNote}

Report concisely what you changed, grouped by finding, and the final build/lint/test/phpstan status you observed.`
}

const verifyPrompt = `You are a build/test verifier for the Nextcloud app at ${APP_DIR}. Do NOT modify source files. Run, in order, capturing real output:
1. cd ${APP_DIR} && npm run build
2. cd ${APP_DIR} && npm run lint
3. cd ${APP_DIR} && ./vendor/bin/phpunit
4. cd ${APP_DIR} && vendor/bin/phpstan analyse --no-progress   (phpstan IS installed)
Report buildOk/lintOk/testsOk booleans: lintOk must reflect BOTH eslint (step 2) AND phpstan (step 4) passing cleanly — if phpstan reports any error, lintOk is false. In failures[], put one concise line per genuine failure (compile error, eslint error, phpstan error with file:line, or failing test name + reason). summary: one paragraph.`

const history = []
// Seeded so round 1's Fix addresses the currently-red state: ContactController
// gained a 5th constructor dependency (IDBConnection) for the photo-from-vCard
// path, which the existing ContactControllerTest does not yet pass.
let priorVerifyFailures = [
  'phpunit: tests/Unit/Controller/ContactControllerTest fails with ArgumentCountError — ContactController::__construct now takes a 5th arg (IDBConnection $db) not provided by the test setUp',
]

for (let round = 1; round <= MAX_ROUNDS; round++) {
  // --- 1) Review the current (committed) codebase with all three personas. ---
  log(`Round ${round}/${MAX_ROUNDS}: reviewing full codebase (grumpy dev + grumpy UX + foul-mood NC app-store reviewer)`)
  phase('Review')
  const [devReview, uxReview, complianceReview] = await parallel([
    () => agent(devReviewPrompt(round), { label: `dev-review-round-${round}`, phase: 'Review', schema: REVIEW_SCHEMA }),
    () => agent(uxReviewPrompt(round), { label: `ux-review-round-${round}`, phase: 'Review', schema: REVIEW_SCHEMA }),
    () => agent(complianceReviewPrompt(round), { label: `compliance-review-round-${round}`, phase: 'Review', schema: REVIEW_SCHEMA }),
  ])

  const dev = devReview || { findings: [], verdict: 'dev review failed to return' }
  const ux = uxReview || { findings: [], verdict: 'ux review failed to return' }
  const compliance = complianceReview || { findings: [], verdict: 'compliance review failed to return' }
  const allFindings = [...dev.findings, ...ux.findings, ...compliance.findings]
  const blockers = blocking(allFindings)
  log(`Round ${round}: reviewers reported ${allFindings.length} finding(s)`)

  // --- 2) FIX everything found (plus any verify failures carried from the
  //        previous round) with the BEST fix — BEFORE verifying. ---
  if (blockers.length > 0 || priorVerifyFailures.length > 0) {
    log(`Round ${round}: fixing ${allFindings.length} finding(s) + ${priorVerifyFailures.length} carried verify failure(s)`)
    phase('Fix')
    await agent(fixPrompt(round, dev, ux, compliance, priorVerifyFailures), { label: `fix-round-${round}`, phase: 'Fix' })
  } else {
    log(`Round ${round}: no findings to fix — proceeding to confirm the tree is still green`)
  }

  // --- 3) VERIFY the result of the fixes. ---
  log(`Round ${round}: verifying build + lint + tests + phpstan`)
  phase('Verify')
  const verify = await agent(verifyPrompt, { label: `verify-round-${round}`, phase: 'Verify', schema: VERIFY_SCHEMA })
  const verifyClean = !!(verify && verify.buildOk && verify.lintOk && verify.testsOk)
  priorVerifyFailures = verify && !verifyClean ? verify.failures : []

  // --- 4) COMMIT this round's fixes (no-op if the tree is clean). ---
  log(`Round ${round}: committing fixes (checkpoint)`)
  phase('Commit')
  await agent(
    `Commit the current working tree of the Nextcloud app at ${APP_DIR} as a checkpoint for review round ${round}. Do NOT modify, add, or revert any source files — only commit what is already there. Run exactly:\n  cd ${APP_DIR} && git add -A && git commit --no-verify -m "fix(review round ${round}): address reviewer findings" -m "Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"\nIf git prints "nothing to commit, working tree clean", that is success — just report it. Do NOT push, do NOT create branches, do NOT amend earlier commits.`,
    { label: `commit-round-${round}`, phase: 'Commit' },
  )

  history.push({
    round,
    totalFindings: allFindings.length,
    blockers: blockers.length,
    verify: verify ? { buildOk: verify.buildOk, lintOk: verify.lintOk, testsOk: verify.testsOk, failures: verify.failures } : null,
    devVerdict: dev.verdict,
    uxVerdict: ux.verdict,
    complianceVerdict: compliance.verdict,
    findings: allFindings,
  })

  log(`Round ${round} result: ${allFindings.length} findings (all severities must reach 0); build=${verify?.buildOk} lint/phpstan=${verify?.lintOk} tests=${verify?.testsOk}`)

  // --- Converged? Zero findings of any severity AND a clean verify. ---
  if (blockers.length === 0 && verifyClean) {
    return {
      converged: true,
      roundsRun: round,
      finalDevVerdict: dev.verdict,
      finalUxVerdict: ux.verdict,
      finalComplianceVerdict: compliance.verdict,
      history,
    }
  }
}

return {
  converged: false,
  roundsRun: MAX_ROUNDS,
  note: 'Hit max rounds without a fully clean review.',
  history,
  lastRound: history[history.length - 1],
}
