# Getting Started (SaaS)

The hosted edition lets you run campaigns without managing any infrastructure. We operate
the app and the transaction backend; you bring your funds and your audience.

## 1. Create an account

1. Open the hosted app and choose **Register**.
2. Enter your name, email, and a password.
3. You land on a **Check your email** page. Open the **confirmation link** in the message
   we send to activate your account. The link works for 60 minutes.

> Registering does not sign you in. The page that follows is the same for every address, so
> the form does not say whether the address already has an account: if it does, the owner gets an
> email saying so instead of a confirmation link, and nothing about their account changes.
> Email confirmation is required before you can reach your dashboard.
>
> The confirmation link signs you in when you open it in the browser you registered from.
> Opened anywhere else, it asks you to log in first.

If you registered with an address that already had an account, use **Forgot your password?**
on the login page rather than registering again.

## 2. Sign in

If the confirmation link signed you in, skip this step. Otherwise, log in with your email
and password. You'll land on your **Dashboard**, which lists your campaigns and their
claim/code counts. It's empty to start.

If you forget your password, choose **Forgot your password?** and enter your email. The
form gives the same answer for every address, so it does not say whether an account exists;
if there is one, the link arrives by email.

### Limits on sign-up, reset and login

These limits keep a burst of requests from one place from slowing the service for everyone.

- Registration: 10 attempts per hour from one IP address, and 20 per minute across the
  whole service.
- Password reset: 10 requests per hour from one IP address, 3 requests per hour from one
  IP address for any one email address, and 30 per minute across the whole service. Asking
  for a link and setting the new password share the per-IP budget.
- Login: 5 failed attempts for one email address from one IP address, then a short
  lockout. Separately, 30 failed attempts per hour from one IP address across all
  addresses. Successful logins do not count toward the 30.

An IPv6 address is counted by its /64 for the per-IP limits. The per-minute limits protect
everyone's sign-ups and resets from a burst. While one is full, new attempts are turned away
until the minute ends, and each refused attempt still counts against the sender's hourly
allowance, so retrying through a full minute can use that allowance up.

A form past a limit shows how long to wait. Scripts get a 429 response with a
`Retry-After` header.

## 3. Create your first campaign

Head to [Campaigns & funding](./campaigns) to create a campaign and fund its bucket
wallet, then [Codes & reward tokens](./codes-and-rewards) to generate claim codes.

## 4. API access (optional)

If you want to integrate programmatically or relay transactions from a self-hosted
instance, create a personal API token from your **Profile** page. See the
[API reference](./api-reference) for the proxy API and quota details.

Tokens can only be created once your email address is confirmed. Until then the form on
the Profile page is switched off and says so.

## Changing your password

Changing your password on the **Profile** page signs your account out everywhere else:
every other browser and device has to log in again, including ones that ticked "remember
me". The browser you changed it in stays signed in. Resetting a forgotten password by email
does the same, and both revoke the account's API tokens.

## What's different on SaaS?

The SaaS edition includes account features that the self-hosted edition does not:
registration, email verification, password reset, a profile page, and API tokens for the
proxy API. See [Editions](./editions) for the full comparison.
