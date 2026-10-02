<?php
declare(strict_types=1);
namespace OpenExit\Internal;
use OpenExit\PaspException;

/** @internal Cross-platform bundle containment helper; not part of the supported API. */
final class Paths
{
    public static function validate(string $path): void
    {
        if ($path==='' || str_contains($path,"\0") || str_contains($path,'\\') || str_contains($path,':') || str_starts_with($path,'/') || str_starts_with($path,'~') || preg_match('/^[A-Za-z]:/', $path) || preg_match('/^[\\\\]{2}/',$path)) throw new PaspException('PASP_PATH_TRAVERSAL','Unsafe bundle path');
        foreach (explode('/', $path) as $part) if ($part==='' || $part==='.' || $part==='..' || preg_match('/[. ]$/',$part) || preg_match('/^(CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])(?:\.|$)/i',$part)) throw new PaspException('PASP_PATH_TRAVERSAL','Unsafe bundle path');
    }
    public static function resolve(string $root, string $relative, bool $mustExist=true): string
    {
        self::validate($relative);
        $base=realpath($root);
        if ($base===false || !is_dir($base)) throw new PaspException('PASP_MALFORMED_PACKAGE','Bundle directory is missing');
        $candidate=$base.DIRECTORY_SEPARATOR.str_replace('/',DIRECTORY_SEPARATOR,$relative);
        $resolved=realpath($candidate);
        if ($resolved===false) { if ($mustExist) throw new PaspException('PASP_MALFORMED_PACKAGE','Bundle file is missing: '.$relative); return $candidate; }
        $prefix=rtrim($base,DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
        if ($resolved!==$base && !str_starts_with(strtolower($resolved),strtolower($prefix))) throw new PaspException('PASP_PATH_TRAVERSAL','Bundle path escapes through a link');
        $st=lstat($resolved);
        if ($st===false || (($st['mode'] & 0170000)!==0100000 && (($st['mode'] & 0170000)!==0040000))) throw new PaspException('PASP_MALFORMED_PACKAGE','Expected regular file or directory');
        return $resolved;
    }
}
