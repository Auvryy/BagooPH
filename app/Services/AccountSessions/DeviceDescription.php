<?php

namespace App\Services\AccountSessions;

use Illuminate\Http\Request;

class DeviceDescription
{
    public function text(?string $value, int $limit): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = mb_substr(trim(preg_replace('/[\p{C}]/u', '', strip_tags(mb_scrub($value))) ?? ''), 0, $limit);

        return $value !== '' ? $value : null;
    }

    public function metadata(Request $request, string $channel = 'web', ?string $name = null): array
    {
        $agent = $this->text($request->userAgent(), 512);
        $os = match (true) {
            str_contains($agent ?? '', 'Android') => 'Android',
            str_contains($agent ?? '', 'iPhone') => 'iPhone',
            str_contains($agent ?? '', 'iPad') => 'iPad',
            str_contains($agent ?? '', 'Windows') => 'Windows',
            str_contains($agent ?? '', 'Macintosh') => 'macOS',
            str_contains($agent ?? '', 'CrOS') => 'ChromeOS',
            str_contains($agent ?? '', 'Linux') => 'Linux',
            default => null,
        };
        $browser = match (true) {
            preg_match('/Edg(?:e|A|iOS)?\//', $agent ?? '') === 1 => 'Edge',
            preg_match('/(?:OPR|Opera)\//', $agent ?? '') === 1 => 'Opera',
            preg_match('/(?:Firefox|FxiOS)\//', $agent ?? '') === 1 => 'Firefox',
            preg_match('/(?:Chrome|CriOS)\//', $agent ?? '') === 1 => 'Chrome',
            str_contains($agent ?? '', 'Safari/') => 'Safari',
            default => 'Browser',
        };
        $reported = $this->text($name, 80);
        $label = $channel === 'rider' ? 'Rider app'.($reported ? ' · '.$reported : ($os ? ' on '.$os : '')) : $browser.($os ? ' on '.$os : '');
        $ip = $request->ip();

        return ['device_label' => $label, 'device_type' => $os === 'iPad' ? 'tablet'
            : (in_array($os, ['Android', 'iPhone'], true) ? 'phone' : ($os ? 'computer' : 'unknown')),
            'network_address' => is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP) ? $ip : null,
            'user_agent' => $agent];
    }

    public function network(?string $address): ?string
    {
        if (! $address || ! filter_var($address, FILTER_VALIDATE_IP)) {
            return null;
        }
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $parts = explode('.', $address);

            return implode('.', array_slice($parts, 0, 3)).'.*';
        }
        $bytes = inet_pton($address);

        return $bytes === false ? null : inet_ntop(substr($bytes, 0, 6).str_repeat("\0", 10)).'/48';
    }
}
