<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Native campaign wallets
    |--------------------------------------------------------------------------
    |
    | A campaign wallet built here is a native script address rather than an
    | account held by the hosted backend. Everything in this file describes the
    | script that guards it. An address is a function of its script, and there is
    | no migration from one address to another once funds have arrived at it, so
    | every value here is frozen for the life of every campaign created under it.
    |
    */

    /*
    | The two successor key hashes, as blake2b-224 hex of an Ed25519 public key.
    |
    | Both are in every script, so either can recover an operator-funded campaign
    | at any time and run a customer-funded one until its expiry, without the
    | platform, and a campaign remains recoverable if the platform stops
    | existing. A hash cannot be added to an address after the
    | fact, so these have to be right before the first address is derived rather
    | than before the first campaign is funded.
    |
    | A campaign cannot be created while either is missing. That is deliberate:
    | the failure to configure them is recoverable, and a campaign whose script
    | omits them is not.
    */
    'owner_key_hashes' => [
        env('CAMPAIGN_OWNER_KEY_HASH_1'),
        env('CAMPAIGN_OWNER_KEY_HASH_2'),
    ],

    /*
    | The platform's own stake address on each network, as a bech32 stake address
    | (stake1... on mainnet, stake_test1... on preprod and preview).
    |
    | A campaign address is a base address: the campaign's script owns the funds
    | and a stake credential beside it owns the staking rights, with no claim on
    | the funds. An operator-funded campaign runs on the operator's own ADA, so it
    | carries this credential and the staking rewards and the delegation stay with
    | the operator. A customer-funded campaign carries the stake credential of the wallet the
    | customer connects instead.
    |
    | A campaign on a network whose variable is unset is refused at creation, with
    | the variable named. The stake credential is inside the address, so changing
    | one of these changes the address of campaigns created afterwards and never
    | the address of one that exists.
    */
    'stake_addresses' => [
        'mainnet' => env('CAMPAIGN_STAKE_ADDRESS_MAINNET'),
        'preprod' => env('CAMPAIGN_STAKE_ADDRESS_PREPROD'),
        'preview' => env('CAMPAIGN_STAKE_ADDRESS_PREVIEW'),
    ],

    /*
    | The longest a campaign's key may spend, in days, counted from the day the
    | campaign is created and including the grace below.
    |
    | The authorisation is a bounded permission: the platform branch of the script
    | stops being able to sign at the campaign's expiry, and this caps how far out
    | that expiry may be. Campaign creation refuses an end date whose expiry (the
    | end date plus the grace) would fall later than this many days from today, and
    | names the latest end date that fits. With the defaults a campaign can end at
    | most 90 days after it is created. The same figure bounds how far ahead a date
    | is ever converted to a slot.
    */
    'authorisation_days' => (int) env('CAMPAIGN_AUTHORISATION_DAYS', 180),

    /*
    | How long a payment stays valid after it is built, in seconds.
    |
    | Every transaction carries an upper bound, and it is short on purpose. Past it the chain will
    | not accept the transaction, which is what lets a payment that never confirmed be told apart
    | from one that is merely slow: expiry is provable, and it is the only condition under which
    | rebuilding a payment cannot pay somebody twice.
    |
    | Long enough that an ordinary delay does not strand a batch, short enough that a stuck one
    | resolves within a shift rather than a day.
    */
    'validity_seconds' => (int) env('CAMPAIGN_VALIDITY_SECONDS', 7200),

    /*
    | How many blocks must sit on top of a transaction before it counts as settled.
    |
    | A transaction in the newest block can still be rolled back, and a campaign's transactions are
    | a chain, so a rollback takes everything built on top of it as well. Waiting a few blocks costs
    | about a minute and saves reconciling a tail that was never really there.
    */
    'confirmation_depth' => (int) env('CAMPAIGN_CONFIRMATION_DEPTH', 3),

    /*
    | The version of the secret-wrapping key currently in use.
    |
    | Stored beside every ciphertext so a secret encrypted under an earlier
    | version can still be read after a rotation, and so a rotation can be
    | verified as complete by counting rows rather than by assertion. The
    | wrapping secret itself is not configured here; see the key below.
    */
    'wrapping_key_version' => (int) env('CAMPAIGN_WRAPPING_KEY_VERSION', 1),

    /*
    | The secret campaign keys are wrapped under, by version.
    |
    | Deliberately not the application key. Rotating that key is a routine and
    | documented operation, and under a cast that used it every campaign secret
    | would become unreadable the first time somebody did so. This is also why
    | the versions are kept rather than replaced: an old wrapping secret has to
    | remain available until every row under it has been re-wrapped.
    */
    'wrapping_keys' => [
        1 => env('CAMPAIGN_WRAPPING_KEY_1'),
        2 => env('CAMPAIGN_WRAPPING_KEY_2'),
    ],

];
