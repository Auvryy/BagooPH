<?php

namespace App\Services\Advertisements;

use App\Models\AdvertisementAsset;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class AdvertisementAssetService
{
    public const MAX_BYTES = 5242880;

    public function store(User $actor, UploadedFile $file, array &$createdPaths): AdvertisementAsset
    {
        if (! function_exists('imagewebp') || ! (gd_info()['WebP Support'] ?? false)) {
            $this->invalid('Image uploads need WebP support on the server. Rebuild the application image before uploading artwork.');
        }
        if (! $file->isValid() || $file->getSize() < 1 || $file->getSize() > self::MAX_BYTES) {
            $this->invalid('Choose a JPEG, PNG or WebP image no larger than 5 MiB.');
        }
        $bytes = file_get_contents($file->getRealPath());
        $info = @getimagesizefromstring($bytes);
        $formats = [IMAGETYPE_JPEG => 'jpeg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];
        if (! $info || ! isset($formats[$info[2]])) {
            $this->invalid('The file must be a real, readable JPEG, PNG or WebP image. Videos, GIF, PDF and SVG are not supported.');
        }
        [$width, $height] = $info;
        if ($width < 940 || $height < 440 || $width > 4096 || $height > 4096 || $width * $height > 16000000) {
            $this->invalid('Use an image at least 940 x 440 pixels, no more than 4096 pixels on either edge and no more than 16 megapixels.');
        }
        if (abs(($width / $height) / (47 / 22) - 1) > 0.01) {
            $this->invalid('Use the 47:22 banner shape, such as 1880 x 880 pixels. The image will not be cropped automatically.');
        }
        $source = @imagecreatefromstring($bytes);
        if (! $source) {
            $this->invalid('We could not read this image. Choose a different image and try again.');
        }
        $scale = min(1, 1880 / $width, 880 / $height);
        $outputWidth = (int) floor($width * $scale);
        $outputHeight = (int) floor($height * $scale);
        $output = imagecreatetruecolor($outputWidth, $outputHeight);
        imagealphablending($output, false);
        imagesavealpha($output, true);
        imagefilledrectangle($output, 0, 0, $outputWidth, $outputHeight, imagecolorallocatealpha($output, 0, 0, 0, 127));
        imagecopyresampled($output, $source, 0, 0, 0, 0, $outputWidth, $outputHeight, $width, $height);
        ob_start();
        try {
            if (! imagewebp($output, null, 86)) {
                $this->invalid('We could not prepare this image. Your previous artwork is unchanged.');
            }
            $optimized = ob_get_contents();
        } finally {
            ob_end_clean();
            imagedestroy($source);
            imagedestroy($output);
        }
        $token = (string) Str::uuid();
        $sourcePath = "advertisements/{$token}/source.".$formats[$info[2]];
        $imagePath = "advertisements/{$token}/image.webp";
        foreach ([$sourcePath => $bytes, $imagePath => $optimized] as $path => $contents) {
            $createdPaths[] = $path;
            if (! Storage::disk('local')->put($path, $contents)) {
                $this->invalid('The image could not be saved. Your previous artwork is unchanged; please try again.');
            }
        }

        return AdvertisementAsset::create(['token' => $token, 'uploaded_by_id' => $actor->id,
            'checksum' => hash('sha256', $bytes), 'source_path' => $sourcePath, 'image_path' => $imagePath,
            'mime' => $info['mime'], 'source_bytes' => strlen($bytes), 'image_bytes' => strlen($optimized),
            'width' => $outputWidth, 'height' => $outputHeight]);
    }

    public function cleanUnreferenced(array $paths): void
    {
        foreach ($paths as $path) {
            try {
                if (! AdvertisementAsset::where('source_path', $path)->orWhere('image_path', $path)->exists()) {
                    Storage::disk('local')->delete($path);
                }
            } catch (Throwable $exception) {
                report($exception);
            }
        }
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['image' => $message]);
    }
}
