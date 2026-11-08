<?php

namespace Tests\Feature\Buyer;

use App\Models\Category;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class BuyerCampaignEventTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_can_view_campaign_events_with_curated_products_and_vouchers(): void
    {
        $events = [
            '11-11-mega-sale',
            'christmas-gala',
            'halloween-spree',
            'mega-carnival',
            'payday-savers',
            'electronics-frenzy',
        ];

        foreach ($events as $event) {
            $response = $this->get(route('buyer.campaign.show', ['event' => $event]));

            $response->assertStatus(200);
            $response->assertInertia(fn (Assert $page) => $page
                ->component('Buyer/Campaign')
                ->has('campaign.title')
                ->has('campaign.badge')
                ->has('campaign.vouchers')
                ->has('products')
                ->has('categories')
                ->has('allCampaigns', 6)
                ->where('event', $event)
            );
        }
    }

    public function test_fallback_to_default_campaign_on_unknown_event(): void
    {
        $response = $this->get('/campaign/non-existent-event');

        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Buyer/Campaign')
            ->where('campaign.slug', '11-11-mega-sale')
        );
    }
}
