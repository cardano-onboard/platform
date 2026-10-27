<?php

namespace App\Cardano;

use RuntimeException;

/**
 * The gate refused a payout over how it collects the platform fee, as opposed to anything else
 * about the transaction. Its own type so that something checking that the fee invariants hold can
 * tell a refusal on them from a refusal for some other reason.
 */
final class FeeInvariantFailed extends RuntimeException {}
