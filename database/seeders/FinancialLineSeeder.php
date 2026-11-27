<?php

namespace Database\Seeders;

use App\Enums\LineGroup;
use App\Enums\LineType;
use App\Models\FinancialLine;
use Illuminate\Database\Seeder;

class FinancialLineSeeder extends Seeder
{
    /** Standard P&L lines shared by every company, in grid order. */
    public const LINES = [
        ['revenue', 'Revenue', LineType::Input, LineGroup::Revenue],
        ['total_revenue', 'Total Revenue', LineType::Derived, null],
        ['direct_materials', 'Direct Materials', LineType::Input, LineGroup::CostOfSales],
        ['direct_labor', 'Direct Labor', LineType::Input, LineGroup::CostOfSales],
        ['direct_overhead', 'Direct Overhead', LineType::Input, LineGroup::CostOfSales],
        ['depreciation', 'Depre. & Amort', LineType::Input, LineGroup::CostOfSales],
        ['other_fixed_overhead', 'Others Fixed Overhead', LineType::Input, LineGroup::CostOfSales],
        ['cost_of_sales', 'Cost of Sales', LineType::Derived, null],
        ['gross_profit', 'Gross Profit', LineType::Derived, null],
        ['admin_exp', 'Admin Exp', LineType::Input, LineGroup::Opex],
        ['selling_distribution', 'Selling & Distr. Cost', LineType::Input, LineGroup::Opex],
        ['other_income', 'Other Income/Expenses', LineType::Input, LineGroup::OtherIncome],
        ['finance_cost', 'Finance Cost', LineType::Input, LineGroup::Finance],
        ['pbt', 'Profit Before Tax', LineType::Derived, null],
    ];

    public function run(): void
    {
        foreach (self::LINES as $i => [$code, $label, $type, $group]) {
            FinancialLine::updateOrCreate(
                ['company_id' => null, 'code' => $code],
                ['label' => $label, 'type' => $type, 'group' => $group, 'sort' => ($i + 1) * 10, 'is_active' => true],
            );
        }
    }
}
