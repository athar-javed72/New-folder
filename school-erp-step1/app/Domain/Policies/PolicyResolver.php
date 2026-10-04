<?php

declare(strict_types=1);

namespace App\Domain\Policies;

use DateTimeImmutable;

/**
 * Effective policy value = preset value, then PUBLISHED overrides active on the date, least to most specific.
 * Associative arrays merge recursively; lists and scalars are replaced; an explicit null really sets null.
 * merge_mode "replace" discards everything below it.
 */
final class PolicyResolver
{
    /** Higher = more specific = wins. */
    private const SCOPE_ORDER = [
        'organization' => 10, 'campus' => 20, 'program' => 30, 'contract_type' => 30,
        'grade' => 40, 'course' => 50, 'employment_contract' => 60,
    ];

    /**
     * @param array<string,mixed> $baseValue
     * @param list<array{scopeable_type:string,status:string,effective_from:string,effective_to?:?string,merge_mode?:string,value:array<string,mixed>}> $overrides
     * @return array<string,mixed>
     */
    public function resolve(array $baseValue, array $overrides, DateTimeImmutable $on): array
    {
        $active = $this->activeOn($overrides, $on);
        usort($active, static function (array $a, array $b): int {
            return [self::SCOPE_ORDER[$a['scopeable_type']] ?? 0, $a['effective_from']]
                <=> [self::SCOPE_ORDER[$b['scopeable_type']] ?? 0, $b['effective_from']];
        });

        $result = $baseValue;
        foreach ($active as $o) {
            $result = ($o['merge_mode'] ?? 'deep_merge') === 'replace' ? $o['value'] : self::merge($result, $o['value']);
        }

        return $result;
    }

    /** @return list<array<string,mixed>> */
    public function activeOn(array $overrides, DateTimeImmutable $on): array
    {
        $day = $on->format('Y-m-d');

        return array_values(array_filter($overrides, static function (array $o) use ($day): bool {
            if (($o['status'] ?? null) !== 'published') {
                return false;
            }
            $to = $o['effective_to'] ?? null;

            return $o['effective_from'] <= $day && ($to === null || $day < $to);
        }));
    }

    public static function merge(array $base, array $override): array
    {
        foreach ($override as $key => $value) {
            if (is_array($value) && ! array_is_list($value) && isset($base[$key]) && is_array($base[$key]) && ! array_is_list($base[$key])) {
                $base[$key] = self::merge($base[$key], $value);
            } else {
                $base[$key] = $value;
            }
        }

        return $base;
    }
}
