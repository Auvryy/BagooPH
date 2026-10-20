<?php

namespace App\Services\Commerce;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use JsonException;

class ProductGalleryInput
{
    public function store(UploadedFile $file): string
    {
        $path = $file->store('products', 'public');
        DB::afterRollBack(fn () => Storage::disk('public')->delete($path));

        return $path;
    }

    public function validate(Request $request, string $status, ?Product $product = null): void
    {
        $files = $request->file('image_files') ?? [];
        if ($request->filled('gallery_manifest')) {
            try {
                $manifest = json_decode($request->input('gallery_manifest'), true, flags: JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                $this->invalid('Choose the product images again before saving.');
            }
            if (! is_array($manifest) || ! array_is_list($manifest)) {
                $this->invalid('Choose the product images again before saving.');
            }
            if (count($manifest) > 9) {
                $this->invalid('Use no more than 9 product images. Remove an image before adding another.');
            }
            $usedFiles = [];
            foreach ($manifest as $entry) {
                if (! is_array($entry)) {
                    $this->invalid('Choose the product images again before saving.');
                }
                if (($entry['type'] ?? null) === 'file') {
                    $index = $entry['file_index'] ?? null;
                    if ((! is_int($index) && ! is_string($index)) || ! ctype_digit((string) $index)
                        || ! isset($files[$index]) || isset($usedFiles[$index])) {
                        $this->invalid('Each uploaded image must appear once in the gallery. Choose the images again.');
                    }
                    $usedFiles[$index] = true;
                } elseif (! in_array($entry['type'] ?? null, ['url', 'existing'], true)
                    || ! $this->realImage($entry['url'] ?? null)) {
                    $this->invalid('Add a product image or a valid image URL. A placeholder cannot be used to publish.');
                }
            }
            $count = count($manifest);
        } elseif ($request->hasFile('image_file')) {
            $count = 1;
        } elseif ($files !== []) {
            $manifest = array_map(fn ($index) => ['type' => 'file', 'file_index' => $index], array_keys($files));
            $request->merge(['gallery_manifest' => json_encode($manifest)]);
            $count = count($manifest);
        } elseif ($request->filled('featured_image')) {
            $count = $this->realImage($request->input('featured_image')) ? 1 : 0;
        } else {
            $images = $product?->images()->pluck('image_url')->all() ?? [];
            $count = array_filter($images, fn ($url) => $this->realImage($url)) !== [] ? count($images) : 0;
            if ($count > 0) {
                $request->merge(['gallery_manifest' => json_encode(array_map(fn ($url) => ['type' => 'existing', 'url' => $url], $images))]);
            }
            if ($count === 0 && $this->realImage($product?->featured_image)) {
                $count = 1;
            }
        }

        if ($count > 9) {
            $this->invalid('Use no more than 9 product images. Remove an image before adding another.');
        }
        if ($status === 'active' && $count === 0) {
            $this->invalid('Add at least 1 product image before publishing. You can save a draft without images.');
        }
    }

    private function realImage(mixed $url): bool
    {
        return is_string($url) && trim($url) !== '' && ! str_starts_with($url, 'blob:')
            && parse_url($url, PHP_URL_PATH) !== '/storage/products/placeholder.webp';
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['gallery_manifest' => $message]);
    }
}
