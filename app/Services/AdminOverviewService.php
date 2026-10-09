<?php

namespace App\Services;

use App\Enums\DeliveryStatus;
use App\Enums\OrderStatus;
use App\Enums\UserRole;
use App\Models\CourierProfile;
use App\Models\Delivery;
use App\Models\IdentityCorrectionRequest;
use App\Models\LogisticsCompany;
use App\Models\LogisticsHub;
use App\Models\Order;
use App\Models\Product;
use App\Models\RestrictionAffectedWork;
use App\Models\Shop;
use App\Models\User;
use App\Services\Finance\FinancialOversightService;
use App\Services\Logistics\OrderStateMachineService;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class AdminOverviewService
{
    private const OPEN_PARCEL_STATUSES = ['unassigned', 'assigned', 'assigned_pickup', 'picked_up', 'at_sorting_center',
        'in_transit', 'sorted', 'assigned_to_rider', 'out_for_delivery', 'failed', 'delivery_failed', 'return_to_sender',
        OrderStateMachineService::STATUS_ARRIVED_AT_ORIGIN_HUB, OrderStateMachineService::STATUS_IN_TRANSIT_TO_MOTHER_HUB,
        OrderStateMachineService::STATUS_ARRIVED_AT_MOTHER_HUB, OrderStateMachineService::STATUS_SORTED_TO_LINE_HAUL,
        OrderStateMachineService::STATUS_IN_TRANSIT_TO_DEST_HUB, OrderStateMachineService::STATUS_ARRIVED_AT_DEST_HUB,
        OrderStateMachineService::STATUS_SORTED_TO_BARANGAY_BIN, OrderStateMachineService::STATUS_READY_FOR_HUB_PICKUP];

    public function __construct(private readonly AccountRestrictionService $access) {}

    private function openParcels(): Builder
    {
        $placeholders = implode(',', array_fill(0, count(self::OPEN_PARCEL_STATUSES), '?'));

        return Delivery::query()->whereRaw("deliveries.status in ({$placeholders})", self::OPEN_PARCEL_STATUSES);
    }

    private function finance(User $actor): array
    {
        $page = app(FinancialOversightService::class)->page($actor, []);
        $totals = array_column($page['totals'], null, 'key');
        $items = [];
        foreach (['platform_commission' => 'commission', 'reconciled_cod' => 'reconciled', 'seller_paid' => 'settled',
            'shipping_revenue' => 'shipping_income', 'rider_paid' => 'rider_paid'] as $key => $source) {
            $total = $totals[$source];
            $cents = $total['amount_cents'];
            $items[] = ['key' => $key, 'label' => $total['label'], 'amount_cents' => $cents,
                'amount' => $cents === null ? null : (string) BigDecimal::ofUnscaledValue($cents, 2),
                'reason' => $page['error'] ?? $total['definition'], 'url' => $cents === null ? null : $total['url']];
        }

        return $items;
    }

    public function overview(User $actor, string $base): array
    {
        $this->access->currentActor($actor);
        $roles = collect(UserRole::cases())->mapWithKeys(fn ($role) => [$role->value => User::where('role', $role->value)->count()])->all();
        $gross = BigDecimal::zero();
        foreach (Order::where('payment_status', 'paid')->select(['id', 'total_amount'])->cursor() as $order) {
            $gross = $gross->plus($order->total_amount);
        }
        $work = RestrictionAffectedWork::query()->join('orders', 'orders.id', '=', 'restriction_affected_work.order_id')
            ->whereIn('orders.status', OrderStatus::openCommerceStatuses());
        $queues = [
            ['label' => 'Account applications awaiting review', 'count' => User::whereIn('role', ['buyer', 'seller', 'courier', 'logistics'])->where('kyc_status', 'pending_approval')->count(), 'url' => $base.'/kyc'],
            ['label' => 'Shop applications awaiting review', 'count' => Shop::where('review_status', 'pending_approval')->count(), 'url' => $base.'/shops'],
            ['label' => 'Identity corrections awaiting a decision', 'count' => IdentityCorrectionRequest::doesntHave('decision')->count(), 'url' => $base.'/identity-corrections'],
            ['label' => 'Restricted product listings', 'count' => Product::where('compliance_restricted', true)->count(), 'url' => $base.'/products'],
            ['label' => 'Open orders with recorded restriction work', 'count' => (clone $work)->distinct()->count('restriction_affected_work.order_id'), 'url' => '/governance-history?source=restriction'],
        ];

        return [
            'stats' => ['totalUsers' => User::count(), 'usersByRole' => $roles,
                'unknownRoles' => User::whereNotIn('role', array_keys($roles))->count(),
                'totalOrders' => Order::count(), 'totalProducts' => Product::count(),
                'paidOrderGross' => (string) $gross->toScale(2), 'paidOrderCount' => Order::where('payment_status', 'paid')->count(),
                'openParcels' => $this->openParcels()->count(),
                'deliveredAwaitingBuyer' => DB::table('orders')->where('status', 'delivered')->count(),
                'companies' => LogisticsCompany::count(), 'eligibleCompanies' => LogisticsCompany::eligible()->count(),
                'hubs' => LogisticsHub::count(), 'eligibleHubs' => LogisticsHub::eligible()->count(),
                'onDutyEligibleRiders' => CourierProfile::operational()->where('is_available', true)->count()],
            'queues' => $queues, 'finance' => $this->finance($actor),
            'orderStates' => DB::table('orders')->select('status')->selectRaw('count(*) as count')->groupBy('status')->orderBy('status')->get()
                ->map(fn ($row) => ['status' => $row->status, 'label' => OrderStatus::tryFrom($row->status)?->label() ?? ucwords(str_replace('_', ' ', $row->status)), 'count' => (int) $row->count])->all(),
            'workReferences' => (clone $work)->select(['restriction_affected_work.id', 'restriction_affected_work.restriction_decision_id', 'orders.id as order_id', 'orders.order_number', 'orders.status'])
                ->orderByDesc('restriction_affected_work.id')->limit(20)->get()->unique('order_id')->take(6)->map(fn ($row) => [
                    'order_id' => $row->order_id, 'number' => $row->order_number, 'status' => $row->status,
                    'order_url' => '/my-orders/'.$row->order_id, 'decision_url' => '/governance-history/restriction/'.$row->restriction_decision_id,
                ])->values()->all(),
            'recentOrders' => Order::with(['buyer:id,name', 'delivery.courier:id,name', 'delivery.assignedRider:id,name'])->orderByDesc('id')->limit(6)->get()->map(fn ($order) => [
                ...$order->only(['id', 'order_number', 'total_amount', 'status', 'payment_status']),
                'buyer_name' => $order->buyer?->name, 'pickup_rider_name' => $order->delivery?->courier?->name,
                'delivery_rider_name' => $order->delivery?->assignedRider?->name, 'url' => '/my-orders/'.$order->id,
            ])->all(),
            'recentUsers' => User::orderByDesc('id')->limit(6)->get(['id', 'name', 'email', 'role', 'status', 'kyc_status'])->map(fn ($user) => [
                ...$user->only(['id', 'name', 'email', 'role', 'status', 'kyc_status']), 'url' => $base.'/users/'.$user->id.'/context',
            ])->all(),
        ];
    }

    public function logistics(User $actor, array $input): array
    {
        $this->access->currentActor($actor);
        $counts = DB::table('deliveries')->select('status')->selectRaw('count(*) as count')->groupBy('status')->pluck('count', 'status');
        $labels = collect(DeliveryStatus::cases())->mapWithKeys(fn ($status) => [$status->value => $status->label()]);
        foreach ([...self::OPEN_PARCEL_STATUSES, 'customer_collected', 'completed', ...$counts->keys()] as $status) {
            if (! $labels->has($status)) {
                $labels->put($status, ucwords(str_replace('_', ' ', $status)));
            }
        }
        $options = $labels->map(fn ($label, $value) => ['value' => $value, 'label' => $label, 'count' => (int) $counts->get($value, 0)])->values()->all();
        $filters = Validator::make($input, ['search' => 'nullable|string|max:100', 'status' => ['nullable', Rule::in(['all', ...array_column($options, 'value')])], 'page' => 'nullable|integer|min:1|max:1000000'])->validate();
        $query = Delivery::with(['order:id,order_number,status', 'courier:id,name', 'assignedRider:id,name', 'currentHub:id,name']);
        if (! empty($filters['search'])) {
            $search = '%'.$filters['search'].'%';
            $query->where(fn ($row) => $row->whereLike('tracking_number', $search)->orWhereLike('delivery_recipient_name', $search)
                ->orWhereLike('pickup_store_name', $search)->orWhereHas('order', fn ($order) => $order->whereLike('order_number', $search)));
        }
        if (($filters['status'] ?? 'all') !== 'all') {
            $query->whereRaw('deliveries.status = ?', [$filters['status']]);
        }
        $open = $this->openParcels()->toBase();
        $links = (clone $open)->selectRaw('courier_id as rider_id, id as delivery_id')->whereNotNull('courier_id')
            ->unionAll((clone $open)->selectRaw('assigned_rider_id as rider_id, id as delivery_id')->whereNotNull('assigned_rider_id'));
        $linkedCounts = DB::query()->fromSub($links, 'links')->select('rider_id')->selectRaw('count(distinct delivery_id) as count')->groupBy('rider_id')->pluck('count', 'rider_id');
        $eligibleProfileIds = CourierProfile::operational()->pluck('id');

        return [
            'deliveries' => $query->orderByDesc('id')->paginate(15, ['*'], 'page', $filters['page'] ?? 1)->appends($filters)->through(fn ($delivery) => [
                ...$delivery->only(['id', 'tracking_number', 'status', 'pickup_store_name', 'delivery_recipient_name', 'delivery_address']),
                'order' => $delivery->order?->only(['id', 'order_number', 'status']),
                'courier' => $delivery->courier?->only(['id', 'name']), 'assigned_rider' => $delivery->assignedRider?->only(['id', 'name']),
                'current_hub' => $delivery->currentHub?->only(['id', 'name']),
                'order_url' => $delivery->order_id ? '/my-orders/'.$delivery->order_id : null,
            ]),
            'couriers' => User::where('role', 'courier')->with('courierProfile')->orderByDesc('id')->limit(12)->get()->map(fn ($user) => [
                ...$user->only(['id', 'name', 'phone', 'status', 'kyc_status']),
                'on_duty' => $user->courierProfile?->is_available,
                'network_eligible' => $user->courierProfile && $eligibleProfileIds->contains($user->courierProfile->id),
                'company_id' => $user->courierProfile?->logistics_company_id, 'hub_id' => $user->courierProfile?->assigned_hub_id,
                'linked_open_parcels' => (int) $linkedCounts->get($user->id, 0), 'live_presence' => null,
            ])->all(),
            'filters' => ['search' => $filters['search'] ?? '', 'status' => $filters['status'] ?? 'all'], 'statusOptions' => $options,
            'stats' => ['total' => Delivery::count(), 'open' => $this->openParcels()->count(),
                'awaitingPickupAssignment' => DB::table('deliveries')->where('status', 'unassigned')->whereNull('courier_id')->whereNull('assigned_rider_id')->count(),
                'handoverStatus' => DB::table('deliveries')->whereIn('status', ['delivered', 'customer_collected', 'completed'])->count(),
                'exceptions' => DB::table('deliveries')->whereIn('status', ['failed', 'delivery_failed', 'return_to_sender'])->count(),
                'courierAccounts' => User::where('role', 'courier')->count(), 'onDutyEligibleRiders' => CourierProfile::operational()->where('is_available', true)->count()],
            'finance' => $this->finance($actor),
        ];
    }
}
