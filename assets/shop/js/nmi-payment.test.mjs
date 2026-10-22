import assert from 'node:assert/strict';
import { afterEach, beforeEach, describe, it, mock } from 'node:test';

import { CARD_FORM, STORED_CARDS, givenAPage, tick } from './test/dom.mjs';
import { gateway, nextAuthentication, reset, widgetMarkup, widgets } from './test/nmi-pay.stub.mjs';

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

/*
 * What follows an authentication. The gateway's interface stays mounted after an attempt it never
 * answered and refuses every later one, so the page has to free it before the shopper can try
 * again; after a verdict the widget frees itself. The deadlines are crossed on mocked timers: the
 * script arms them with the global `setTimeout`, which is the one mocked here.
 */
const DECISION_DEADLINE_MS = 45000;
const CHALLENGE_DEADLINE_MS = 600000;

/* Lets every promise and observer waiting to run do so. setImmediate is not mocked. */
const settle = async () => {
    for (let round = 0; round < 5; round += 1) {
        await new Promise((resolve) => setImmediate(resolve));
    }
};

/* Moves the mocked clock on, after and before letting everything waiting on it run. */
const advance = async (ms = 1) => {
    await settle();
    mock.timers.tick(ms);
    await settle();
};

describe('authenticating again on the same page', () => {
    let page;
    let button;
    let fetch;

    const start = async (html) => {
        page = givenAPage(html);
        button = page.document.querySelector('[data-nmi-pay-button]');
        await load();
        await advance();
    };

    const payWithANewCard = async (token) => {
        page.collect.responses.push({ token, card: null });
        button.click();
        await advance();
        await advance();
    };

    const shown = () => page.document.querySelector('[data-nmi-error]').textContent;

    beforeEach(() => {
        reset();
        mock.timers.enable({ apis: ['setTimeout'] });
        ({ fetch } = globalThis);
        globalThis.fetch = async () => ({ ok: true, json: async () => ({ customer_vault_id: '1736036779', currency: 'USD', amount: '12.99' }) });
    });

    afterEach(() => {
        mock.timers.reset();
        globalThis.fetch = fetch;
        reset();
    });

    it('authenticates and pays with the next press after an authentication that never answered', async () => {
        await start(CARD_FORM);
        const seen = events(page.document.querySelector('[data-nmi-payment]'), ['nmi:failed']);

        nextAuthentication.silent = true;
        await payWithANewCard('tok-unanswered');
        await advance(DECISION_DEADLINE_MS);

        assert.deepEqual(seen.map((event) => event.detail.reason), ['not_authenticated']);
        assert.equal(shown(), 'The card could not be authenticated.');
        assertIdle(button);
        assert.equal(page.submitted.length, 0, 'Nothing is charged for an attempt nobody answered.');

        await payWithANewCard('tok-next');

        assert.equal(gateway.refused.length, 0, 'The second authentication was not refused.');
        assert.equal(page.submitted.length, 1, 'The second attempt paid, with no reload.');
        assert.equal(page.submitted[0].payment_token, 'tok-next');
        assert.equal(page.submitted[0].cavv, 'AAABBJ');
    });

    it('authenticates a saved card after an attempt with a new card that never answered', async () => {
        await start(STORED_CARDS + CARD_FORM);

        nextAuthentication.silent = true;
        await payWithANewCard('tok-unanswered');
        await advance(DECISION_DEADLINE_MS);

        page.document.querySelector('[data-nmi-stored-card-pay]').click();
        await advance();
        await advance();

        assert.equal(gateway.refused.length, 0);
        assert.equal(page.submitted.length, 1, 'The saved card paid, with no reload.');
        assert.equal(page.submitted[0].stored_card, '42');
        assert.equal(page.submitted[0].cavv, 'AAABBJ');
    });

    it('lets a challenge run past the deadline for a verdict, and closes it once nobody finished it', async () => {
        await start(CARD_FORM);
        const seen = events(page.document.querySelector('[data-nmi-payment]'), ['nmi:failed']);

        nextAuthentication.challenges = true;
        await payWithANewCard('tok-challenged');
        await advance(DECISION_DEADLINE_MS);

        assert.equal(seen.length, 0, 'A shopper in a challenge is not cut short.');
        assertBusy(button, 'Pay');
        assert.equal(widgets[0].closed, 0, 'The challenge is left open.');
        assert.notEqual(gateway.mounted, null);

        await advance(CHALLENGE_DEADLINE_MS);

        assert.deepEqual(seen.map((event) => event.detail.reason), ['not_authenticated']);
        assertIdle(button);
        assert.equal(widgets[0].closed, 1, 'The abandoned challenge is closed.');
        assert.equal(gateway.mounted, null);

        await payWithANewCard('tok-next');

        assert.equal(page.submitted.length, 1);
        assert.equal(page.submitted[0].payment_token, 'tok-next');
    });

    it('leaves the widget alone after a verdict, and the next press authenticates', async () => {
        await start(CARD_FORM);

        nextAuthentication.fails = true;
        await payWithANewCard('tok-refused');

        assert.equal(shown(), 'The card could not be authenticated.');
        assert.equal(widgets.length, 1, 'No second widget is mounted.');
        assert.equal(widgets[0].closed, 0);
        assert.equal(widgets[0].destroyed, false);

        await payWithANewCard('tok-next');

        assert.equal(page.submitted.length, 1);
        assert.equal(widgets.length, 1);
    });

    it('takes a dialog the shopper closed as their answer, and the next press authenticates', async () => {
        await start(CARD_FORM);

        nextAuthentication.challenges = true;
        await payWithANewCard('tok-challenged');
        widgets[0].element.querySelector('.close-button').click();
        await advance();

        assertIdle(button);
        assert.equal(page.submitted.length, 0);
        assert.equal(widgets.length, 1, 'The widget freed itself; nothing was remounted.');

        await payWithANewCard('tok-next');

        assert.equal(page.submitted.length, 1);
    });

    it('does not replace the widget under an attempt that took it over', async () => {
        await start(STORED_CARDS + CARD_FORM);
        const savedCardButton = page.document.querySelector('[data-nmi-stored-card-pay]');

        nextAuthentication.silent = true;
        await payWithANewCard('tok-unanswered');
        await advance(10000);
        savedCardButton.click();
        await advance();

        await advance(DECISION_DEADLINE_MS - 10000);

        assertIdle(button);
        assertBusy(savedCardButton, 'Pay');
        assert.equal(widgets.length, 1, 'The saved card is still waiting on this widget.');
        assert.equal(widgets[0].closed, 0);

        await advance(10000);

        assertIdle(savedCardButton);
        assert.equal(widgets.length, 2, 'Replaced once the last attempt on it was given up.');
        assert.equal(widgets[0].closed, 1);
    });

    it('remounts a widget it cannot close, and says so in the console', async () => {
        widgetMarkup.closeControl = false;
        const warnings = [];
        const { warn } = console;
        console.warn = (message) => warnings.push(message);
        try {
            await start(CARD_FORM);
            const target = page.document.querySelector('[data-nmi-three-d-secure]');

            nextAuthentication.silent = true;
            await payWithANewCard('tok-unanswered');
            await advance(DECISION_DEADLINE_MS);

            assert.equal(warnings.length, 1);
            assert.match(warnings[0], /reload/);
            assert.equal(widgets.length, 2, 'A fresh widget was mounted.');
            assert.equal(widgets[0].destroyed, true);
            assert.equal(widgets[1].target, target, 'On the same element.');
            assert.deepEqual([...target.children], [widgets[1].element], 'Only the fresh widget is on the page.');
        } finally {
            console.warn = warn;
        }
    });
});

/*
 * The province as the issuer is told it. Sylius keeps the whole ISO 3166-2 code and NMI takes only
 * the two-character subdivision, so the page carries one and the authentication sends the other.
 */
describe('the province the issuer is told', () => {
    beforeEach(() => reset());

    afterEach(() => reset());

    const toldFor = async (state) => {
        const page = givenAPage(CARD_FORM);
        const container = page.document.querySelector('[data-nmi-payment]');
        if (state !== undefined) {
            container.dataset.nmiState = state;
        }
        await load();
        await tick();

        page.collect.responses.push({ token: 'tok-once-abc', card: null });
        page.document.querySelector('[data-nmi-pay-button]').click();
        await tick();
        await tick();

        const [started] = widgets.at(-1).started;

        return started;
    };

    it('is the subdivision of the code Sylius keeps', async () => {
        assert.equal((await toldFor('US-FL')).state, 'FL');
    });

    it('is the subdivision as it is when the page already carries only that', async () => {
        assert.equal((await toldFor('FL')).state, 'FL');
    });

    for (const state of ['ES-M', 'GB-ENG', 'Florida']) {
        it(`is left out when ${state} yields no two-character subdivision`, async () => {
            assert.equal((await toldFor(state)).state, undefined);
        });
    }

    it('is left out when the page carries none', async () => {
        assert.equal((await toldFor(undefined)).state, undefined);
    });
});

/*
 * A page that opens on a saved card, which puts the card form away before it mounts. Collect.js
 * sizes its frames by what it measures in each field when it builds them, so they are built only
 * once the fields are shown.
 */
describe('the card form behind a saved card', () => {
    const PUT_AWAY_CARD_FORM = CARD_FORM
        .replace('<div data-nmi-payment ', '<div data-nmi-payment data-nmi-new-card-only ')
        .replace('<button type="button" data-nmi-pay-button disabled>', '<button type="button" data-nmi-pay-button data-nmi-new-card-only disabled>');

    let page;
    let container;
    let timers;
    let fetch;

    /* The window's own timers, which the frames deadline runs on, kept to be fired by hand. */
    const keepWindowTimers = () => {
        timers = [];
        page.window.setTimeout = (callback, ms) => timers.push({ callback, ms, cleared: false });
        page.window.clearTimeout = (id) => {
            if (timers[id - 1]) {
                timers[id - 1].cleared = true;
            }
        };
    };

    const passTheFramesDeadline = () => timers.filter((timer) => timer.ms === 20000 && !timer.cleared).forEach((timer) => timer.callback());

    const chooseANewCard = async () => {
        const radio = page.document.querySelector('[data-nmi-payment-source][value="new"]');
        radio.checked = true;
        radio.dispatchEvent(new page.window.Event('change', { bubbles: true }));
        await tick();
        await tick();
    };

    const frames = () => [...page.document.querySelectorAll('[data-nmi-field] iframe')];

    beforeEach(() => {
        reset();
        page = givenAPage(STORED_CARDS + PUT_AWAY_CARD_FORM);
        container = page.document.querySelector('[data-nmi-payment]');
        keepWindowTimers();
        ({ fetch } = globalThis);
    });

    afterEach(() => {
        globalThis.fetch = fetch;
        reset();
    });

    it('builds no card fields while the saved card is chosen, and never says the form could not be loaded', async () => {
        const seen = events(container, ['nmi:mounted', 'nmi:failed']);
        await load();
        await tick();

        assert.equal(container.hidden, true, 'The saved card is chosen.');
        assert.equal(page.collect.configured, 0, 'Nothing is built behind the saved card.');

        passTheFramesDeadline();

        assert.deepEqual(seen, []);
        assert.equal(page.document.querySelector('[data-nmi-error]').hidden, true);
    });

    it('builds the fields at the theme\'s height once a new card is chosen, and says so once', async () => {
        const seen = events(container, ['nmi:mounted']);
        await load();
        await tick();

        await chooseANewCard();

        assert.equal(page.collect.configured, 1);
        assert.equal(frames().length, 3);
        frames().forEach((frame) => assert.equal(frame.style.height, '42px', 'Measured while shown.'));
        assert.equal(page.document.querySelector('[data-nmi-pay-button]').disabled, false);
        assert.equal(seen.length, 1);
    });

    it('pays with the new card chosen there', async () => {
        await load();
        await tick();
        await chooseANewCard();

        page.collect.responses.push({ token: 'tok-new-card', card: null });
        page.document.querySelector('[data-nmi-pay-button]').click();
        await tick();
        await tick();

        assert.equal(page.submitted.length, 1);
        assert.equal(page.submitted[0].payment_token, 'tok-new-card');
        assert.equal(page.submitted[0].cavv, 'AAABBJ');
    });

    it('counts the frames deadline from the moment the fields are built', async () => {
        const seen = events(container, ['nmi:failed']);
        await load();
        await tick();
        passTheFramesDeadline();
        page.collect.configure = function configure(options) {
            this.options = options;
            this.configured += 1;
        };

        await chooseANewCard();
        passTheFramesDeadline();

        assert.deepEqual(seen.map((event) => event.detail.reason), ['unavailable']);
    });
});

describe('the frames', () => {
    it('carry their field\'s title once they exist', async () => {
        reset();
        const page = givenAPage(CARD_FORM);
        await load();
        await tick();

        const titled = [...page.document.querySelectorAll('[data-nmi-field]')].map((field) => [field.dataset.nmiTitle, field.querySelector('iframe')?.title]);

        assert.deepEqual(titled, [['Card number', 'Card number'], ['Expiry date', 'Expiry date'], ['Security code', 'Security code']]);
    });
});
