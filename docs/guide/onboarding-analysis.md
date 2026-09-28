# Measuring onboarding

Claim counts tell you a campaign was used. They don't tell you whether it worked.

The question this platform exists to answer is whether an airdrop actually brought new
people onto Cardano, and whether those people did anything afterwards. The **Onboarding**
panel on the campaign page answers that from public chain data.

## What it measures

For every wallet that claimed from your campaign, the analysis asks four things.

**New or established.** Did the wallet have any on-chain history before it claimed? A
wallet whose first-ever transaction is your campaign's claim is a wallet your campaign put
on chain. A wallet that had been transacting for two years was already here, and giving
your campaign credit for it would flatter the numbers.

**Transacted for itself.** Did the wallet later send a transaction of its own? This is the
retention signal, and it is deliberately strict in two ways. The wallet has to appear as an
*input* to a later transaction, because a wallet that merely receives a second payout has
not done anything, and counting receipts would mean you could inflate your own figure by
sending more tokens. And a transaction that carried nothing but the wallet's own stake
certificate does not count, because delegating is not spending. See below.

**Delegated.** Is the wallet registered and delegated to a stake pool, and did that happen
before or after it claimed? Both are reported, for every campaign, including campaigns that
never asked anyone to stake. The gap between a campaign that guides people into staking and
one that doesn't is the clearest evidence that campaign design changes behaviour, and a
claimant who was already delegating before your event is not that evidence.

**When.** How long after each claim the wallet did those things, reported at 30, 60 and 90
days.

### Delegating is not transacting

Registering a stake key and delegating to a pool is a transaction, and the wallet pays the
deposit and the fee for it. The wallet is therefore an *input* to its own delegation. A
measurement that looks only at inputs counts a wallet that did nothing but delegate as one
that went on to transact, and on a campaign that encouraged staking that can be most of the
figure.

The analysis reads the certificates in each transaction and reports the two separately. A
wallet that delegated and did nothing else is counted as having delegated and not as having
transacted. A wallet that delegated and paid somebody in the same transaction is counted as
both.

## Reading the result

The panel leads with four numbers:

| Metric | What it means |
|---|---|
| Claimant wallets | Distinct wallets that claimed, excluding any you flagged as operator tests |
| Newly onboarded | The share with no prior on-chain history |
| New wallets that transacted | The share of those new wallets that later sent a transaction of their own, not counting delegation |
| Delegated to a pool | The share of claimants delegating now, including those that already were before your campaign |

Below them, the same new wallets cut by how long after claiming anything happened:

| Column | What it means |
|---|---|
| Observable | Wallets whose claim was old enough, when the analysis ran, to have been watched for the whole window, and whose follow-up can be placed against its boundary |
| Too recent | Wallets that claimed too recently to have a window this long. Not a zero: nothing has failed to happen yet |
| No timestamp | Wallets that did something the chain query reported no time for. Also not a zero: it happened, and where it falls in the window is unknowable |
| Transacted | Of the observable wallets, how many sent a transaction of their own inside the window |
| Delegated | How many delegated after claiming inside the window, out of the wallets whose delegation can be placed against its boundary |

Transacting and delegating are timed separately, so the two shares are not always taken
over the same wallets. A wallet whose transaction carries no time can still have a
delegation the chain timed exactly, and that wallet belongs in the delegation window even
though it is out of the activity one. The delegated column prints the wallets it was
counted over.

A wallet that claimed nine days ago has no ninety-day answer. Counting it as a wallet that
did nothing would report your campaign as having failed at something it has not been given
time to do, so it sits in **Too recent** instead and is out of that window's percentage
entirely.

A wallet is held out the same way when what it did carries no time. The headline counts it,
because it transacted. No window counts it, because nothing places it inside or outside
thirty days, and leaving it in the denominator would count it as a wallet that was watched
and did nothing. **No timestamp** says how many those were.

The windows are measured against the moment the analysis read the chain, not against today.
A result from July goes on describing what July saw however long you leave it, and only a
re-run changes it.

Underneath sits the observation window: how long, on average, each wallet was watched for,
and how many wallets that average was taken over. Read it first. A figure two weeks after an
event and the same figure after three months are completely different claims, and the second
is the one that means something. A figure quoted without its window is not a result.

That length comes from what the run recorded and is never counted from today. A wallet no
run has watched has no length, so it is absent from the average rather than contributing the
time that has passed since its claim. Where no wallet here has one, the panel says there is
no window rather than printing a length.

Expand the panel for the per-wallet rows behind the summary.

### Unknown is not zero

A campaign analyzed before the platform could separate delegation from activity has a
stored result that cannot answer these questions. Its activity figure counted both, so it
is not shown, and the windows are not shown either. The panel says the answer is unknown
and offers a re-run.

A run the chain query layer would not answer is not the same thing, and the panel says so
in different words. That run could separate delegation from activity and never got the
chance to read anything, so it names the query layer instead of calling the result old.

Every figure asks whether its own population was read, not whether some other one was. A run
whose history read worked and whose account read failed knows how many claimants were new
and knows nothing about who delegates. Where the population behind a figure is empty, that
figure reads unknown rather than zero percent.

A wallet the chain query could not be read for is unknown in the same way. A query that
fails is not a measurement, so nothing about that wallet is written: it is not new, not
established, and in none of the percentages. The run says so too. A run that read
everything it went to read finishes as a complete result, and one the query layer left
holes in reports how many wallets it could not read and offers a re-run. A re-run that
fails does not erase what an earlier one measured, so a wallet keeps its last good reading
and the date that reading was taken.

All of those are different from a campaign that was measured and where nobody did anything.
That one shows zero. The panel never prints one for the other, and neither does the export: an
unknown cell is blank, a measured nothing is `0`.

A range in which nobody claimed is an answer of its own. The panel says the range holds no
claimant wallets, rather than reporting it as a range nobody has measured yet.

Nothing is re-analyzed on your behalf. A past campaign is re-read when you ask for it.

## What the result covers

Below the numbers the panel states how many claims the analysis covered, out of how many
the campaign holds.

A claim can only be classified once it carries a transaction hash, and a claim gains one
when its status is checked. Two kinds of claim are therefore missing from any result. A
claim with no confirmed transaction has not been confirmed by the transaction backend yet,
and a later run picks it up unless the claim has failed outright, which is permanent. A
confirmed claim from an address with no stake key has no wallet history to look up at all,
and no amount of checking changes that. The panel counts the two separately.

Read the coverage line before quoting any percentage. Sixty percent of twelve claims is a
true statement about twelve claims and looks exactly like a statement about the campaign.

## Running it

Click **Analyze** on the campaign page. The analysis runs in the background because it
makes roughly one query per claimant wallet, so a campaign with a few hundred claims takes
a couple of minutes. You can leave the page while it runs, and the panel updates itself
when it finishes.

If the campaign holds claims with no confirmed transaction, the run checks them with the
transaction backend first and measures afterwards, so claims that confirm are included in
the same run. The panel says how many are outstanding before you start, and offers
**Check claims** if you want the check without the analysis. The same control sits above
the codes table.

The panel says which phase the run is in while it works. It finds the claimant wallets,
reads the claim transactions, reads each wallet's history, reads the transactions those
wallets made later, reads their stake delegations, then records the result. Reading
wallet history is the long phase, because it is the one that makes a query per wallet, and
it reports how many wallets it has read out of how many there are. The panel updates
itself, so you do not have to reload the page to see the result arrive.

Asking for a second analysis while one is running does nothing, and the page says so. A
campaign has one analysis to run, and a second copy would repeat every query to arrive at
the same answer. If a run stops without finishing, the panel shows the reason and the
**Analyze** button starts a fresh one.

Re-run it whenever you want a fresh read. Results are stored per wallet and per transaction,
and a re-run replaces a campaign's rows rather than adding to them, so the counts behind the
windows cannot drift by being measured twice. Activity and delegation both climb over the
weeks after an event, so it is worth re-running a month or two later: the 60 and 90 day
columns only fill in once the wallets have been watched that long.

## Measuring one event rather than the whole campaign

A campaign run at a conference rarely stops collecting claims when the conference ends.
Spare cards get handed out afterwards, a social post brings in more weeks later, and all
of it lands on the same campaign. Those claims are real, and they answer a different
question from the one the event asked.

Set **From** and **To** above the numbers to scope the result to a date range. Claims
outside it leave the population rather than being reclassified, and the panel shows the
whole-campaign figure beneath each scoped one. The difference between the two is what the
range was asked for. Take a set of claimants who were all new wallets and a set who mostly
already had history. Measured together they produce one average, and it describes neither
group.

Either end can be left empty: everything since the event, or everything up to the day it
closed. The day you name as the end is included whole.

Scoping recomputes from results already stored. It makes no chain queries, spends none of
your rate limit, and writes nothing, so the campaign's own stored result goes on meaning
the whole campaign however many ranges you look at.

From the command line:

```bash
php artisan onboard:analyze "My Campaign" \
    --from=2026-09-01 --to=2026-09-03 --scope-only
```

`--scope-only` reports the range from stored results without running the analysis again.
Without it the analysis runs first, and the range is reported after the whole-campaign
table.

## Excluding your own test claims

Most events involve a few claims made by staff to check the setup. Left in, they land in
the numbers as established wallets that never transacted and quietly drag every percentage
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
alongside. Whether the wallet was new, and its prior transaction count. How many
transactions of its own it sent (`own_transactions`), and how many days passed before the
first (`days_to_first_activity`). Whether it delegated after claiming and how long that
took (`delegated_after_claim`, `days_to_first_delegation`). Whether it delegates now, and to
which pool.

Where no analysis has been run those columns are blank rather than guessed, and so are the
cells for a wallet the chain query could not be read for. A blank is not a zero.

Each row also names the **partner** the code was generated for, next to the code itself.
Grouping the rows by that column counts the claims each partner brought in. The cell is
blank for codes generated before the campaign used partners, and for batches left on
Unassigned.

Two columns say what each claim was paid: `reward_lovelace`, and `reward_tokens` as
`policy.asset=quantity` in hex, semicolon separated. They come from the claim rather than
from the code, because a code's reward can be changed while it is in circulation and
reading it back would report today's configuration for a payment made under an earlier one.
Both are blank for a claim that has not been sent yet and for claims taken before the
platform recorded rewards.

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
person new to Cardano — someone can arrive with a fresh wallet. The claim endpoint only
accepts base addresses, which carry a stake key. Claims made from an enterprise address
before the endpoint required base addresses are excluded entirely, because wallet history is
keyed on the stake key and there is nothing to look up.

The activity figure counts any self-initiated transaction other than the wallet's own stake
certificates. It shows that a wallet is being used, not what it was used for.

Two things cannot be placed against a window at all: a wallet with no claim date recorded,
and a transaction the chain query returned no time for. Both are held out of all three
windows rather than counted as having happened on the day of the claim, and the window table
says how many were held out.
