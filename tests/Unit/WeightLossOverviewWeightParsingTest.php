<?php

namespace Tests\Unit;

use App\Support\WeightLossOverview;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\TestCase;

class WeightLossOverviewWeightParsingTest extends TestCase
{
    #[DataProvider('weights')]
    public function test_weight_readings_are_normalized_without_guessing_ambiguous_stones(
        mixed $value,
        mixed $kilograms,
        mixed $stones,
        mixed $pounds,
        ?float $expected,
    ): void {
        $method = new ReflectionMethod(WeightLossOverview::class, 'weightInKg');

        $this->assertSame($expected, $method->invoke(null, $value, $kilograms, $stones, $pounds));
    }

    public static function weights(): array
    {
        return [
            'labelled kilograms' => ['90 kg', null, null, null, 90.0],
            'explicit kilograms take priority' => ['14', '89.5', null, null, 89.5],
            'labelled stone and pounds' => ['14 st 2 lb', null, null, null, 89.81],
            'separate stone and pounds' => [null, null, 14, 2, 89.81],
            'labelled pounds' => ['198 lb', null, null, null, 89.81],
            'plausible bare kilograms' => ['90', null, null, null, 90.0],
            'ambiguous bare stones are rejected' => ['14', null, null, null, null],
            'implausible high value is rejected' => ['600 kg', null, null, null, null],
        ];
    }
}
