<?php

namespace App\Cardano;

use RuntimeException;

/**
 * The inputs a transaction spends are already gone.
 *
 * Its own type because it is the one submission failure that must not be retried blindly and must
 * not be treated as success. Either this transaction was already accepted and consumed them, or
 * something else did, and the two need opposite responses: the first is finished, the second has
 * failed and its claims are unpaid.
 *
 * The message the chain sends says which inputs, not which of those happened, so whatever catches
 * this resolves it by asking the chain whether the transaction is there rather than by guessing.
 */
class ChainInputsConsumed extends RuntimeException {}
