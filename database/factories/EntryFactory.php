<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Entry;
use App\Models\FinancialLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Entry> */
class EntryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'year' => 2026,
            'month' => fake()->numberBetween(1, 12),
            'week' => fake()->numberBetween(1, 4),
            'financial_line_id' => FinancialLine::factory(),
            'actual' => (string) fake()->randomFloat(2, 10, 5000),
            'forecast' => (string) fake()->randomFloat(2, 10, 5000),
        ];
    }
}
