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
| Drawing a .docx in the browser, and replacing linked-item markers with the missing-item box | `amd/src/preview.js` (loads the bundled `amd/src/docxpreview.js`) | `tests/behat/bundled_preview.feature` |
| Per-file report data (findings, facts, preview) | `classes/output/report_builder.php` | `tests/behat/assign_submission_checks.feature` |
| Facts about a file (kind, pages, words, slides, sheets, formulas, charts, pictures, size), cached by content | `classes/local/file_facts.php` | `tests/file_facts_test.php` |
| Where a linked file sits (slide or presentation page); online videos and media on sign-in storage, and naming the service | `classes/local/checks/linked_media.php` | `tests/checks_test.php`, `tests/behat/online_video_links.feature` |
| Which hosts count as sign-in storage | site setting `local_submissioncheck/signinhosts` (`settings.php`; default in `linked_media::DEFAULT_SIGNIN_HOSTS`) | `tests/checks_test.php` |
| The review page for an assignment upload (tabs, side panel, fixed answer bar) | `review.php`, `classes/output/review_page.php`, `templates/review.mustache` | `tests/behat/review_flow.feature` |
| When the assignment page sends the student to the review page, and the banner it keeps | `classes/hook_callbacks.php`, `classes/local/adapter/assign.php` (session flag) | `tests/assign_test.php` |
| Tabs, viewed count, keeping focus clear of the fixed bar | `amd/src/review.js` | `tests/behat/review_flow.feature` |
| The temporary copy of a Word document the browser draws: drawing groups become their pictures, text-box text joins the text, drawings with neither and linked items are marked | `classes/local/word_copy.php` | `tests/preview_test.php`, `tests/behat/word_drawing_groups.feature` |
| The grey box for a drawing the preview cannot show | `templates/not_shown.mustache` | `tests/behat/word_drawing_groups.feature` |
| The missing-item box (one template for the browser and the server) | `templates/missing_item.mustache` | `tests/preview_test.php`, `tests/behat/review_flow.feature` |
| Per-file report parts shared by the review page and the quiz | `templates/file_checks.mustache`, `templates/file_facts.mustache`, `templates/file_preview.mustache` | `tests/behat/quiz_essay_attachment_checks.feature` |
| Confirmation window: who submitted what, and the answer (answers post to `confirm.php`, which returns to the assignment page) | `classes/local/confirmation.php`, `confirm.php` | `tests/assign_test.php` |
| Test switches (stand-in converter, converter or check outage) | `classes/local/testing.php` | used by every Behat feature |
| Privacy | `classes/privacy/provider.php` | `tests/privacy/provider_test.php` |

Third-party code is listed in `thirdpartylibs.xml`.
