<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class UploadController extends Controller
{
    /**
     * POST /api/admin/uploads/banner-image
     * Body: multipart/form-data with `file` field.
     *
     * Saves the file under storage/app/public/banners/, returns the
     * relative path so the client can store it in the banner row, plus
     * an absolute URL for immediate preview.
     */
    public function bannerImage(Request $request): JsonResponse
    {
        $data = $request->validate(
            [
                'file' => [
                    'required',
                    'file',
                    'image',
                    'mimes:jpg,jpeg,png,webp,avif',
                    'max:10240', // 10 MB (PHP upload_max_filesize must be ≥ 12 MB)
                ],
            ],
            [
                'file.required' =>
                    "Aucun fichier reçu. Vérifiez la taille (max 10 Mo) et réessayez.",
                'file.file' => "Fichier invalide.",
                'file.image' => "Le fichier doit être une image.",
                'file.mimes' =>
                    "Formats acceptés : JPG, PNG, WebP, AVIF.",
                'file.max' =>
                    "Image trop volumineuse (max 10 Mo).",
            ]
        );

        $file = $data['file'];

        // Hero displays at most ~1920px wide on retina laptops. Anything
        // bigger is wasted bandwidth + decode cost. Resize to fit a 1920x800
        // box, keep aspect ratio, re-encode as JPEG at q=82. A 4MB phone
        // photo lands around 200-400 KB after this.
        $name = Str::random(24).'.jpg';
        $absoluteDir = storage_path('app/public/banners');
        if (! is_dir($absoluteDir)) {
            mkdir($absoluteDir, 0755, true);
        }
        $destination = $absoluteDir.DIRECTORY_SEPARATOR.$name;
        $this->resizeAndStore($file->getRealPath(), $destination, 1920, 800);

        $relative = '/storage/banners/'.$name;
        $absolute = rtrim((string) config('app.url'), '/').$relative;

        return response()->json([
            'path' => $relative,
            'url' => $absolute,
        ]);
    }

    /**
     * POST /api/admin/uploads/product-image
     * Body: multipart/form-data with `file` field.
     *
     * Stores product photos under storage/app/public/products/. Resized to
     * 1600×1600 max (square-ish gallery target), JPEG q=82.
     */
    public function productImage(Request $request): JsonResponse
    {
        $data = $request->validate(
            [
                'file' => [
                    'required', 'file', 'image',
                    'mimes:jpg,jpeg,png,webp,avif',
                    'max:10240',
                ],
            ],
            [
                'file.required' => "Aucun fichier reçu. Vérifiez la taille (max 10 Mo) et réessayez.",
                'file.image' => "Le fichier doit être une image.",
                'file.mimes' => "Formats acceptés : JPG, PNG, WebP, AVIF.",
                'file.max' => "Image trop volumineuse (max 10 Mo).",
            ]
        );

        $file = $data['file'];
        $name = Str::random(24).'.jpg';
        $absoluteDir = storage_path('app/public/products');
        if (! is_dir($absoluteDir)) {
            mkdir($absoluteDir, 0755, true);
        }
        $destination = $absoluteDir.DIRECTORY_SEPARATOR.$name;
        $this->resizeAndStore($file->getRealPath(), $destination, 1600, 1600);

        $relative = '/storage/products/'.$name;
        $absolute = rtrim((string) config('app.url'), '/').$relative;

        return response()->json(['path' => $relative, 'url' => $absolute]);
    }

    /**
     * POST /api/admin/uploads/category-image
     * Body: multipart/form-data with `file` field.
     *
     * Stores category-card backgrounds under categories/. Resized to fit
     * 1200×800 (background card aspect), JPEG q=82. Falls through the same
     * pipeline as banners — admin-only, EXIF-aware, no upscaling.
     */
    public function categoryImage(Request $request): JsonResponse
    {
        $data = $request->validate(
            [
                'file' => [
                    'required', 'file', 'image',
                    'mimes:jpg,jpeg,png,webp,avif',
                    'max:10240',
                ],
            ],
            [
                'file.required' => "Aucun fichier reçu. Vérifiez la taille (max 10 Mo) et réessayez.",
                'file.image' => "Le fichier doit être une image.",
                'file.mimes' => "Formats acceptés : JPG, PNG, WebP, AVIF.",
                'file.max' => "Image trop volumineuse (max 10 Mo).",
            ]
        );

        $file = $data['file'];
        $name = Str::random(24).'.jpg';
        $absoluteDir = storage_path('app/public/categories');
        if (! is_dir($absoluteDir)) {
            mkdir($absoluteDir, 0755, true);
        }
        $destination = $absoluteDir.DIRECTORY_SEPARATOR.$name;
        $this->resizeAndStore($file->getRealPath(), $destination, 1200, 800);

        $relative = '/storage/categories/'.$name;
        $absolute = rtrim((string) config('app.url'), '/').$relative;

        return response()->json(['path' => $relative, 'url' => $absolute]);
    }

    /**
     * POST /api/admin/uploads/logo
     * Body: multipart/form-data with `file` field.
     *
     * Stores the file under banners/ at a smaller size (max 600x200) and
     * preserves PNG transparency when the source is a PNG.
     */
    public function logoImage(Request $request): JsonResponse
    {
        $data = $request->validate(
            [
                'file' => [
                    'required',
                    'file',
                    'image',
                    'mimes:jpg,jpeg,png,webp,avif,svg',
                    'max:4096', // 4 MB — logos are small
                ],
            ],
            [
                'file.required' =>
                    "Aucun fichier reçu. Vérifiez la taille (max 4 Mo) et réessayez.",
                'file.image' => "Le fichier doit être une image.",
                'file.mimes' => "Formats acceptés : JPG, PNG, WebP, AVIF, SVG.",
                'file.max' => "Image trop volumineuse (max 4 Mo).",
            ]
        );

        $file = $data['file'];
        $clientExt = strtolower($file->getClientOriginalExtension());

        $absoluteDir = storage_path('app/public/logos');
        if (! is_dir($absoluteDir)) {
            mkdir($absoluteDir, 0755, true);
        }

        // SVG: store as-is — no rasterizing.
        if ($clientExt === 'svg') {
            $name = Str::random(24).'.svg';
            $file->storeAs('logos', $name, 'public');
        } else {
            // For raster sources, preserve PNG transparency by saving as PNG
            // if the source is PNG; everything else collapses to JPEG.
            $isPng = $clientExt === 'png';
            $name = Str::random(24).($isPng ? '.png' : '.jpg');
            $destination = $absoluteDir.DIRECTORY_SEPARATOR.$name;
            $this->resizeAndStore($file->getRealPath(), $destination, 600, 200, $isPng);
        }

        $relative = '/storage/logos/'.$name;
        $absolute = rtrim((string) config('app.url'), '/').$relative;

        return response()->json([
            'path' => $relative,
            'url' => $absolute,
        ]);
    }

    /**
     * Read the source, downscale to fit ($maxW × $maxH) while preserving
     * aspect ratio (no upscaling), then save as JPEG quality 82 (or PNG
     * with transparency when $preservePng is true).
     * EXIF orientation is honoured so portrait phone shots come out right.
     */
    private function resizeAndStore(string $source, string $destination, int $maxW, int $maxH, bool $preservePng = false): void
    {
        $info = @getimagesize($source);
        if ($info === false) {
            throw new \RuntimeException('Invalid image.');
        }

        [$w, $h, $type] = $info;
        switch ($type) {
            case IMAGETYPE_JPEG: $src = imagecreatefromjpeg($source); break;
            case IMAGETYPE_PNG:  $src = imagecreatefrompng($source); break;
            case IMAGETYPE_WEBP: $src = imagecreatefromwebp($source); break;
            case IMAGETYPE_AVIF: $src = function_exists('imagecreatefromavif')
                ? imagecreatefromavif($source)
                : throw new \RuntimeException('AVIF not supported.'); break;
            default: throw new \RuntimeException('Unsupported image type.');
        }
        if (! $src) {
            throw new \RuntimeException('Failed to decode image.');
        }

        // Auto-rotate from EXIF for JPEGs (phone photos).
        if ($type === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
            $exif = @exif_read_data($source);
            $orientation = $exif['Orientation'] ?? 1;
            if (in_array($orientation, [3, 6, 8], true)) {
                $angle = match ($orientation) {
                    3 => 180,
                    6 => -90,
                    8 => 90,
                    default => 0,
                };
                $rotated = imagerotate($src, $angle, 0);
                if ($rotated) {
                    imagedestroy($src);
                    $src = $rotated;
                    if ($angle === 90 || $angle === -90) {
                        [$w, $h] = [$h, $w];
                    }
                }
            }
        }

        // Scale-to-fit; never upscale.
        $ratio = min($maxW / $w, $maxH / $h, 1.0);
        $newW = (int) round($w * $ratio);
        $newH = (int) round($h * $ratio);

        $dst = imagecreatetruecolor($newW, $newH);
        if ($preservePng) {
            // Preserve transparency for PNG output (used by logos).
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
            imagefilledrectangle($dst, 0, 0, $newW, $newH, $transparent);
        } else {
            // White background so PNG transparency doesn't go black when we
            // re-encode to JPEG.
            $white = imagecolorallocate($dst, 255, 255, 255);
            imagefilledrectangle($dst, 0, 0, $newW, $newH, $white);
        }
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $newW, $newH, $w, $h);
        imagedestroy($src);

        if ($preservePng) {
            imagepng($dst, $destination, 8); // compression level 8/9
        } else {
            imagejpeg($dst, $destination, 82);
        }
        imagedestroy($dst);
    }
}
