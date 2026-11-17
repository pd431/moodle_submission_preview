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
 * Moves the submission check banner (and any notes for staff) to the top of the assignment page.
 *
 * @module     local_submissioncheck/report
 * @copyright  2026 Pierre Camaly de Brosses - University of Exeter
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Pending from 'core/pending';

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
 * Move what the plugin added to the page to the top of the main content.
 *
 * @param {string} id The id of the element holding the banner and any notes for staff.
 */
export const init = (id) => {
    const start = () => {
        const wrapper = document.getElementById(id);
        if (wrapper) {
            moveToTop(wrapper);
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
