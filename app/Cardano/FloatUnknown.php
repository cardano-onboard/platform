<?php

namespace App\Cardano;

use RuntimeException;

/**
 * A campaign's balance could not be read.
 *
 * Its own type because the alternative, returning zero, would report every campaign as being unable
 * to pay the moment a chain-data provider went down. That is the exact confusion this part of the
 * system exists to prevent: a campaign that cannot be read and one that has run out look the same
 * from outside and need opposite responses.
 */
class FloatUnknown extends RuntimeException {}
