<?php

declare(strict_types=1);

return [
    /*
     * Peers whose `X-Forwarded-For` is believed.
     *
     * **Empty means the header is ignored**, which is the safe default: the worker has no socket,
     * so it cannot tell a proxy from a client, and the server has already resolved the forwarded
     * address where it was configured to trust one. Believe a header by default and a client can
     * choose its own `ip()`.
     *
     * A list of CIDRs, e.g. ['10.0.0.0/8'].
     */
    'trusted_proxies' => array_filter(
        array_map('trim', explode(',', (string) env('VOLUCER_TRUSTED_PROXIES', ''))),
    ),
];
