<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Tests\Support\DbFactory as F;

/**
 * Inserts a row directly into the sod_rules table.
 *
 * @param  array<string, mixed>  $over
 */
function insertSodRule(array $over = []): string
{
    $id = F::id();
    DB::table('sod_rules')->insert($over + [
        'id' => $id,
        'organization_id' => null,
        'permission_a' => 'fees.discount.approve',
        'permission_b' => 'fees.discount.create',
        'record_type' => 'fee_discount',
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $id;
}

it('allows valid rule inserts for both system and organization levels', function () {
    $systemRuleId = insertSodRule([
        'organization_id' => null,
        'permission_a' => 'fees.discount.approve',
        'permission_b' => 'fees.discount.create',
        'record_type' => 'fee_discount',
    ]);
    expect(DB::table('sod_rules')->where('id', $systemRuleId)->exists())->toBeTrue();

    $org = F::org();
    $orgRuleId = insertSodRule([
        'organization_id' => $org,
        'permission_a' => 'fees.refund.approve',
        'permission_b' => 'fees.refund.request',
        'record_type' => 'refund',
    ]);
    expect(DB::table('sod_rules')->where('id', $orgRuleId)->exists())->toBeTrue();
});

it('rejects reversed pair where permission_a >= permission_b', function () {
    // Reversed (permission_a > permission_b)
    $reversed = F::exception(fn () => insertSodRule([
        'permission_a' => 'fees.discount.create',
        'permission_b' => 'fees.discount.approve',
        'record_type' => 'fee_discount',
    ]));
    expect($reversed)->not->toBeNull();
    expect($reversed?->getMessage())->toContain('sod_rules_pair_order_chk');

    // Equal (permission_a == permission_b)
    $equal = F::exception(fn () => insertSodRule([
        'permission_a' => 'fees.discount.create',
        'permission_b' => 'fees.discount.create',
        'record_type' => 'fee_discount',
    ]));
    expect($equal)->not->toBeNull();
    expect($equal?->getMessage())->toContain('sod_rules_pair_order_chk');
});

it('rejects duplicate system rule with NULL organization', function () {
    insertSodRule([
        'organization_id' => null,
        'permission_a' => 'fees.discount.approve',
        'permission_b' => 'fees.discount.create',
        'record_type' => 'fee_discount',
    ]);

    $duplicate = F::exception(fn () => insertSodRule([
        'organization_id' => null,
        'permission_a' => 'fees.discount.approve',
        'permission_b' => 'fees.discount.create',
        'record_type' => 'fee_discount',
    ]));

    expect($duplicate)->not->toBeNull();
    expect($duplicate?->getMessage())->toContain('sod_rules_unique');
});

it('allows same pair with a different record_type', function () {
    $rule1 = insertSodRule([
        'organization_id' => null,
        'permission_a' => 'fees.discount.approve',
        'permission_b' => 'fees.discount.create',
        'record_type' => 'fee_discount',
    ]);

    $rule2 = insertSodRule([
        'organization_id' => null,
        'permission_a' => 'fees.discount.approve',
        'permission_b' => 'fees.discount.create',
        'record_type' => 'scholarship_discount',
    ]);

    expect(DB::table('sod_rules')->where('id', $rule1)->exists())->toBeTrue();
    expect(DB::table('sod_rules')->where('id', $rule2)->exists())->toBeTrue();

    $count = DB::table('sod_rules')
        ->where('permission_a', 'fees.discount.approve')
        ->where('permission_b', 'fees.discount.create')
        ->count();

    expect($count)->toBe(2);
});
