<?php

namespace bwd\imagor;

use bwd\imagor\models\Settings;
use Craft;
use craft\base\Plugin;
use craft\imagetransforms\ImageTransformer as CraftImageTransformer;

/**
 * Swaps Craft's ImageTransformer for one that asks imagor to make each file. Craft's transform
 * index, paths, storage and URLs are untouched, and Craft makes the file itself whenever imagor
 * can't. Without IMAGOR_URL the plugin does nothing.
 *
 * @method static Imagor getInstance()
 * @method Settings getSettings()
 */
class Imagor extends Plugin
{
    public string $schemaVersion = '1.0.0';

    public function init(): void
    {
        parent::init();

        // Every ImageTransformer Craft creates comes from the DI container, so this one line puts
        // ours in place for page renders, queue jobs and console alike
        if ($this->getSettings()->isEnabled()) {
            Craft::$container->set(CraftImageTransformer::class, Transformer::class);
        }
    }

    protected function createSettingsModel(): Settings
    {
        return new Settings();
    }
}
