<?php

declare(strict_types=1);

use App\Services\Privacy\BlindIndex;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    // Reset key to the test key from phpunit.xml before each test
    config(['privacy.blind_index_key' => 'test-blind-index-key-32-chars-long!']);
});

it('normalizes identifiers by removing non-alphanumerics and converting to uppercase', function () {
    expect(BlindIndex::normalize('12345-1234567-1'))->toBe('1234512345671')
        ->and(BlindIndex::normalize('ab-123 456'))->toBe('AB123456')
        ->and(BlindIndex::normalize('  pk--123/456#  '))->toBe('PK123456')
        ->and(BlindIndex::normalize(''))->toBe('')
        ->and(BlindIndex::normalize('---   '))->toBe('');
});

it('produces the same hash regardless of dashes and spaces', function () {
    $orgId = '01J8ORG0000000000000000001';

    $hash1 = BlindIndex::hash($orgId, '12345-1234567-1');
    $hash2 = BlindIndex::hash($orgId, '12345 1234567 1');
    $hash3 = BlindIndex::hash($orgId, '1234512345671');
    $hash4 = BlindIndex::hash($orgId, '  12345 - 1234567 - 1  ');

    expect($hash1)->toBeString()
        ->and($hash1)->toBe($hash2)
        ->and($hash2)->toBe($hash3)
        ->and($hash3)->toBe($hash4);
});

it('produces the same hash regardless of letter case', function () {
    $orgId = '01J8ORG0000000000000000001';

    $hashLower = BlindIndex::hash($orgId, 'ab1234567');
    $hashUpper = BlindIndex::hash($orgId, 'AB1234567');
    $hashMixed = BlindIndex::hash($orgId, 'aB-123 456 7');

    expect($hashLower)->toBeString()
        ->and($hashLower)->toBe($hashUpper)
        ->and($hashUpper)->toBe($hashMixed);
});

it('produces different hashes for the same value in different organizations', function () {
    $orgA = '01J8ORGAAAAAAAAAAAAAAAAAAAA';
    $orgB = '01J8ORGBBBBBBBBBBBBBBBBBBBB';
    $val = '12345-1234567-1';

    $hashA = BlindIndex::hash($orgA, $val);
    $hashB = BlindIndex::hash($orgB, $val);

    expect($hashA)->not->toBeNull()
        ->and($hashB)->not->toBeNull()
        ->and($hashA)->not->toBe($hashB);
});

it('produces different hashes when using a different key', function () {
    $orgId = '01J8ORG0000000000000000001';
    $val = '12345-1234567-1';

    config(['privacy.blind_index_key' => 'key-alpha-32-characters-long-1111']);
    $hashAlpha = BlindIndex::hash($orgId, $val);

    config(['privacy.blind_index_key' => 'key-bravo-32-characters-long-2222']);
    $hashBravo = BlindIndex::hash($orgId, $val);

    expect($hashAlpha)->not->toBeNull()
        ->and($hashBravo)->not->toBeNull()
        ->and($hashAlpha)->not->toBe($hashBravo);
});

it('produces an output of exactly 64 lowercase hex characters', function () {
    $orgId = '01J8ORG0000000000000000001';
    $hash = BlindIndex::hash($orgId, '12345-1234567-1');

    expect($hash)->toBeString()
        ->and(strlen((string) $hash))->toBe(64)
        ->and($hash)->toMatch('/^[0-9a-f]{64}$/');
});

it('returns null for null, empty string, and punctuation-only values', function () {
    $orgId = '01J8ORG0000000000000000001';

    expect(BlindIndex::hash($orgId, null))->toBeNull()
        ->and(BlindIndex::hash($orgId, ''))->toBeNull()
        ->and(BlindIndex::hash($orgId, '   '))->toBeNull()
        ->and(BlindIndex::hash($orgId, '---'))->toBeNull()
        ->and(BlindIndex::hash($orgId, '- - -  --'))->toBeNull()
        ->and(BlindIndex::hash($orgId, ' ,./;\'[]\=- '))->toBeNull();
});

it('throws a RuntimeException when the blind index key is missing or null', function () {
    $orgId = '01J8ORG0000000000000000001';

    config(['privacy.blind_index_key' => null]);

    expect(fn () => BlindIndex::hash($orgId, '1234567890123'))
        ->toThrow(RuntimeException::class, 'Blind index key is missing or shorter than 32 characters.');
});

it('throws a RuntimeException when the blind index key is shorter than 32 characters', function () {
    $orgId = '01J8ORG0000000000000000001';

    config(['privacy.blind_index_key' => str_repeat('x', 31)]);

    expect(fn () => BlindIndex::hash($orgId, '1234567890123'))
        ->toThrow(RuntimeException::class, 'Blind index key is missing or shorter than 32 characters.');
});

it('works normally when the blind index key has exactly 32 characters', function () {
    $orgId = '01J8ORG0000000000000000001';

    config(['privacy.blind_index_key' => str_repeat('z', 32)]);

    $hash = BlindIndex::hash($orgId, '1234567890123');
    expect($hash)->toBeString()
        ->and(strlen((string) $hash))->toBe(64)
        ->and($hash)->toMatch('/^[0-9a-f]{64}$/');
});

it('matches an independently computed HMAC-SHA256 test vector', function () {
    $key = 'test-vector-key-at-least-32-chars-long!';
    config(['privacy.blind_index_key' => $key]);

    $orgId = '01J8ORGTEST0000000000000001';
    $raw = '  42101-1234567-1  ';
    $normalized = '4210112345671';

    $expected = strtolower(hash_hmac('sha256', $orgId.'|'.$normalized, $key));

    expect(BlindIndex::hash($orgId, $raw))->toBe($expected);
});

it('never leaks the plain value or the key in exception messages', function () {
    $orgId = '01J8ORG0000000000000000001';
    $plainValue = 'SECRET-PASSPORT-999';
    $shortKey = 'short-key-12345';

    config(['privacy.blind_index_key' => $shortKey]);

    try {
        BlindIndex::hash($orgId, $plainValue);
        test()->fail('Expected exception was not thrown');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->not->toContain($plainValue)
            ->and($e->getMessage())->not->toContain($shortKey);
    }
});
