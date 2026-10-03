<?php

$proxies = array_values(array_filter(
    array_map('trim', explode(',', (string) env('TRUSTED_PROXIES', ''))),
    fn (string $proxy) => $proxy !== '',
));

foreach ($proxies as $proxy) {
    $parts = explode('/', $proxy);
    $address = $parts[0];
    $maximumPrefix = filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? 128 : 32;

    if (
        ! filter_var($address, FILTER_VALIDATE_IP)
        || count($parts) > 2
        || (isset($parts[1]) && (! ctype_digit($parts[1]) || (int) $parts[1] < 1 || (int) $parts[1] > $maximumPrefix))
    ) {
        throw new InvalidArgumentException('TRUSTED_PROXIES must contain explicit proxy IP addresses or nonzero CIDR ranges.');
    }
}

return [
    // An explicit empty array also disables the framework's automatic hosted-platform trust.
    'proxies' => $proxies,
];
