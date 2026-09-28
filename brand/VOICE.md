# Onboard.Ninja voice

How this brand writes. Identity only. If you cloned this repository to build something and
need to name the project or match its tone, everything you need is here.

Derived from how the project talks to people at events and from the language in the
codebase. Where those disagree, the event language wins on audience and vocabulary, and the
codebase wins on anything factual about what the product
does.

## Who this brand is talking to

Three readers. They want different things and they do not share a vocabulary, which is the
single most important thing on this page.

### 1. People at events who are not in crypto

The real growth audience. They are at a Comic-Con, a car show, a boat or RV show, a music
festival, an esports convention or a school workshop. Ages roughly 18 to 65. Comfortable
scanning a QR code. Curious, not crypto-native, and reasonably sceptical of anything that
smells like a scam.

They did not come to the event to learn about blockchains. They are being offered something
free that takes about thirty seconds. Write to them the way a person at the booth would: short,
warm, second person, and anchored to a physical thing they already understand.

> "It's like a digital version of a limited-edition badge or trading card. You actually own
> it, and no one can take it away."

The exit line that matters most, and the reason it works, is that it retires the fear rather
than answering a question:

> "You're all set, and you didn't need to know anything about crypto to do it."

### 2. Event organisers and brands

They run the event or own the audience. They want attendee engagement that works for
thousands of people without creating a support queue, and they want to be able to report on
what happened afterwards. Their core message is about protecting their own audience:

> "We help you give people digital ownership safely, without scaring them away."

This reader is where the measurement half of the product earns its place. They are the one
person in the room who wants a number.

### 3. Crypto-native projects and developers

Blockchain projects, NFT communities, DAOs, Web3 startups, and the developers evaluating
whether to run this themselves. For this reader the standard, the interface and the compose
file are the argument, and vagueness reads as evasion. Show them early.

## How it sounds

**Friendly, and not "crypto bro."** That phrase is in the brand positioning verbatim and it is
the sharpest guardrail here. Warm, direct, human. No hype, no swagger, no insider signalling,
no implication that the reader is late to something.

**Calm and educational rather than promotional.** The goal is not to sell crypto. It is to show a better first experience. Explain, demonstrate, and let the
thing be good.

**Plain.** The documentation standard on record is "explain it to my aunt." Short sentences,
ordinary words. "Scan. Download a wallet app. Claim it."

**Concrete, through analogy.** Never define a term where a comparison will do: a digital build plate, a stamp in your digital passport, a VIP wristband that is permanent,
a skin or achievement badge that stays yours. Reach for the physical object the reader already
owns.

**Reassuring about risk, without raising it.** "No money. No risk. Just try it." Name safety
as a property of the design rather than as a problem being defended against.

**Specific with numbers, and specific about their limits.** For organisers and developers, the
product's credibility rests on figures anyone can check on chain. Adjectives throw that away.

**Willing to say what it cannot do.** The editions documentation states plainly that a
self-hosted instance cannot yet put a transaction on chain by itself, and that page is the most
trustworthy thing the project has written. In a category whose adjacent keywords are full of
things that overpromise, saying the limit out loud is a differentiator.

For developer-facing writing specifically, the voice in the code comments is the right one and
should be promoted: plain, exact, and slightly impatient with vagueness. From the analysis
service, on why a metric is defined the way it is: "A wallet that merely receives a second
payout has done nothing, and counting that would let an operator inflate the number by sending
more tokens." That register belongs to reader three. It would be cold at a booth.

The mascot carries the energy and the playfulness. It does that visually. The sentences do not
need to, and wordplay should never carry an argument.

## Words this brand uses

**The name is `Onboard.Ninja`.** One word each side of a dot, both capitalised. Not "Onboard
Ninja", not "OnboardNinja", not lowercase in prose. The wordmark reads ONBOARD because the
ninja face is the O. The domain is `onboard.ninja` and the account is `@OnboardNinja`; those
are written as they are.

Editions are **hosted** and **self-hosted**.

### Talking to people who are not in crypto

Use: **digital collectible, secure claim, ownership, proof, membership, reward, digital pass,
badge, keepsake, claim code, QR code, scan, wallet app.**

Frames that carry the whole proposition:

- "Scan. Download a wallet app. Claim it."
- "Choose a self-custody wallet, no accounts or passwords."
- "This is your digital collectible, and it's really yours."
- "You don't need to know crypto to use this."
- "Make crypto feel like a bonus, not homework."

### Talking to crypto projects and developers

Use: **CIP-99 compliant onboarding, automated wallet and campaign management, a safer
alternative to paper wallets, scalable onboarding infrastructure, API-first, native assets,
stake key, delegation, policy ID, deep link, token fountain.**

**CIP-99 is always explained in the same breath as it is claimed.** "Built on CIP-99, the
Cardano standard for onboarding claims." Never "Proof of Onboarding" on its own: the phrase
does not survive first contact, and at least one implementing wallet's own documentation
expands POO incorrectly. Name a wallet as an implementor only after checking it still is.

**Cardano is named for this reader and for organisers, and it is a supporting message rather
than an opening one for everybody else.** It belongs in anything describing what the product
is built on. It does not belong in the first sentence spoken to someone at a car show.

### The three measurement terms, defined wherever they appear

They are the whole argument for the organiser audience, and an undefined metric is worth
nothing:

- **New**, a wallet whose first transaction on chain is the claim. The campaign put it there.
- **Transacted for itself**, a wallet that later sends a transaction of its own. Receiving a
  second payout does not count, deliberately, so that whoever runs the campaign cannot inflate
  the number by spending more. Nor does a transaction carrying nothing but the wallet's own
  stake certificate. A wallet pays the deposit and the fee for its own delegation, so counting
  inputs alone reads a wallet that only delegated as one that went on to spend. The earlier term
  was activated. It counted both, and it is no longer reported.
- **Delegated**, a wallet registered and delegated to a stake pool, counted apart from the
  figure above.

## Words this brand avoids

**With a non-crypto audience, never: seed phrase, private key, gas fees, or blockchain jargon
of any kind.** The irony is deliberate and worth understanding: seed phrases are the problem the product solves, and naming them to
someone who has never met one installs the fear rather than removing it. Say what they get,
not what they escaped.

**Investment language, anywhere, to anyone.** No price, no value, no returns, no "early." Crypto
scepticism is the first risk to the whole approach, and avoiding investment framing is how it
is answered. This one is not a style preference.

**"Airdrop" never stands alone.** It is the word parts of the audience use, so it stays in
crypto-facing copy, but bare "airdrop" sits in a keyword space occupied by farming bots and
file-sharing tools, and it carries a scam association in Cardano discourse. Three words fix it:
"Cardano airdrops for live events." The pairing "airdrop ninja" is the worst case and should
never be written. For a non-crypto audience, prefer "digital collectible" or "reward" outright.

**"DIY."** It sounds hobbyist. The edition is self-hosted.

**Sovereignty language for the self-hosted edition.** No "independent", "trustless", "no third
parties", "run it entirely yourself". A self-hosted instance still relies on an external
service to put a transaction on chain. The claim that is true today is **data custody**:
claimant addresses, stake keys and the onboarding analysis stay on your own database. This is a
factual limit, not a stylistic one, and it changes when the code changes.

**Web3 as the main frame.** It says nothing, it dates quickly, and it hides which chain this is
for.

**Beta as an identity.** The product launched. Version numbers can say beta; the writing
should not lead with it.

**Hype.** "Onboard.Ninja is not a hype product. It is infrastructure." Showing beats telling.

**Anything about a customer's own campaign.** Customers run campaigns on this platform. Never
reference one, never quote a number from one, never imply their data is being read. Only
figures already published, or visible to anyone on the platform.

**Any claim that cannot be shown.** No superlatives, no invented percentages, no future
features described in the present tense.

## House rules overridden

The default prose style is inherited: no emoji, no em dashes, no arrows standing in for words,
no decorated headings. Onboard.Ninja overrides none of them.

- Emoji: not used in written copy. The playfulness in the brand is visual and belongs to the
  mascot and the illustration, not to the sentences.
- Em dashes: not used; a comma, a colon or two sentences instead. Spoken lines and
  laid-out artwork sometimes use them freely. Those are not written copy, and they are not the
  precedent.
- Exclamation marks: at most one, and rarely. Something said aloud at a booth may be warmer than a page.
- Hashtags: at most one, and only where it does real work.

Silence means the house rule stands.
