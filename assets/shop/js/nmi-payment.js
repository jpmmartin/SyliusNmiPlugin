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

const mount = (container) => {
    if (container.dataset.nmiMounted === 'true') {
        return;
    }
    container.dataset.nmiMounted = 'true';

    const tokenizationKey = container.dataset.nmiTokenizationKey;
    const target = document.querySelector(THREE_D_SECURE_SELECTOR);
    // The template renders both of these translated, so the literals are only reached by a
    // store that overrode the template and dropped the attribute. Saying nothing at all
    // would be worse than saying it in one language.
    const notAuthenticated = () => container.dataset.nmiAuthFailedMessage || 'The card could not be authenticated.';

    let settle = null;
    let extendDeadline = null;

    // A store that removed the authentication hookable has decided to pay without it. That is its
    // decision to make, and the alternative — refusing to charge at all — would be worse.
    const threeDSecure = target
        ? mountNmiThreeDSecure(target, {
              tokenizationKey,
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
          })
        : null;

    const authenticate = (token) =>
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

            threeDSecure.startThreeDSecure(paymentInformation(container, token));
        });

    mountNmiPayments(container, {
        tokenizationKey,
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
                const outcome = await authenticate(event.token);

                // A shopper who fails, abandons, or is blocked is not charged: returning the
                // message leaves them on the page with the form intact.
                if (!outcome.authenticated) {
                    return outcome.message || notAuthenticated();
                }

                fields = outcome.fields;
            }

            postToken(container, {
                payment_token: event.token,
                _csrf_token: container.dataset.nmiCsrfToken,
                // Omitted when unticked: postToken drops empty values, so an unsaved card sends
                // nothing at all rather than a falsy flag the server would have to interpret.
                ...(storeCardRequested() ? { store_card: '1', ...describedCard(event) } : {}),
                ...fields,
            });

            // The page is already navigating; this only stops the component showing an error.
            return true;
        },
    });
};

const mountAll = () => document.querySelectorAll(MOUNT_SELECTOR).forEach(mount);

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', mountAll);
} else {
    mountAll();
}
