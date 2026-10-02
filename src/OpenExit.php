<?php
declare(strict_types=1);
namespace OpenExit;

use OpenExit\Model\Manifest;

final class OpenExit
{
    public static function paspVersion(): string { return OpenExitInfo::PASP_VERSION; }
    public static function version(): string { return OpenExitInfo::SDK_VERSION; }
    public static function loadSchema(string $name): \stdClass { return SchemaEngine::load($name); }
    public static function parseManifest(string|\Stringable|\stdClass $input): Manifest
    {
        if ($input instanceof \stdClass) return new Manifest(clone $input);
        return new Manifest(SchemaEngine::decode((string)$input,'PASP_INVALID_MANIFEST'));
    }
    public static function validateManifest(Manifest|\stdClass|string $input): void
    {
        $m=$input instanceof Manifest?$input->document:($input instanceof \stdClass?$input:self::parseManifest($input)->document);
        foreach (is_array($m->resources ?? null)?$m->resources:[] as $resource) {
            if (!is_object($resource) || !isset($resource->name,$resource->descriptor) || !is_string($resource->name) || !is_string($resource->descriptor)) continue;
            if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/',$resource->name)) throw new PaspException('PASP_INVALID_RESOURCE','Invalid resource name');
            if (isset($seen[$resource->name])) throw new PaspException('PASP_DUPLICATE_RESOURCE','Duplicate resource name');
            $seen[$resource->name]=true;
            try { \OpenExit\Internal\Paths::validate($resource->descriptor); }
            catch (PaspException $e) { throw $e; }
        }
        if (isset($m->paspVersion) && $m->paspVersion!=='1.0') throw new PaspException('PASP_UNSUPPORTED_VERSION','Unsupported PASP version');
        SchemaEngine::validate($m,'manifest','PASP_INVALID_MANIFEST');
    }
    public static function inspectBundle(string $path): \OpenExit\Model\InspectionResult { return Bundle::inspect($path); }
    public static function verifyBundle(string $path): \OpenExit\Model\InspectionResult { return Bundle::verify($path); }
}
