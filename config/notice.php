<?php

return [
    // A short operator-facing message shown as a persistent banner on the guest and
    // authenticated layouts. Unset (the default) shows nothing: production and the
    // self-hosted edition both ship silent until an operator sets one, for example to
    // send visitors on a retired deployment toward wherever the platform lives now.
    'message' => env('APP_NOTICE'),

    // 'warning' or 'info'. Anything else, including unset, renders as 'info' rather
    // than passing an unrecognised value through to the alert component.
    'type' => env('APP_NOTICE_TYPE', 'info'),

    'link' => [
        // Rendered only when this is a well-formed http(s) URL. A blank, malformed, or
        // non-http(s) value (a bare host, a javascript: URL, a typo'd scheme) drops the
        // link rather than shipping something a browser would refuse or that would run
        // script from a config value. The message itself still renders without it.
        'url' => env('APP_NOTICE_LINK_URL'),

        // Falls back to the URL itself when the URL is set and this is not.
        'text' => env('APP_NOTICE_LINK_TEXT'),
    ],
];
