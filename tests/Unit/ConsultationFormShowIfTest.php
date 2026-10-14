<?php

namespace Tests\Unit;

use App\Http\Controllers\ConsultationFormController;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ConsultationFormShowIfTest extends TestCase
{
    #[DataProvider('informationConditionCases')]
    public function test_in_condition_takes_precedence_over_null_builder_comparators(
        string $answer,
        bool $expected,
    ): void {
        $controller = new class extends ConsultationFormController
        {
            public function conditionIsMet(array $condition, array $data): bool
            {
                return $this->showIfConditionIsMet($condition, $data);
            }
        };

        $condition = [
            'enabled' => true,
            'field' => 'information',
            'equals' => null,
            'in' => ['yes'],
            'notEquals' => null,
            'truthy' => false,
        ];

        $this->assertSame(
            $expected,
            $controller->conditionIsMet($condition, ['information' => $answer]),
        );
    }

    public static function informationConditionCases(): array
    {
        return [
            'yes shows the detail field' => ['Yes', true],
            'no hides the detail field' => ['no', false],
        ];
    }
}
