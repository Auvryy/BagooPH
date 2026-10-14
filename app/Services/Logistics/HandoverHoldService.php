<?php

namespace App\Services\Logistics;

use App\Exceptions\HandoverHoldException;
use App\Models\Delivery;
use App\Models\HandoverIssue;
use App\Models\HandoverRecoveryGrant;
use App\Models\LogisticsManifest;
use App\Models\LogisticsManifestEvent;
use App\Models\User;
use Illuminate\Support\Collection;

class HandoverHoldService
{
    private array $verifiedRecoveryScopes = [];

    public function active(Delivery $parcel): ?HandoverIssue
    {
        return HandoverIssue::where('active_delivery_id', $parcel->id)->where('state', 'needs_recovery')->first();
    }

    public function held(Delivery $parcel): bool
    {
        return HandoverIssue::where('active_delivery_id', $parcel->id)->where('state', 'needs_recovery')->exists();
    }

    public function requiresReviewedRecovery(Delivery $parcel): bool
    {
        return $this->held($parcel) && ! isset($this->verifiedRecoveryScopes[$parcel->id]);
    }

    public function pendingAcknowledged(int $userId, ?int $exceptDeliveryId = null): Collection
    {
        return HandoverRecoveryGrant::where('holder_id', $userId)->whereIn('mode', HubHandoverRecoveryService::MODES)
            ->when($exceptDeliveryId, fn ($query) => $query->where('delivery_id', '!=', $exceptDeliveryId))
            ->whereHas('issue', fn ($query) => $query->where('state', 'needs_recovery')->whereNotNull('active_delivery_id'))
            ->whereHas('events', fn ($query) => $query->where('event_type', 'holder_acknowledged')->where('actor_id', $userId))
            ->with(['issue.delivery', 'events' => fn ($query) => $query->where('event_type', 'holder_acknowledged')->where('actor_id', $userId)])
            ->get()->filter(function ($grant) {
                $recordedSource = $grant->events->first()?->parcel_snapshot['recovery_source']['current']['checkpoint_id'] ?? null;
                $currentSource = $grant->issue->delivery->checkpoints()->whereNull('source_checkpoint_id')->whereNotNull('custody_after')->max('id');

                return $recordedSource !== null && $currentSource !== null && (int) $recordedSource === (int) $currentSource;
            })->values();
    }

    public function assertMovable(Delivery $parcel): void
    {
        if ($this->held($parcel) && ! isset($this->verifiedRecoveryScopes[$parcel->id])) {
            throw new HandoverHoldException;
        }
    }

    public function runLinkedRecovery(Delivery $parcel, User $actor, LogisticsManifestEvent $observation, callable $command, array $accounts = [], bool $authorizationOnly = false): mixed
    {
        if (! $this->held($parcel) || isset($this->verifiedRecoveryScopes[$parcel->id])) {
            return $command();
        }

        return app(HubHandoverRecoveryService::class)->runLinkedRecovery($parcel, $actor, $observation,
            fn () => $this->scope($parcel, $command), $accounts, $authorizationOnly);
    }

    public function manifestParcelIds(LogisticsManifest $manifest): array
    {
        return HandoverRecoveryGrant::where('mode', 'hub_manifest')->where('source_snapshot->context->reference_id', $manifest->id)
            ->whereHas('issue', fn ($query) => $query->where('state', 'needs_recovery')->whereNotNull('active_delivery_id'))
            ->with(['issue.delivery', 'events'])->get()->filter(function ($grant) {
                $closed = $grant->events->contains(fn ($event) => in_array($event->event_type, ['recovery_received', 'recovery_withdrawn'], true));
                if (! $closed && $grant->expires_at->isFuture()) {
                    return true;
                }
                $ack = $grant->events->firstWhere('event_type', 'holder_acknowledged');
                $recorded = $ack?->parcel_snapshot['recovery_source']['current']['checkpoint_id'] ?? null;
                $current = $grant->issue->delivery->checkpoints()->whereNull('source_checkpoint_id')->whereNotNull('custody_after')->max('id');

                return $recorded !== null && $current !== null && (int) $recorded === (int) $current;
            })->pluck('delivery_id')->unique()->sort()->values()->all();
    }

    public function withVerifiedReceipt(Delivery $parcel, User $actor, HandoverRecoveryGrant $grant, callable $command): mixed
    {
        app(HubHandoverRecoveryService::class)->verifyReceiptScope($parcel, $actor, $grant);

        return $this->scope($parcel, $command);
    }

    private function scope(Delivery $parcel, callable $command): mixed
    {
        $this->verifiedRecoveryScopes[$parcel->id] = ($this->verifiedRecoveryScopes[$parcel->id] ?? 0) + 1;
        try {
            return $command();
        } finally {
            if (--$this->verifiedRecoveryScopes[$parcel->id] === 0) {
                unset($this->verifiedRecoveryScopes[$parcel->id]);
            }
        }
    }
}
