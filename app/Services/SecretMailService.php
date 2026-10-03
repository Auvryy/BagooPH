<?php

namespace App\Services;

use RuntimeException;

class SecretMailService
{
    public function assertSafeTransport(): void
    {
        $this->assertMailer((string) config('mail.default'), []);
    }

    private function assertMailer(string $mailer, array $visited): void
    {
        if (in_array($mailer, $visited, true)) {
            throw new RuntimeException('Secret mail transport configuration is cyclic.');
        }
        $settings = config('mail.mailers.'.$mailer, []);
        $transport = $settings['transport'] ?? null;
        if (in_array($transport, ['failover', 'roundrobin'], true)) {
            $children = $settings['mailers'] ?? [];
            if (! is_array($children) || $children === []) {
                throw new RuntimeException('Secret mail transport is not configured.');
            }
            foreach ($children as $child) {
                $this->assertMailer((string) $child, [...$visited, $mailer]);
            }

            return;
        }
        if ($transport === 'array' && app()->environment('testing')) {
            return;
        }
        if (! in_array($transport, ['smtp', 'sendmail', 'ses', 'ses-v2', 'postmark', 'mailgun', 'resend'], true)) {
            throw new RuntimeException('Secret mail requires a delivery transport that does not log message contents.');
        }
    }
}
