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

### What's on the Costs tab?

What the campaign has spent: network fees paid to the chain, and rewards given away.
Figures come from what each claim recorded at the time it happened, not from today's
rates. See [What this campaign cost](./campaigns#what-this-campaign-cost).

### Why does the campaign page warn me it's running short?

It's on by default. **Warn me when this campaign is running short**, in the campaign
settings dialog, alerts you once ten or fewer claims can still be served, unless you set a
different threshold. It only warns while the campaign is active, never before it starts or
after it ends. Turn it off, or change the threshold, in the same dialog.

### Why can't I use every Cardano network for a campaign?

This deployment only offers the networks its operator allowed. A staging or demo install
commonly restricts itself to `preprod` and `preview` so real mainnet funds can never pass
through it by accident. See [Allowed networks](./configuration#allowed-networks).

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
status periodically; **Check claims** on the campaign page forces an immediate re-check.
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

### The share of wallets that transacted looks low.

Check the observation window first. A week after an event almost nobody has come back yet;
the number is meaningful at one to three months. It's worth re-running the analysis later
rather than treating the first read as final.

### The panel says some wallets are unknown.

Those are wallets the chain query could not be read for. They are shown as unknown rather
than counted as wallets that did nothing, and the run itself says it could not read
everything. Re-run the analysis when the query layer is healthy again.

### Why are activity and delegation reported separately, and over different time windows?

Registering a stake key and delegating to a pool is itself a transaction, so counting it as
"the wallet transacted" would credit a campaign for staking on any drop that encouraged it.
The analysis reads what each transaction actually did and reports the two apart: sent a
transaction of its own, and delegated, each timed from the claim and shown at 30, 60 and 90
days. A wallet needs time to come back and do either, so a figure is only meaningful next to
the window it was measured over — see
[Reading the result](./onboarding-analysis#reading-the-result).

### Should I count my own test claims?

No. Flag them as operator wallets so they're excluded — otherwise they land in the results
as established wallets that never transacted, and drag every percentage down. See
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
batch, and claim rate limits per IP, per campaign, and per account for a codes:claim
token. See [Configuration](./configuration).
