<?php

use Uptimex\Client\Sql\SqlNormalizer;

it('replaces single-quoted string literals with placeholders', function () {
    $sql = "SELECT * FROM users WHERE name = 'Alice'";
    expect(SqlNormalizer::normalize($sql))->toBe('SELECT * FROM users WHERE name = ?');
});

it('replaces double-quoted string literals with placeholders', function () {
    $sql = 'SELECT * FROM users WHERE name = "Bob"';
    expect(SqlNormalizer::normalize($sql))->toBe('SELECT * FROM users WHERE name = ?');
});

it('replaces numeric literals with placeholders', function () {
    expect(SqlNormalizer::normalize('SELECT * FROM users WHERE id = 42'))->toBe('SELECT * FROM users WHERE id = ?')
        ->and(SqlNormalizer::normalize('SELECT * FROM products WHERE price = 19.99'))->toBe('SELECT * FROM products WHERE price = ?');
});

it('keeps PDO ? placeholders intact', function () {
    expect(SqlNormalizer::normalize('SELECT * FROM users WHERE id = ?'))->toBe('SELECT * FROM users WHERE id = ?');
});

it('collapses whitespace runs', function () {
    $sql = "SELECT  *\n  FROM   users\n  WHERE id = 1";
    expect(SqlNormalizer::normalize($sql))->toBe('SELECT * FROM users WHERE id = ?');
});

it('produces the same fingerprint for shape-equivalent queries', function () {
    expect(SqlNormalizer::fingerprint('SELECT * FROM users WHERE id = 1'))
        ->toBe(SqlNormalizer::fingerprint('SELECT * FROM users WHERE id = 9999'));
});

it('produces different fingerprints for shape-different queries', function () {
    $a = SqlNormalizer::fingerprint('SELECT * FROM users WHERE id = 1');
    $b = SqlNormalizer::fingerprint("SELECT * FROM users WHERE name = 'Alice'");
    expect($a)->not->toBe($b);
});

it('handles hex literals', function () {
    expect(SqlNormalizer::normalize('SELECT * FROM uuids WHERE id = 0xDEADBEEF'))
        ->toBe('SELECT * FROM uuids WHERE id = ?');
});

it('does not strip identifiers that contain digits', function () {
    expect(SqlNormalizer::normalize('SELECT * FROM users_2024'))
        ->toBe('SELECT * FROM users_2024');
});

it('returns 40-char sha1 fingerprint', function () {
    expect(strlen(SqlNormalizer::fingerprint('SELECT 1')))->toBe(40);
});
