/*
 * Mounts the gateway's card fields on the pay page, authenticates the card with 3-D Secure, and
 * sends back the token together with what the authentication produced.
 *
 * The card number, expiry and verification value are entered inside the gateway's own frames and
 * are never readable from this page, so nothing here can forward them even by accident: the only
 * things this file ever sends to the store are the token and the authentication result.
 *
 * Everything it needs is on the mount element and in the markup around it, put there by the
 * server: the fields are the theme's own form, and the frames are laid into it.
 */
import { mountNmiThreeDSecure } from '@nmipayments/nmi-pay';

import { collectStylesFor } from './input-styles.mjs';

/*
 * The gateway's tokenisation script. NMI publishes it as a hosted script only — it derives every
 * endpoint it uses from its own address — so it is fetched here rather than bundled, and it reads
 * the tokenization key off its own script node, which is why that node is built by hand below.
 */
const COLLECT_JS_URL = 'https://secure.nmi.com/token/Collect.js';

const MOUNT_SELECTOR = '#nmi-payment';
const FIELD_SELECTOR = '[data-nmi-field]';
const PAY_BUTTON_SELECTOR = '#nmi-card-pay';
const CARD_ERROR_SELECTOR = '#nmi-card-error';
const THREE_D_SECURE_SELECTOR = '#nmi-three-d-secure';
const STORE_CARD_SELECTOR = '#nmi-store-card';
const STORED_CARDS_SELECTOR = '#nmi-stored-cards';
const STORED_CARD_PAY_SELECTOR = '#nmi-stored-card-pay';
const STORED_CARD_ERROR_SELECTOR = '#nmi-stored-card-error';
const PAYMENT_SOURCE_SELECTOR = 'input[name="nmi_payment_source"]';
const NEW_CARD_ONLY_SELECTOR = '[data-nmi-new-card-only]';

/*
 * The theme's class for a text input, and the class it marks an invalid one with. The frames copy
 * the look of an input carrying the first, and the probe below reads what the second does to it.
 * Bootstrap's names, which the Sylius 2 shop theme uses.
 */
const INPUT_CLASS = 'form-control';

const INVALID_INPUT_CLASS = 'is-invalid';

/*
 * How long an attempt may wait for the frames to answer with a token or a verdict. Tokenisation
 * takes a second or two; the deadline exists because Collect.js, having reported an empty field,
 * waits for exactly this long before it accepts another attempt — see endAttempt.
 */
const ATTEMPT_DEADLINE_MS = 8000;

/* How long the frames get to report ready before the page says the form could not be loaded. */
const FRAMES_DEADLINE_MS = 20000;

/* The new-card form is the choice when there is nothing saved to choose instead. */
const NEW_CARD = 'new';

/*
 * Whether the shopper asked for the card to be kept.
 *
 * Read when the token arrives rather than when the form is mounted, because they may tick it
 * after the card fields are already up. Absent unless the server rendered it — and the server
 * checks again on the way in regardless, because this is a request for something, never permission.
 */
const storeCardRequested = () => document.querySelector(STORE_CARD_SELECTOR)?.checked === true;

/*
 * How Collect.js described the card when it handed over the token: the gateway's masked number,
 * the expiry and the brand. Sent only when the shopper asked for the card to be kept, and only to
 * let the store notice it is one they already have before the gateway is asked to keep a second
 * copy.
 *
 * `card` is documented as part of the callback's response with every field nullable, so every
 * field here is optional and postToken drops the ones that are missing. **This is never a card
 * number**: the gateway reports it already masked, as `411111******1111`.
 */
const describedCard = (response) => {
    const card = response.card;
    if (!card) {
        return {};
    }

    // The last four digits, taken from the mask the gateway already returns. The store refuses
    // anything else in this field, and there is nothing else here to send: the number never
    // existed on this page in a readable form.
    const lastFour = (card.number ?? '').replace(/\D/g, '').slice(-4);

    return { store_card_brand: card.type, store_card_last_four: lastFour, store_card_exp: card.exp };
};

/**
 * Posts the token as an ordinary form so the browser follows the store's redirect itself and
 * flash messages survive. A background request would have to reimplement both.
 */
const postToken = (container, fields) => {
    const form = document.createElement('form');
    form.method = 'post';
    form.action = container.dataset.nmiActionUrl;
    form.style.display = 'none';

    Object.entries(fields).forEach(([name, value]) => {
        if (value === undefined || value === null || value === '') {
            return;
        }

        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = name;
        input.value = value;
        form.appendChild(input);
    });

    document.body.appendChild(form);
    form.submit();
};

/** What the issuer is told about the shopper. The more it gets, the less likely a challenge. */
const paymentInformation = (container, token) => ({
    paymentToken: token,
    currency: container.dataset.nmiCurrency,
    amount: container.dataset.nmiAmountMajor,
    firstName: container.dataset.nmiFirstName ?? '',
    lastName: container.dataset.nmiLastName ?? '',
    email: container.dataset.nmiEmail,
    address1: container.dataset.nmiAddress1,
    city: container.dataset.nmiCity,
    postalCode: container.dataset.nmiPostalCode,
    state: container.dataset.nmiState,
    country: container.dataset.nmiCountry,
    phone: container.dataset.nmiPhone,
});

/** The widget's names for the authentication result, under the names the store expects. */
const authenticationFields = (event) => ({
    cardholder_auth: event.cardHolderAuth,
    cavv: event.cavv,
    xid: event.xid,
    eci: event.eci,
    three_ds_version: event.threeDsVersion,
    directory_server_id: event.directoryServerId,
});

/*
 * The widget reports a completed authentication and a failed one, but there is a third outcome
 * it reports through neither: when the gateway blocks authentication outright — its own console
 * carries "Blocked due to Failed Authentication rule" — no callback runs at all. Without a
 * deadline the shopper waits on a spinner for ever, so one is armed before authentication starts
 * and pushed back once a challenge appears, because from that point a person is typing.
 */
const DECISION_DEADLINE_MS = 45000;

const CHALLENGE_DEADLINE_MS = 600000;

/*
 * The 3-D Secure widget, built once and shared by both ways of paying.
 *
 * There is one authentication widget on the page and two things that may need it: a card the
 * shopper just typed, which authenticates its token, and a card the gateway already holds, which
 * authenticates its vault reference. Building it twice would mean two mount points fighting over
 * the same element.
 *
 * Null when the store removed either hookable. That is a store deciding to pay without
 * authentication, and the alternative — refusing to charge at all — would be worse.
 */
let authenticator;

const sharedAuthenticator = () => {
    if (authenticator !== undefined) {
        return authenticator;
    }

    const target = document.querySelector(THREE_D_SECURE_SELECTOR);
    const source = document.querySelector(MOUNT_SELECTOR);

    if (!target || !source) {
        authenticator = null;

        return authenticator;
    }

    // The template renders this translated, so the literal is only reached by a store that
    // overrode the template and dropped the attribute. Saying nothing at all would be worse than
    // saying it in one language.
    const notAuthenticated = () => source.dataset.nmiAuthFailedMessage || 'The card could not be authenticated.';

    let settle = null;
    let extendDeadline = null;

    const widget = mountNmiThreeDSecure(target, {
        tokenizationKey: source.dataset.nmiTokenizationKey,
        onChallenge: () => extendDeadline?.(CHALLENGE_DEADLINE_MS),
        onComplete: (event) => settle?.(
            // A completion carrying no cryptogram is not an authentication. It happens, and
            // treating it as success would charge a card nobody vouched for — the one thing
            // the specification forbids. A card the issuer merely does not support still
            // comes back with one, so this rejects nothing that genuinely authenticated.
            event && event.cavv
                ? { authenticated: true, fields: authenticationFields(event) }
                : { authenticated: false, message: notAuthenticated() },
        ),
        onFailure: (event) => settle?.({ authenticated: false, message: event?.message }),
    });

    authenticator = {
        notAuthenticated,
        /* `paymentInfo` names the card either by its token or by its vault reference. */
        authenticate: (paymentInfo) =>
            new Promise((resolve) => {
                let done = false;
                let deadline = null;

                const finish = (outcome) => {
                    if (done) {
                        return;
                    }
                    done = true;
                    clearTimeout(deadline);
                    settle = null;
                    extendDeadline = null;
                    resolve(outcome);
                };

                extendDeadline = (ms) => {
                    clearTimeout(deadline);
                    deadline = setTimeout(() => finish({ authenticated: false, message: notAuthenticated() }), ms);
                };

                settle = finish;
                extendDeadline(DECISION_DEADLINE_MS);

                widget.startThreeDSecure(paymentInfo);
            }),
    };

    return authenticator;
};

/* What the probe reads: the union of what input-styles compares, plus the placeholder's own. */
const PROBED_PROPERTIES = ['border-color', 'box-shadow', 'outline-width', 'outline-color', 'background-color', 'color'];

const PROBED_PLACEHOLDER_PROPERTIES = ['color', 'opacity', 'font-style', 'font-weight', 'letter-spacing', 'text-transform'];

/*
 * What the theme does to a text input beyond its resting look, read off a probe input that exists
 * for one task and is gone before anything is painted. Collect.js copies the resting look into the
 * frames by itself and cannot copy a state — the theme's focus ring, the border it gives an
 * invalid input, the colour of its placeholder — so those are read here and handed to it.
 *
 * Transitions are switched off on the probe because a computed style read straight after a class
 * change reports the value mid-transition. Focus is taken without scrolling and handed back to
 * whatever had it. And `:focus` only matches while the document itself has focus, so from a
 * background tab the focused reading equals the resting one and the frame keeps its own ring.
 */
const probeInputStyles = () => {
    const previouslyFocused = document.activeElement;

    const read = (className, focus) => {
        const input = document.createElement('input');
        input.type = 'text';
        input.placeholder = 'x';
        input.className = className;
        input.setAttribute('aria-hidden', 'true');
        input.tabIndex = -1;
        input.style.transition = 'none';
        document.body.appendChild(input);

        if (focus) {
            input.focus({ preventScroll: true });
        }

        const style = window.getComputedStyle(input);
        const values = {};
        PROBED_PROPERTIES.forEach((property) => {
            values[property] = style.getPropertyValue(property);
        });

        let placeholder = null;
        try {
            const placeholderStyle = window.getComputedStyle(input, '::placeholder');
            placeholder = {};
            PROBED_PLACEHOLDER_PROPERTIES.forEach((property) => {
                placeholder[property] = placeholderStyle.getPropertyValue(property);
            });
        } catch {
            placeholder = null;
        }

        if (focus) {
            input.blur();
        }
        input.remove();

        return { values, placeholder };
    };

    const rest = read(INPUT_CLASS, false);
    const focused = read(INPUT_CLASS, true);
    const invalid = read(`${INPUT_CLASS} ${INVALID_INPUT_CLASS}`, false);

    if (previouslyFocused instanceof HTMLElement && previouslyFocused !== document.body) {
        previouslyFocused.focus({ preventScroll: true });
    }

    return { rest: rest.values, focused: focused.values, invalid: invalid.values, placeholder: rest.placeholder };
};

/*
 * The gateway's script, loaded once per page. Reused when the page already has it — a store may
 * load it for something of its own — and waited for when its tag is there but still loading.
 */
const loadCollectJs = (tokenizationKey) =>
    new Promise((resolve, reject) => {
        if (window.CollectJS) {
            resolve(window.CollectJS);

            return;
        }

        const settleOn = (script) => {
            script.addEventListener('load', () => (window.CollectJS ? resolve(window.CollectJS) : reject(new Error('Collect.js loaded and exposed nothing.'))));
            script.addEventListener('error', () => reject(new Error('Collect.js could not be loaded.')));
        };

        const existing = document.querySelector(`script[src="${COLLECT_JS_URL}"]`);
        if (existing) {
            settleOn(existing);

            return;
        }

        const script = document.createElement('script');
        script.src = COLLECT_JS_URL;
        script.dataset.tokenizationKey = tokenizationKey;
        script.dataset.variant = 'inline';
        settleOn(script);
        document.head.appendChild(script);
    });

const mount = (container) => {
    if (container.dataset.nmiMounted === 'true') {
        return;
    }
    container.dataset.nmiMounted = 'true';

    const fields = [...container.querySelectorAll(FIELD_SELECTOR)];
    const button = document.querySelector(PAY_BUTTON_SELECTOR);
    const errorLine = document.querySelector(CARD_ERROR_SELECTOR);

    // A store that overrode the card form and kept none of its markup. There is nothing to mount
    // into, and saying so in the console beats a page that waits for ever.
    if (fields.length === 0 || !button) {
        console.warn('NMI: the card form has no field elements or no pay button, so nothing was mounted.');

        return;
    }

    // Translated by the template; the literals are only reached by a store that overrode it and
    // dropped the attributes.
    const messages = {
        unreadable: container.dataset.nmiErrorMessage || 'The card details could not be read.',
        unavailable: container.dataset.nmiUnavailableMessage || 'The card form could not be loaded.',
    };

    const say = (message) => {
        if (!errorLine) {
            return;
        }

        errorLine.textContent = message ?? '';
        errorLine.hidden = !message;
    };

    const threeDSecure = sharedAuthenticator();

    let collect = null;
    let ready = false;

    /* The attempt in progress, or null: `tokenising` until the frames answer, `authenticating` after. */
    let attempt = null;

    /*
     * Ends an attempt that went nowhere: says why, hands the button back — and clears Collect.js's
     * own "in submission" flag and its deadline timer. Observed rather than read: after an empty
     * field, Collect.js reports it and keeps the flag set, so every later press is ignored until
     * its own timeout runs out. The two names are public on its instance and documented nowhere;
     * should a later Collect.js drop them, its timeout still frees the button after the deadline.
     */
    const endAttempt = (message) => {
        if (attempt === null) {
            return;
        }
        attempt = null;
        say(message);
        button.disabled = false;

        if (collect && typeof collect.inSubmission === 'boolean') {
            collect.inSubmission = false;
        }
        if (collect && collect.responseTimeout) {
            window.clearTimeout(collect.responseTimeout);
        }
    };

    const onToken = async (response) => {
        // A token after the deadline has already ended the attempt: the shopper has the button
        // back and may be pressing it, and Collect.js prepares a fresh token for that press.
        if (attempt === null) {
            return;
        }

        if (!response || !response.token) {
            endAttempt(messages.unreadable);

            return;
        }

        let authentication = {};

        if (threeDSecure !== null) {
            attempt = { phase: 'authenticating' };
            const outcome = await threeDSecure.authenticate(paymentInformation(container, response.token));

            // A shopper who fails, abandons, or is blocked is not charged: the message leaves
            // them on the page with the form intact.
            if (!outcome.authenticated) {
                endAttempt(outcome.message || threeDSecure.notAuthenticated());

                return;
            }

            authentication = outcome.fields;
        }

        postToken(container, {
            payment_token: response.token,
            _csrf_token: container.dataset.nmiCsrfToken,
            // Omitted when unticked: postToken drops empty values, so an unsaved card sends
            // nothing at all rather than a falsy flag the server would have to interpret.
            ...(storeCardRequested() ? { store_card: '1', ...describedCard(response) } : {}),
            // The account's add-a-card page asks for this, and needs it: the gateway's vault
            // call answers with the masked number and the expiry and no brand at all, so
            // without it the card cannot be described and the record would be stranded there.
            ...(container.dataset.nmiDescribeCard !== undefined ? { card_brand: response.card?.type } : {}),
            ...authentication,
        });

        // The page is navigating; the button stays disabled so it cannot be pressed twice.
    };

    button.addEventListener('click', () => {
        if (!ready || attempt !== null || collect === null) {
            return;
        }

        attempt = { phase: 'tokenising' };
        say(null);
        button.disabled = true;
        collect.startPaymentRequest();
    });

    const fieldOptions = {};
    fields.forEach((field) => {
        fieldOptions[field.dataset.nmiField] = {
            selector: `#${field.id}`,
            title: field.dataset.nmiTitle ?? '',
            placeholder: field.dataset.nmiPlaceholder ?? '',
        };
    });

    const framesDeadline = window.setTimeout(() => {
        if (!ready) {
            say(messages.unavailable);
        }
    }, FRAMES_DEADLINE_MS);

    loadCollectJs(container.dataset.nmiTokenizationKey)
        .then((instance) => {
            collect = instance;

            /*
             * Collect.js measures a probe input inside each field element while configuring, and
             * an element hidden because a saved card is preselected measures nothing. So the
             * new-card path is shown for the duration of the call — which is synchronous, so
             * nothing is painted in between — and put away again.
             */
            const putAway = [...document.querySelectorAll(NEW_CARD_ONLY_SELECTOR)].filter((element) => element.hidden);
            putAway.forEach((element) => {
                element.hidden = false;
            });

            try {
                collect.configure({
                    variant: 'inline',
                    styleSniffer: true,
                    snifferClass: INPUT_CLASS,
                    fields: fieldOptions,
                    ...collectStylesFor(probeInputStyles()),
                    timeoutDuration: ATTEMPT_DEADLINE_MS,
                    timeoutCallback: () => {
                        if (attempt?.phase === 'tokenising') {
                            endAttempt(messages.unreadable);
                        }
                    },
                    // Reported on blur as well as on an attempt; only the attempt is anyone's
                    // business here — the frame itself draws the invalid border either way.
                    validationCallback: (field, valid) => {
                        if (!valid && attempt?.phase === 'tokenising') {
                            endAttempt(messages.unreadable);
                        }
                    },
                    fieldsAvailableCallback: () => {
                        ready = true;
                        window.clearTimeout(framesDeadline);
                        button.disabled = false;
                    },
                    callback: onToken,
                });
            } finally {
                putAway.forEach((element) => {
                    element.hidden = true;
                });
            }

            // The title Collect.js is given names the input inside the frame; the frame itself
            // gets none, and a frame without a title is what a screen reader announces as such.
            fields.forEach((field) => {
                const frame = field.querySelector('iframe');
                if (frame && !frame.title) {
                    frame.title = field.dataset.nmiTitle ?? '';
                }
            });
        })
        .catch(() => {
            window.clearTimeout(framesDeadline);
            say(messages.unavailable);
        });
};

/*
 * Which of the two ways to pay the shopper picked. Absent radios mean there is nothing saved, so
 * the new-card form is the only path and the answer never changes.
 */
const chosenSource = () => document.querySelector(`${PAYMENT_SOURCE_SELECTOR}:checked`)?.value ?? NEW_CARD;

/*
 * What the store will tell us about a saved card, and only when asked.
 *
 * **The vault reference is deliberately not on the page.** It is fetched here, at the moment the
 * shopper presses Pay, from a route that hands it to the card's owner and to nobody else — so it
 * never appears in the page source, a cached copy or a screenshot. Null when the store refuses,
 * which is what an expired, foreign or deleted card looks like from here.
 */
const authenticationDetails = async (container, storedCard) => {
    const body = new FormData();
    body.append('stored_card', storedCard);
    body.append('_csrf_token', container.dataset.nmiCsrfToken);

    try {
        const response = await fetch(container.dataset.nmiAuthenticateUrl, {
            method: 'POST',
            body,
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
        });

        return response.ok ? await response.json() : null;
    } catch {
        return null;
    }
};

/*
 * Swaps between the two ways to pay.
 *
 * The card fields are put away rather than unmounted: the gateway owns frames it would have to
 * rebuild, and a shopper toggling back and forth would watch them flicker for nothing.
 */
const mountStoredCards = (container) => {
    if (container.dataset.nmiMounted === 'true') {
        return;
    }
    container.dataset.nmiMounted = 'true';

    const payButton = container.querySelector(STORED_CARD_PAY_SELECTOR);
    const errorMessage = container.querySelector(STORED_CARD_ERROR_SELECTOR);

    const say = (message) => {
        if (!errorMessage) {
            return;
        }

        errorMessage.textContent = message;
        errorMessage.hidden = message === '';
    };

    const show = () => {
        const savedCard = chosenSource() !== NEW_CARD;

        document.querySelectorAll(NEW_CARD_ONLY_SELECTOR).forEach((element) => {
            element.hidden = savedCard;
        });

        if (payButton) {
            payButton.hidden = !savedCard;
        }
    };

    document
        .querySelectorAll(PAYMENT_SOURCE_SELECTOR)
        .forEach((radio) => radio.addEventListener('change', show));

    payButton?.addEventListener('click', async () => {
        const storedCard = chosenSource();
        if (storedCard === NEW_CARD) {
            return;
        }

        payButton.disabled = true;
        say('');

        const threeDSecure = container.dataset.nmiAuthenticate === '1' ? sharedAuthenticator() : null;

        if (threeDSecure !== null) {
            const details = await authenticationDetails(container, storedCard);

            // The store would not say which card this is, which is the same answer the charge
            // would give. Let it fail there, where the shopper gets a sentence about it.
            if (details === null) {
                postToken(container, { stored_card: storedCard, _csrf_token: container.dataset.nmiCsrfToken });

                return;
            }

            const outcome = await threeDSecure.authenticate({
                // NMI's vault integration authenticates the reference rather than a token, and
                // asks for nothing else. Its prose calls this `customerVaultToken` and its own
                // example calls it `customerVaultId`; the example is what integrators run.
                customerVaultId: details.customer_vault_id,
                currency: details.currency,
                amount: details.amount,
            });

            if (!outcome.authenticated) {
                say(outcome.message || threeDSecure.notAuthenticated());
                payButton.disabled = false;

                return;
            }

            postToken(container, {
                stored_card: storedCard,
                _csrf_token: container.dataset.nmiCsrfToken,
                ...outcome.fields,
            });

            return;
        }

        // Nothing is tokenised and nothing is authenticated: the card is already at the gateway,
        // and the store reads its reference out of its own row rather than from this page.
        postToken(container, { stored_card: storedCard, _csrf_token: container.dataset.nmiCsrfToken });
    });

    show();
};

const mountAll = () => {
    document.querySelectorAll(STORED_CARDS_SELECTOR).forEach(mountStoredCards);
    document.querySelectorAll(MOUNT_SELECTOR).forEach(mount);
};

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', mountAll);
} else {
    mountAll();
}
