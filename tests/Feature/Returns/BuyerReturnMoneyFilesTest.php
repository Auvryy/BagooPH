<?php

namespace Tests\Feature\Returns;

use App\Services\Returns\BuyerReturnMoneyFiles;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BuyerReturnMoneyFilesTest extends TestCase
{
    public static function boundaries(): array
    {
        return [[5242879, true], [5242880, true], [5242881, false]];
    }

    #[DataProvider('boundaries')]
    public function test_actual_receipt_bytes_one_below_at_and_above_five_mb(int $bytes, bool $accepted): void
    {
        $photo = UploadedFile::fake()->image('real.png');
        $content = file_get_contents($photo->getRealPath());
        $file = UploadedFile::fake()->createWithContent('renamed.pdf', $content.str_repeat(' ', $bytes - strlen($content)));
        if (! $accepted) {
            $this->expectException(ValidationException::class);
        }
        $info = app(BuyerReturnMoneyFiles::class)->inspect($file);
        $this->assertSame($bytes, $info['proof_bytes']);
        $this->assertSame('png', $info['extension']);
        $this->assertSame('image/png', $info['proof_mime']);
        $this->assertSame(hash_file('sha256', $file->getRealPath()), $info['proof_hash']);
    }

    public static function pdfVersions(): array
    {
        return [['1.4'], ['1.7'], ['2.0']];
    }

    #[DataProvider('pdfVersions')]
    public function test_pdf_receipts_use_actual_pdf_content_and_a_downloaded_canonical_extension(string $version): void
    {
        $body = "%PDF-{$version}\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";
        $file = UploadedFile::fake()->createWithContent('renamed.mp4', $body);
        $info = app(BuyerReturnMoneyFiles::class)->inspect($file);
        $this->assertSame('pdf', $info['extension']);
        $this->assertSame('application/pdf', $info['proof_mime']);
    }
}
