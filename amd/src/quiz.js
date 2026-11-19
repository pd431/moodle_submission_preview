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
 * Checks and previews for files attached to quiz essay questions, and the acknowledgement before finishing.
 *
 * @module     local_submissioncheck/quiz
 * @copyright  2026 Pierre Camaly de Brosses - University of Exeter
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import Templates from 'core/templates';
import {renderAll, enableJumps} from 'local_submissioncheck/preview';
import {guard} from 'local_submissioncheck/doubleconfirm';
import Notification from 'core/notification';

/** How often to ask whether a preview is ready, in milliseconds. */
const POLL_INTERVAL = 5000;

/** How many times to ask before leaving the "preparing" message in place. */
const MAX_POLLS = 36;

/** Wait for the file manager to settle before checking, in milliseconds. */
const SETTLE = 800;

/**
 * Show the report for one question's attachments under its file manager.
 *
 * @param {number} cmid
 * @param {number} attemptid
 * @param {number} slot
 * @param {HTMLInputElement} input The file manager's draft item id field.
 * @param {HTMLElement} container
 * @param {number} count Polls so far.
 */
const checkQuestion = (cmid, attemptid, slot, input, container, count) => {
    Ajax.call([{
        methodname: 'local_submissioncheck_check_quiz_draft',
        args: {cmid: cmid, attemptid: attemptid, slot: slot, draftitemid: parseInt(input.value, 10)},
    }])[0].then((response) => {
        // Replacing is synchronous in some Moodle versions and returns a promise in others.
        return Promise.resolve(Templates.replaceNodeContents(container, response.html, '')).then(() => {
            renderAll(container);
            if (response.pending && count < MAX_POLLS) {
                window.setTimeout(() => checkQuestion(cmid, attemptid, slot, input, container, count + 1), POLL_INTERVAL);
            }
            return null;
        });
    }).catch(Notification.exception);
};

/**
 * Watch every essay question that takes attachments on the attempt page.
 *
 * @param {number} cmid
 * @param {number} attemptid
 */
const initAttemptPage = (cmid, attemptid) => {
    document.querySelectorAll('.que.essay').forEach((question) => {
        const input = question.querySelector('input[type="hidden"][name$="_attachments"]');
        const match = input ? input.name.match(/^q\d+:(\d+)_attachments$/) : null;
        if (!match) {
            return;
        }
        const slot = parseInt(match[1], 10);
        const area = input.closest('.attachments') || input.parentElement;
        const container = document.createElement('div');
        container.className = 'local-submissioncheck-question';
        container.setAttribute('aria-live', 'polite');
        area.after(container);
        enableJumps(container);

        let timer = null;
        const schedule = () => {
            window.clearTimeout(timer);
            timer = window.setTimeout(() => checkQuestion(cmid, attemptid, slot, input, container, 0), SETTLE);
        };
        new MutationObserver(schedule).observe(area, {childList: true, subtree: true});
        schedule();
    });
};

/**
 * Show every attachment on the summary page and ask for the acknowledgement before finishing.
 *
 * @param {number} cmid
 * @param {number} attemptid
 */
const initSummaryPage = (cmid, attemptid) => {
    const form = document.getElementById('frm-finishattempt');
    const container = document.createElement('div');
    container.className = 'local-submissioncheck-summary';
    enableJumps(container);
    const anchor = form ? form.closest('.controls') || form : null;
    if (anchor) {
        anchor.before(container);
    } else {
        (document.querySelector('[role="main"]') || document.body).append(container);
    }

    let needsAck = false;
    let armedLabel = '';
    let acknowledged = false;
    const announce = document.createElement('p');
    announce.className = 'sr-only visually-hidden';
    announce.setAttribute('aria-live', 'polite');
    container.after(announce);

    const render = (count) => Ajax.call([{
        methodname: 'local_submissioncheck_get_quiz_summary',
        args: {cmid: cmid, attemptid: attemptid},
    }])[0].then((response) => {
        needsAck = response.needsack;
        armedLabel = response.armedlabel;
        // Replacing is synchronous in some Moodle versions and returns a promise in others.
        return Promise.resolve(Templates.replaceNodeContents(container, response.html, '')).then(() => {
            renderAll(container);
            if (response.pending && count < MAX_POLLS) {
                window.setTimeout(() => render(count + 1), POLL_INTERVAL);
            }
            return null;
        });
    }).catch(Notification.exception);

    // With problems, finishing asks twice; the second press records that the student read them, then lets the quiz
    // carry on (its own confirmation dialogue follows).
    const button = form ? form.querySelector('[type="submit"]') : null;
    if (button) {
        const options = {
            active: () => needsAck && !acknowledged,
            announce: announce,
            onConfirm: () => {
                if (acknowledged) {
                    return true;
                }
                Ajax.call([{
                    methodname: 'local_submissioncheck_acknowledge_quiz',
                    args: {cmid: cmid, attemptid: attemptid},
                }])[0].then(() => {
                    acknowledged = true;
                    button.click();
                    return null;
                }).catch(Notification.exception);
                return false;
            },
        };
        Object.defineProperty(options, 'armedLabel', {get: () => armedLabel});
        guard(button, options);
    }

    render(0);
};

/**
 * Initialise on the attempt or summary page.
 *
 * @param {number} cmid
 * @param {number} attemptid
 * @param {boolean} isSummary
 */
export const init = (cmid, attemptid, isSummary) => {
    if (isSummary) {
        initSummaryPage(cmid, attemptid);
    } else {
        initAttemptPage(cmid, attemptid);
    }
};
