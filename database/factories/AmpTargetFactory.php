<?php

namespace Database\Factories;

use App\Models\AmpTarget;
use App\Models\Company;
use App\Models\FinancialLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AmpTarget> */
class AmpTargetFactory extends Factory
{
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'year' => 2026,
            'month' => fake()->numberBetween(1, 12),
            'financial_line_id' => FinancialLine::factory(),
            'amount' => (string) fake()->randomFloat(2, 100, 20000),
        ];
    }
}
