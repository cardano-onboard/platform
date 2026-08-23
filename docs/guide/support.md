# Getting help

## Where to ask

**Something's broken, or a feature is missing.** Open an issue on the
[platform repository](https://github.com/cardano-onboard/platform/issues). Bugs and feature
requests are both welcome there, and issues are answered in public so the next person with
the same problem finds the answer.

**You're running a live event and something's wrong right now.** Say so in the first line of
your report. Event-day problems get looked at first, because a campaign that fails on the
floor cannot be retried next week.

**Security.** Do not open a public issue for a vulnerability. Report it privately through
the repository's security advisories so it can be fixed before it's described in public.

## Before you report

Check [Troubleshooting](./troubleshooting) and the [FAQ](./faq) first: most reports so far
have matched something already listed there.

A report that includes these gets resolved much faster:

- Which edition, hosted or self-hosted, and which version
- Which network the campaign runs on
- What you did, what you expected, what happened instead
- The campaign ID, if it relates to a specific campaign
- Any error shown on screen, and anything relevant from the logs on a self-hosted install

Leave out private keys, API tokens, and the contents of your environment file. Nothing in a
useful bug report requires them.

## What to expect

Issues are triaged as they arrive. Anything that stops claims working is treated as urgent;
everything else is prioritised against the published
[roadmap](https://github.com/orgs/cardano-onboard/projects/2), which shows what is being
worked on now and what is planned.

Fixes ship to the self-hosted edition as tagged releases. The
[releases page](https://github.com/cardano-onboard/platform/releases) lists what changed in
each one.

## Documentation

If something here is wrong or missing, that's a bug too. Every page has an edit link at the
bottom.
