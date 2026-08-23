# Troubleshooting

Problems that have actually come up, and what fixed them. Installation issues first, then
runtime.

## Installation

### The container won't start because port 3306 is in use

A MySQL already running on the host takes the port before the container can. Remap it in
your environment file rather than stopping the host database:

```
DB_PORT=3307
```

The compose file reads the port from your environment, so no file needs editing.

### The home page is blank or 404s on a fresh self-hosted install

Usually a stale route cache from a build where the route file differed. Clear the caches
and rebuild them:

```bash
php artisan optimize:clear
php artisan optimize
```

### Email isn't sending

If your mailer is an HTTP API rather than SMTP, it needs an HTTP client that isn't
installed by default:

```bash
composer require symfony/http-client
```

The symptom is a mail driver that appears configured correctly but throws as soon as
anything tries to send, including the verification mail on a new account.

### New accounts can't reach anything after registering

Email verification is required before the application is reachable. On a self-hosted
install with no working mailer, nobody can get past that. Either configure a mailer, or
verify an account directly:

```bash
php artisan tinker --execute="App\Models\User::where('email','you@example.com')->update(['email_verified_at'=>now()]);"
```

### Assets don't load and the console shows Content-Security-Policy errors

The policy allows your own origin. If your build is served from a separate asset domain,
set `ASSET_URL` to it; the policy picks that origin up automatically. If you serve assets
from somewhere the policy doesn't know about, extend the directives in `config/security.php`
rather than turning the policy off.

## Claims

### A claim is stuck as pending

Pending means dispatched but not yet confirmed on chain. In order:

1. Press **Check Claimed** to force an immediate status re-check.
2. Check the campaign bucket has funds. A token reward needs ADA alongside it, so a bucket
   with tokens but no ADA cannot pay out.
3. Look at the claim's retry count. Claims retry automatically; repeated failures point at
   funding or at the transaction backend rather than at the claim.

### Claims fail once the bucket runs low

Each token-bearing claim carries a minimum ADA amount that stays with the token in the
recipient's wallet. A bucket holding tokens but too little ADA will fail every remaining
claim. Top up and the queued claims go through.

### Someone claims and nothing arrives

Rewards go to the address submitted at claim time, which is on the campaign page and in the
claims export. If a claimant used a friend's phone or pasted an address from elsewhere, the
tokens went to that wallet. There's nothing to recover from your side.

### QR codes don't scan reliably on the event floor

The claim URL is most of the payload, so a long domain makes for a dense code. Configure the
optional short claim host, print larger, and keep good contrast. Test scanning under the
lighting you'll actually have, with a phone at arm's length.

## Onboarding analysis

### The analysis says it's running and nothing happens

It runs as a queued job, so a worker has to be running. Without one, run it directly:

```bash
php artisan onboard:analyze "My Campaign"
```

### The analysis finishes but shows fewer wallets than I have claims

Two expected reasons. Claims that never confirmed on chain have no transaction to classify
against and are skipped. Claimants who presented an enterprise address are excluded, since
wallet history is keyed on the stake key and there is nothing to look up. Several claims
from one wallet also count once, because the unit is the wallet, not the claim.

### The analysis fails partway on a large campaign

Rate limiting on the public query layer. Set `KOIOS_API_TOKEN` to raise your limit and run
it again — results are stored per wallet, so a re-run picks up rather than starting over.

### My numbers look worse than I expected

Check whether your own test claims are in there. Unflagged, they appear as established
wallets that never activated. See
[Excluding your own test claims](./onboarding-analysis#excluding-your-own-test-claims).

## Still stuck?

See [Getting help](./support).
