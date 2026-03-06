<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('review_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('review_task_id')->constrained()->cascadeOnDelete();
            $table->string('file_path');
            $table->integer('line_number')->nullable();
            $table->string('severity')->default('info');
            $table->string('category')->default('general');
            $table->text('body');
            $table->unsignedBigInteger('github_comment_id')->nullable();
            $table->timestamps();

            $table->index(['review_task_id', 'severity']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('review_comments');
    }
};
