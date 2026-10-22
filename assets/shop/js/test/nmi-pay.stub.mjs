/*
 * A stand-in for `@nmipayments/nmi-pay` under Node: the real package defines custom elements at
 * import time and cannot load without a browser. The widget it mounts answers every
 * authentication with a completed, frictionless result, which is what the contract tests need;
 * a test that wants something else says so on `nextAuthentication` before starting one.
 *
 * What it reproduces of the real widget, read in the package's own build: it keeps only the
 * interface of its latest start; `complete` and `failure` call back and then unmount it; the
 * dialog's close control unmounts it and calls back `onFailure`; `destroy()` only takes the
 * element out of the page, and a destroyed widget starts nothing. What it reproduces of the gateway's script was observed
 * in the browser's console, not read: while one interface is mounted, starting another is
 * refused — "You may not start another 3DS instance" — and nothing is called back.
 */
export const widgets = [];

/* What the next authentication does: complete, unless a test asks otherwise. Reset after use. */
export const nextAuthentication = { fails: false, silent: false, challenges: false };

/* The interface the gateway's script holds for the page, and the starts it refused because of it. */
export const gateway = { mounted: null, refused: [] };

/* Whether widgets mounted from now on carry the dialog's close control. */
export const widgetMarkup = { closeControl: true };

/* Puts everything back, because the script is imported afresh per test and this module is not. */
export const reset = () => {
    widgets.length = 0;
    gateway.mounted = null;
    gateway.refused.length = 0;
    Object.assign(nextAuthentication, { fails: false, silent: false, challenges: false });
    widgetMarkup.closeControl = true;
};

export const mountNmiThreeDSecure = (target, options) => {
    const element = target.ownerDocument.createElement('nmi-3ds');
    target.appendChild(element);

    const widget = {
        target,
        options,
        element,
        started: [],
        interface: null,
        closed: 0,
        destroyed: false,
        unmount() {
            if (gateway.mounted === this.interface) {
                gateway.mounted = null;
            }
            this.interface = null;
        },
        startThreeDSecure(paymentInformation) {
            if (this.destroyed) {
                return;
            }
            this.started.push(paymentInformation);
            // The widget holds only the interface of its latest start, refused or not, and that
            // is the one its close control unmounts.
            this.interface = { paymentInformation };
            if (gateway.mounted !== null) {
                gateway.refused.push(paymentInformation);

                return;
            }
            gateway.mounted = this.interface;

            const { fails, silent, challenges } = nextAuthentication;
            Object.assign(nextAuthentication, { fails: false, silent: false, challenges: false });

            if (silent) {
                return;
            }
            if (challenges) {
                setTimeout(() => options.onChallenge?.(), 0);

                return;
            }
            if (fails) {
                setTimeout(() => {
                    options.onFailure({ message: 'The card could not be authenticated.' });
                    this.unmount();
                }, 0);

                return;
            }
            setTimeout(() => {
                options.onComplete({
                    cavv: 'AAABBJ',
                    xid: 'xid-1',
                    eci: '05',
                    cardHolderAuth: 'verified',
                    threeDsVersion: '2.2.0',
                    directoryServerId: 'ds-1',
                });
                this.unmount();
            }, 0);
        },
        destroy() {
            this.destroyed = true;
            element.remove();
        },
    };

    if (widgetMarkup.closeControl) {
        const close = target.ownerDocument.createElement('button');
        close.className = 'close-button';
        close.addEventListener('click', () => {
            widget.closed += 1;
            widget.unmount();
            options.onFailure?.({ code: 'TRANSACTION_STATUS_N', message: 'Not Authenticated/Account Not Verified; Transaction denied' });
        });
        element.appendChild(close);
    }

    widgets.push(widget);

    return widget;
};
