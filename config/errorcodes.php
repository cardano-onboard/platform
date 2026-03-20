<?php

return [
    'ERROR_INVALID_ADDRESS' => [
        'code'   => 400,
        'status' => 'invalidaddress',
    ],
    'ERROR_MISSING_CODE'    => [
        'code'   => 400,
        'status' => 'missingcode',
    ],
    'ERROR_INVALID_NETWORK' => [
        'code'   => 400,
        'status' => 'invalidnetwork',
    ],
    'ERROR_NOT_FOUND'       => [
        'code'   => 404,
        'status' => 'notfound',
    ],
    'ERROR_ALREADY_CLAIMED' => [
        'code'   => 409,
        'status' => 'alreadyclaimed',
    ],
    'ERROR_EXPIRED'         => [
        'code'   => 410,
        'status' => 'expired',
    ],
    'ERROR_TOO_EARLY'       => [
        'code'   => 425,
        'status' => 'tooearly',
    ],
    'ERROR_RATE_LIMITED'    => [
        'code'   => 429,
        'status' => 'ratelimited',
    ],
];
