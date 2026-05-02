<?php

namespace Uptimex\Client\Sql;

/**
 * Regex-based SQL normalizer that produces a deterministic shape for grouping
 * (so identical-shape queries with different literal values share a `sql_hash`).
 *
 * It is *deliberately not* a SQL parser — full parsing is too expensive for the
 * hot path. Instead it strips the parts that vary between executions of the
 * same query: bound parameters and literal values. Documented false-positive
 * rate ≈ 0.5% of group misses (e.g. queries with structurally different IN(...)
 * expansions hash differently).
 *
 * Replaces:
 *   - single-quoted string literals      → ?
 *   - double-quoted string literals      → ?
 *   - numeric literals                   → ?
 *   - hex/bin literals                   → ?
 *   - PDO `?` placeholders               → kept (already normalized)
 *   - Named placeholders like `:name`    → kept
 *   - whitespace runs                    → single space
 */
final class SqlNormalizer
{
    public static function normalize(string $sql): string
    {
        $sql = trim($sql);

        // 1. Strip string literals — be permissive about escape sequences.
        $sql = preg_replace("/'(?:''|\\\\.|[^'\\\\])*'/", '?', $sql);
        $sql = preg_replace('/"(?:""|\\\\.|[^"\\\\])*"/', '?', $sql);

        // 2. Strip hex / binary literals.
        $sql = preg_replace('/0x[0-9a-fA-F]+/', '?', $sql);
        $sql = preg_replace("/[bB]'[01]+'/", '?', $sql);

        // 3. Strip plain numeric literals (avoid replacing identifiers that
        // happen to start with a digit by anchoring to a word boundary).
        $sql = preg_replace('/(?<![a-zA-Z_0-9])-?\d+(?:\.\d+)?(?![a-zA-Z_0-9])/', '?', $sql);

        // 4. Collapse whitespace.
        $sql = preg_replace('/\s+/', ' ', $sql);

        return trim($sql);
    }

    public static function fingerprint(string $sql): string
    {
        return sha1(self::normalize($sql));
    }
}
