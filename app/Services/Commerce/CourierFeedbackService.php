<?php

namespace App\Services\Commerce;

use App\Models\Delivery;
use App\Models\DeliveryFeedback;
use App\Models\LogisticsCompany;
use App\Models\User;
use App\Rules\ApplicationText;
use App\Rules\AsciiPositiveInteger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CourierFeedbackService
{
    public const ALLOWED_TAGS = [
        'fast_delivery',
        'polite_rider',
        'parcel_intact',
        'late_delivery',
        'rude_behavior',
        'damaged_outer_box',
    ];

    /**
     * Records dedicated courier/delivery service feedback decoupled from product and shop ratings.
     */
    public function submit(User $buyer, Delivery $delivery, array $input): DeliveryFeedback
    {
        $data = Validator::make($input, [
            'rating' => ['bail', 'required', new AsciiPositiveInteger, 'integer', 'between:1,5'],
            'timeliness_rating' => ['bail', 'nullable', new AsciiPositiveInteger, 'integer', 'between:1,5'],
            'handling_rating' => ['bail', 'nullable', new AsciiPositiveInteger, 'integer', 'between:1,5'],
            'communication_rating' => ['bail', 'nullable', new AsciiPositiveInteger, 'integer', 'between:1,5'],
            'comment' => ['nullable', 'string', new ApplicationText('notes', 1, 1000)],
            'tags' => ['nullable', 'array', 'max:5'],
            'tags.*' => ['required', 'string', Rule::in(self::ALLOWED_TAGS)],
        ])->validate();

        return DB::transaction(function () use ($buyer, $delivery, $data) {
            $lockedDelivery = Delivery::with('order')->whereKey($delivery->id)->lockForUpdate()->firstOrFail();
            $order = $lockedDelivery->order;
            if (! $order || $order->buyer_id !== $buyer->id) {
                throw new AuthorizationException('This delivery belongs to another buyer.');
            }

            if ($order->status !== 'completed' && ! in_array($lockedDelivery->status, ['delivered', 'customer_collected'], true)) {
                throw ValidationException::withMessages([
                    'rating' => 'Courier service feedback is available once the parcel is delivered or received.',
                ]);
            }

            $referenceTime = $lockedDelivery->delivered_at ?? $order->completed_at;
            if ($referenceTime && now()->gt($referenceTime->copy()->addDays(30))) {
                throw ValidationException::withMessages([
                    'rating' => 'Delivery feedback must be submitted within 30 days of delivery.',
                ]);
            }

            $existing = DeliveryFeedback::where('delivery_id', $lockedDelivery->id)->lockForUpdate()->first();
            if ($existing) {
                if ($existing->edited_at !== null) {
                    throw ValidationException::withMessages([
                        'rating' => 'Delivery feedback may only be updated once after submission.',
                    ]);
                }

                $existing->update([
                    'rating' => (int) $data['rating'],
                    'timeliness_rating' => $data['timeliness_rating'] ?? $existing->timeliness_rating,
                    'handling_rating' => $data['handling_rating'] ?? $existing->handling_rating,
                    'communication_rating' => $data['communication_rating'] ?? $existing->communication_rating,
                    'comment' => $data['comment'] ?? null,
                    'tags' => array_values(array_unique($data['tags'] ?? [])),
                    'edited_at' => now(),
                ]);

                return $existing->fresh();
            }

            return DeliveryFeedback::create([
                'delivery_id' => $lockedDelivery->id,
                'order_id' => $order->id,
                'buyer_id' => $buyer->id,
                'logistics_company_id' => $lockedDelivery->logistics_company_id,
                'rider_id' => $lockedDelivery->assigned_rider_id ?? $lockedDelivery->courier_id,
                'rating' => (int) $data['rating'],
                'timeliness_rating' => $data['timeliness_rating'] ?? null,
                'handling_rating' => $data['handling_rating'] ?? null,
                'communication_rating' => $data['communication_rating'] ?? null,
                'comment' => $data['comment'] ?? null,
                'tags' => array_values(array_unique($data['tags'] ?? [])),
                'submitted_at' => now(),
            ]);
        });
    }

    public function companySummary(LogisticsCompany $company): array
    {
        if (! Schema::hasTable('delivery_feedbacks')) {
            return ['average_rating' => null, 'feedback_count' => 0];
        }

        $query = DeliveryFeedback::where('logistics_company_id', $company->id);
        $count = (clone $query)->count();

        return [
            'average_rating' => $count > 0 ? round((float) (clone $query)->avg('rating'), 2) : null,
            'feedback_count' => $count,
        ];
    }
}
