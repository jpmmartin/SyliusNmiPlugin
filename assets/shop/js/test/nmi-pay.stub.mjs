/*
 * A stand-in for `@nmipayments/nmi-pay` under Node: the real package defines custom elements at
 * import time and cannot load without a browser. The widget it mounts answers every
 * authentication with a completed, frictionless result, which is what the contract tests need;
 * a test that wants a failure sets `nextAuthentication.fails` before starting one.
 */
export const widgets = [];

/* What the next authentication does: complete, unless a test asks it to fail. Reset after use. */
export const nextAuthentication = { fails: false };

export const mountNmiThreeDSecure = (target, options) => {
    const widget = {
        target,
        options,
        started: [],
        startThreeDSecure(paymentInformation) {
            this.started.push(paymentInformation);
            if (nextAuthentication.fails) {
                nextAuthentication.fails = false;
                setTimeout(() => options.onFailure({ message: 'The card could not be authenticated.' }), 0);

                return;
            }
            setTimeout(() => options.onComplete({
                cavv: 'AAABBJ',
                xid: 'xid-1',
                eci: '05',
                cardHolderAuth: 'verified',
                threeDsVersion: '2.2.0',
                directoryServerId: 'ds-1',
            }), 0);
        },
    };
    widgets.push(widget);

    return widget;
};
