<?php

namespace Database\Factories;

use App\Enums\LineGroup;
use App\Enums\LineType;
use App\Models\FinancialLine;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<FinancialLine> Custom per-company line by default. */
class FinancialLineFactory extends Factory
{
    public function definition(): array
    {
        $label = fake()->words(2, true);

        return [
            'company_id' => null,
            'code' => 'custom_'.Str::snake($label).'_'.Str::lower(Str::random(4)),
            'label' => Str::title($label),
            'type' => LineType::Input,
            'group' => LineGroup::CostOfSales,
            'sort' => 65,
            'is_active' => true,
        ];
    }
}
