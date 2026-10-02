<?php
declare(strict_types=1);
namespace OpenExit;

final class PaspException extends \RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
    public function code(): string { return $this->errorCode; }
}
