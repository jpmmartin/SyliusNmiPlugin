import assert from 'node:assert/strict';
import { beforeEach, describe, it } from 'node:test';

import { CARD_FORM, givenAPage, tick } from './test/dom.mjs';

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
