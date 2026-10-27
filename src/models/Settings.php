<?php

namespace bwd\imagor\models;

use Closure;
use craft\base\Model;
use craft\helpers\App;

/**
 * Works from env vars alone; config/imagor.php (optional) overrides any of these.
 */
class Settings extends Model
{
    /** imagor's base URL. Empty: the plugin is off and Craft transforms as usual. */
    public string $url = '$IMAGOR_URL';

    /** IMAGOR_SECRET. Empty means unsigned (`unsafe`) URLs: local testing only. */
    public string $secret = '$IMAGOR_SECRET';

    /** IMAGOR_SIGNER_TYPE: sha1, sha256 or sha512 */
    public string $signer = 'sha1';

    /** IMAGOR_SIGNER_TRUNCATE */
    public ?int $truncate = null;

    /**
     * Where imagor finds the original:
     * - `url`: the asset's public URL, so imagor holds no storage keys
     * - `s3`: the object key for imagor's S3 loader, with `bucket/` in front when [[bucket]] is set
     * - a closure: fn(Asset $asset): string, the image part of the imagor path
     */
    public string|Closure $source = 'url';

    /** For `s3`: the bucket (first path segment, for S3_LOADER_BUCKET=*) */
    public ?string $bucket = null;

    /** For `s3`: anything in front of the volume subpath in the object key */
    public string $keyPrefix = '';

    /** Seconds to wait for a connection; a down server fails fast */
    public int $connectTimeout = 3;

    /** Seconds to wait for the finished file */
    public int $timeout = 60;

    /** After imagor is unreachable, Craft makes every file itself for this long */
    public int $downSeconds = 300;

    public function isEnabled(): bool
    {
        return (bool)App::parseEnv($this->url);
    }
}
