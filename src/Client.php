<?php

namespace bwd\imagor;

use bwd\imagor\models\Settings;
use Closure;
use Craft;
use craft\elements\Asset;
use craft\helpers\App;
use craft\helpers\FileHelper;
use craft\models\ImageTransform;
use craft\models\ImageTransformIndex;
use RuntimeException;

/**
 * Builds signed imagor URLs and fetches the finished file. imagor loads the original itself (its
 * HTTP or S3 loader), so the site's server never downloads or decodes it.
 *
 * Sizes and crop boxes follow Craft's own rules (output size from Asset::getWidth/getHeight, focal
 * point over position, upscaling), so the file matches what Craft would have made and the
 * width/height attributes in templates stay right. https://docs.imagor.net
 */
class Client
{
    public function __construct(private Settings $settings)
    {
    }

    /**
     * Makes the transformed file and returns a local temp path to it.
     *
     * @throws UnavailableException when imagor can't be reached or reports itself unavailable
     * @throws RuntimeException when it fails on this image
     */
    public function generate(Asset $asset, ImageTransformIndex $index): string
    {
        $format = $index->detectedFormat ?: $index->format ?: $asset->getExtension();
        $url = rtrim(App::parseEnv($this->settings->url), '/') . '/' .
            $this->sign($this->path($asset, $index->getTransform(), $format));

        $tempPath = Craft::$app->getPath()->getTempPath() . DIRECTORY_SEPARATOR .
            FileHelper::uniqueName($asset->getFilename(false) . '.' . $format);

        try {
            $response = Craft::createGuzzleClient([
                'timeout' => $this->settings->timeout,
                'connect_timeout' => $this->settings->connectTimeout,
            ])->get($url, ['sink' => $tempPath, 'http_errors' => false]);
        } catch (\GuzzleHttp\Exception\ConnectException $e) {
            FileHelper::unlink($tempPath);
            throw new UnavailableException("imagor unreachable: {$e->getMessage()}", previous: $e);
        }

        $status = $response->getStatusCode();

        if ($status !== 200 || !filesize($tempPath)) {
            FileHelper::unlink($tempPath);
            $message = "imagor returned $status for $url";
            throw in_array($status, [502, 503, 504], true)
                ? new UnavailableException($message)
                : new RuntimeException($message);
        }

        return $tempPath;
    }

    /**
     * The imagor path (everything after the signature).
     */
    public function path(Asset $asset, ImageTransform $transform, string $format): string
    {
        $width = (int)$asset->getWidth($transform);
        $height = (int)$asset->getHeight($transform);
        $filters = [
            'format(' . ($format === 'jpg' ? 'jpeg' : $format) . ')',
            'quality(' . ($transform->quality ?: Craft::$app->getConfig()->getGeneral()->defaultImageQuality) . ')',
        ];
        $segments = [];

        switch ($transform->mode) {
            case 'stretch':
                $segments[] = 'stretch';
                $segments[] = "{$width}x{$height}";
                break;
            case 'fit':
                $segments[] = 'fit-in';
                $segments[] = "{$width}x{$height}";
                $filters[] = 'upscale()';
                break;
            case 'letterbox':
                $segments[] = 'fit-in';
                $segments[] = "{$width}x{$height}";
                $filters[] = 'fill(' . ($transform->fill ? ltrim($transform->fill, '#') : 'none') . ')';
                $filters[] = 'upscale()';
                break;
            default: // crop
                if ($box = $this->cropBox($asset, $transform, $width, $height)) {
                    $segments[] = $box;
                }
                $segments[] = "{$width}x{$height}";
        }

        $segments[] = 'filters:' . implode(':', $filters);
        $segments[] = $this->source($asset);

        return implode('/', $segments);
    }

    /**
     * The region of the original to keep, in source pixels, matching Craft's scaleAndCrop():
     * the largest box with the output's aspect ratio, centred on the focal point (or placed by
     * position) and pushed back inside the image.
     */
    public function cropBox(Asset $asset, ImageTransform $transform, int $width, int $height): ?string
    {
        $srcW = (int)$asset->getWidth();
        $srcH = (int)$asset->getHeight();

        if (!$transform->width || !$transform->height || !$srcW || !$srcH || !$width || !$height) {
            return null;
        }

        $aspect = $width / $height;
        [$cropW, $cropH] = $srcW / $srcH > $aspect
            ? [$srcH * $aspect, $srcH]
            : [$srcW, $srcW / $aspect];

        if ($asset->getHasFocalPoint()) {
            $focal = $asset->getFocalPoint();
            $x = $focal['x'] * $srcW - $cropW / 2;
            $y = $focal['y'] * $srcH - $cropH / 2;
        } else {
            $position = preg_match('/^(top|center|bottom)-(left|center|right)$/', $transform->position)
                ? $transform->position
                : 'center-center';
            [$v, $h] = explode('-', $position);
            $x = ($srcW - $cropW) * ['left' => 0, 'center' => 0.5, 'right' => 1][$h];
            $y = ($srcH - $cropH) * ['top' => 0, 'center' => 0.5, 'bottom' => 1][$v];
        }

        $x = (int)round(max(0, min($x, $srcW - $cropW)));
        $y = (int)round(max(0, min($y, $srcH - $cropH)));

        return sprintf('%dx%d:%dx%d', $x, $y, $x + (int)round($cropW), $y + (int)round($cropH));
    }

    public function source(Asset $asset): string
    {
        if ($this->settings->source instanceof Closure) {
            return ($this->settings->source)($asset);
        }

        if ($this->settings->source === 's3') {
            $key = implode('/', array_filter([
                trim($this->settings->keyPrefix, '/'),
                trim($asset->getVolume()->getSubpath(), '/'),
                $asset->getPath(),
            ]));
            $bucket = $this->settings->bucket ? App::parseEnv($this->settings->bucket) : null;

            return implode('/', array_map('rawurlencode', explode('/', ($bucket ? "$bucket/" : '') . $key)));
        }

        // The original's public URL, versioned by the asset's modified date so a file replaced
        // under the same name is never resized from imagor's cached copy of the old one. Sent as
        // unpadded base64url so spaces and odd characters can't upset the path or its signature.
        $url = $asset->getUrl(null, false) ?? throw new RuntimeException("Asset {$asset->id} has no public URL");
        $url = strtok($url, '?') . '?v=' . ($asset->dateModified?->getTimestamp() ?? 0);

        return 'b64:' . rtrim(strtr(base64_encode($url), '+/', '-_'), '=');
    }

    /**
     * imagor's signature: base64url(HMAC(secret, path)), padding kept, optionally truncated.
     */
    public function sign(string $path): string
    {
        $secret = App::parseEnv($this->settings->secret);

        if (!$secret) {
            return "unsafe/$path";
        }

        $hash = strtr(base64_encode(hash_hmac($this->settings->signer, $path, $secret, true)), '+/', '-_');

        if ($this->settings->truncate) {
            $hash = substr($hash, 0, $this->settings->truncate);
        }

        return "$hash/$path";
    }
}
