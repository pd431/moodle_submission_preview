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
 * Draws Word documents in the check report, in the student's browser, when the site's converter has no preview for them.
 *
 * The document is drawn into a sandboxed frame that cannot run scripts, and its links keep their text but go nowhere.
 *
 * @module     local_submissioncheck/preview
 * @copyright  2026 Pierre Camaly de Brosses - University of Exeter
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Pending from 'core/pending';
import Templates from 'core/templates';
import {getString} from 'core/str';

/** Options for the renderer: whole pages, pictures inline, no comments or experimental layout. */
const OPTIONS = {
    className: 'docx',
    inWrapper: true,
    ignoreLastRenderedPageBreak: true,
    useBase64URL: true,
    renderComments: false,
    experimental: false,
};

/** Marker the server puts where a linked item was (classes/local/word_copy.php). */
const MISSING = /\[\[submissioncheck-missing\|([^|\]]*)\|(\d+)\|(\d+)\]\]/;

/** Marker the server puts where media on sign-in storage was (classes/local/word_copy.php). */
const CLOUD = /\[\[submissioncheck-cloud\|([^|\]]*)\|([^|\]]*)\|(\d+)\|(\d+)\]\]/;

/** Marker the server puts where a drawing the renderer cannot draw was (classes/local/word_copy.php). */
const NOT_SHOWN = /\[\[submissioncheck-notshown\|(\d+)\|(\d+)\]\]/;

/**
 * Replace each marker in a drawn document with its box, at the size of the original frame when known: the red
 * missing-item box for a linked item or media on sign-in storage, the grey box for a drawing the preview cannot show.
 *
 * @param {Document} doc The frame's document.
 * @returns {Promise}
 */
const replaceMarkers = async(doc) => {
    const walker = doc.createTreeWalker(doc.body, NodeFilter.SHOW_TEXT);
    const found = [];
    while (walker.nextNode()) {
        const missing = walker.currentNode.data.match(MISSING);
        const cloud = missing ? null : walker.currentNode.data.match(CLOUD);
        const notshown = missing || cloud ? null : walker.currentNode.data.match(NOT_SHOWN);
        if (cloud) {
            found.push({node: walker.currentNode, template: 'missing_item', string: 'clouditem',
                name: {name: cloud[1], host: cloud[2]}, broken: cloud[1],
                width: parseInt(cloud[3], 10), height: parseInt(cloud[4], 10)});
        } else if (missing) {
            found.push({node: walker.currentNode, template: 'missing_item', string: 'missingitem', name: missing[1],
                broken: missing[1], width: parseInt(missing[2], 10), height: parseInt(missing[3], 10)});
        } else if (notshown) {
            found.push({node: walker.currentNode, template: 'not_shown', string: 'drawingnotshown', name: null, broken: null,
                width: parseInt(notshown[1], 10), height: parseInt(notshown[2], 10)});
        }
    }
    for (const item of found) {
        const message = await getString(item.string, 'local_submissioncheck', item.name);
        const {html} = await Templates.renderForPromise('local_submissioncheck/' + item.template, {
            message: message,
            name: item.broken,
            width: item.width ? item.width + 'px' : null,
            height: item.height ? item.height + 'px' : null,
        });
        const box = doc.createRange().createContextualFragment(html);
        const holder = item.node.parentElement && item.node.parentElement.tagName === 'SPAN'
            ? item.node.parentElement : item.node;
        holder.replaceWith(box);
    }
};

/**
 * Load the bundled renderer only when a Word document is on the page.
 *
 * @returns {Promise<Object>}
 */
const loadRenderer = () => new Promise((resolve, reject) => {
    window.require(['local_submissioncheck/docxpreview'], resolve, reject);
});

/**
 * Show a message in place of the preview.
 *
 * @param {HTMLElement} region
 * @param {string} message
 */
const showMessage = (region, message) => {
    const p = document.createElement('p');
    p.className = 'alert alert-info m-2';
    p.textContent = message;
    region.replaceChildren(p);
};

/**
 * Draw one Word document.
 *
 * @param {HTMLElement} region The placeholder from the files template.
 * @returns {Promise}
 */
const renderOne = async(region) => {
    if (region.dataset.started) {
        return;
    }
    region.dataset.started = '1';
    const pending = new Pending('local_submissioncheck/preview:docx');
    try {
        const [response, renderer] = await Promise.all([
            fetch(region.dataset.url, {credentials: 'same-origin'}),
            loadRenderer(),
        ]);
        if (!response.ok) {
            throw new Error(response.statusText);
        }
        const data = await response.blob();
        const frame = document.createElement('iframe');
        frame.setAttribute('sandbox', 'allow-same-origin');
        frame.title = region.dataset.label;
        region.replaceChildren(frame);
        const doc = frame.contentDocument;
        await renderer.renderAsync(data, doc.body, doc.head, OPTIONS);
        await replaceMarkers(doc);
        // Links a marker cannot follow are outlined in red (the server lists their exact addresses); every link keeps
        // its text but goes nowhere.
        let broken = {};
        try {
            broken = JSON.parse(region.dataset.brokentargets || '{}') || {};
        } catch (e) {
            broken = {};
        }
        doc.querySelectorAll('a[href]').forEach((link) => {
            const href = link.getAttribute('href');
            if (Object.prototype.hasOwnProperty.call(broken, href)) {
                link.dataset.broken = broken[href];
                link.style.cssText += ';color:#b00020;outline:2px solid #b00020;outline-offset:1px;font-weight:700;';
            }
            if (!href.startsWith('#')) {
                link.removeAttribute('href');
            }
        });
        doc.body.style.margin = '0';
        region.dataset.rendered = '1';
    } catch (error) {
        showMessage(region, region.dataset.failed);
    } finally {
        pending.resolve();
    }
};

/**
 * Show a broken item where the preview marks it: in a drawn Word document, scroll to it and flash it; in a text-only
 * preview, move the frame to its box.
 *
 * @param {HTMLElement} root Where the file's preview is.
 * @param {HTMLElement} button The item's button in the side list.
 */
const showBroken = (root, button) => {
    const label = button.dataset.preview;
    const docx = Array.from(root.querySelectorAll('[data-region="local-submissioncheck-docx"]'))
        .find((region) => region.dataset.label === label);
    const frame = docx ? docx.querySelector('iframe') : null;
    if (frame && frame.contentDocument) {
        const target = Array.from(frame.contentDocument.querySelectorAll('[data-broken]'))
            .find((el) => el.dataset.broken === button.dataset.name);
        if (target) {
            frame.scrollIntoView({block: 'start'});
            target.scrollIntoView({block: 'center'});
            target.style.boxShadow = '0 0 0 6px #ffd400';
            window.setTimeout(() => {
                target.style.boxShadow = '';
            }, 1500);
        }
        return;
    }
    const outline = Array.from(root.querySelectorAll('[data-region="local-submissioncheck-frame"]'))
        .find((el) => el.title === label);
    if (outline) {
        outline.src = outline.src.split('#')[0] + '#' + button.dataset.anchor;
        outline.scrollIntoView({block: 'start'});
    }
};

/**
 * Let the broken items in the side lists inside an element jump to their place in the preview.
 *
 * @param {HTMLElement} root
 */
export const enableJumps = (root) => {
    if (!root || root.dataset.jumps) {
        return;
    }
    root.dataset.jumps = '1';
    root.addEventListener('click', (e) => {
        const button = e.target.closest('[data-action="local-submissioncheck-jump"]');
        if (button) {
            showBroken(root, button);
        }
    });
};

/**
 * Draw every Word document preview inside an element.
 *
 * @param {HTMLElement} root
 */
export const renderAll = (root) => {
    if (!root) {
        return;
    }
    root.querySelectorAll('[data-region="local-submissioncheck-docx"]').forEach((region) => {
        renderOne(region);
    });
};
