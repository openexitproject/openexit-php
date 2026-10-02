<?php
declare(strict_types=1);
namespace OpenExit\Tests\Conformance;
use OpenExit\OpenExit;
use PHPUnit\Framework\TestCase;

final class ConformanceTest extends TestCase
{
    public function testCanonicalSuite(): void
    {
        $protocol=dirname(__DIR__,4).'/protocol/pasp/v1/conformance';
        $suite=json_decode((string)file_get_contents($protocol.'/suite.json'),false,512,JSON_THROW_ON_ERROR);
        foreach ($suite->cases as $case) {
            $path=$protocol.'/'.$case->path;
            $result=OpenExit::verifyBundle($path);
            self::assertSame($case->expected->valid,$result->valid,$case->id);
            if (!$case->expected->valid) self::assertSame($case->expected->errorCode,$result->errors[0]->code,$case->id);
        }
    }
}
