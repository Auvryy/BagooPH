<?php

namespace Tests\Feature\AI;

use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AssistantTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'ai.api_key' => 'test-key',
            'ai.model' => 'test-model',
        ]);
    }

    public function test_provider_http_errors_become_safe_assistant_failures(): void
    {
        foreach ([400, 429, 500] as $status) {
            Http::fake(['*' => Http::response(['error' => ['message' => 'private-provider-detail']], $status)]);
            try {
                app(\App\Services\AI\AiTextService::class)->generateJson('Return JSON', 'Describe a bag');
                $this->fail('Expected a safe provider failure.');
            } catch (\RuntimeException $exception) {
                $this->assertSame('The assistant could not respond right now.', $exception->getMessage());
                $this->assertStringNotContainsString('test-key', $exception->getMessage());
                $this->assertStringNotContainsString('private-provider-detail', $exception->getMessage());
            }
        }
    }

    public function test_seller_can_generate_a_reviewable_listing_draft(): void
    {
        Http::fake([
            '*' => Http::response([
                'candidates' => [[
                    'content' => ['parts' => [[
                        'text' => json_encode([
                            'description' => 'A practical everyday bag with a lightweight design.',
                            'selling_points' => ['Lightweight design', 'Everyday use'],
                            'seo_title' => 'Everyday lightweight bag',
                            'image_alt_text' => 'Lightweight everyday bag',
                        ]),
                    ]]],
                ]],
            ]),
        ]);

        $seller = User::factory()->seller()->create();

        $response = $this->actingAs($seller)->postJson(route('seller.products.assist-description'), [
            'name' => 'Everyday Carry Bag',
            'category' => 'Bags',
            'details' => 'Black nylon bag with two exterior pockets.',
        ]);

        $response->assertOk()
            ->assertJsonPath('content.description', 'A practical everyday bag with a lightweight design.')
            ->assertJsonCount(2, 'content.selling_points');

        Http::assertSent(fn ($request) => $request->url() === 'https://generativelanguage.googleapis.com/v1beta/models/test-model:generateContent'
            && $request->hasHeader('x-goog-api-key', 'test-key')
            && ! str_contains($request->url(), 'test-key')
            && str_contains($request->body(), 'Black nylon bag')
            && str_contains($request->body(), 'Never invent prices'));
    }

    public function test_support_uses_only_the_authenticated_buyers_orders(): void
    {
        Http::fake([
            '*' => Http::response([
                'candidates' => [[
                    'content' => ['parts' => [[
                        'text' => json_encode([
                            'reply' => 'Your order is currently being prepared.',
                            'suggested_questions' => ['How do I track it?'],
                        ]),
                    ]]],
                ]],
            ]),
        ]);

        $buyer = User::factory()->buyer()->create();
        $otherBuyer = User::factory()->buyer()->create();
        Order::factory()->create(['buyer_id' => $buyer->id, 'order_number' => 'BGO-MY-ORDER']);
        Order::factory()->create(['buyer_id' => $otherBuyer->id, 'order_number' => 'BGO-PRIVATE-ORDER']);

        $response = $this->actingAs($buyer)->postJson(route('buyer.support.assistant'), [
            'message' => 'Where is my order?',
        ]);

        $response->assertOk()->assertJsonPath('reply', 'Your order is currently being prepared.');

        Http::assertSent(fn ($request) => str_contains($request->body(), 'BGO-MY-ORDER')
            && !str_contains($request->body(), 'BGO-PRIVATE-ORDER')
            && str_contains($request->body(), 'Never claim that you changed an order'));
    }

    public function test_non_buyers_cannot_use_buyer_support(): void
    {
        $seller = User::factory()->seller()->create();

        $this->actingAs($seller)
            ->postJson(route('buyer.support.assistant'), ['message' => 'Hello'])
            ->assertForbidden();
    }

}
