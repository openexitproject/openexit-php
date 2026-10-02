<?php
declare(strict_types=1);
namespace OpenExit\Tests\Security;
use OpenExit\Internal\Paths;
use OpenExit\PaspException;
use PHPUnit\Framework\TestCase;

final class ContainmentTest extends TestCase
{
    public function testOutsideFileSymlinkIsRejected(): void
    {
        $root=sys_get_temp_dir().'/oe-root-'.bin2hex(random_bytes(5)); $outside=sys_get_temp_dir().'/oe-out-'.bin2hex(random_bytes(5));
        mkdir($root); file_put_contents($outside,'x');
        try {
            if (!@symlink($outside,$root.'/link')) self::markTestSkipped('Windows or filesystem policy does not permit symlink creation');
            try { Paths::resolve($root,'link'); self::fail('outside file symlink accepted'); }
            catch (PaspException $e) { self::assertSame('PASP_PATH_TRAVERSAL',$e->errorCode); }
        } finally { if (is_link($root.'/link')) unlink($root.'/link'); if (is_dir($root)) rmdir($root); if (is_file($outside)) unlink($outside); }
    }
    public function testOutsideDirectorySymlinkIsRejected(): void
    {
        $root=sys_get_temp_dir().'/oe-root-'.bin2hex(random_bytes(5)); $outside=sys_get_temp_dir().'/oe-out-'.bin2hex(random_bytes(5));
        mkdir($root); mkdir($outside); file_put_contents($outside.'/file','x');
        try {
            if (!@symlink($outside,$root.'/link')) self::markTestSkipped('Windows or filesystem policy does not permit symlink creation');
            try { Paths::resolve($root,'link/file'); self::fail('outside directory symlink accepted'); }
            catch (PaspException $e) { self::assertSame('PASP_PATH_TRAVERSAL',$e->errorCode); }
        } finally { if (is_link($root.'/link')) unlink($root.'/link'); if (is_dir($root)) rmdir($root); if (is_file($outside.'/file')) unlink($outside.'/file'); if (is_dir($outside)) rmdir($outside); }
    }
    public function testWindowsJunctionEscapeIsRejected(): void
    {
        if (PHP_OS_FAMILY!=='Windows') self::markTestSkipped('Windows junction test');
        $base=sys_get_temp_dir().'/oe-junction-'.bin2hex(random_bytes(5)); $root=$base.'/root'; $outside=$base.'/outside';
        mkdir($root,0777,true); mkdir($outside); file_put_contents($outside.'/file','x'); $junction=$root.'/junction';
        exec('cmd /c mklink /J "'.$junction.'" "'.$outside.'"',$output,$status);
        try {
            if ($status!==0 || !is_dir($junction)) self::markTestSkipped('Junction creation is unavailable');
            try { Paths::resolve($root,'junction/file'); self::fail('outside junction accepted'); }
            catch (PaspException $e) { self::assertSame('PASP_PATH_TRAVERSAL',$e->errorCode); }
        } finally { if (is_dir($junction)) rmdir($junction); if (is_dir($root)) rmdir($root); if (is_file($outside.'/file')) unlink($outside.'/file'); if (is_dir($outside)) rmdir($outside); if (is_dir($base)) rmdir($base); }
    }
}
