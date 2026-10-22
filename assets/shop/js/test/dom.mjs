/*
 * A page for the script to live in, and the two things Node lacks: a DOM and Collect.js.
 *
 * jsdom supplies the DOM; the globals the script reaches for are copied onto `globalThis` before
 * the script is imported, because it looks them up as bare names. Collect.js is faked on
 * `window` the way the script finds a store's own copy: `configure` keeps the options, builds the
 * frames and reports them ready, `startPaymentRequest` answers with whatever the test queued.
 *
 * The frames are built as the hosted Collect.js builds them, read in its source: once a promise
 * of its own has settled, so always after `configure()` has returned, and each sized by what a
 * probe input measures inside its field — nothing, when the field is not displayed. Here that is
 * a frame of `42px` for a displayed field and of `0px` for one that is not.
 */
import { JSDOM } from 'jsdom';

/* Whether an element is displayed: neither it nor an ancestor is hidden or `display: none`. */
const displayed = (window, element) => {
    for (let node = element; node; node = node.parentElement) {
        if (node.hidden || window.getComputedStyle(node).display === 'none') {
            return false;
        }
    }

    return true;
};

export const givenAPage = (html) => {
    const dom = new JSDOM(`<!doctype html><html><body>${html}</body></html>`, { url: 'https://store.example/en_US/pay' });
    const { window } = dom;

    for (const name of ['window', 'document', 'HTMLElement', 'HTMLFormElement', 'HTMLInputElement', 'CustomEvent', 'Event', 'Node', 'MutationObserver', 'getComputedStyle']) {
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
        configured: 0,
        responses: [],
        inSubmission: false,
        configure(options) {
            this.options = options;
            this.configured += 1;
            Promise.resolve().then(() => {
                Object.values(options.fields ?? {}).forEach(({ selector }) => {
                    const field = window.document.querySelector(selector);
                    if (!field) {
                        return;
                    }
                    field.querySelector('iframe')?.remove();
                    const frame = window.document.createElement('iframe');
                    frame.style.height = displayed(window, field) ? '42px' : '0px';
                    field.appendChild(frame);
                });
                setTimeout(() => options.fieldsAvailableCallback?.(), 0);
            });
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

/*
 * The saved-card path as the pay page renders it for a shopper with one card, which is chosen. It
 * sits above the card form on the page, which is where authentication finds its key and its place,
 * so a test renders the two together.
 */
export const STORED_CARDS = `
    <div data-nmi-stored-cards data-nmi-action-url="/nmi/pay/hash-1" data-nmi-csrf-token="csrf-1"
         data-nmi-authenticate-url="/nmi/pay/hash-1/authenticate" data-nmi-authenticate="1">
        <input type="radio" name="nmi_payment_source" data-nmi-payment-source value="42" checked>
        <input type="radio" name="nmi_payment_source" data-nmi-payment-source value="new">
        <p data-nmi-stored-card-error hidden></p>
        <button type="button" data-nmi-stored-card-pay hidden>Pay</button>
    </div>
`;
