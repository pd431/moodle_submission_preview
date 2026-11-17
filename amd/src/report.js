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
 * Moves the check report to the top of the assignment page and refreshes it while a preview is made.
 *
 * @module     local_submissioncheck/report
 * @copyright  2026 Pierre Camaly de Brosses - University of Exeter
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import Templates from 'core/templates';
import Notification from 'core/notification';
import Pending from 'core/pending';

/** How often to ask whether a preview is ready, in milliseconds. */
const POLL_INTERVAL = 5000;

/** How many times to ask before leaving the "preparing" message in place. */
const MAX_POLLS = 36;

/**
 * Put an element first in the page's main content.
 *
 * @param {HTMLElement} element
 */
const moveToTop = (element) => {
    const main = document.querySelector('#region-main [role="main"]') || document.querySelector('[role="main"]');
    if (main && main.firstElementChild !== element) {
        main.insertBefore(element, main.firstElementChild);
    }
};

/**
 * Ask for the report again until no preview is pending.
 *
 * @param {HTMLElement} report
 * @param {number} count Polls so far.
 */
const poll = (report, count) => {
    if (count >= MAX_POLLS) {
        return;
    }
    window.setTimeout(() => {
        Ajax.call([{
            methodname: 'local_submissioncheck_get_assign_report',
            args: {cmid: parseInt(report.dataset.cmid, 10)},
        }])[0].then((response) => {
            if (!response.html) {
                return null;
            }
            // Replacing is synchronous in some Moodle versions and returns a promise in others.
            return Promise.resolve(Templates.replaceNodeContents(report, response.html, '')).then(() => {
                const replacement = report.querySelector('[data-region="local-submissioncheck-report"]');
                if (replacement) {
                    report.replaceWith(replacement);
                    if (response.pending) {
                        poll(replacement, count + 1);
                    }
                }
                return null;
            });
        }).catch(Notification.exception);
    }, POLL_INTERVAL);
};

/**
 * Move what the plugin added to the page to the top of the main content, and keep the report up to date.
 *
 * @param {string} id The id of the element holding the report and any notes for staff.
 */
export const init = (id) => {
    const start = () => {
        const wrapper = document.getElementById(id);
        if (!wrapper) {
            return;
        }
        moveToTop(wrapper);
        const report = wrapper.querySelector('[data-region="local-submissioncheck-report"]');
        if (report && report.dataset.pending) {
            poll(report, 0);
        }
    };
    // This is printed after the footer's scripts, so it may not be in the document yet.
    if (document.readyState === 'loading') {
        const pending = new Pending('local_submissioncheck/report:init');
        document.addEventListener('DOMContentLoaded', () => {
            start();
            pending.resolve();
        }, {once: true});
    } else {
        start();
    }
};
