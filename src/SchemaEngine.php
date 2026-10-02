<?php
declare(strict_types=1);
namespace OpenExit;

use Opis\JsonSchema\CompliantValidator;

/** @internal Draft 2020-12 validation using only bundled schemas. */
final class SchemaEngine
{
    private const SCHEMAS=['asset','checkpoint','event','inspection-result','manifest','relationship','resource','resource-chunk','scope'];
    public static function schemaPath(string $name): string
    {
        $name=str_ends_with($name,'.schema.json')?substr($name,0,-12):$name;
        if (!in_array($name,self::SCHEMAS,true)) throw new \InvalidArgumentException('Unknown PASP schema');
        return dirname(__DIR__).'/resources/schemas/'.$name.'.schema.json';
    }
    public static function load(string $name): \stdClass { return self::decode((string)file_get_contents(self::schemaPath($name)),'PASP_INVALID_MANIFEST'); }
    public static function decode(string $json,string $code): \stdClass
    {
        if (preg_match('//u',$json)!==1) throw new PaspException($code,'Invalid UTF-8',new \JsonException('Malformed UTF-8'));
        try { $v=json_decode($json,false,512,JSON_THROW_ON_ERROR|JSON_BIGINT_AS_STRING); }
        catch (\JsonException $e) { throw new PaspException($code,'Invalid JSON',$e); }
        if (!$v instanceof \stdClass) throw new PaspException($code,'Expected JSON object');
        return $v;
    }
    public static function validate(\stdClass $data,string $schema,string $code): void
    {
        $schemaObj=self::load($schema);
        $schemaObj=self::expand($schemaObj);
        self::validateWithSchema($data,$schemaObj,$code);
    }
    public static function validateWithSchema(mixed $data,\stdClass $schemaObj,string $code): void
    {
        try { $result=self::prepareValidator($schemaObj)->validate($data,$schemaObj); }
        catch (PaspException $e) { throw $e; }
        catch (\Throwable $e) { throw new PaspException($code,'JSON Schema evaluation failed',$e); }
        if (!$result->isValid()) throw new PaspException($code,'JSON schema validation failed');
    }
    public static function prepareValidator(\stdClass $schemaObj): CompliantValidator
    {
        self::checkReferences($schemaObj);
        return new CompliantValidator();
    }
    private static function expand(\stdClass $schema): \stdClass
    {
        $walk=function(mixed $node) use (&$walk): mixed {
            if ($node instanceof \stdClass) {
                if (isset($node->{'$ref'}) && is_string($node->{'$ref'})) {
                    $ref=$node->{'$ref'};
                    if (preg_match('/^(?:https?:|file:|ftp:|\\\\\\\\)/i',$ref)) throw new PaspException('PASP_INVALID_RESOURCE','External schema references are disabled');
                    if (!str_starts_with($ref,'#') && (str_contains($ref,':') || basename(parse_url($ref,PHP_URL_PATH) ?: $ref)!==$ref)) throw new PaspException('PASP_INVALID_RESOURCE','External schema references are disabled');
                    if (!str_starts_with($ref,'#')) {
                        $target=self::load(basename(parse_url($ref,PHP_URL_PATH) ?: $ref));
                        $target->{'$id'}='urn:openexit:packaged:'.basename(parse_url($ref,PHP_URL_PATH) ?: $ref);
                        $replacement=$walk($target);
                        $siblings=clone $node; unset($siblings->{'$ref'});
                        if (get_object_vars($siblings)===[]) return $replacement;
                        // Canonical PASP references are isolated schema applications; retain JSON Schema 2020-12 sibling semantics.
                        $node->{'allOf'}=[$replacement]; unset($node->{'$ref'});
                    }
                }
                foreach (get_object_vars($node) as $key=>$value) $node->{$key}=$walk($value);
            } elseif (is_array($node)) foreach ($node as $i=>$value) $node[$i]=$walk($value);
            return $node;
        };
        return $walk($schema);
    }
    private static function checkReferences(mixed $node): void
    {
        if ($node instanceof \stdClass) foreach (get_object_vars($node) as $k=>$v) {
            if (($k==='$ref'||$k==='$dynamicRef'||$k==='$recursiveRef') && is_string($v)) {
                if (!str_starts_with($v,'#')) throw new PaspException('PASP_INVALID_RESOURCE','External schema references are disabled');
                if (preg_match('/^(?:https?:|file:|ftp:|\\\\\\\\)/i',$v)) throw new PaspException('PASP_INVALID_RESOURCE','External schema references are disabled');
            }
            self::checkReferences($v);
        } elseif (is_array($node)) foreach ($node as $v) self::checkReferences($v);
    }
}
