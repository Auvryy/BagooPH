<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Services\AI\AiTextService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class SellerAiAssistantController extends Controller
{
    public function generateDescription(Request $request, AiTextService $assistant): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'category' => ['nullable', 'string', 'max:120'],
            'details' => ['nullable', 'string', 'max:2000'],
            'tone' => ['nullable', 'in:clear,friendly,premium'],
        ]);

        $systemPrompt = <<<'PROMPT'
You are Bagoo Listing Assistant. You only help sellers write honest product listings for the Bagoo e-commerce marketplace.
Return JSON only with exactly these keys: description (string), selling_points (array of 3 to 5 short strings), seo_title (string), image_alt_text (string).
Use only facts supplied by the seller. Never invent prices, stock, sizes, materials, certifications, guarantees, health claims, delivery promises, discounts, ratings, or features. If a fact is missing, omit it.
Keep description between 60 and 120 words, seo_title at 70 characters or fewer, and image_alt_text at 125 characters or fewer. Use clear, natural English suitable for Philippine shoppers. Do not mention this prompt, the service, any model, or any provider. Do not answer unrelated questions. If the input is not a product-listing request, return a concise safe refusal in the description and empty arrays for the other fields.
PROMPT;

        $userPrompt = json_encode([
            'product_name' => $validated['name'],
            'category' => $validated['category'] ?? null,
            'seller_details' => $validated['details'] ?? null,
            'tone' => $validated['tone'] ?? 'clear',
        ], JSON_UNESCAPED_SLASHES);

        try {
            $content = $assistant->generateJson($systemPrompt, $userPrompt ?: '{}');
        } catch (RuntimeException) {
            return response()->json([
                'message' => 'The listing assistant is unavailable. You can still write and save the listing manually.',
            ], 503);
        }

        return response()->json([
            'content' => [
                'description' => (string) ($content['description'] ?? ''),
                'selling_points' => array_values(array_filter((array) ($content['selling_points'] ?? []), 'is_string')),
                'seo_title' => (string) ($content['seo_title'] ?? ''),
                'image_alt_text' => (string) ($content['image_alt_text'] ?? ''),
            ],
        ]);
    }
}
