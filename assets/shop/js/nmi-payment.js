/*
 * Mounts NMI's card fields on the pay page, authenticates the card with 3-D Secure, and sends
 * back the token together with what the authentication produced.
 *
 * The card number, expiry and verification value are entered inside the gateway's own frames and
 * are never readable from this page, so nothing here can forward them even by accident: the only
 * things this file ever sends to the store are the token and the authentication result.
 *
 * Everything it needs is on the mount element, put there by the server.
 */
import { mountNmiPayments, mountNmiThreeDSecure } from '@nmipayments/nmi-pay';

const MOUNT_SELECTOR = '#nmi-payment';
const THREE_D_SECURE_SELECTOR = '#nmi-three-d-secure';
const STORE_CARD_SELECTOR = '#nmi-store-card';
const STORED_CARDS_SELECTOR = '#nmi-stored-cards';
const STORED_CARD_PAY_SELECTOR = '#nmi-stored-card-pay';
const STORED_CARD_ERROR_SELECTOR = '#nmi-stored-card-error';
const PAYMENT_SOURCE_SELECTOR = 'input[name="nmi_payment_source"]';
const NEW_CARD_ONLY_SELECTOR = '[data-nmi-new-card-only]';

/* The new-card form is the choice when there is nothing saved to choose instead. */
const NEW_CARD = 'new';

/*
 * Whether the shopper asked for the card to be kept.
 *
 * Read when the form is submitted rather than when it is mounted, because they may tick it after
 * the card fields are already up. Absent unless the server rendered it — and the server checks
 * again on the way in regardless, because this is a request for something, never permission.
 */
const storeCardRequested = () => document.querySelector(STORE_CARD_SELECTOR)?.checked === true;

/*
 * How the component's own token lookup described the card: the gateway's masked number, the
 * expiry and the brand. Sent only when the shopper asked for the card to be kept, and only to let
 * the store notice it is one they already have before the gateway is asked to keep a second copy.
 *
 * `lookupData` is documented as present only when the lookup succeeded, so every field here is
 * optional and postToken drops the ones that are missing. **This is never a card number**: the
 * component reports it already masked, as `411111******1111`.
 */
const describedCard = (event) => {
    const card = event.lookupData?.card;
    if (!card) {
        return {};
    }

    // The last four digits, taken from the mask the component already returns. The store refuses
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

/** The component's names for the authentication result, under the names the store expects. */
const authenticationFields = (event) => ({
    cardholder_auth: event.cardHolderAuth,
    cavv: event.cavv,
    xid: event.xid,
    eci: event.eci,
    three_ds_version: event.threeDsVersion,
    directory_server_id: event.directoryServerId,
});

/*
 * The component reports a completed authentication and a failed one, but there is a third
 * outcome it reports through neither: when the gateway blocks authentication outright — its own
 * console carries "Blocked due to Failed Authentication rule" — no callback runs at all. Without
 * a deadline the shopper waits on a spinner for ever, so one is armed before authentication
 * starts and pushed back once a challenge appears, because from that point a person is typing.
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

const mount = (container) => {
    if (container.dataset.nmiMounted === 'true') {
        return;
    }
    container.dataset.nmiMounted = 'true';

    const threeDSecure = sharedAuthenticator();

    mountNmiPayments(container, {
        tokenizationKey: container.dataset.nmiTokenizationKey,
        layout: 'multiLine',
        // Cards only. Wallets and bank debits are out of this plugin's scope, and offering a
        // method the store cannot charge would be worse than not offering it.
        paymentMethods: ['card'],
        onPay: async (event) => {
            if (!event || !event.token) {
                return container.dataset.nmiErrorMessage || 'The card could not be read.';
            }

            let fields = {};

            if (threeDSecure !== null) {
                const outcome = await threeDSecure.authenticate(paymentInformation(container, event.token));

                // A shopper who fails, abandons, or is blocked is not charged: returning the
                // message leaves them on the page with the form intact.
                if (!outcome.authenticated) {
                    return outcome.message || threeDSecure.notAuthenticated();
                }

                fields = outcome.fields;
            }

            postToken(container, {
                payment_token: event.token,
                _csrf_token: container.dataset.nmiCsrfToken,
                // Omitted when unticked: postToken drops empty values, so an unsaved card sends
                // nothing at all rather than a falsy flag the server would have to interpret.
                ...(storeCardRequested() ? { store_card: '1', ...describedCard(event) } : {}),
                // The account's add-a-card page asks for this, and needs it: the gateway's vault
                // call answers with the masked number and the expiry and no brand at all, so
                // without it the card cannot be described and the record would be stranded there.
                ...(container.dataset.nmiDescribeCard !== undefined ? { card_brand: event.lookupData?.card?.type } : {}),
                ...fields,
            });

            // The page is already navigating; this only stops the component showing an error.
            return true;
        },
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
 * The card fields are put away rather than unmounted: the component owns iframes it would have to
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
