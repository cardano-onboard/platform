<?php

namespace App\Cardano;

use RuntimeException;

/**
 * The chain, or the provider in front of it, refused a transaction outright.
 *
 * Distinct from an answer that leaves the outcome unknown. A refusal means this submission was not
 * accepted, so a transaction refused the first time it was offered never landed and never will from
 * that offer, and nothing may be built on its outputs.
 */
final class ChainRejected extends RuntimeException {}
