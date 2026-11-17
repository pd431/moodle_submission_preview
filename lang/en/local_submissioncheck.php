<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Language strings.
 *
 * @package    local_submissioncheck
 * @copyright  2026 Pierre Camaly de Brosses - University of Exeter
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['acknowledged'] = 'Acknowledged';
$string['autoaccepted'] = 'Accepted automatically after {$a} minutes.';
$string['autoaccepted_warnings'] = 'Accepted automatically after {$a} minutes. The warnings below were not acknowledged.';
$string['cannotreplace'] = 'You can\'t replace this file now. Contact your module team if it is the wrong file.';
$string['cannotreplace_cutoff'] = 'You can\'t replace this file because the cut-off date has passed. Contact your module team if it is the wrong file.';
$string['checksfor'] = 'Submission checks for {$a}';
$string['checksincomplete'] = 'Some checks could not be completed for {$a}.';
$string['checksnotrun'] = 'Checks could not run';
$string['checksoff'] = 'Submission checks are not on for this activity.';
$string['checksunavailable_assign'] = 'Checks aren\'t available right now. Your file has been submitted.';
$string['checksunavailable_quiz'] = 'Checks aren\'t available right now. You can still submit.';
$string['col_acknowledged'] = 'Warnings';
$string['col_checks'] = 'Checks';
$string['col_confirmation'] = 'Confirmation';
$string['col_file'] = 'File';
$string['col_student'] = 'Student';
$string['col_submitted'] = 'Submitted';
$string['confirm_plural'] = 'Yes, these are the files I intended to submit';
$string['confirm_single'] = 'Yes, this is the file I intended to submit';
$string['confirmed_other'] = '{$a} confirmed this file.';
$string['confirmed_other_ack'] = '{$a} confirmed this file and read the warnings about it.';
$string['confirmed_you'] = 'You confirmed this file.';
$string['confirmed_you_ack'] = 'You confirmed this file and read the warnings about it.';
$string['continuechecking'] = 'Continue checking your submission';
$string['defaulttags'] = 'Tags that turn checks on';
$string['defaulttags_desc'] = 'Comma-separated tags. An assignment or quiz whose "Submission checks" setting is Automatic has checks on when it carries one of these tags, for example: summative, formative.';
$string['disclaimer'] = 'This is an approximate preview of your document(s). Layout may be approximate. Use it to check this is what you intended to submit to {$a->modulecode} {$a->assessment}, and that it has all the content you expect, and the latest changes you made to it.';
$string['event_upload_confirmed'] = 'Submitted file confirmed';
$string['event_upload_returned'] = 'Went back to replace a submitted file';
$string['finding_cannotopen'] = '{$a->name} could not be opened. It may be damaged or only partly uploaded.';
$string['finding_googleshortcut'] = '{$a->name} is a shortcut to a {$a->kind}, not the document. Download it as Word or PDF and upload that.';
$string['finding_linkedmedia'] = 'The following files have been linked but not embedded in your file. A marker will not be able to see them. See {$a->link} for solutions.';
$string['finding_lockfile'] = '{$a->name} is a temporary lock file Word creates while a document is open, not your document.';
$string['finding_notext'] = '{$a->name} appears to contain no text.';
$string['finding_passwordprotected'] = '{$a->name} is password protected. Your marker will not be able to open it.';
$string['finding_webshortcut'] = '{$a->name} is a shortcut to a web page, not a document.';
$string['finding_windowsshortcut'] = '{$a->name} is a Windows shortcut to a file on your computer, not the file itself.';
$string['finding_wrongextension'] = '{$a->name} is named as {$a->expected} but is actually {$a->actual}.';
$string['finding_zerobytes'] = '{$a->name} is empty (0 bytes). It may not have saved or uploaded properly.';
$string['findingshort_cannotopen'] = 'Could not be opened';
$string['findingshort_googleshortcut'] = 'Shortcut to a {$a->kind}';
$string['findingshort_linkedmedia'] = 'Linked, not embedded: {$a->items}';
$string['findingshort_lockfile'] = 'Word lock file';
$string['findingshort_notext'] = 'No text';
$string['findingshort_passwordprotected'] = 'Password protected';
$string['findingshort_webshortcut'] = 'Shortcut to a web page';
$string['findingshort_windowsshortcut'] = 'Windows shortcut';
$string['findingshort_wrongextension'] = 'Named as {$a->expected}, actually {$a->actual}';
$string['findingshort_zerobytes'] = 'Empty (0 bytes)';
$string['goback'] = 'No, wrong file: take me back';
$string['googledoc'] = 'Google Doc';
$string['googlesheet'] = 'Google Sheet';
$string['googleslides'] = 'Google Slides presentation';
$string['helplink'] = 'Help link for linked media';
$string['helplink_desc'] = 'Shown to students whose file links to media instead of embedding it.';
$string['itemonslide'] = '{$a->name} (slide {$a->slide})';
$string['kind_excel'] = 'an Excel spreadsheet';
$string['kind_html'] = 'a web page (HTML)';
$string['kind_image'] = 'an image';
$string['kind_iwork'] = 'an Apple Pages, Keynote or Numbers file';
$string['kind_odf'] = 'an OpenDocument file';
$string['kind_office'] = 'a Word, PowerPoint or Excel file';
$string['kind_oldoffice'] = 'an older Word, PowerPoint or Excel file';
$string['kind_pdf'] = 'a PDF';
$string['kind_powerpoint'] = 'a PowerPoint presentation';
$string['kind_rtf'] = 'an RTF document';
$string['kind_text'] = 'plain text';
$string['kind_word'] = 'a Word document';
$string['kind_zip'] = 'a zip file';
$string['messageprovider:unconfirmed'] = 'Reminder to check a submitted file';
$string['mode'] = 'Submission checks';
$string['mode_auto'] = 'Automatic';
$string['mode_help'] = 'After a student submits files, show them a report of checks on the files and a preview, and give them time to confirm or replace the file. Automatic turns checks on when the activity has one of the tags set by the site administrator.';
$string['mode_off'] = 'Off';
$string['mode_on'] = 'On';
$string['needsnodrafts'] = 'Submission checks need "Require students to click the submit button" set to No, so the upload time is the submission time and students can replace a wrong file.';
$string['noproblems'] = 'No problems found in {$a}';
$string['noproblemsshort'] = 'No problems found';
$string['noresults'] = 'No files have been checked in this activity yet.';
$string['notacknowledged'] = 'Not acknowledged';
$string['notrunning_assign'] = 'Submission checks are on for this assignment but are not running: they need file submissions, with "Require students to click the submit button" set to No.';
$string['nowarnings'] = 'No warnings';
$string['openpreview'] = 'Open the preview of {$a} in a new tab';
$string['pluginname'] = 'Submission checks';
$string['previewcouldnotread'] = 'This preview couldn\'t read {$a}. Your file has been submitted as it is.';
$string['previewloading'] = 'Preparing a preview of {$a}…';
$string['previewnotext'] = '(No text on this slide.)';
$string['previewof'] = 'Preview of {$a}';
$string['previewpending'] = 'Preparing a preview of {$a}. This can take a minute.';
$string['previewshortened'] = 'Only the beginning of this file is shown.';
$string['previewslide'] = 'Slide {$a}';
$string['previewtextonly'] = 'Text only: layout, pictures and formatting aren\'t shown.';
$string['previewtoolarge'] = '{$a} is too large to preview here. Your file has been submitted as it is.';
$string['previewunavailable_assign'] = 'A preview isn\'t available right now. Your file has been submitted.';
$string['previewunavailable_quiz'] = 'A preview isn\'t available right now. You can still submit.';
$string['previewunsupported'] = 'There is no preview for this type of file ({$a}).';
$string['privacy:metadata:core_files'] = 'Previews are made from the files students submit and stored by Moodle\'s document conversion.';
$string['privacy:metadata:file'] = 'The files in each submission that was checked.';
$string['privacy:metadata:file:contenthash'] = 'A fingerprint of the file\'s content.';
$string['privacy:metadata:file:filename'] = 'The file\'s name.';
$string['privacy:metadata:preference:seen'] = 'The last check report the user has seen, so a returning user is told they can continue checking.';
$string['privacy:metadata:result'] = 'What the checks found in a file, kept so the same file is not checked twice.';
$string['privacy:metadata:result:filename'] = 'The file\'s name.';
$string['privacy:metadata:result:findings'] = 'What the checks found.';
$string['privacy:metadata:upload'] = 'Each checked submission and what the student said about it.';
$string['privacy:metadata:upload:acknowledged'] = 'Whether the warnings were acknowledged.';
$string['privacy:metadata:upload:resolvedby'] = 'The user who confirmed the submission.';
$string['privacy:metadata:upload:state'] = 'Whether the submission is awaiting confirmation, was confirmed, accepted automatically, or replaced.';
$string['privacy:metadata:upload:timeresolved'] = 'When the submission was confirmed or accepted.';
$string['privacy:metadata:upload:timeuploaded'] = 'When the files were submitted.';
$string['privacy:metadata:upload:uploadedby'] = 'The user who uploaded the files.';
$string['privacy:metadata:upload:userid'] = 'The student the submission belongs to.';
$string['privacystate_0'] = 'Awaiting confirmation';
$string['privacystate_1'] = 'Confirmed';
$string['privacystate_2'] = 'Accepted automatically';
$string['privacystate_3'] = 'Replaced';
$string['quizacklabel'] = 'I have read these warnings and still want to submit these files';
$string['quizackrequired'] = 'Please read the warnings about your files and tick the box to confirm before finishing.';
$string['quiznearend'] = 'The time limit is close, so you can finish without confirming.';
$string['quizsummaryheading'] = 'Check your attached files';
$string['quiztimelimitnote'] = 'Time spent checking your files counts towards the time limit.';
$string['reminderbody'] = 'You submitted work to {$a->activity} at {$a->time}. Check the report on your submission and confirm it is the file you intended to submit by {$a->deadline}.';
$string['reminderlink'] = 'Go to the check report';
$string['reminderminutes'] = 'Remind after (minutes)';
$string['reminderminutes_desc'] = 'If a student has not confirmed their submission after this many minutes, they are sent a reminder with a link back to the check report.';
$string['remindersubject'] = 'Check your submission to {$a}';
$string['replacelate'] = 'The due date has passed. If you replace this file, the new file will be recorded as late.';
$string['reportheading'] = 'Check your submission';
$string['resultsheading'] = 'Submission check results: {$a}';
$string['resultslink'] = 'Submission check results';
$string['settingsheader'] = 'Submission checks';
$string['state_autoaccepted'] = 'Accepted automatically';
$string['state_awaiting'] = 'Awaiting confirmation until {$a}';
$string['state_confirmed'] = 'Confirmed by {$a->name}, {$a->time}';
$string['state_finishedunconfirmed'] = 'Finished without confirming';
$string['status_auto_notags'] = 'Checks are off: no tags have been set up to turn checks on.';
$string['status_auto_off'] = 'Checks are off: this activity has none of the tags {$a}';
$string['status_auto_on'] = 'Checks are on because this activity is tagged {$a}';
$string['status_off'] = 'Checks are off for this activity.';
$string['status_on'] = 'Checks are on for this activity.';
$string['submissioncheck:viewresults'] = 'View submission check results';
$string['submittedat_other_plural'] = '{$a->name} submitted these files at {$a->time}.';
$string['submittedat_other_single'] = '{$a->name} submitted this file at {$a->time}.';
$string['submittedat_you_plural'] = 'You submitted these files at {$a}.';
$string['submittedat_you_single'] = 'You submitted this file at {$a}.';
$string['task_autoaccept'] = 'Accept submissions whose confirmation window has ended';
$string['task_cleanup'] = 'Remove unused submission check results';
$string['task_remind'] = 'Remind students to check their submission';
$string['window_plural'] = 'Check this report thoroughly. You have {$a->minutes} minutes to confirm these are the files you intended to submit. Unless you choose to resubmit, your recorded submission timestamp will remain {$a->time}. Confirm by {$a->deadline}.';
$string['window_single'] = 'Check this report thoroughly. You have {$a->minutes} minutes to confirm this is the file you intended to submit. Unless you choose to resubmit, your recorded submission timestamp will remain {$a->time}. Confirm by {$a->deadline}.';
$string['windowended'] = 'The {$a} minutes to confirm had already ended, so your submission was accepted automatically.';
$string['windowminutes'] = 'Minutes to confirm';
$string['windowminutes_desc'] = 'How long a student has to confirm or replace a file after submitting it. After that it is accepted automatically.';
