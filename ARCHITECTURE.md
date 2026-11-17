# Architecture: where each rule lives

One owner per concern. Change a rule in its owner; callers pass flags or data and never restate it.

| Concern | Owner | Guard test |
|---|---|---|
| Whether checks are on for an activity (own setting, or site tags on Automatic) | `classes/local/activation.php` | `tests/assign_test.php` |
| Assignment specifics: which files, who may act, group submissions | `classes/local/adapter/assign.php` | `tests/assign_test.php` |
| Quiz essay attachment specifics | `classes/local/adapter/quiz.php` | `tests/behat/quiz_essay_attachment_checks.feature` |
| What a file really is (magic bytes, package kind) and safe reads of its content | `classes/local/inspected_file.php` | `tests/checks_test.php` |
| The text of an Office or OpenDocument package, per page or slide | `classes/local/package_text.php` | `tests/preview_test.php`, `tests/checks_test.php` |
| The checks, their order and the time budget | `classes/local/check_registry.php`, one class per check in `classes/local/checks/` | `tests/checks_test.php` |
| Check results cached by content and name | `classes/local/result_store.php` | `tests/checks_test.php` |
| Which preview a file gets and its state (ready, pending, unsupported, unavailable, too large) | `classes/local/preview.php` | `tests/preview_test.php` |
| Server-rendered HTML previews (spreadsheet, plain text, text-only outline) | `classes/local/html_preview.php` | `tests/preview_test.php` |
| Sending a preview to the browser, with its access check and sandbox headers | `preview.php` | `tests/behat/bundled_preview.feature` |
| Drawing a .docx in the browser | `amd/src/preview.js` (loads the bundled `amd/src/docxpreview.js`) | `tests/behat/bundled_preview.feature` |
| Per-file report data (findings, then preview) | `classes/output/report_builder.php` | `tests/behat/assign_submission_checks.feature` |
| Confirmation window: who submitted what, and the answer | `classes/local/confirmation.php`, `confirm.php` | `tests/assign_test.php` |
| Test switches (stand-in converter, converter or check outage) | `classes/local/testing.php` | used by every Behat feature |
| Privacy | `classes/privacy/provider.php` | `tests/privacy/provider_test.php` |

Third-party code is listed in `thirdpartylibs.xml`.
