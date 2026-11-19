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
 * Asking twice before going ahead with problems: the first press of the button does not act; for one second the
 * button looks disabled while a bar fills across it, and a press then does nothing; then it turns red and reads its
 * second-press label. The second press goes ahead. Moving focus away, or selecting anything else, puts it back.
 *
 * Like "Click again to delete" in the My assessments mitigation menu (data-armed).
 *
 * @module     local_submissioncheck/doubleconfirm
 * @copyright  2026 Pierre Camaly de Brosses - University of Exeter
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/** Milliseconds between the first press and when the second press is allowed. */
export const WAIT = 1000;

/**
 * Guard a button.
 *
 * @param {HTMLElement} button
 * @param {Object} options
 * @param {string} options.armedLabel Label for the second press.
 * @param {HTMLElement|null} options.announce Live region that announces the label change.
 * @param {Function} [options.onFirst] Called on the first press.
 * @param {Function} [options.onArmed] Called when the second press is allowed.
 * @param {Function} [options.onReset] Called when the button goes back to its first label.
 * @param {Function} [options.onConfirm] Called on the second press with the click event; return true to let it through.
 * @param {Function} [options.active] Whether asking twice applies now; when it returns false, presses go straight through.
 */
export const guard = (button, options) => {
    if (button.dataset.guarded) {
        return;
    }
    button.dataset.guarded = '1';
    const label = button.dataset.label || button.textContent.trim();
    let state = button.dataset.armed ? 'armed' : 'idle';
    let timer = null;

    const setLabel = (text) => {
        if (button.tagName === 'INPUT') {
            button.value = text;
        } else {
            button.textContent = text;
        }
    };

    const reset = () => {
        if (state === 'idle') {
            return;
        }
        window.clearTimeout(timer);
        state = 'idle';
        delete button.dataset.armed;
        button.removeAttribute('aria-disabled');
        button.classList.remove('local-submissioncheck-waiting', 'local-submissioncheck-armed');
        setLabel(label);
        if (options.onReset) {
            options.onReset();
        }
    };

    button.addEventListener('click', (e) => {
        if (options.active && !options.active()) {
            return;
        }
        if (state === 'armed') {
            if (!options.onConfirm || options.onConfirm(e)) {
                return;
            }
            e.preventDefault();
            e.stopImmediatePropagation();
            return;
        }
        e.preventDefault();
        e.stopImmediatePropagation();
        if (state === 'waiting') {
            return;
        }
        state = 'waiting';
        button.setAttribute('aria-disabled', 'true');
        button.classList.add('local-submissioncheck-waiting');
        if (options.onFirst) {
            options.onFirst();
        }
        timer = window.setTimeout(() => {
            state = 'armed';
            button.dataset.armed = '1';
            button.removeAttribute('aria-disabled');
            button.classList.remove('local-submissioncheck-waiting');
            button.classList.add('local-submissioncheck-armed');
            setLabel(options.armedLabel);
            if (options.announce) {
                options.announce.textContent = options.armedLabel;
            }
            if (options.onArmed) {
                options.onArmed();
            }
        }, WAIT);
    }, true);

    button.addEventListener('blur', reset);
    document.addEventListener('click', (e) => {
        if (!button.contains(e.target)) {
            reset();
        }
    }, true);
};
