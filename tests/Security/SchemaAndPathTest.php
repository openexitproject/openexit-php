<?php
declare(strict_types=1);
namespace OpenExit\Tests\Security;
use OpenExit\Internal\Paths;
use OpenExit\OpenExit;
use OpenExit\PaspException;
use OpenExit\SchemaEngine;
use PHPUnit\Framework\TestCase;

final class SchemaAndPathTest extends TestCase
{
    public function testPackagedSchemasMatchCanonical(): void
    {
        $root=dirname(__DIR__,4); $names=['asset','checkpoint','event','inspection-result','manifest','relationship','resource','resource-chunk','scope'];
        self::assertCount(9,glob(dirname(__DIR__,2).'/resources/schemas/*.schema.json'));
        foreach ($names as $name) self::assertSame(file_get_contents($root.'/protocol/pasp/v1/schemas/'.$name.'.schema.json'),file_get_contents(OpenExitSchemaPath::get($name)),$name);
        self::assertIsObject(OpenExit::loadSchema('manifest'));
    }
    public function testUnsafePathsAlwaysFail(): void
    {
        foreach (['../secret','../../secret','/etc/passwd','C:\\Windows\\x','C:/Windows/x','\\\\server\\share','foo/..\\..\\bar','foo\\..\\..\\bar',"a\0b"] as $path) {
            try { Paths::validate($path); self::fail('accepted '.$path); } catch (PaspException $e) { self::assertSame('PASP_PATH_TRAVERSAL',$e->errorCode); }
        }
        Paths::validate('resources/users/resource.json');
    }
    public function testMalformedUtf8IsRejected(): void
    {
        try { OpenExit::parseManifest("{\"x\":\"\xFF\"}"); self::fail(); } catch (PaspException $e) { self::assertSame('PASP_INVALID_MANIFEST',$e->errorCode); }
    }
    public function testMalformedUtf8ResourceLineMapsToStableCode(): void
    {
        $source=dirname(__DIR__,4).'/protocol/pasp/v1/conformance/valid/minimal'; $tmp=sys_get_temp_dir().'/oe-utf8-'.bin2hex(random_bytes(5));
        self::ensureDirectory($tmp); $it=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($source,\FilesystemIterator::SKIP_DOTS),\RecursiveIteratorIterator::SELF_FIRST);
        foreach ($it as $item) { $dest=$tmp.DIRECTORY_SEPARATOR.$it->getSubPathName(); if ($item->isDir()) self::ensureDirectory($dest); else { self::ensureDirectory(dirname($dest)); copy($item->getPathname(),$dest); } }
        file_put_contents($tmp.'/resources/users/00000001.ndjson',"\xFF\n");
        try { $result=OpenExit::verifyBundle($tmp); self::assertSame('PASP_INVALID_RECORD',$result->errors[0]->code); }
        finally { $it=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($tmp,\FilesystemIterator::SKIP_DOTS),\RecursiveIteratorIterator::CHILD_FIRST); foreach ($it as $item) $item->isDir()&&!$item->isLink()?rmdir($item->getPathname()):unlink($item->getPathname()); rmdir($tmp); }
    }
    public function testDraft202012Keywords(): void
    {
        $cases=[
            ['{"$schema":"https://json-schema.org/draft/2020-12/schema","$defs":{"n":{"const":3}},"$ref":"#/$defs/n"}', '3', true],
            ['{"$schema":"https://json-schema.org/draft/2020-12/schema","prefixItems":[{"const":"x"}],"items":false}', '["x"]', true],
            ['{"$schema":"https://json-schema.org/draft/2020-12/schema","type":"object","dependentRequired":{"a":["b"]}}', '{"a":1}', false],
            ['{"$schema":"https://json-schema.org/draft/2020-12/schema","type":"object","properties":{"a":{"type":"string"}},"unevaluatedProperties":false}', '{"a":"x","b":1}', false],
        ];
        foreach ($cases as [$schemaJson,$valueJson,$valid]) {
            $schema=json_decode($schemaJson,false,512,JSON_THROW_ON_ERROR); $value=json_decode($valueJson,false,512,JSON_THROW_ON_ERROR);
            try { SchemaEngine::validateWithSchema($value,$schema,'PASP_INVALID_MANIFEST'); self::assertTrue($valid); }
            catch (PaspException) { self::assertFalse($valid); }
        }
    }
    public function testExternalReferencesAreRejectedBeforeResolution(): void
    {
        foreach (['http://127.0.0.1:1/schema.json','https://127.0.0.1/schema.json','file:///etc/passwd'] as $ref) {
            $schema=(object)['$ref'=>$ref];
            try { SchemaEngine::validateWithSchema((object)[], $schema,'PASP_INVALID_MANIFEST'); self::fail('accepted '.$ref); }
            catch (PaspException $e) { self::assertSame('PASP_INVALID_RESOURCE',$e->errorCode); }
        }
    }
    private static function ensureDirectory(string $directory): void
    {
        if (!is_dir($directory) && !mkdir($directory,0777,true) && !is_dir($directory)) throw new \RuntimeException('Cannot create test directory: '.$directory);
    }
}
final class OpenExitSchemaPath { public static function get(string $name): string { return dirname(__DIR__,2).'/resources/schemas/'.$name.'.schema.json'; } }
