# imagor for Craft

Hands Craft's image transforms to [imagor](https://docs.imagor.net) (libvips). imagor fetches the original itself, so the site's server never downloads, decodes or resizes it. In testing, a 24MP original cost Imagick about 370MB and 4.8s across five sizes; imagor took 77MB and 1.9s, on its own server.

Craft's transform index, file paths, storage and URLs don't change, and neither do templates. Pair it with Blitz's page generation so transforms are made in the background.

## Install

```bash
composer require borders-dev/craft-imagor
php craft plugin/install imagor
```

```bash
IMAGOR_URL=https://imagor.example.com
IMAGOR_SECRET=…   # same as the imagor server
```

Without `IMAGOR_URL`, the plugin does nothing and Craft transforms as usual.

## Fallback

Craft makes the file itself when imagor fails on an image. If imagor is unreachable (connection fails within 3s, or 502/503/504), Craft handles everything for 5 minutes before trying imagor again.

## Options: `config/imagor.php` (optional)

```php
return [
    'source' => 'url',        // imagor fetches the original's public URL (no keys on imagor)
    // 'source' => 's3', 'bucket' => '$AWS_BUCKET',  // or its S3 loader
    'signer' => 'sha1',       // match IMAGOR_SIGNER_TYPE
    'connectTimeout' => 3,
    'timeout' => 60,
    'downSeconds' => 300,
];
```

Originals are requested as `…/file.jpg?v=<asset modified time>`, so a file replaced under the same name is never resized from imagor's cached copy.

## imagor server

`imagor/docker-compose.yml` runs one imagor for every site (e.g. a Ploi Docker app proxied to `127.0.0.1:8000`). It keeps fetched originals for an hour and serves only signed URLs.
