<?php

namespace App\Services\Governance;

use App\Models\Delivery;
use App\Models\Message;
use App\Models\ModerationSignal;
use App\Models\Order;
use App\Models\Review;
use App\Models\User;
use App\Rules\ApplicationText;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ModerationSignalService
{
    public const BYPASS_PATTERNS = [
        '/\bgcash\b/i',
        '/\bpaymaya\b|\bmaya\b/i',
        '/\bviber\b|\btelegram\b|\bwhatsapp\b/i',
        '/\bbank\s*transfer\b|\bdirect\s*transfer\b/i',
        '/\boff[\s-]?platform\b|\boutside\s+bagoo\b/i',
        '/\bsend\s+money\b|\bmessage\s+me\s+at\b/i',
    ];

    public const COURIER_COMPLAINT_PATTERNS = [
        '/\b(courier|rider|delivery\s*(?:guy|driver|rider)|shipping\s*delay|late\s*delivery|delayed\s*parcel|hub\s*delay|lost\s*in\s*transit|crushed\s*box)\b/i',
    ];

    /** @return array<int, string> */
    public function detectBypassTerms(?string $text): array
    {
        if (! is_string($text) || trim($text) === '') {
            return [];
        }

        $matched = [];
        foreach (self::BYPASS_PATTERNS as $pattern) {
            if (preg_match_all($pattern, $text, $hits)) {
                foreach ($hits[0] as $term) {
                    $matched[] = mb_strtolower(trim($term));
                }
            }
        }

        return array_values(array_unique($matched));
    }

    /** @return array<int, string> */
    public function detectCourierTerms(?string $text): array
    {
        if (! is_string($text) || trim($text) === '') {
            return [];
        }

        $matched = [];
        foreach (self::COURIER_COMPLAINT_PATTERNS as $pattern) {
            if (preg_match_all($pattern, $text, $hits)) {
                foreach ($hits[0] as $term) {
                    $matched[] = mb_strtolower(trim($term));
                }
            }
        }

        return array_values(array_unique($matched));
    }

    /**
     * Records a strictly non-punitive signal when chat text mentions external payment/contact terms.
     * Keyword matches never trigger bans or penalties on their own.
     */
    public function inspectMessage(Message $message): ?ModerationSignal
    {
        if (! Schema::hasTable('moderation_signals')) {
            return null;
        }

        $terms = $this->detectBypassTerms($message->message);
        if ($terms === []) {
            return null;
        }

        return ModerationSignal::create([
            'reference' => 'SIG-'.Str::upper(Str::random(10)),
            'signal_type' => 'off_platform_bypass_keyword',
            'severity' => 'advisory',
            'status' => 'open',
            'is_punitive' => false,
            'subject_user_id' => $message->sender_id,
            'order_id' => $message->order_id,
            'message_id' => $message->id,
            'matched_terms' => $terms,
            'context_payload' => [
                'sender_id' => $message->sender_id,
                'receiver_id' => $message->receiver_id,
                'shop_id' => $message->shop_id,
            ],
            'summary' => 'External contact or payment keyword detected in chat. Recorded for non-punitive anti-scam review and buyer check-in.',
        ]);
    }

    public function recordStalledOrderSignal(Order $order, ?Delivery $delivery = null, ?string $summary = null): ?ModerationSignal
    {
        if (! Schema::hasTable('moderation_signals')) {
            return null;
        }

        $existing = ModerationSignal::where('order_id', $order->id)
            ->where('signal_type', 'anti_scam_stalled_order')
            ->where('status', 'open')
            ->first();
        if ($existing) {
            return $existing;
        }

        return ModerationSignal::create([
            'reference' => 'SIG-'.Str::upper(Str::random(10)),
            'signal_type' => 'anti_scam_stalled_order',
            'severity' => 'advisory',
            'status' => 'open',
            'is_punitive' => false,
            'subject_user_id' => $order->buyer_id,
            'order_id' => $order->id,
            'delivery_id' => $delivery?->id ?? $order->delivery?->id,
            'summary' => $summary ?? 'Order progress stalled before delivery completion. Prompt buyer with a non-punitive safety check-in.',
        ]);
    }

    public function recordVelocitySignal(Review $review, int $recentCount, string $windowLabel): ?ModerationSignal
    {
        if (! Schema::hasTable('moderation_signals')) {
            return null;
        }

        return ModerationSignal::create([
            'reference' => 'SIG-'.Str::upper(Str::random(10)),
            'signal_type' => 'review_velocity_burst',
            'severity' => 'review_required',
            'status' => 'open',
            'is_punitive' => false,
            'subject_user_id' => $review->buyer_id,
            'order_id' => $review->order_id,
            'review_id' => $review->id,
            'context_payload' => ['product_id' => $review->product_id, 'recent_count' => $recentCount, 'window' => $windowLabel],
            'summary' => "Unusual review velocity ({$recentCount} in {$windowLabel}) placed review {$review->id} into moderation hold without penalizing buyer.",
        ]);
    }

    public function recordCourierKeywordSignal(Review $review, array $terms): ?ModerationSignal
    {
        if (! Schema::hasTable('moderation_signals')) {
            return null;
        }

        return ModerationSignal::create([
            'reference' => 'SIG-'.Str::upper(Str::random(10)),
            'signal_type' => 'review_courier_keyword',
            'severity' => 'advisory',
            'status' => 'open',
            'is_punitive' => false,
            'subject_user_id' => $review->buyer_id,
            'order_id' => $review->order_id,
            'review_id' => $review->id,
            'matched_terms' => $terms,
            'summary' => 'Review comment contains courier/delivery terms. Kept visible for buyer honesty; available for seller score-exclusion review.',
        ]);
    }

    public function submitBuyerCheckin(User $buyer, ModerationSignal $signal, array $input): ModerationSignal
    {
        $order = $signal->order;
        abort_unless(
            ($order && $order->buyer_id === $buyer->id) || $signal->subject_user_id === $buyer->id,
            403,
            'This safety check-in belongs to another buyer.'
        );

        $data = Validator::make($input, [
            'response' => ['required', 'string', Rule::in(['safe_on_platform', 'reported_bypass'])],
            'notes' => ['nullable', 'string', new ApplicationText('notes', 3, 500)],
        ])->validate();

        $signal->update([
            'buyer_checkin_response' => $data['response'],
            'buyer_checkin_notes' => isset($data['notes']) ? trim((string) $data['notes']) : null,
            'checked_in_at' => now(),
            'status' => 'checked_in',
            'severity' => $data['response'] === 'reported_bypass' ? 'high_priority' : 'advisory',
        ]);

        return $signal->fresh();
    }

    public function reviewSignal(User $actor, ModerationSignal $signal, array $input, EnforcementService $enforcement): ModerationSignal
    {
        $actor = $enforcement->assertVerifiedAdmin($actor);
        $data = Validator::make($input, [
            'status' => ['required', 'string', Rule::in(['confirmed_violation', 'dismissed_benign'])],
            'review_notes' => ['required', 'string', new ApplicationText('notes', 5, 1000)],
        ])->validate();

        $signal->update([
            'status' => $data['status'],
            'reviewed_by_id' => $actor->id,
            'review_notes' => trim($data['review_notes']),
            'reviewed_at' => now(),
        ]);

        return $signal->fresh();
    }
}
