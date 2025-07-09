<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class IdenticonController extends Controller
{
    private const DEFAULT_SIZE = 250;
    private const MIN_SIZE = 50;
    private const MAX_SIZE = 500;
    private const GRID_SIZE = 5;
    private const CACHE_TTL = 3600; // 1 hour

    /**
     * Generate an identicon image based on a seed string
     *
     * @param Request $request
     * @param string $seed
     * @return Response
     */
    public function generate(Request $request, string $seed): Response
    {
        try {
            // Validate and sanitize inputs
            $this->validateInputs($request, $seed);

            $size = $this->getValidatedSize($request);
            $format = $this->getValidatedFormat($request);
            $background = $this->getValidatedBackground($request);

            // Generate cache key
            $cacheKey = $this->generateCacheKey($seed, $size, $format, $background);

            // Try to get from cache first
            $cachedImage = Cache::get($cacheKey);
            if ($cachedImage) {
                return $this->createImageResponse($cachedImage, $format, $cacheKey);
            }

            // Generate new image
            $imageData = $this->generateIdenticon($seed, $size, $background);

            // Cache the result
            Cache::put($cacheKey, $imageData, self::CACHE_TTL);

            return $this->createImageResponse($imageData, $format, $cacheKey);

        } catch (ValidationException $e) {
            return response()->json([
                'error' => 'Validation failed',
                'details' => $e->errors()
            ], 400);
        } catch (\Exception $e) {
            Log::error('Identicon generation failed', [
                'seed' => $seed,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'error' => 'Failed to generate identicon',
                'message' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Validate request inputs
     *
     * @param Request $request
     * @param string $seed
     * @throws ValidationException
     */
    private function validateInputs(Request $request, string $seed): void
    {
        if (empty(trim($seed))) {
            throw ValidationException::withMessages([
                'seed' => ['Seed cannot be empty']
            ]);
        }

        if (strlen($seed) > 255) {
            throw ValidationException::withMessages([
                'seed' => ['Seed cannot exceed 255 characters']
            ]);
        }

        $request->validate([
            'size' => 'integer|min:' . self::MIN_SIZE . '|max:' . self::MAX_SIZE,
            'format' => 'string|in:png,jpg,jpeg,gif',
            'background' => 'string|regex:/^#?[0-9a-fA-F]{6}$/'
        ]);
    }

    /**
     * Get validated size from request
     *
     * @param Request $request
     * @return int
     */
    private function getValidatedSize(Request $request): int
    {
        $size = $request->get('size', self::DEFAULT_SIZE);
        return max(self::MIN_SIZE, min(self::MAX_SIZE, (int)$size));
    }

    /**
     * Get validated format from request
     *
     * @param Request $request
     * @return string
     */
    private function getValidatedFormat(Request $request): string
    {
        return $request->get('format', 'png');
    }

    /**
     * Get validated background color from request
     *
     * @param Request $request
     * @return string
     */
    private function getValidatedBackground(Request $request): string
    {
        $bg = $request->get('background', 'ffffff');
        return ltrim($bg, '#');
    }

    /**
     * Generate cache key for the identicon
     *
     * @param string $seed
     * @param int $size
     * @param string $format
     * @param string $background
     * @return string
     */
    private function generateCacheKey(string $seed, int $size, string $format, string $background): string
    {
        return 'identicon:' . md5($seed . $size . $format . $background);
    }

    /**
     * Generate the identicon image
     *
     * @param string $seed
     * @param int $size
     * @param string $background
     * @return string
     * @throws \Exception
     */
    private function generateIdenticon(string $seed, int $size, string $background): string
    {
        // Generate hash from seed
        $hash = hash('sha256', $seed);

        // Calculate block size
        $blockSize = $size / self::GRID_SIZE;

        // Create image
        $image = imagecreatetruecolor($size, $size);
        if (!$image) {
            throw new \Exception('Failed to create image canvas');
        }

        // Set background color
        $bgColor = $this->hexToRgb($background);
        $bg = imagecolorallocate($image, $bgColor['r'], $bgColor['g'], $bgColor['b']);
        if ($bg === false) {
            imagedestroy($image);
            throw new \Exception('Failed to allocate background color');
        }

        imagefill($image, 0, 0, $bg);

        // Extract color from hash (use different part than pattern)
        $colorHex = substr($hash, 56, 6); // Use end of hash for color
        $colorRgb = $this->hexToRgb($colorHex);

        // Ensure color is different from background
        if ($this->colorsAreSimilar($colorRgb, $bgColor)) {
            $colorRgb = $this->adjustColor($colorRgb, $bgColor);
        }

        $color = imagecolorallocate($image, $colorRgb['r'], $colorRgb['g'], $colorRgb['b']);
        if ($color === false) {
            imagedestroy($image);
            throw new \Exception('Failed to allocate foreground color');
        }

        // Generate pattern (use first part of hash)
        $this->drawPattern($image, $hash, $color, $blockSize);

        // Generate image data
        $imageData = $this->renderImage($image);

        // Clean up
        imagedestroy($image);

        return $imageData;
    }

    /**
     * Draw the identicon pattern
     *
     * @param resource $image
     * @param string $hash
     * @param resource $color
     * @param float $blockSize
     */
    private function drawPattern($image, string $hash, $color, float $blockSize): void
    {
        // Use first 15 characters for pattern
        $patternData = substr($hash, 0, 15);
        $index = 0;

        for ($y = 0; $y < self::GRID_SIZE; $y++) {
            for ($x = 0; $x < 3; $x++) { // Only process left half + center
                $value = hexdec($patternData[$index++]) % 2;

                if ($value) {
                    // Draw left side
                    $this->drawBlock($image, $x, $y, $blockSize, $color);

                    // Mirror to right side (skip center column)
                    if ($x < 2) {
                        $mirrorX = 4 - $x;
                        $this->drawBlock($image, $mirrorX, $y, $blockSize, $color);
                    }
                }
            }
        }
    }

    /**
     * Draw a single block
     *
     * @param resource $image
     * @param int $x
     * @param int $y
     * @param float $blockSize
     * @param resource $color
     */
    private function drawBlock($image, int $x, int $y, float $blockSize, $color): void
    {
        $x1 = (int)($x * $blockSize);
        $y1 = (int)($y * $blockSize);
        $x2 = (int)(($x + 1) * $blockSize - 1);
        $y2 = (int)(($y + 1) * $blockSize - 1);

        imagefilledrectangle($image, $x1, $y1, $x2, $y2, $color);
    }

    /**
     * Convert hex color to RGB array
     *
     * @param string $hex
     * @return array
     */
    private function hexToRgb(string $hex): array
    {
        $hex = ltrim($hex, '#');

        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }

        return [
            'r' => hexdec(substr($hex, 0, 2)),
            'g' => hexdec(substr($hex, 2, 2)),
            'b' => hexdec(substr($hex, 4, 2))
        ];
    }

    /**
     * Check if two colors are too similar
     *
     * @param array $color1
     * @param array $color2
     * @return bool
     */
    private function colorsAreSimilar(array $color1, array $color2): bool
    {
        $threshold = 50;
        $diff = abs($color1['r'] - $color2['r']) +
                abs($color1['g'] - $color2['g']) +
                abs($color1['b'] - $color2['b']);

        return $diff < $threshold;
    }

    /**
     * Adjust color to be different from background
     *
     * @param array $color
     * @param array $background
     * @return array
     */
    private function adjustColor(array $color, array $background): array
    {
        $avgBg = ($background['r'] + $background['g'] + $background['b']) / 3;

        if ($avgBg > 127) {
            // Light background, make color darker
            return [
                'r' => max(0, $color['r'] - 100),
                'g' => max(0, $color['g'] - 100),
                'b' => max(0, $color['b'] - 100)
            ];
        } else {
            // Dark background, make color lighter
            return [
                'r' => min(255, $color['r'] + 100),
                'g' => min(255, $color['g'] + 100),
                'b' => min(255, $color['b'] + 100)
            ];
        }
    }

    /**
     * Render image to string
     *
     * @param resource $image
     * @return string
     * @throws \Exception
     */
    private function renderImage($image): string
    {
        ob_start();

        if (!imagepng($image)) {
            ob_end_clean();
            throw new \Exception('Failed to render image');
        }

        $data = ob_get_clean();

        if ($data === false) {
            throw new \Exception('Failed to capture image data');
        }

        return $data;
    }

    /**
     * Create HTTP response for image
     *
     * @param string $imageData
     * @param string $format
     * @param string $cacheKey
     * @return Response
     */
    private function createImageResponse(string $imageData, string $format, string $cacheKey): Response
    {
        $contentType = $this->getContentType($format);
        $etag = md5($imageData);

        return response($imageData)
            ->header('Content-Type', $contentType)
            ->header('Cache-Control', 'public, max-age=' . self::CACHE_TTL)
            ->header('ETag', $etag)
            ->header('Last-Modified', gmdate('D, d M Y H:i:s') . ' GMT')
            ->header('Expires', gmdate('D, d M Y H:i:s', time() + self::CACHE_TTL) . ' GMT');
    }

    /**
     * Get content type for format
     *
     * @param string $format
     * @return string
     */
    private function getContentType(string $format): string
    {
        $types = [
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'gif' => 'image/gif'
        ];

        return $types[$format] ?? 'image/png';
    }

    /**
     * Get info about an identicon without generating it
     *
     * @param string $seed
     * @return \Illuminate\Http\JsonResponse
     */
    public function info(string $seed): \Illuminate\Http\JsonResponse
    {
        try {
            if (empty(trim($seed))) {
                return response()->json(['error' => 'Seed cannot be empty'], 400);
            }

            $hash = hash('sha256', $seed);
            $colorHex = substr($hash, 56, 6);
            $colorRgb = $this->hexToRgb($colorHex);

            return response()->json([
                'seed' => $seed,
                'hash' => $hash,
                'color' => [
                    'hex' => '#' . $colorHex,
                    'rgb' => $colorRgb
                ],
                'cache_key' => $this->generateCacheKey($seed, self::DEFAULT_SIZE, 'png', 'ffffff')
            ]);

        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to get identicon info'], 500);
        }
    }
}
