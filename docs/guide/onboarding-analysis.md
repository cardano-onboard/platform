# Measuring onboarding

Claim counts tell you a campaign was used. They don't tell you whether it worked.

The question this platform exists to answer is whether an airdrop actually brought new
people onto Cardano, and whether those people did anything afterwards. The **Onboarding**
panel on the campaign page answers that from public chain data.

## What it measures

For every wallet that claimed from your campaign, the analysis asks three things.

**New or established.** Did the wallet have any on-chain history before it claimed? A
wallet whose first-ever transaction is your campaign's claim is a wallet your campaign put
on chain. A wallet that had been transacting for two years was already here, and giving
your campaign credit for it would flatter the numbers.

**Activated.** Did the wallet later send a transaction of its own? This is the retention
signal, and it is deliberately strict: the wallet has to appear as an *input* to a later
transaction. A wallet that merely receives a second payout has not done anything, and
counting receipts would mean you could inflate your own activation rate by sending more
tokens.

**Delegated.** Is the wallet registered and delegated to a stake pool? This is reported
for every campaign, including campaigns that never asked anyone to stake, because the gap
between a campaign that guides people into staking and one that doesn't is the clearest
evidence that campaign design changes behaviour.

## Reading the result

The panel leads with four numbers:

| Metric | What it means |
|---|---|
| Claimant wallets | Distinct wallets that claimed, excluding any you flagged as operator tests |
| Newly onboarded | The share with no prior on-chain history |
| New wallets activated | The share of those new wallets that later transacted for themselves |
| Delegated to a pool | The share of claimants now delegating |

Underneath sits the observation window: how long, on average, since the claims happened.
Read it first. An activation rate two weeks after an event and the same rate after three
months are completely different claims, and the second is the one that means something. A
figure quoted without its window is not a result.

Expand the panel for the per-wallet rows behind the summary.

## Running it

Click **Analyze** on the campaign page. The analysis runs in the background because it
makes roughly one query per claimant wallet, so a campaign with a few hundred claims takes
a couple of minutes. You can leave the page while it runs.

Re-run it whenever you want a fresh read. Results are stored per wallet, so a re-run
refreshes what has changed rather than starting over. Activation and delegation both climb
over the weeks after an event, so it is worth re-running a month or two later.

## Excluding your own test claims

Most events involve a few claims made by staff to check the setup. Left in, they land in
the numbers as established wallets that never activated and quietly drag every percentage
down.

Flag them by stake key from the command line:

```bash
php artisan onboard:analyze "My Campaign" \
    --operator=stake1u9... \
    --operator=stake1uy...
```

Flagged wallets are excluded from every percentage and reported separately. The flag
survives re-analysis.

## Exporting

**Export claims** downloads every claimed address as CSV, with the classification columns
alongside: whether the wallet was new, its prior transaction count, whether it activated,
whether it delegates, and to which pool. Where no analysis has been run those columns are
blank rather than guessed.

## On the self-hosted edition

The analysis uses [Koios](https://koios.rest), a public Cardano query layer, and works the
same on a self-hosted install across mainnet, preprod and preview. No account is needed.
For campaigns with many hundreds of claimants, set `KOIOS_API_TOKEN` to raise your rate
limit.

If you run without a queue worker, run the analysis directly:

```bash
php artisan onboard:analyze "My Campaign"
```

## What it can't tell you

A wallet with no prior history is new *to the chain*, which is not quite the same as a
person new to Cardano — someone can arrive with a fresh wallet. Claimants who present an
enterprise address (one with no stake key) are excluded entirely, because wallet history
is keyed on the stake key and there is nothing to look up.

Activation counts any self-initiated transaction. It shows that a wallet is being used,
not what it was used for.
