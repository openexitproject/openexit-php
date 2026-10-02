<?php
declare(strict_types=1);
namespace OpenExit;

use OpenExit\Internal\Paths;
use OpenExit\Model\InspectionError;
use OpenExit\Model\InspectionResult;

/** @internal Bundle verification engine. Use the OpenExit facade. */
final class Bundle
{
    public static function inspect(string $root): InspectionResult { return self::run($root,false); }
    public static function verify(string $root): InspectionResult { return self::run($root,true); }
    private static function run(string $root,bool $deep): InspectionResult
    {
        try {
            $manifestPath=Paths::resolve($root,'manifest.json');
            $m=self::jsonFile($manifestPath,'PASP_INVALID_MANIFEST');
            OpenExit::validateManifest($m);
            $names=[]; $resourceCount=0;
            foreach ($m->resources as $entry) {
                if (isset($names[$entry->name])) throw new PaspException('PASP_DUPLICATE_RESOURCE','Duplicate resource');
                $names[$entry->name]=true; $resourceCount++;
                $descriptor=Paths::resolve($root,$entry->descriptor);
                $d=self::jsonFile($descriptor,'PASP_INVALID_RESOURCE');
                SchemaEngine::validate($d,'resource','PASP_INVALID_RESOURCE');
                if ($d->name!==$entry->name) throw new PaspException('PASP_INVALID_RESOURCE','Resource descriptor name mismatch');
                $schema=$d->schema;
                if (is_string($schema)) {
                    try { $schemaPath=Paths::resolve($root,$schema); }
                    catch (PaspException $e) { throw new PaspException('PASP_MISSING_SCHEMA','Resource schema is missing',$e); }
                    $schema=self::jsonFile($schemaPath,'PASP_MISSING_SCHEMA');
                }
                if (!$schema instanceof \stdClass) throw new PaspException('PASP_INVALID_RESOURCE','Record schema must be an object or path');
                $expected=1; $total=0;
                foreach ($d->chunks as $chunk) {
                    if ($chunk->sequence!==$expected || $chunk->path!==sprintf('resources/%s/%08d.ndjson',$d->name,$expected)) throw new PaspException('PASP_INVALID_RESOURCE','Chunk sequence or path invalid');
                    $p=Paths::resolve($root,$chunk->path);
                    if ($deep) {
                        $actual=self::ndjson($p,$schema,true,$d->identity);
                        if ($actual['bytes']!==$chunk->uncompressedBytes || $actual['sha256']!==$chunk->sha256) throw new PaspException('PASP_CHECKSUM_MISMATCH','Resource chunk integrity mismatch');
                        if ($actual['records']!==$chunk->recordCount) throw new PaspException('PASP_INVALID_RESOURCE','Chunk record count mismatch');
                        $total+=$actual['records'];
                    } else $total+=$chunk->recordCount;
                    $expected++;
                }
                if ($total!==$d->recordCount) throw new PaspException('PASP_INVALID_RESOURCE','Resource record count mismatch');
            }
            $assetCount=self::assets($root,$m,$deep);
            if (isset($m->relationships)) self::relationships($root,$m,$names);
            if ($deep && isset($m->integrity->checksums)) self::checksumIndex($root,$m->integrity->checksums);
            return new InspectionResult(true,$m->paspVersion,$resourceCount,$assetCount,[], $deep);
        } catch (PaspException $e) {
            return new InspectionResult(false,null,0,0,[new InspectionError($e->errorCode,$e->getMessage())],$deep);
        } catch (\Throwable $e) {
            return new InspectionResult(false,null,0,0,[new InspectionError('PASP_MALFORMED_PACKAGE','Cannot inspect bundle: '.$e->getMessage())],$deep);
        }
    }
    private static function jsonFile(string $path,string $code): \stdClass
    {
        $bytes=self::readSmall($path,$code);
        return SchemaEngine::decode($bytes,$code);
    }
    private static function readSmall(string $path,string $code): string
    {
        $h=self::open($path,$code);
        try { $data=stream_get_contents($h); if ($data===false) throw new PaspException($code,'Cannot read bundle file'); return $data; }
        finally { fclose($h); }
    }
    /** @return resource */
    private static function open(string $path,string $code)
    {
        set_error_handler(static fn()=>true);
        try { $h=fopen($path,'rb'); } finally { restore_error_handler(); }
        if ($h===false) throw new PaspException($code,'Cannot open bundle file');
        $stat=fstat($h);
        if ($stat===false || (($stat['mode'] & 0170000)!==0100000)) { fclose($h); throw new PaspException($code,'Bundle data path is not a regular file'); }
        return $h;
    }
    /** @return array{bytes:int,records:int,sha256:string} */
    private static function ndjson(string $path,\stdClass $schema,bool $deep,array $identity): array
    {
        $h=self::open($path,'PASP_INVALID_RESOURCE'); $ctx=hash_init('sha256'); $bytes=0; $records=0;
        $validator=$deep?SchemaEngine::prepareValidator($schema):null;
        try {
            while (($line=fgets($h))!==false) {
                $bytes+=strlen($line); hash_update($ctx,$line);
                $payload=rtrim($line,"\r\n");
                if (trim($payload)==='') continue;
                try { $record=SchemaEngine::decode($payload,'PASP_INVALID_RECORD'); }
                catch (PaspException $e) { throw new PaspException('PASP_INVALID_RECORD','Malformed NDJSON record',$e); }
                if ($deep) self::validateRecord($record,$schema,$identity,$validator);
                $records++;
            }
            if (!feof($h)) throw new PaspException('PASP_INVALID_RESOURCE','Failed reading resource stream');
            $sha=hash_final($ctx);
            return ['bytes'=>$bytes,'records'=>$records,'sha256'=>$sha];
        } finally { fclose($h); }
    }
    private static function validateRecord(\stdClass $record,\stdClass $schema,array $identity,?\Opis\JsonSchema\CompliantValidator $validator): void
    {
        foreach ($identity as $key) if (!property_exists($record,$key)) throw new PaspException('PASP_INVALID_RECORD','Resource record is missing identity');
        try { $result=$validator?->validate($record,$schema); }
        catch (\Throwable $e) { throw new PaspException('PASP_INVALID_RECORD','Record schema validation failed',$e); }
        if ($result===null || !$result->isValid()) throw new PaspException('PASP_INVALID_RECORD','Record schema validation failed');
    }
    private static function assets(string $root,\stdClass $manifest,bool $deep): int
    {
        $summary=$manifest->assets; $index=$summary->index ?? 'assets/index.ndjson';
        if (($summary->count??0)===0 && !isset($summary->index)) return 0;
        try { $path=Paths::resolve($root,$index); }
        catch (PaspException $e) { throw new PaspException('PASP_MISSING_ASSET','Asset index is missing',$e); }
        $h=self::open($path,'PASP_MISSING_ASSET'); $count=0; $total=0;
        try { while (($line=fgets($h))!==false) {
            if (trim($line)==='') continue;
            $item=SchemaEngine::decode(rtrim($line,"\r\n"),'PASP_INVALID_RESOURCE');
            SchemaEngine::validate($item,'asset','PASP_INVALID_RESOURCE');
            try { $asset=Paths::resolve($root,$item->path); }
            catch (PaspException $e) { throw new PaspException('PASP_MISSING_ASSET','Asset bytes are missing',$e); }
            if ($deep) { [$bytes,$sha]=self::hashFile($asset); if ($bytes!==$item->byteLength || $sha!==$item->sha256) throw new PaspException('PASP_CHECKSUM_MISMATCH','Asset integrity mismatch'); $total+=$bytes; }
            else $total+=$item->byteLength;
            $count++;
        }} finally { fclose($h); }
        if ($count!==$summary->count || $total!==$summary->totalBytes) throw new PaspException('PASP_INVALID_MANIFEST','Asset totals mismatch');
        return $count;
    }
    /** @return array{int,string} */
    private static function hashFile(string $path): array
    {
        $h=self::open($path,'PASP_MISSING_ASSET'); $ctx=hash_init('sha256'); $bytes=0;
        try { while (!feof($h)) { $buf=fread($h,8192); if ($buf===false) throw new PaspException('PASP_MALFORMED_PACKAGE','Asset read failed'); if ($buf==='') continue; $bytes+=strlen($buf); hash_update($ctx,$buf); } }
        finally { fclose($h); }
        return [$bytes,hash_final($ctx)];
    }
    private static function relationships(string $root,\stdClass $m,array $names): void
    {
        $rel=Paths::resolve($root,$m->relationships); $value=json_decode(self::readSmall($rel,'PASP_INVALID_RELATIONSHIP'),false,512,JSON_THROW_ON_ERROR);
        if (!is_array($value)) throw new PaspException('PASP_INVALID_RELATIONSHIP','Relationships must be an array');
        foreach ($value as $item) { SchemaEngine::validate($item,'relationship','PASP_INVALID_RELATIONSHIP'); if (!isset($names[$item->from->resource],$names[$item->to->resource])) throw new PaspException('PASP_INVALID_RELATIONSHIP','Relationship resource is missing'); }
    }
    private static function checksumIndex(string $root,string $relative): void
    {
        $path=Paths::resolve($root,$relative); $h=self::open($path,'PASP_MALFORMED_PACKAGE');
        try { while (($line=fgets($h))!==false) {
            if (trim($line)==='') continue;
            $parts=preg_split('/\s+/',trim($line),2);
            if (count($parts)!==2 || !preg_match('/^[a-f0-9]{64}$/',$parts[0])) throw new PaspException('PASP_MALFORMED_PACKAGE','Malformed checksum index entry');
            $file=Paths::resolve($root,$parts[1]); [$bytes,$sha]=self::hashFile($file); unset($bytes);
            if ($sha!==$parts[0]) throw new PaspException('PASP_CHECKSUM_MISMATCH','Package checksum index mismatch');
        }} finally { fclose($h); }
    }
}
