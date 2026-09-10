import assert from 'node:assert/strict';
import { describe, it } from 'node:test';

import { collectStylesFor, differences } from './input-styles.mjs';

/** What a probe input reads on the Sylius 2 shop theme, taken off a real store's pay page. */
const SYLIUS_REST = {
    'border-color': 'rgb(222, 226, 230)',
    'box-shadow': 'none',
    'outline-width': '3px',
    'outline-color': 'rgb(33, 37, 41)',
    'background-color': 'rgb(255, 255, 255)',
    color: 'rgb(33, 37, 41)',
};

const SYLIUS_FOCUSED = { ...SYLIUS_REST, 'box-shadow': 'rgb(0, 0, 0) 0px 0px 0px 0.8px' };

const SYLIUS_INVALID = { ...SYLIUS_REST, 'border-color': 'rgb(220, 53, 69)' };

const SYLIUS_PLACEHOLDER = { color: 'rgba(33, 37, 41, 0.75)', opacity: '1', 'font-style': 'normal', 'font-weight': '400' };

describe('differences', () => {
    it('keeps what the state changes and drops what it does not', () => {
        assert.deepEqual(
            differences(SYLIUS_REST, SYLIUS_FOCUSED, ['border-color', 'box-shadow']),
            { 'box-shadow': 'rgb(0, 0, 0) 0px 0px 0px 0.8px' },
        );
    });

    it('ignores a property the probe could not read', () => {
        assert.deepEqual(differences(SYLIUS_REST, { 'border-color': '' }, ['border-color']), {});
        assert.deepEqual(differences(null, null, ['border-color']), {});
    });
});

describe('collectStylesFor', () => {
    it('hands the frame the Sylius theme: its focus ring, its invalid border and its placeholder', () => {
        assert.deepEqual(
            collectStylesFor({ rest: SYLIUS_REST, focused: SYLIUS_FOCUSED, invalid: SYLIUS_INVALID, placeholder: SYLIUS_PLACEHOLDER }),
            {
                focusCss: { 'box-shadow': 'rgb(0, 0, 0) 0px 0px 0px 0.8px', 'outline-width': '0' },
                invalidCss: { 'border-color': 'rgb(220, 53, 69)' },
                placeholderCss: { color: 'rgba(33, 37, 41, 0.75)', 'font-style': 'normal', 'font-weight': '400' },
            },
        );
    });

    it('says nothing about focus when the theme draws nothing on focus, so the frame keeps its own ring', () => {
        const styles = collectStylesFor({ rest: SYLIUS_REST, focused: { ...SYLIUS_REST }, invalid: SYLIUS_INVALID, placeholder: SYLIUS_PLACEHOLDER });

        assert.equal(styles.focusCss, undefined);
        assert.deepEqual(styles.invalidCss, { 'border-color': 'rgb(220, 53, 69)' });
    });

    it('leaves an outline the theme asked for alone', () => {
        const focused = { ...SYLIUS_REST, 'outline-width': '2px', 'outline-color': 'rgb(0, 0, 255)' };

        assert.deepEqual(
            collectStylesFor({ rest: SYLIUS_REST, focused }).focusCss,
            { 'outline-width': '2px', 'outline-color': 'rgb(0, 0, 255)' },
        );
    });

    it('does not repeat a placeholder at full opacity, which is what the frame draws anyway', () => {
        assert.deepEqual(collectStylesFor({ placeholder: { color: 'rgb(117, 117, 117)', opacity: '1' } }), { placeholderCss: { color: 'rgb(117, 117, 117)' } });
        assert.deepEqual(collectStylesFor({ placeholder: { color: 'rgb(117, 117, 117)', opacity: '0.5' } }), { placeholderCss: { color: 'rgb(117, 117, 117)', opacity: '0.5' } });
    });

    it('is empty for a probe that read nothing, so the frame is left exactly as Collect.js draws it', () => {
        assert.deepEqual(collectStylesFor({}), {});
        assert.deepEqual(collectStylesFor(), {});
        assert.deepEqual(collectStylesFor({ rest: SYLIUS_REST, focused: null, invalid: undefined, placeholder: {} }), {});
    });
});
