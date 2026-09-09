# Configuration reference

Every field on the NMI payment method, what it is, where to get it, and what choosing it costs.

You reach this form at **Configuration → Payment methods → Create → NMI**. The README's install
steps have to be done first, or NMI does not appear in the list of gateways at all.

## The short version

If you want to change as little as possible: **fill in the three credentials, choose the
environment, and leave every switch alone.** Those defaults give you an immediate card charge,
no saved cards, and no webhooks — which is the behaviour a store that has never heard of this
plugin already has.

The one default that is a *decision* rather than an absence is **Authenticate saved cards with
3-D Secure**, which is on. It only applies once you turn saved cards on, and leaving it on is the
safe side of a choice with liability consequences. See below.

## What you paste

These four come from NMI, not from you. Three are required; the fourth is only needed if you want
NMI to tell your store what it did.

### Tokenization key — required

**What it is:** a public key with the *Tokenization* permission. Your shoppers' browsers use it to
turn card details into a token, so the card number never reaches your server.

**Where to get it:** NMI Merchant Portal, **Settings → Security Keys**. Create one with the
Tokenization permission if you have none.

**It is visible to shoppers, by design.** It is in the page source of your checkout. It cannot
charge anything on its own — it can only exchange a card for a token that your server must then
present with the private key.

### Security key — required

**What it is:** the private API key. Your store uses it to charge, capture, void and refund.

**Where to get it:** the same **Settings → Security Keys** page. This is the one with API
permissions, not the tokenization one.

**Never let this reach a browser.** It is stored encrypted — by Sylius, using the key named in
`SYLIUS_PAYMENT_ENCRYPTION_KEY_PATH`, which step 6 of the README warns you is probably still the
one your store's skeleton shipped. Fix that before you paste this.

### Environment — required

**What it is:** which NMI gateway the store talks to. *Sandbox* for testing, *Production* for real
money.

There is no third state and no "test mode" switch elsewhere: this field is the whole of it. A
payment method pointed at Sandbox cannot take a real payment, and one pointed at Production will
take real money from the first shopper who reaches it.

**If you use a reseller or white-label gateway**, neither value is right on its own — see
*Reseller and white-label gateways* in the README, which overrides the host for every NMI payment
method.

### Webhook signing key — optional

**What it is:** the key NMI signs its event deliveries with, so your store can prove a delivery
came from NMI and not from someone who guessed the URL.

**Where to get it:** NMI Merchant Portal, **Settings → Webhooks**. The page shows one signing key
for the whole account, above the list of endpoints.

**Leave it blank and no event is accepted.** That is not a degraded mode: with no key there is
nothing to verify a delivery against, and this plugin will not act on unverified instructions
about money. A store that never wires webhooks behaves exactly as it did before.

Pasting it is not enough on its own — your developer must also import the plugin's webhook route,
or nothing reaches the store at all. The README's *Webhooks* section covers both halves.

## What you decide

Five switches. Four are off by default; one is on. None of them has to be touched for card
payments to work.

### Authorize first, capture later — off

**Off:** the shopper's card is charged at checkout.

**On:** checkout only *authorises* the card, and the money is taken when the order ships, or by
hand from the order screen.

**Why you might turn it on:** you do not want to hold money for goods you have not sent.

**What it costs:** an authorisation expires — typically in about a week, though the exact window is
your acquirer's, not this plugin's — and an expired one cannot be captured. It also cannot be
captured *in parts*: NMI closes the authorisation on the first capture, so an order shipped in two
parcels is charged in full at the first shipment.

### Let shoppers save their card — off

**Off:** nothing about saved cards exists. No option at checkout, no page in the shopper's account,
no rows in the database.

**On:** a signed-in shopper may choose to keep a card on file with NMI and pay with it next time.

**What it does not do:** it never saves anything the shopper did not ask to save, it is never
offered to guests, and **your store still holds no card number** — only the reference NMI hands
back, which is useless to any other gateway account and is stored encrypted.

**What it costs:** the account routes have to be imported (README step 3), or the saved-cards page
404s. And you inherit the question below.

### Authenticate saved cards with 3-D Secure — **on**

This is the one default that is a decision. It only applies once saved cards are on.

**On:** a returning shopper authenticates again before each payment, exactly as they did the first
time.

**Off:** paying with a saved card is one click.

**What it costs, and this is the part worth reading twice:** for cardholders in the **EU and the
UK**, a payment carrying no authentication may be declined outright by the issuer, and liability
for a chargeback **stays with you** rather than moving to the issuer. Selling into **North
America**, that pressure does not apply the same way, and one-click is the more common trade.

**Why the default is on:** the alternative is a payment nobody authenticated, and a store that
wants that should have to say so rather than inherit it.

> These are statements about card scheme rules and liability in more than one jurisdiction, and
> they have not been reviewed by anyone qualified to make them. Treat them as a prompt to check
> your own position, not as advice.

### Email the shopper when a saved card is closed or flagged — off

**Off:** when NMI's card updater reports that a shopper's bank closed or flagged a card they saved
with you, the card is still marked, their account still shows it, and checkout still stops
offering it. Only the email is skipped.

**On:** they are emailed about it as well, in the language of the channel the payment method serves.

**Why the default is off:** it is outbound mail sent on your behalf about something you did not do.
That is a decision an operator makes, not one they inherit.

**It needs webhooks.** Nothing else tells your store that a card was closed.

### List transactions this store does not recognise — off

**Off:** an event naming a transaction your store has no record of is accepted and logged, and
nothing else happens.

**On:** it also appears under **Sales → Gateway notices**.

**Why the default is off, and why most stores should leave it there:** it is only useful if the NMI
account is *yours alone*. If you share it with another shop, another system, or a virtual terminal
somebody else uses, every one of their transactions arrives at your endpoint too — and this would
list all of them.

**It needs webhooks**, for the same reason as above.

## What is not on this form

**Which NMI account a card added from the account area belongs to.** When a channel has exactly one
NMI payment method that saves cards, that is the one. When it has two, the *Add a card* page
answers 404 rather than guessing — choosing properly means asking the shopper before the card form
is mounted, and that page does not exist yet.

**The retention period for received webhook events.** That is an argument to the prune command, not
a stored setting. See *Webhooks* in the README.

**Anything about partial captures.** There are none; see *Authorize first, capture later* above.
