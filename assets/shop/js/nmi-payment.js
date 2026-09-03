/*
 * Mounts NMI's card fields on the pay page and sends back the token they produce.
 *
 * The card number, expiry and verification value are entered inside the gateway's own frames and
 * are never readable from this page, so nothing here can forward them even by accident: the only
 * thing this file ever sends to the store is the token.
 *
 * Everything it needs is on the mount element, put there by the server.
 */
import { mountNmiPayments } from '@nmipayments/nmi-pay';

const MOUNT_SELECTOR = '#nmi-payment';

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

const mount = (container) => {
    if (container.dataset.nmiMounted === 'true') {
        return;
    }
    container.dataset.nmiMounted = 'true';

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

            postToken(container, {
                payment_token: event.token,
                _csrf_token: container.dataset.nmiCsrfToken,
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
