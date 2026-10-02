<?php
declare(strict_types=1);
namespace OpenExit\Tests\Streaming;
use OpenExit\OpenExit;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use FilesystemIterator;

final class StreamingTest extends TestCase
{
    public function testVersionAndEmptySchemaLoad(): void
    {
        self::assertSame('0.1.0',OpenExit::version()); self::assertSame('1.0',OpenExit::paspVersion());
        self::assertSame('https://json-schema.org/draft/2020-12/schema',OpenExit::loadSchema('manifest')->{'$schema'});
    }
    public function testLargeAssetAnd250StreamingDescriptors(): void
    {
        $source=dirname(__DIR__,4).'/protocol/pasp/v1/conformance/valid/empty-resource';
        $tmp=sys_get_temp_dir().'/openexit-php-'.bin2hex(random_bytes(6)); self::ensureDirectory($tmp);
        try {
            $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::SELF_FIRST);
            foreach ($it as $item) { $dest=$tmp.DIRECTORY_SEPARATOR.$it->getSubPathName(); if ($item->isDir()) self::ensureDirectory($dest); else { self::ensureDirectory(dirname($dest)); copy($item->getPathname(),$dest); } }
            self::ensureDirectory($tmp.'/assets/objects');
            $asset=fopen($tmp.'/assets/objects/large.bin','wb'); $ctx=hash_init('sha256'); $block=str_repeat("x",65536); $bytes=16*1024*1024;
            for ($i=0;$i<$bytes/strlen($block);$i++) { fwrite($asset,$block); hash_update($ctx,$block); } fclose($asset); $sha=hash_final($ctx);
            $index=fopen($tmp.'/assets/index.ndjson','wb');
            fwrite($index,json_encode(['id'=>'large','path'=>'assets/objects/large.bin','sha256'=>$sha,'byteLength'=>$bytes],JSON_THROW_ON_ERROR)."\n"); fclose($index);
            $manifest=json_decode((string)file_get_contents($tmp.'/manifest.json'),false,512,JSON_THROW_ON_ERROR); $manifest->assets=(object)['count'=>1,'totalBytes'=>$bytes,'index'=>'assets/index.ndjson'];
            file_put_contents($tmp.'/manifest.json',json_encode($manifest,JSON_THROW_ON_ERROR));
            $largeResult=OpenExit::verifyBundle($tmp); self::assertTrue($largeResult->valid,$largeResult->errors[0]->message??''); self::assertSame(1,$largeResult->assetCount);
            // Exercise the descriptor stream independently with 250 zero-byte entries.
            $z=fopen($tmp.'/assets/objects/large.bin','wb'); fclose($z);
            $zero=hash('sha256',''); $index=fopen($tmp.'/assets/index.ndjson','wb');
            for ($i=0;$i<250;$i++) fwrite($index,json_encode(['id'=>'z'.$i,'path'=>'assets/objects/large.bin','sha256'=>$zero,'byteLength'=>0],JSON_THROW_ON_ERROR)."\n"); fclose($index);
            $manifest=json_decode((string)file_get_contents($tmp.'/manifest.json'),false,512,JSON_THROW_ON_ERROR); $manifest->assets=(object)['count'=>250,'totalBytes'=>0,'index'=>'assets/index.ndjson'];
            file_put_contents($tmp.'/manifest.json',json_encode($manifest,JSON_THROW_ON_ERROR));
            $result=OpenExit::verifyBundle($tmp); self::assertTrue($result->valid,$result->errors[0]->message??''); self::assertSame(250,$result->assetCount);
        } finally { self::removeTree($tmp); }
    }
    private static function removeTree(string $root): void
    {
        if (!is_dir($root)) return;
        $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $item) $item->isDir()&&!$item->isLink()?rmdir($item->getPathname()):unlink($item->getPathname()); rmdir($root);
    }
    private static function ensureDirectory(string $directory): void
    {
        if (!is_dir($directory) && !mkdir($directory,0777,true) && !is_dir($directory)) throw new \RuntimeException('Cannot create test directory: '.$directory);
    }
}
