<?php

namespace Tests\Feature\Seller;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\E2E\Support\InteractsWithRoles;
use Tests\TestCase;

class VoucherLinkTest extends TestCase
{
    use InteractsWithRoles, RefreshDatabase;

    public function test_a_voucher_link_without_a_numeric_id_is_not_found_instead_of_failing(): void
    {
        $seller = $this->createApprovedUser('seller');

        foreach (['/seller/vouchers/create', '/seller/vouchers/abc', '/admin/vouchers/new'] as $url) {
            $this->actingAs($seller)->get($url)->assertNotFound();
        }
    }
}
