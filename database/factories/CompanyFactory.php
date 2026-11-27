<?php

namespace Database\Factories;

use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Company> */
class CompanyFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => 'HICOM '.fake()->unique()->company(),
            'code' => Str::upper(Str::random(6)),
            'color' => fake()->hexColor(),
            'is_active' => true,
        ];
    }
}
