# Mainnet era summaries

`mainnet-era-summaries.json` is the mainnet half of the Ouroboros era summaries, kept here so that
`CampaignScriptShapeTest` can convert a campaign expiry date to a mainnet slot without reaching into another
package's test fixtures.

The numbers were not typed in. They are the mainnet subtree of the era summaries the transaction package records
under `tests/fixtures/cardano-time/era-summaries.json`, copied across whole, along with the provenance block that
says where they were fetched from and why they come from the Ogmios endpoint rather than from Koios's own
`/era_summaries`. Preprod is not carried here because nothing in the application's own suite converts a preprod slot.

An era summary for a past era is settled history and does not change. A hard fork appends a new one, and appending a
new era changes nothing about how a slot before it is converted, so a stale copy is wrong only for a date after the
fork that produced it. `CampaignScriptShapeTest` converts a fixed date in 2027 and asserts a fixed slot, so a fork
that changed the slot length before then would show up as a failure rather than as a silently different address.
