<?php

namespace bwd\imagor;

use Craft;
use craft\elements\Asset;
use craft\helpers\FileHelper;
use craft\helpers\Image;
use craft\imagetransforms\ImageTransformer;
use craft\models\ImageTransformIndex;
use Throwable;

/**
 * Craft's transformer with one step swapped: imagor makes the file instead of Craft downloading the
 * original and resizing it with Imagick. Anything imagor can't do falls back to Craft.
 */
class Transformer extends ImageTransformer
{
    private const DOWN_KEY = 'imagor:down';

    protected function generateTransformedImage(Asset $asset, ImageTransformIndex $index): void
    {
        $settings = Imagor::getInstance()->getSettings();
        $cache = Craft::$app->getCache();

        if (!Image::canManipulateAsImage($asset->getExtension()) || $cache->get(self::DOWN_KEY)) {
            parent::generateTransformedImage($asset, $index);
            return;
        }

        $fs = $asset->getVolume()->getTransformFs();
        $path = $this->getTransformBasePath($asset) . $this->getTransformSubpath($asset, $index);

        // Same as Craft: a file that's already there (index cleared, say) and not stale is reused
        if ($fs->fileExists($path)) {
            $changed = $index->getTransform()->parameterChangeTime;
            if (!$changed || $changed->getTimestamp() <= $fs->getDateModified($path)) {
                return;
            }
        }

        try {
            $tempPath = (new Client($settings))->generate($asset, $index);
        } catch (Throwable $e) {
            if ($e instanceof UnavailableException) {
                $cache->set(self::DOWN_KEY, true, $settings->downSeconds);
            }

            Craft::warning("imagor failed for asset {$asset->id} ({$index->transformString}), using Craft: {$e->getMessage()}", __METHOD__);
            parent::generateTransformedImage($asset, $index);
            return;
        }

        $stream = fopen($tempPath, 'rb');

        try {
            $fs->writeFileFromStream($path, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
            FileHelper::unlink($tempPath);
        }
    }
}
