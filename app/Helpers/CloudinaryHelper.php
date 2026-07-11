<?php

namespace App\Helpers;

use Cloudinary\Cloudinary;
use Cloudinary\Api\Upload\UploadApi;
use Cloudinary\Api\Admin\AdminApi;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class CloudinaryHelper
{
    private static ?Cloudinary $instance = null;

    /**
     * Get Cloudinary instance (singleton).
     */
    private static function getCloudinary(): Cloudinary
    {
        if (self::$instance === null) {
            self::$instance = new Cloudinary([
                'cloud' => [
                    'cloud_name' => env('CLOUDINARY_CLOUD_NAME'),
                    'api_key'    => env('CLOUDINARY_API_KEY'),
                    'api_secret' => env('CLOUDINARY_API_SECRET'),
                ],
                'url' => [
                    'secure' => true,
                ],
            ]);
        }
        return self::$instance;
    }

    /**
     * Upload file ke Cloudinary.
     * @param \Illuminate\Http\UploadedFile $file
     * @param string $folder Nama folder di Cloudinary (misal 'bukti-pembayaran')
     * @return string Full HTTPS URL Cloudinary
     */
    public static function upload($file, string $folder): string
    {
        $cloudinary = self::getCloudinary();

        $result = $cloudinary->uploadApi()->upload($file->getRealPath(), [
            'folder' => $folder,
            'resource_type' => 'image',
        ]);

        return $result['secure_url'];
    }

    /**
     * Hapus file - deteksi otomatis Cloudinary vs Lokal.
     * @param string|null $path Path/URL file yang akan dihapus
     */
    public static function delete(?string $path): void
    {
        if (empty($path)) return;

        if (str_starts_with($path, 'http')) {
            // === CLOUDINARY ===
            $publicId = self::extractPublicId($path);

            if ($publicId) {
                try {
                    $cloudinary = self::getCloudinary();
                    $result = $cloudinary->uploadApi()->destroy($publicId, ['invalidate' => true]);
                    
                    Log::info('Cloudinary file deleted', [
                        'public_id' => $publicId,
                        'result' => $result['result'] ?? 'unknown',
                    ]);
                } catch (\Exception $e) {
                    Log::warning('Cloudinary delete failed', [
                        'public_id' => $publicId,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        } else {
            // === LOKAL ===
            if (Storage::disk('public')->exists($path)) {
                Storage::disk('public')->delete($path);
                Log::info('Local file deleted', ['path' => $path]);
            }
        }
    }

    /**
     * Ekstrak public_id dari URL Cloudinary.
     *
     * Contoh URL:
     *   https://res.cloudinary.com/ajkffjgh/image/upload/v1720700000/bad-products/abc123.jpg
     *
     * Langkah parsing:
     *   1. parse_url() → ambil path: /ajkffjgh/image/upload/v1720700000/bad-products/abc123.jpg
     *   2. explode('/') → [ajkffjgh, image, upload, v1720700000, bad-products, abc123.jpg]
     *   3. Cari posisi segment "upload"
     *   4. Ambil semua segment SETELAH "upload"
     *   5. Buang segment pertama JIKA cocok /^v\d+$/ (version number)
     *   6. implode('/') → "bad-products/abc123.jpg"
     *   7. Buang ekstensi file → "bad-products/abc123"
     *
     * Hasil: "bad-products/abc123" — public_id yang valid untuk destroy()
     */
    public static function extractPublicId(string $url): ?string
    {
        $parsed = parse_url($url);
        if (!isset($parsed['path'])) return null;

        $segments = explode('/', trim($parsed['path'], '/'));

        // Cari posisi segment "upload"
        $uploadIndex = array_search('upload', $segments);
        if ($uploadIndex === false) return null;

        // Ambil semua segment setelah "upload"
        $afterUpload = array_slice($segments, $uploadIndex + 1);

        if (empty($afterUpload)) return null;

        // Buang version number (v1234567890) jika ada
        if (preg_match('/^v\d+$/', $afterUpload[0])) {
            array_shift($afterUpload);
        }

        if (empty($afterUpload)) return null;

        // Gabung kembali dan buang ekstensi file
        $fullPath = implode('/', $afterUpload);
        $publicId = preg_replace('/\.[^.]+$/', '', $fullPath);

        return $publicId;
    }
}
