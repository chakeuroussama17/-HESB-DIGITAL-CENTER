<?php

namespace App\Enums;

/**
 * Which bucket an input line rolls into. The derived rows are pure sums over these
 * buckets, so custom "+ Add Row" lines automatically flow into the right subtotal.
 */
enum LineGroup: string
{
    case Revenue = 'revenue';
    case CostOfSales = 'cost_of_sales';
    case Opex = 'opex';            // Admin Exp, Selling & Distribution
    case OtherIncome = 'other';    // signed: + income, - expense
    case Finance = 'finance';

    public function label(): string
    {
        return match ($this) {
            self::Revenue => 'Revenue',
            self::CostOfSales => 'Cost of Sales',
            self::Opex => 'Operating Expenses',
            self::OtherIncome => 'Other Income / (Expenses)',
            self::Finance => 'Finance Cost',
        };
    }

    /** Costs are keyed in as positive numbers and presented in brackets. */
    public function isCost(): bool
    {
        return in_array($this, [self::CostOfSales, self::Opex, self::Finance], true);
    }

    /** Higher is better for income lines, lower is better for cost lines. */
    public function higherIsBetter(): bool
    {
        return ! $this->isCost();
    }
}
