<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Monthly figures uploaded as finished totals (e.g. "HA Rev / HA PBT" sheets).
     * Stored exactly as reported; where present they take precedence over the
     * figures computed from weekly entries and line-level AMP.
     */
    public function up(): void
    {
        Schema::create('monthly_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            $table->string('metric', 20); // revenue | gross_profit | pbt | ebitda
            $table->decimal('amp', 15, 2)->nullable();
            $table->decimal('actual', 15, 2)->nullable();
            $table->foreignId('upload_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'year', 'month', 'metric'], 'monthly_results_unique');
        });
    }


    public function down(): void
    {
        Schema::dropIfExists('monthly_results');
    }
};
 