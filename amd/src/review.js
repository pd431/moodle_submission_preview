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
 * The review page: one tab per file (WAI-ARIA tabs: arrow keys, Home, End), the viewed count, keeping focused content
 * clear of the fixed answer bar, and refreshing while a converter is still making a preview.
 *
 * @module     local_submissioncheck/review
 * @copyright  2026 Pierre Camaly de Brosses - University of Exeter
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import Templates from 'core/templates';
import Notification from 'core/notification';
import {getString} from 'core/str';
import {renderAll, enableJumps} from 'local_submissioncheck/preview';
import {guard} from 'local_submissioncheck/doubleconfirm';

/** How often to ask whether a preview is ready, in milliseconds. */
const POLL_INTERVAL = 5000;

/** How many times to ask before leaving the "preparing" message in place. */
const MAX_POLLS = 36;

/** Files viewed so far, by name; kept across refreshes of the page content. */
const viewed = new Set();

/**
 * Keep anything that takes focus or is scrolled to clear of the fixed bar (WCAG 2.4.11).
 *
 * @param {HTMLElement} review
 */
const clearOfBar = (review) => {
    const bar = review.querySelector('[data-region="local-submissioncheck-bar"]');
    const height = bar ? bar.offsetHeight + 16 : 0;
    document.documentElement.style.scrollPaddingBottom = height + 'px';
    const page = document.getElementById('page');
    if (page) {
        page.style.scrollPaddingBottom = height + 'px';
    }
};

/**
 * Update "Viewed n of m files".
 *
 * @param {HTMLElement} review
 * @param {number} total
 */
const showViewed = async(review, total) => {
    const holder = review.querySelector('[data-region="local-submissioncheck-viewed"]');
    if (holder) {
        holder.textContent = await getString('viewedfiles', 'local_submissioncheck', {viewed: viewed.size, total: total});
    }
};

/**
 * Show one file's panel and draw its preview if it has not been drawn yet.
 *
 * @param {HTMLElement} review
 * @param {HTMLElement[]} tabs
 * @param {HTMLElement} tab
 * @param {boolean} focus
 */
const select = (review, tabs, tab, focus) => {
    tabs.forEach((other) => {
        const selected = other === tab;
        other.setAttribute('aria-selected', selected ? 'true' : 'false');
        other.tabIndex = selected ? 0 : -1;
        const panel = document.getElementById(other.getAttribute('aria-controls'));
        if (panel) {
            panel.hidden = !selected;
            if (selected) {
                renderAll(panel);
            }
        }
    });
    if (focus) {
        tab.focus();
    }
    viewed.add(tab.dataset.filename);
    showViewed(review, tabs.length);
};

/**
 * Set up the tabs.
 *
 * @param {HTMLElement} review
 */
const setUpTabs = (review) => {
    const tablist = review.querySelector('[data-region="local-submissioncheck-tabs"]');
    if (!tablist) {
        renderAll(review);
        return;
    }
    const tabs = Array.from(tablist.querySelectorAll('[role="tab"]'));
    const current = tabs.find((tab) => tab.getAttribute('aria-selected') === 'true') || tabs[0];
    tabs.forEach((tab) => tab.addEventListener('click', () => select(review, tabs, tab, false)));
    tablist.addEventListener('keydown', (e) => {
        const index = tabs.indexOf(document.activeElement);
        if (index < 0) {
            return;
        }
        const next = {
            ArrowRight: tabs[(index + 1) % tabs.length],
            ArrowDown: tabs[(index + 1) % tabs.length],
            ArrowLeft: tabs[(index - 1 + tabs.length) % tabs.length],
            ArrowUp: tabs[(index - 1 + tabs.length) % tabs.length],
            Home: tabs[0],
            End: tabs[tabs.length - 1],
        }[e.key];
        if (next) {
            e.preventDefault();
            select(review, tabs, next, true);
        }
    });
    select(review, tabs, current, false);
};

/**
 * With problems, "Yes" asks twice: the first press arms it on the server (which also checks the wait), the second,
 * a second or more later, confirms.
 *
 * @param {HTMLElement} review
 */
const setUpAnswer = (review) => {
    const form = review.querySelector('[data-region="local-submissioncheck-answer"]');
    if (!form || !form.dataset.needsack) {
        return;
    }
    const button = form.querySelector('[data-region="local-submissioncheck-confirm"]');
    const ack = form.querySelector('[data-region="local-submissioncheck-ack"]');
    guard(button, {
        armedLabel: form.dataset.armedlabel,
        announce: review.querySelector('[data-region="local-submissioncheck-announce"]'),
        onFirst: () => {
            const body = new URLSearchParams({
                sesskey: form.elements.sesskey.value,
                uploadid: form.elements.uploadid.value,
                action: 'arm',
                ajax: '1',
            });
            // The form's buttons are named "action", so form.action is not the address.
            fetch(form.getAttribute('action'), {method: 'POST', credentials: 'same-origin', body: body}).catch(() => null);
        },
        onArmed: () => {
            ack.value = '1';
        },
        onReset: () => {
            ack.value = '0';
        },
    });
};

/**
 * Set up one rendering of the review content.
 *
 * @param {HTMLElement} review
 */
const start = (review) => {
    setUpTabs(review);
    enableJumps(review);
    setUpAnswer(review);
    clearOfBar(review);
};

/**
 * Ask for the page content again until no preview is pending, keeping the selected tab.
 *
 * @param {HTMLElement} review
 * @param {number} count Polls so far.
 */
const poll = (review, count) => {
    if (count >= MAX_POLLS) {
        return;
    }
    window.setTimeout(() => {
        const holder = document.createElement('div');
        let name = null;
        let pending = false;
        Ajax.call([{
            methodname: 'local_submissioncheck_get_assign_report',
            args: {cmid: parseInt(review.dataset.cmid, 10)},
        }])[0].then((response) => {
            if (!response.html) {
                return null;
            }
            const selected = review.querySelector('[role="tab"][aria-selected="true"]');
            name = selected ? selected.dataset.filename : null;
            pending = response.pending;
            // Replacing is synchronous in some Moodle versions and returns a promise in others; then() takes either.
            return Templates.replaceNodeContents(holder, response.html, '');
        }).then(() => {
            const replacement = holder.querySelector('[data-region="local-submissioncheck-review"]');
            if (replacement) {
                replacement.id = review.id;
                review.replaceWith(replacement);
                const tab = name ? Array.from(replacement.querySelectorAll('[role="tab"]'))
                    .find((t) => t.dataset.filename === name) : null;
                if (tab) {
                    replacement.querySelectorAll('[role="tab"]').forEach((t) => {
                        t.setAttribute('aria-selected', t === tab ? 'true' : 'false');
                    });
                }
                start(replacement);
                if (pending) {
                    poll(replacement, count + 1);
                }
            }
            return null;
        }).catch(Notification.exception);
    }, POLL_INTERVAL);
};

/**
 * Start the review page.
 *
 * @param {string} id The review section's id.
 */
export const init = (id) => {
    const review = document.getElementById(id);
    if (!review) {
        return;
    }
    start(review);
    window.addEventListener('resize', () => {
        const current = document.querySelector('[data-region="local-submissioncheck-review"]');
        if (current) {
            clearOfBar(current);
        }
    });
    if (review.dataset.pending) {
        poll(review, 0);
    }
};
