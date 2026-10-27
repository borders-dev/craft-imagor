# Release Notes for imagor

## 1.1.0 - 2026-10-27

### Changed
- The plugin is now opt-in: it only hands transforms to imagor when `IMAGOR_ENABLED=true` and `IMAGOR_URL` is set. Existing installs need `IMAGOR_ENABLED=true` added to keep using imagor.

## 1.0.0 - 2026-10-27

### Added
- Initial release. Hands Craft's image transforms to imagor (libvips), so the site's server never downloads or resizes originals.
- Falls back to Craft's own transformer when imagor fails on an image, and for 5 minutes after imagor is unreachable.
- Versioned source URLs, so a file replaced under the same name is never resized from imagor's cached copy.
