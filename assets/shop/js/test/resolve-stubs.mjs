const STUBS = {
    '@nmipayments/nmi-pay': new URL('./nmi-pay.stub.mjs', import.meta.url).href,
};

export const resolve = (specifier, context, nextResolve) =>
    STUBS[specifier] ? { url: STUBS[specifier], shortCircuit: true } : nextResolve(specifier, context);
