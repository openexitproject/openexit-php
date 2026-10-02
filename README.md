# OpenExit PHP SDK

OpenExit implements PASP 1.0, the Portable Application State Protocol for describing and verifying application state bundles. This package targets PHP 8.2 or newer.

Install with Composer:

```sh
composer require openexit/openexit
```

```php
<?php
use OpenExit\OpenExit;
use OpenExit\PaspException;

echo OpenExit::version();     // 0.1.0
echo OpenExit::paspVersion(); // 1.0
$schema = OpenExit::loadSchema('manifest');
$manifest = OpenExit::parseManifest(file_get_contents('bundle/manifest.json'));
OpenExit::validateManifest($manifest);
$inspection = OpenExit::inspectBundle('bundle');
$verification = OpenExit::verifyBundle('bundle');
if (!$verification->valid) {
    foreach ($verification->errors as $error) {
        printf("%s: %s\n", $error->code, $error->message);
    }
}
```

`parseManifest` parses strict UTF-8 JSON and returns a `Manifest`; `validateManifest` performs schema validation. `inspectBundle` checks bundle structure and metadata. `verifyBundle` streams resource records and asset bytes to verify schema, SHA-256, byte totals, and record counts. Bundle operations return `InspectionResult`; a failure is represented by its `errors` list.

Parsing and validation errors throw `PaspException`, whose `errorCode` (also available via `code()`) is a stable PASP code, for example `PASP_INVALID_MANIFEST` or `PASP_UNSUPPORTED_VERSION`. Bundle errors use the same codes in `InspectionError`.

The nine canonical schemas are included in the Composer package. Validation is offline and does not load remote schema references. The package supports directory bundles; it does not provide a framework integration or a bundle writer.

## Development

Run `composer install`, `composer validate --strict`, and `composer test`. The conformance test reads canonical fixtures from the OpenExit monorepo's `protocol/pasp/v1/conformance` directory. Release archives exclude tests and development files.

## Release maintenance

The monorepo copy at `sdk/php/` is canonical. Publish from a split repository whose root contains this directory's contents. Use `git subtree split --prefix=sdk/php HEAD` to produce a release commit, sync that commit to the split repository's root branch, and tag `v0.1.0` only after release checks pass. See `IMPLEMENTATION_NOTES.md` for the repeatable commands. Do not publish the monorepo root as the Composer package.
