/*
 * A page for the script to live in, and the two things Node lacks: a DOM and Collect.js.
 *
 * jsdom supplies the DOM; the globals the script reaches for are copied onto `globalThis` before
 * the script is imported, because it looks them up as bare names. Collect.js is faked on
 * `window` the way the script finds a store's own copy: `configure` keeps the options and reports
 * the frames ready, `startPaymentRequest` answers with whatever the test queued.
 */
import { JSDOM } from 'jsdom';

export const givenAPage = (html) => {
    const dom = new JSDOM(`<!doctype html><html><body>${html}</body></html>`, { url: 'https://store.example/en_US/pay' });
    const { window } = dom;

    for (const name of ['window', 'document', 'HTMLElement', 'HTMLFormElement', 'HTMLInputElement', 'CustomEvent', 'Event', 'Node', 'getComputedStyle']) {
        globalThis[name] = window[name] ?? window;
    }
    globalThis.window = window;
    globalThis.document = window.document;
    globalThis.getComputedStyle = window.getComputedStyle.bind(window);

    // jsdom does not navigate; what the script would post is kept for the test to read.
    const submitted = [];
    window.HTMLFormElement.prototype.submit = function submit() {
        submitted.push(Object.fromEntries([...this.querySelectorAll('input')].map((input) => [input.name, input.value])));
    };

    const collect = {
        options: null,
        responses: [],
        inSubmission: false,
        configure(options) {
            this.options = options;
            setTimeout(() => options.fieldsAvailableCallback?.(), 0);
        },
        startPaymentRequest() {
            this.inSubmission = true;
            const response = this.responses.shift();
            setTimeout(() => this.options.callback(response), 0);
        },
    };
    window.CollectJS = collect;

    return { window, document: window.document, collect, submitted };
};

export const tick = () => new Promise((resolve) => setTimeout(resolve, 5));

export const CARD_FORM = `
    <div data-nmi-payment data-nmi-tokenization-key="tok-public" data-nmi-amount="1299" data-nmi-currency="USD"
         data-nmi-amount-major="12.99" data-nmi-action-url="/nmi/pay/hash-1" data-nmi-csrf-token="csrf-1"
         data-nmi-error-message="The card details could not be read." data-nmi-unavailable-message="The card form could not be loaded."
         data-nmi-auth-failed-message="The card could not be authenticated.">
        <div data-nmi-field="ccnumber" data-nmi-title="Card number"></div>
        <div data-nmi-field="ccexp" data-nmi-title="Expiry date"></div>
        <div data-nmi-field="cvv" data-nmi-title="Security code"></div>
        <p data-nmi-error hidden></p>
    </div>
    <button type="button" data-nmi-pay-button disabled>Pay</button>
    <div data-nmi-three-d-secure></div>
`;
