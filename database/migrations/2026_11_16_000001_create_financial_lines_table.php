<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_lines', function (Blueprint $table) {
            $table->id();
            // NULL = standard line shared by every company; set = custom "+ Add Row" line.
            $table->foreignId('company_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('code', 50);
            $table->string('label');
            $table->string('type', 10);   // input | derived
            $table->string('group', 20)->nullable(); // LineGroup for input lines
            $table->unsignedSmallInteger('sort')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['company_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_lines');
    }
};
