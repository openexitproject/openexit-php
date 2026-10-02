<?php
declare(strict_types=1);
require __DIR__.'/vendor/autoload.php';
use OpenExit\OpenExit;

$protocol=getenv('OPENEXIT_PROTOCOL_FIXTURES');
if (!$protocol) throw new RuntimeException('Set OPENEXIT_PROTOCOL_FIXTURES to protocol/pasp/v1/conformance');
if (OpenExit::version()!=='0.1.0' || OpenExit::paspVersion()!=='1.0') throw new RuntimeException('Version mismatch');
OpenExit::loadSchema('manifest');
$manifest=OpenExit::parseManifest((string)file_get_contents($protocol.'/valid/minimal/manifest.json'));
OpenExit::validateManifest($manifest);
$inspection=OpenExit::inspectBundle($protocol.'/valid/minimal');
if (!$inspection->valid) throw new RuntimeException('Consumer inspection failed');
foreach (['valid/minimal'=>null,'invalid/chunk-sequence-zero'=>'PASP_INVALID_RESOURCE','invalid/chunk-sequence-gap'=>'PASP_INVALID_RESOURCE','invalid/bad-checksum'=>'PASP_CHECKSUM_MISMATCH'] as $case=>$expected) {
    $result=OpenExit::verifyBundle($protocol.'/'.$case);
    if ($expected===null ? !$result->valid : ($result->valid || $result->errors[0]->code!==$expected)) throw new RuntimeException('Consumer verification failed for '.$case);
    printf("%s: %s\n",$case,$result->valid?'PASS':$result->errors[0]->code);
}
echo "SDK ".OpenExit::version()." / PASP ".OpenExit::paspVersion()."\n";
