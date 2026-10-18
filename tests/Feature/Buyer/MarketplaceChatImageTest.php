<?php

namespace Tests\Feature\Buyer;

use App\Models\Message;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class MarketplaceChatImageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('public');
    }

    private function inquiry(): array
    {
        $buyer = User::factory()->create();
        $product = Product::factory()->create();
        $this->actingAs($buyer);

        return [$buyer, $product, ['receiver_id' => $product->shop->user_id, 'shop_id' => $product->shop_id,
            'product_id' => $product->id, 'message' => 'Please check this product photo.', 'request_key' => (string) Str::uuid()]];
    }

    public function test_real_image_is_private_owned_and_retry_safe_after_the_rapid_duplicate_window(): void
    {
        [$buyer, $product, $input] = $this->inquiry();
        $input['attachment'] = UploadedFile::fake()->image('bag.jpg');
        $response = $this->postJson(route('chat.send'), $input)->assertOk()->assertJsonPath('success', true);
        $message = Message::firstOrFail();
        $url = $response->json('message.attachment_url');
        $this->assertSame(route('chat.attachment', $message, absolute: false), $url);
        $response->assertJsonMissingPath('message.attachment_path')->assertJsonMissingPath('message.attachment_hash')->assertJsonMissingPath('message.request_key');
        $this->assertCount(1, Storage::disk('local')->allFiles('chat-images'));
        $this->assertSame([], Storage::disk('public')->allFiles());
        $this->get($url)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->actingAs($product->shop->user)->get($url)->assertOk();
        $this->actingAs(User::factory()->create())->get($url)->assertForbidden();
        $this->actingAs(User::factory()->seller()->create())->get($url)->assertForbidden();
        $this->actingAs($buyer);
        $this->travel(10)->seconds();
        $this->postJson(route('chat.send'), $input)->assertOk()->assertJsonPath('is_duplicate', true);
        $input['request_key'] = strtoupper($input['request_key']);
        $this->postJson(route('chat.send'), $input)->assertOk()->assertJsonPath('is_duplicate', true);
        $this->assertDatabaseCount('messages', 1);
        $this->assertCount(1, Storage::disk('local')->allFiles('chat-images'));
        $input['message'] = 'A changed request.';
        $this->postJson(route('chat.send'), $input)->assertConflict();
        $this->assertDatabaseCount('messages', 1);
    }

    public function test_invalid_file_size_format_content_and_text_never_store_messages_or_files(): void
    {
        [, , $input] = $this->inquiry();
        foreach ([UploadedFile::fake()->create('video.mp4', 10, 'video/mp4'),
            UploadedFile::fake()->create('pretend.jpg', 10, 'image/jpeg'), UploadedFile::fake()->image('oversize.jpg')->size(5121)] as $image) {
            $this->postJson(route('chat.send'), [...$input, 'attachment' => $image])->assertUnprocessable()->assertJsonValidationErrors('attachment');
        }
        $this->postJson(route('chat.send'), [...$input, 'message' => str_repeat('a', 1001)])->assertUnprocessable()->assertJsonValidationErrors('message');
        $this->assertDatabaseCount('messages', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->postJson(route('chat.send'), [...$input, 'message' => '', 'attachment' => UploadedFile::fake()->image('photo.png')])->assertOk();
    }

    public function test_foreign_context_and_malformed_identifiers_do_not_create_a_conversation(): void
    {
        [, $product, $input] = $this->inquiry();
        $foreign = Product::factory()->create();
        $this->postJson(route('chat.send'), [...$input, 'product_id' => $foreign->id])->assertForbidden();
        $order = Order::factory()->create();
        $this->postJson(route('chat.send'), [...$input, 'order_id' => $order->id])->assertForbidden();
        $this->postJson(route('chat.send'), [...$input, 'receiver_id' => [$product->shop->user_id]])->assertUnprocessable()->assertJsonValidationErrors('receiver_id');
        $this->getJson(route('chat.messages', User::factory()->create()->id))->assertForbidden();
        $this->assertDatabaseCount('messages', 0);
    }

    public function test_storage_failure_rolls_back_message_and_removes_the_unconfirmed_image(): void
    {
        [, , $input] = $this->inquiry();
        Event::listen('eloquent.created: '.Message::class, fn () => throw new RuntimeException('Private write failure.'));
        $this->postJson(route('chat.send'), [...$input, 'attachment' => UploadedFile::fake()->image('photo.jpg')])
            ->assertStatus(500)->assertJson(['success' => false, 'message' => 'Your message was not confirmed. Try again.']);
        $this->assertDatabaseCount('messages', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_read_acknowledges_only_the_messages_in_the_returned_snapshot(): void
    {
        [$buyer, $product] = $this->inquiry();
        $seller = $product->shop->user;
        $first = Message::create(['sender_id' => $seller->id, 'receiver_id' => $buyer->id, 'message' => 'First message.']);
        $injected = null;
        Event::listen(QueryExecuted::class, function ($query) use ($seller, $buyer, &$injected) {
            if ($injected === null && str_starts_with(strtolower($query->sql), 'select * from "messages"')) {
                $injected = false;
                $injected = Message::create(['sender_id' => $seller->id, 'receiver_id' => $buyer->id, 'message' => 'A later message.']);
            }
        });
        $this->getJson(route('chat.messages', $seller->id))->assertOk()->assertJsonCount(1, 'messages');
        $this->assertTrue($first->fresh()->is_read);
        $this->assertInstanceOf(Message::class, $injected);
        $this->assertFalse($injected->fresh()->is_read);
    }

    public function test_message_limit_blocks_new_uploads_but_retains_successful_retry_access(): void
    {
        [$buyer, , $input] = $this->inquiry();
        $input['attachment'] = UploadedFile::fake()->image('photo.jpg');
        $this->postJson(route('chat.send'), $input)->assertOk();
        for ($i = 0; $i < 60; $i++) {
            RateLimiter::hit('marketplace-message:'.$buyer->id, 60);
        }
        $this->postJson(route('chat.send'), $input)->assertOk()->assertJsonPath('is_duplicate', true);
        $this->postJson(route('chat.send'), [...$input, 'request_key' => (string) Str::uuid(), 'message' => 'Another photo.'])->assertStatus(429);
        $this->assertDatabaseCount('messages', 1);
        $this->assertCount(1, Storage::disk('local')->allFiles('chat-images'));
    }
}
