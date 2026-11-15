<?php

namespace Tests\Feature\Finance;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\E2E\Support\InteractsWithRoles;
use Tests\TestCase;

/** A seller's money lives on Finances and Payouts; delivery-partner earnings are not a seller page. */
class SellerFinancePagesTest extends TestCase
{
    use InteractsWithRoles, RefreshDatabase;

    public function test_seller_is_sent_from_delivery_earnings_to_finances(): void
    {
        $this->withoutVite();
        $seller = $this->createApprovedUser('seller');

        $this->actingAs($seller)->get('/delivery-earnings')->assertRedirect('/financial-oversight');
        $this->actingAs($seller)->get('/financial-oversight')->assertOk()
            ->assertInertia(fn ($page) => $page->component('Finance/Overview')->where('mode', 'seller'));
    }
}
