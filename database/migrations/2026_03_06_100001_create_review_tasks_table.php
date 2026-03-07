<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('review_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('repository_id')->constrained()->cascadeOnDelete();
            $table->integer('pr_number');
            $table->string('pr_title');
            $table->string('pr_url');
            $table->string('pr_author')->nullable();
            $table->string('status')->default('pending');
            $table->string('jules_session_id')->nullable();
            $table->string('jules_fix_pr_url')->nullable();
            $table->text('review_summary')->nullable();
            $table->longText('diff_content')->nullable();
            $table->integer('iteration')->default(1);
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['repository_id', 'pr_number']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('review_tasks');
    }
};
