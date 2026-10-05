<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

it('produces the same rate limiter key for same email and same IP', function () {
    /** @var callable $limiter */
    $limiter = RateLimiter::limiter('login');
    expect($limiter)->not->toBeNull();

    $req1 = Request::create('/login', 'POST', ['email' => 'teacher@school.edu.pk'], server: ['REMOTE_ADDR' => '203.0.113.10']);
    $req2 = Request::create('/login', 'POST', ['email' => 'teacher@school.edu.pk'], server: ['REMOTE_ADDR' => '203.0.113.10']);

    /** @var Limit $limit1 */
    $limit1 = $limiter($req1);
    /** @var Limit $limit2 */
    $limit2 = $limiter($req2);

    expect($limit1->key)->toBe($limit2->key)
        ->and($limit1->key)->toBe('teacher@school.edu.pk|203.0.113.10');
});

it('produces a different rate limiter key for same email with different IP', function () {
    /** @var callable $limiter */
    $limiter = RateLimiter::limiter('login');

    $req1 = Request::create('/login', 'POST', ['email' => 'teacher@school.edu.pk'], server: ['REMOTE_ADDR' => '203.0.113.10']);
    $req2 = Request::create('/login', 'POST', ['email' => 'teacher@school.edu.pk'], server: ['REMOTE_ADDR' => '198.51.100.25']);

    /** @var Limit $limit1 */
    $limit1 = $limiter($req1);
    /** @var Limit $limit2 */
    $limit2 = $limiter($req2);

    expect($limit1->key)->not->toBe($limit2->key)
        ->and($limit1->key)->toBe('teacher@school.edu.pk|203.0.113.10')
        ->and($limit2->key)->toBe('teacher@school.edu.pk|198.51.100.25');
});

it('produces a different rate limiter key for different email with same IP', function () {
    /** @var callable $limiter */
    $limiter = RateLimiter::limiter('login');

    $req1 = Request::create('/login', 'POST', ['email' => 'teacher1@school.edu.pk'], server: ['REMOTE_ADDR' => '203.0.113.10']);
    $req2 = Request::create('/login', 'POST', ['email' => 'teacher2@school.edu.pk'], server: ['REMOTE_ADDR' => '203.0.113.10']);

    /** @var Limit $limit1 */
    $limit1 = $limiter($req1);
    /** @var Limit $limit2 */
    $limit2 = $limiter($req2);

    expect($limit1->key)->not->toBe($limit2->key)
        ->and($limit1->key)->toBe('teacher1@school.edu.pk|203.0.113.10')
        ->and($limit2->key)->toBe('teacher2@school.edu.pk|203.0.113.10');
});

it('normalizes email case and surrounding spaces so they do not change the key', function () {
    /** @var callable $limiter */
    $limiter = RateLimiter::limiter('login');

    $req1 = Request::create('/login', 'POST', ['email' => 'Admin@School.edu.pk'], server: ['REMOTE_ADDR' => '203.0.113.10']);
    $req2 = Request::create('/login', 'POST', ['email' => '  admin@school.edu.pk  '], server: ['REMOTE_ADDR' => '203.0.113.10']);
    $req3 = Request::create('/login', 'POST', ['email' => 'ADMIN@SCHOOL.EDU.PK'], server: ['REMOTE_ADDR' => '203.0.113.10']);

    /** @var Limit $limit1 */
    $limit1 = $limiter($req1);
    /** @var Limit $limit2 */
    $limit2 = $limiter($req2);
    /** @var Limit $limit3 */
    $limit3 = $limiter($req3);

    expect($limit1->key)->toBe('admin@school.edu.pk|203.0.113.10')
        ->and($limit2->key)->toBe('admin@school.edu.pk|203.0.113.10')
        ->and($limit3->key)->toBe('admin@school.edu.pk|203.0.113.10');
});

it('handles missing or non-string email gracefully without crashing', function () {
    /** @var callable $limiter */
    $limiter = RateLimiter::limiter('login');

    $reqMissing = Request::create('/login', 'POST', server: ['REMOTE_ADDR' => '203.0.113.10']);
    /** @var Limit $limitMissing */
    $limitMissing = $limiter($reqMissing);
    expect($limitMissing->key)->toBe('|203.0.113.10');

    $reqNull = Request::create('/login', 'POST', ['email' => null], server: ['REMOTE_ADDR' => '203.0.113.10']);
    /** @var Limit $limitNull */
    $limitNull = $limiter($reqNull);
    expect($limitNull->key)->toBe('|203.0.113.10');
});

it('enforces a rate limit of 5 per minute', function () {
    /** @var callable $limiter */
    $limiter = RateLimiter::limiter('login');
    $req = Request::create('/login', 'POST', ['email' => 'test@school.test'], server: ['REMOTE_ADDR' => '127.0.0.1']);

    /** @var Limit $limit */
    $limit = $limiter($req);

    expect($limit->maxAttempts)->toBe(5)
        ->and($limit->decaySeconds)->toBe(60);
});

it('uses Argon2id hashing driver by default and verifies password hashing behavior', function () {
    expect(config('hashing.driver'))->toBe('argon2id');

    $password = 'CorrectHorseBatteryStaple!42';
    $hash = Hash::make($password);

    expect(Hash::check($password, $hash))->toBeTrue()
        ->and(Hash::check('WrongPasswordHere', $hash))->toBeFalse()
        ->and(Hash::needsRehash($hash))->toBeFalse();
});
