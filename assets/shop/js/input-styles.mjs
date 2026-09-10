/*
 * What the gateway's frames are told about the theme's inputs beyond their resting look.
 *
 * Collect.js copies a resting `form-control` into each frame by itself — height, border, radius,
 * font, colours — and that is where the fields get their look. What it cannot copy is a state:
 * how the theme draws an input that has focus, one the store has marked invalid, and the colour of
 * its placeholder. The script reads those off a probe input on the page, and this module turns the
 * readings into the three style options Collect.js accepts for them, sending only what differs
 * from the resting input and only properties a frame will apply. No DOM in here, on purpose: it
 * is what lets Node test it without a browser.
 */

/** Of everything a probe can read, what a frame accepts for an input with focus. */
const FOCUS_PROPERTIES = ['border-color', 'box-shadow', 'outline-width', 'outline-color', 'background-color', 'color'];

/** For an input the theme marks invalid. */
const INVALID_PROPERTIES = ['border-color', 'box-shadow', 'background-color', 'color'];

/** For the placeholder: the frame accepts font and colour properties there, and nothing about the box. */
const PLACEHOLDER_PROPERTIES = ['color', 'opacity', 'font-style', 'font-weight', 'letter-spacing', 'text-transform'];

const present = (value) => typeof value === 'string' && value.trim() !== '';

/**
 * The properties of `state` that a theme actually changes from `rest`.
 *
 * @param {Record<string, string> | null | undefined} rest the resting input
 * @param {Record<string, string> | null | undefined} state the same input in one state
 * @param {string[]} properties the properties worth comparing
 * @returns {Record<string, string>}
 */
export const differences = (rest, state, properties) => {
    const changed = {};

    properties.forEach((property) => {
        const value = state?.[property];
        if (present(value) && value !== rest?.[property]) {
            changed[property] = value.trim();
        }
    });

    return changed;
};

/**
 * Collect.js's `focusCss`, `invalidCss` and `placeholderCss` for this theme, each present only
 * when the theme gives the frame something to do.
 *
 * @param {{ rest?: Record<string, string> | null, focused?: Record<string, string> | null, invalid?: Record<string, string> | null, placeholder?: Record<string, string> | null }} readings
 * @returns {{ focusCss?: Record<string, string>, invalidCss?: Record<string, string>, placeholderCss?: Record<string, string> }}
 */
export const collectStylesFor = ({ rest, focused, invalid, placeholder } = {}) => {
    const styles = {};

    const focusCss = differences(rest, focused, FOCUS_PROPERTIES);
    if (Object.keys(focusCss).length > 0) {
        // A theme that draws its own focus — a ring, a border — has already switched the browser's
        // outline off, the way Bootstrap does, and the frame must not draw both. A theme that asks
        // for an outline of its own is left alone.
        if (!('outline-width' in focusCss)) {
            focusCss['outline-width'] = '0';
        }
        styles.focusCss = focusCss;
    }

    const invalidCss = differences(rest, invalid, INVALID_PROPERTIES);
    if (Object.keys(invalidCss).length > 0) {
        styles.invalidCss = invalidCss;
    }

    // Not a difference from anything: the placeholder is its own pseudo-element, and the frame
    // draws it in the browser's default grey unless told otherwise. Full opacity is that default
    // and is not worth saying.
    const placeholderCss = {};
    PLACEHOLDER_PROPERTIES.forEach((property) => {
        const value = placeholder?.[property];
        if (present(value) && !(property === 'opacity' && value.trim() === '1')) {
            placeholderCss[property] = value.trim();
        }
    });
    if (Object.keys(placeholderCss).length > 0) {
        styles.placeholderCss = placeholderCss;
    }

    return styles;
};
