import assert from 'node:assert/strict';
import { afterEach, beforeEach, describe, it } from 'node:test';

import { CARD_FORM, STORED_CARDS, givenAPage, tick } from './test/dom.mjs';
import { nextAuthentication } from './test/nmi-pay.stub.mjs';

/*
 * The contract a store's own script can rely on: where the parts are found, what the four events
 * carry, what `tokenize` mode does and does not do, what `submit` posts. Run with the gateway's
 * widget package routed to a stand-in (see test/register.mjs) and Collect.js faked on the page.
 */
const load = async () => import(`./nmi-payment.js?${Math.random()}`);

const events = (element, names) => {
    const seen = [];
    names.forEach((name) => element.addEventListener(name, (event) => seen.push({ name, detail: event.detail })));

    return seen;
};

describe('the card form', () => {
    let page;

    beforeEach(() => {
        page = givenAPage(CARD_FORM);
    });

    it('finds its parts by attribute, on the page when they are sibling hookables, and says when it is ready', async () => {
        const container = page.document.querySelector('[data-nmi-payment]');
        const seen = events(container, ['nmi:mounted']);
        const { mount } = await load();

        mount(container);
        await tick();

        assert.equal(page.collect.options.fields.ccnumber.selector, '#nmi-field-ccnumber', 'A field rendered without an id is given one for Collect.js.');
        assert.equal(seen.length, 1, 'Mounted is announced once the frames take input.');
        assert.equal(page.document.querySelector('[data-nmi-pay-button]').disabled, false, 'The button outside the container is the one enabled.');
    });

    it('in tokenize mode hands over the token and its authentication and posts nothing', async () => {
        const container = page.document.querySelector('[data-nmi-payment]');
        container.dataset.nmiMode = 'tokenize';
        const seen = events(container, ['nmi:token', 'nmi:submitted', 'nmi:failed']);
        const { mount } = await load();
        mount(container);
        await tick();

        page.collect.responses.push({ token: 'tok-once-abc', card: { type: 'visa', number: '411111******1111', exp: '1030' } });
        page.document.querySelector('[data-nmi-pay-button]').click();
        await tick();
        await tick();

        assert.equal(page.submitted.length, 0, 'Nothing is posted in tokenize mode.');
        assert.deepEqual(seen.map((event) => event.name), ['nmi:token']);
        const { detail } = seen[0];
        assert.equal(detail.token, 'tok-once-abc');
        assert.equal(detail.authentication.cavv, 'AAABBJ');
        assert.equal(detail.fields.payment_token, 'tok-once-abc');
        assert.equal(detail.fields.eci, '05', 'The fields are what submit would post, minus the CSRF token.');
        assert.equal(page.document.querySelector('[data-nmi-pay-button]').disabled, false, 'The form is usable again.');
    });

    it('in the default mode posts the token with the authentication and the CSRF token, announcing it first', async () => {
        const container = page.document.querySelector('[data-nmi-payment]');
        const seen = events(container, ['nmi:submitted', 'nmi:token']);
        const { mount } = await load();
        mount(container);
        await tick();

        page.collect.responses.push({ token: 'tok-once-abc', card: null });
        page.document.querySelector('[data-nmi-pay-button]').click();
        await tick();
        await tick();

        assert.equal(page.submitted.length, 1);
        assert.equal(page.submitted[0].payment_token, 'tok-once-abc');
        assert.equal(page.submitted[0].cavv, 'AAABBJ');
        assert.equal(page.submitted[0]._csrf_token, 'csrf-1');
        assert.deepEqual(seen.map((event) => event.name), ['nmi:submitted'], 'No token event outside tokenize mode.');
        assert.equal(seen[0].detail.fields._csrf_token, 'csrf-1');
    });

    it('announces a failure with its reason, shows the message, and gives the button back', async () => {
        const container = page.document.querySelector('[data-nmi-payment]');
        const seen = events(container, ['nmi:failed']);
        const { mount } = await load();
        mount(container);
        await tick();

        page.collect.responses.push({});
        page.document.querySelector('[data-nmi-pay-button]').click();
        await tick();

        assert.deepEqual(seen, [{ name: 'nmi:failed', detail: { reason: 'unreadable', message: 'The card details could not be read.' } }]);
        const error = page.document.querySelector('[data-nmi-error]');
        assert.equal(error.hidden, false);
        assert.equal(error.textContent, 'The card details could not be read.');
        assert.equal(page.document.querySelector('[data-nmi-pay-button]').disabled, false);
        assert.equal(page.submitted.length, 0);
    });

    it('refuses a second card form on the same page, because the gateway allows one', async () => {
        const second = page.document.createElement('div');
        second.innerHTML = CARD_FORM;
        page.document.body.appendChild(second);
        const warnings = [];
        const { warn } = console;
        console.warn = (message) => warnings.push(message);
        try {
            const { mount } = await load();
            const [first, other] = page.document.querySelectorAll('[data-nmi-payment]');
            mount(first);
            mount(other);
            await tick();

            assert.equal(first.dataset.nmiMounted, 'true');
            assert.notEqual(other.dataset.nmiMounted, 'true');
            // Refused twice: once by the page's own mounting on load, once by the call above.
            assert.ok(warnings.length >= 1);
            warnings.forEach((warning) => assert.match(warning, /allows one/));
        } finally {
            console.warn = warn;
        }
    });
});

describe('submit', () => {
    it('posts the given fields with the CSRF token, and a listener may keep it from posting', async () => {
        const page = givenAPage(CARD_FORM);
        const container = page.document.querySelector('[data-nmi-payment]');
        const { submit } = await load();

        assert.equal(submit(container, { payment_token: 'tok-kept', cavv: 'AAABBJ' }), true);
        assert.deepEqual(page.submitted, [{ payment_token: 'tok-kept', cavv: 'AAABBJ', _csrf_token: 'csrf-1' }]);

        container.addEventListener('nmi:submitted', (event) => event.preventDefault());
        assert.equal(submit(container, { payment_token: 'tok-refused' }), false);
        assert.equal(page.submitted.length, 1, 'Cancelled, so nothing more was posted.');
    });
});

/*
 * The busy state a press puts a button in: the theme's spinner beside the label, a status line for
 * screen readers, `aria-busy` — for as long as the attempt runs, and not a moment before or after.
 */
const spinnerOf = (button) => button.querySelector('[data-nmi-spinner]');

const assertBusy = (button, label) => {
    assert.equal(button.disabled, true);
    assert.equal(button.getAttribute('aria-busy'), 'true');
    assert.equal(button.querySelectorAll('[data-nmi-spinner]').length, 1, 'Exactly one spinner.');
    const spinner = spinnerOf(button);
    const wheel = spinner.querySelector('.spinner-border.spinner-border-sm');
    assert.ok(wheel, 'The theme\'s small border spinner.');
    assert.equal(wheel.getAttribute('aria-hidden'), 'true');
    const status = spinner.querySelector('.visually-hidden[role="status"]');
    assert.ok(status, 'A line only a screen reader reads.');
    assert.ok(button.textContent.includes(label), 'The label is kept beside the spinner.');

    return status.textContent;
};

const assertIdle = (button) => {
    assert.equal(button.disabled, false);
    assert.equal(button.hasAttribute('aria-busy'), false);
    assert.equal(spinnerOf(button), null);
};

describe('the busy pay button', () => {
    let page;
    let button;

    beforeEach(async () => {
        page = givenAPage(CARD_FORM);
        button = page.document.querySelector('[data-nmi-pay-button]');
    });

    it('shows nothing while the frames load, and nothing once they are ready', async () => {
        await load();

        assert.equal(button.disabled, true);
        assert.equal(spinnerOf(button), null, 'No spinner before anything is pressed.');

        await tick();
        assertIdle(button);
    });

    it('shows the spinner from the press, once however often it is pressed', async () => {
        await load();
        await tick();
        page.collect.startPaymentRequest = () => {};

        button.click();
        button.click();

        assert.equal(assertBusy(button, 'Pay'), 'Processing…', 'The literal, when the template gave no words.');
    });

    it('reads to screen readers the words the template translated', async () => {
        button.dataset.nmiProcessingMessage = 'Procesando…';
        await load();
        await tick();
        page.collect.startPaymentRequest = () => {};

        button.click();

        assert.equal(assertBusy(button, 'Pay'), 'Procesando…');
    });

    it('gives the button back idle when a field is empty or invalid', async () => {
        await load();
        await tick();

        page.collect.responses.push({});
        button.click();
        await tick();

        assertIdle(button);
    });

    it('gives the button back idle when the fields do not answer in time', async () => {
        await load();
        await tick();
        page.collect.startPaymentRequest = () => {};

        button.click();
        assertBusy(button, 'Pay');
        page.collect.options.timeoutCallback();

        assertIdle(button);
    });

    it('keeps the spinner while a successful attempt posts and the page moves on', async () => {
        await load();
        await tick();

        page.collect.responses.push({ token: 'tok-once-abc', card: null });
        button.click();
        await tick();
        await tick();

        assert.equal(page.submitted.length, 1);
        assertBusy(button, 'Pay');
    });

    it('gives the button back idle once tokenize mode has handed the token over', async () => {
        page.document.querySelector('[data-nmi-payment]').dataset.nmiMode = 'tokenize';
        await load();
        await tick();

        page.collect.responses.push({ token: 'tok-once-abc', card: null });
        button.click();
        await tick();
        await tick();

        assertIdle(button);
    });
});

describe('the busy saved-card button', () => {
    let page;
    let button;
    let fetch;

    beforeEach(() => {
        page = givenAPage(STORED_CARDS + CARD_FORM);
        button = page.document.querySelector('[data-nmi-stored-card-pay]');
        ({ fetch } = globalThis);
        globalThis.fetch = async () => ({ ok: true, json: async () => ({ customer_vault_id: '1736036779', currency: 'USD', amount: '12.99' }) });
    });

    afterEach(() => {
        globalThis.fetch = fetch;
        nextAuthentication.fails = false;
    });

    it('is busy from the press and stays busy while the authenticated card is posted', async () => {
        await load();

        button.click();
        assertBusy(button, 'Pay');
        await tick();
        await tick();

        assert.equal(page.submitted.length, 1, 'The saved card was posted.');
        assert.equal(page.submitted[0].stored_card, '42');
        assert.equal(page.submitted[0].cavv, 'AAABBJ', 'Posted after authenticating, which is the path under test.');
        assertBusy(button, 'Pay');
    });

    it('is given back idle when authentication fails', async () => {
        nextAuthentication.fails = true;
        await load();

        button.click();
        await tick();
        await tick();

        assert.equal(page.submitted.length, 0);
        assertIdle(button);
    });
});
