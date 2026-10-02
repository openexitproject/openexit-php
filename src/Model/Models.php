<?php
declare(strict_types=1);
namespace OpenExit\Model;

enum ConsistencyLevel: string { case SNAPSHOT='snapshot'; case BOUNDED='bounded'; case BEST_EFFORT='best_effort'; }
final readonly class Scope { public function __construct(public string $type, public string $id, public ?\stdClass $metadata=null) {} }
final readonly class ProducerInfo { public function __construct(public string $name, public string $version) {} }
final readonly class IntegrityInfo { public function __construct(public string $algorithm, public ?string $checksums=null) {} }
final readonly class Consistency { public function __construct(public ConsistencyLevel $level, public ?\stdClass $metadata=null) {} }
final readonly class ResourceChunk { public function __construct(public int $sequence, public string $path, public int $recordCount, public int $uncompressedBytes, public string $sha256) {} }
final readonly class Resource { /** @param list<string> $identity @param list<ResourceChunk> $chunks */ public function __construct(public string $name, public string|\stdClass $schema, public array $identity, public int $recordCount, public array $chunks, public string $descriptor) {} }
final readonly class AssetSummary { public function __construct(public int $count, public int $totalBytes, public ?string $index=null) {} }
final readonly class RelationshipEndpoint { public function __construct(public string $resource, public string $pointer) {} }
final readonly class Relationship { public function __construct(public string $id, public RelationshipEndpoint $from, public RelationshipEndpoint $to, public string $cardinality, public ?\stdClass $metadata=null) {} }
final readonly class ResourceReference { public function __construct(public string $name, public string $descriptor) {} }
final readonly class Manifest { public function __construct(public \stdClass $document) {} public function paspVersion(): ?string { return $this->document->paspVersion ?? null; } }
final readonly class InspectionError { public function __construct(public string $code, public string $message, public ?string $path=null) {} }
final readonly class InspectionResult { /** @param list<InspectionError> $errors */ public function __construct(public bool $valid, public ?string $paspVersion, public int $resourceCount, public int $assetCount, public array $errors=[], public bool $verified=false) {} }
