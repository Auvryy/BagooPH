<?php

$values = static fn (string $key): array => array_values(array_filter(array_map('trim', explode(',', (string) env($key, ''))), static fn ($value) => $value !== ''));

return [
    'contract_version' => 1,
    'google' => [
        'enabled' => env('BUYER_NATIVE_GOOGLE_ENABLED', false),
        'audiences' => $values('BUYER_NATIVE_GOOGLE_AUDIENCES'),
        'authorized_parties' => $values('BUYER_NATIVE_GOOGLE_AUTHORIZED_PARTIES'),
    ],
    'links' => [
        'enabled' => env('BUYER_MOBILE_LINKS_ENABLED', false),
        'origin' => env('BUYER_MOBILE_LINK_ORIGIN', ''),
        'android_application_id' => env('BUYER_MOBILE_ANDROID_APPLICATION_ID', ''),
        'android_sha256_fingerprints' => $values('BUYER_MOBILE_ANDROID_SHA256_FINGERPRINTS'),
        'apple_team_id' => env('BUYER_MOBILE_APPLE_TEAM_ID', ''),
        'ios_bundle_id' => env('BUYER_MOBILE_IOS_BUNDLE_ID', ''),
    ],
];
