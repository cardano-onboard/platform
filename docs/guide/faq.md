# Frequently asked questions

Questions that come up repeatedly from event organisers, self-hosters and claimants.

## Campaigns and funding

### Why does my campaign need ADA if I'm giving away a token?

Every Cardano transaction that carries a native token must also carry a minimum amount of
ADA, held in the same output. It isn't a fee — it stays with the token in the recipient's
wallet — but it does have to come from your campaign bucket. Budget for it on top of the
transaction fees, or claims will start failing once the bucket runs low.

### How much ADA should I put in the bucket?

The campaign page calculates the shortfall for you from the unclaimed codes and shows what
to add. As a rule of thumb, allow roughly 1.2 ADA per token-bearing claim on top of
whatever ADA you are actually giving away.

### Can I get unclaimed funds back?

Yes. **Refund Bucket** on the campaign page returns the remaining balance to an address you
specify. Do it once the campaign has ended, since refunding does not stop codes working.

### Why can't I edit some campaign fields any more?

Fields that would change what an already-issued reward is worth lock once a campaign has
claims. Changing them afterwards would mean two people who scanned the same batch of cards
got different things, with nothing in the record to explain why.

## Codes and rewards

### What's the difference between uses and per-wallet?

**Uses** is how many times a code can be claimed in total. **Per wallet** is how many times
any one wallet may claim it. Set per-wallet to 0 for no limit. A single-use card is uses 1,
per-wallet 1; a poster with one code for a room of people is uses 200, per-wallet 1.

### Can I delete codes I got wrong?

Yes, as long as they haven't been claimed. Delete a single code from its row, or select
several and delete the batch. There's also an option to clear every unclaimed code in the
campaign, which is the usual fix for an import that was wrong from the start. Claimed codes
are never deleted, because their claims are the record that someone was paid.

### Why are my printed QR codes now invalid?

Deleting a code invalidates any QR already printed or shared for it. Delete the batch
before the cards go out, not after.

## Claiming

### A claim says pending. Is it stuck?

Probably not. Claims are dispatched asynchronously and confirmed on chain, which usually
takes under a minute but can be longer when the network is busy. The scheduler re-checks
status periodically; **Check Claimed** on the campaign page forces an immediate re-check.
See [Troubleshooting](./troubleshooting#a-claim-is-stuck-as-pending).

### Someone claimed but says nothing arrived.

Ask which wallet they used. Rewards go to the address they submitted, so if they claimed on
a friend's phone or pasted the wrong address, the tokens are in that wallet. The claimed
address is on the campaign page and in the claims export.

### Can the same person claim twice?

Only if you let them. Per-wallet limits are enforced per stake key, so a wallet cannot
exceed its allowance by presenting different payment addresses. Turn on **one per wallet**
at campaign level to apply it across every code in the campaign.

## Measuring results

### How do I know whether the campaign actually onboarded anyone?

Run the [onboarding analysis](./onboarding-analysis) on the campaign. It classifies each
claimant wallet as new or established from public chain data, and reports how many of the
new wallets went on to transact for themselves.

### My activation rate looks low.

Check the observation window first. A week after an event almost nobody has come back yet;
the number is meaningful at one to three months. It's worth re-running the analysis later
rather than treating the first read as final.

### Should I count my own test claims?

No. Flag them as operator wallets so they're excluded — otherwise they land in the results
as established wallets that never activated, and drag every percentage down. See
[Excluding your own test claims](./onboarding-analysis#excluding-your-own-test-claims).

## Self-hosting

### Do I need the hosted transaction backend?

No. The self-hosted edition can run against its own backend. See
[Editions](./editions) for what differs between the two.

### Which network should I start on?

Preprod. It behaves like mainnet and the tokens are free, so the whole flow, including
printing and scanning cards, can be rehearsed at no cost.

### Where do I set upload and rate limits?

All of them are environment variables, including maximum upload size, maximum codes per
batch, and claim rate limits per IP and per campaign. See [Configuration](./configuration).
