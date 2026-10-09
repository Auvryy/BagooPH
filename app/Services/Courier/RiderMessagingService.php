<?php

namespace App\Services\Courier;

use App\Models\DeliveryCheckpoint;
use App\Models\Message;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class RiderMessagingService
{
    public function __construct(private readonly CourierMessagingService $messaging) {}

    private function assignments(User $actor): Builder
    {
        $accessible = $this->messaging->conversationQuery($actor);
        $profile = $actor->courierProfile;

        return DeliveryCheckpoint::whereIn('delivery_id', $accessible->select('deliveries.id'))
            ->whereNull('source_checkpoint_id')->whereNotNull('target_state')
            ->whereRaw('delivery_checkpoints.id = (select max(newer.id) from delivery_checkpoints newer where newer.delivery_id = delivery_checkpoints.delivery_id and newer.checkpoint_type = delivery_checkpoints.checkpoint_type and newer.source_checkpoint_id is null)')
            ->where(function ($query) use ($actor, $profile) {
                $query->where(fn ($pickup) => $pickup->where('checkpoint_type', 'assigned_pickup')->where('target_state->pickup_rider_id', $actor->id)
                    ->whereHas('delivery', fn ($parcel) => $parcel->where('courier_id', $actor->id)->where('origin_bayan_hub_id', $profile?->assigned_hub_id)
                        ->whereHas('order.items.product.shop.user')))
                    ->orWhere(fn ($final) => $final->where('checkpoint_type', 'assigned_to_rider')->where('target_state->assigned_rider_id', $actor->id)
                        ->whereHas('delivery', fn ($parcel) => $parcel->where('assigned_rider_id', $actor->id)->where('destination_bayan_hub_id', $profile?->assigned_hub_id)
                            ->whereHas('order.buyer')));
            })->with(['delivery.order.buyer', 'delivery.order.items.product.shop.user']);
    }

    public function list(Request $request): array
    {
        $page = RiderApiInput::page($request);
        $rows = $this->assignments($request->user())->orderByDesc('created_at')->orderByDesc('id')
            ->paginate((int) $page['per_page'], ['*'], 'page', (int) $page['page']);

        return ['items' => $rows->getCollection()->map(fn ($assignment) => $this->present($assignment, $request->user()))->all(),
            'pagination' => ['page' => $rows->currentPage(), 'per_page' => $rows->perPage(), 'total' => $rows->total(), 'last_page' => $rows->lastPage()]];
    }

    public function owned(User $actor, string $thread): array
    {
        if (preg_match('/\A(pickup|final_mile)-([1-9][0-9]*)-([1-9][0-9]*)-([1-9][0-9]*)\z/', $thread, $parts) !== 1) {
            abort(404);
        }
        [$deliveryId, $assignmentId, $participantId] = array_map(fn ($id) => RiderApiInput::id($id), array_slice($parts, 2));
        $assignment = $this->assignments($actor)->where('delivery_id', $deliveryId)->whereKey($assignmentId)->firstOrFail();
        $phase = $assignment->checkpoint_type === 'assigned_pickup' ? 'pickup' : 'final_mile';
        $participant = collect($this->messaging->participantsForDelivery($assignment->delivery, $actor))->firstWhere('phase', $phase);
        if ($phase !== $parts[1] || ! $participant || $participant['user']->id !== $participantId) {
            RiderApiInput::error('STALE_CONVERSATION', 'This thread is no longer the selected assignment and participant.', 409);
        }

        return [$assignment, $phase, $participant];
    }

    public function thread(Request $request, string $thread): array
    {
        [$assignment, $phase, $participant] = $this->owned($request->user(), $thread);
        $page = RiderApiInput::page($request);
        $rows = $this->messages($assignment, $request->user(), $participant['user']->id)->orderByDesc('id')
            ->paginate((int) $page['per_page'], ['*'], 'page', (int) $page['page']);

        return ['thread' => $this->present($assignment, $request->user()),
            'items' => $rows->getCollection()->map(fn ($message) => $this->message($message, $request->user()))->all(),
            'pagination' => ['page' => $rows->currentPage(), 'per_page' => $rows->perPage(), 'total' => $rows->total(), 'last_page' => $rows->lastPage()]];
    }

    private function messages(DeliveryCheckpoint $assignment, User $actor, int $participantId): Builder
    {
        return Message::where('order_id', $assignment->delivery->order_id)->where(fn ($q) => $q
            ->where(fn ($incoming) => $incoming->where('sender_id', $participantId)->where('receiver_id', $actor->id))
            ->orWhere(fn ($outgoing) => $outgoing->where('sender_id', $actor->id)->where('receiver_id', $participantId)));
    }

    public function present(DeliveryCheckpoint $assignment, User $actor): array
    {
        $phase = $assignment->checkpoint_type === 'assigned_pickup' ? 'pickup' : 'final_mile';
        $participant = collect($this->messaging->participantsForDelivery($assignment->delivery, $actor))->firstWhere('phase', $phase);
        abort_unless($participant, 404);
        $user = $participant['user'];
        $query = $this->messages($assignment, $actor, $user->id);
        $last = (clone $query)->orderByDesc('id')->first();
        $avatar = is_string($user->avatar) && preg_match('#\A/storage/avatars/[A-Za-z0-9._/-]+\z#', $user->avatar) === 1 && ! str_contains($user->avatar, '..') ? $user->avatar : null;

        return ['id' => $phase.'-'.$assignment->delivery_id.'-'.$assignment->id.'-'.$user->id,
            'delivery_id' => (string) $assignment->delivery_id, 'assignment_reference' => $assignment->record_reference,
            'phase' => $phase, 'tracking_number' => $assignment->delivery->tracking_number,
            'participant' => ['id' => (string) $user->id, 'name' => $user->name, 'role' => $user->role, 'avatar_url' => $avatar],
            'can_send' => $participant['can_send'], 'send_denial' => $participant['can_send'] ? null : 'PHASE_NOT_ACTIVE',
            'unread_count' => (clone $query)->where('receiver_id', $actor->id)->where('is_read', false)->count(),
            'last_message' => $last ? $this->message($last, $actor) : null];
    }

    public function message(Message $message, User $actor): array
    {
        return ['id' => (string) $message->id, 'sender_id' => (string) $message->sender_id,
            'text' => $message->message, 'from_rider' => $message->sender_id === $actor->id,
            'is_read' => $message->is_read, 'recorded_at' => RiderApiInput::time($message->created_at)];
    }
}
