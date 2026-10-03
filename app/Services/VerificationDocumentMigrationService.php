<?php

namespace App\Services;

use App\Models\LogisticsCompany;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class VerificationDocumentMigrationService
{
    public function migrate(bool $dryRun = false): array
    {
        $references = [];
        $failed = 0;
        foreach (User::cursor() as $user) {
            foreach (array_diff(VerificationDocumentService::FIELDS, ['franchise_document_path']) as $field) {
                $this->collect($references, $failed, $user->$field, ['user', $user->id, $field]);
            }
        }
        foreach (LogisticsCompany::cursor() as $company) {
            foreach (['business_permit_path', 'franchise_document_path'] as $field) {
                $this->collect($references, $failed, $company->accreditation_details[$field] ?? null, ['company', $company->id, $field]);
            }
        }

        $paths = array_unique([...Storage::disk('public')->allFiles('kyc_documents'), ...array_keys($references)]);
        $processed = 0;
        foreach ($paths as $path) {
            try {
                if (! Storage::disk('public')->exists($path) && ! Storage::disk('local')->exists($path)) {
                    throw new RuntimeException('A referenced verification file is missing.');
                }
                if ($dryRun) {
                    if (Storage::disk('public')->exists($path) && Storage::disk('local')->exists($path)
                        && $this->fingerprint('public', $path) !== $this->fingerprint('local', $path)) {
                        throw new RuntimeException('Conflicting private verification file.');
                    }
                    $processed++;

                    continue;
                }

                $this->copyVerified($path);
                DB::transaction(function () use ($references, $path) {
                    foreach ($references[$path] ?? [] as [$model, $id, $field]) {
                        if ($model === 'user') {
                            User::whereKey($id)->where($field, '/storage/'.$path)->update([$field => $path]);
                        } else {
                            $company = LogisticsCompany::whereKey($id)->lockForUpdate()->first();
                            $details = $company?->accreditation_details ?? [];
                            if (($details[$field] ?? null) === '/storage/'.$path) {
                                $details[$field] = $path;
                                $company->update(['accreditation_details' => $details]);
                            }
                        }
                    }
                });

                if (Storage::disk('public')->exists($path) && ! Storage::disk('public')->delete($path)) {
                    throw new RuntimeException('A public verification file could not be retired.');
                }
                $processed++;
            } catch (Throwable) {
                $failed++;
            }
        }

        return ['processed' => $processed, 'failed' => $failed];
    }

    private function collect(array &$references, int &$failed, ?string $stored, array $reference): void
    {
        if (! $stored || ! str_starts_with($stored, '/storage/')) {
            return;
        }
        if (preg_match('/\A\/storage\/kyc_documents\/[A-Za-z0-9_-][A-Za-z0-9._-]*\z/', $stored) !== 1) {
            $failed++;

            return;
        }
        $references[substr($stored, strlen('/storage/'))][] = $reference;
    }

    private function copyVerified(string $path): void
    {
        $public = Storage::disk('public');
        $private = Storage::disk('local');
        if (! $public->exists($path)) {
            return;
        }
        if ($private->exists($path)) {
            if ($this->fingerprint('public', $path) !== $this->fingerprint('local', $path)) {
                throw new RuntimeException('Conflicting private verification file.');
            }

            return;
        }

        $stream = $public->readStream($path);
        if (! is_resource($stream)) {
            throw new RuntimeException('A verification file could not be read.');
        }
        try {
            if (! $private->put($path, $stream, ['visibility' => 'private'])) {
                throw new RuntimeException('A verification file could not be copied.');
            }
        } finally {
            fclose($stream);
        }
        if ($this->fingerprint('public', $path) !== $this->fingerprint('local', $path)) {
            throw new RuntimeException('Verification copy checksum mismatch.');
        }
    }

    private function fingerprint(string $disk, string $path): string
    {
        $stream = Storage::disk($disk)->readStream($path);
        if (! is_resource($stream)) {
            throw new RuntimeException('A verification file could not be checked.');
        }
        try {
            $hash = hash_init('sha256');
            hash_update_stream($hash, $stream);

            return hash_final($hash);
        } finally {
            fclose($stream);
        }
    }
}
