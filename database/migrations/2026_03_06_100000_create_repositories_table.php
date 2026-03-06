<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('repositories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('owner');
            $table->string('repo');
            $table->string('jules_source')->nullable();
            $table->string('default_branch')->default('main');
            $table->string('webhook_secret')->nullable();
            $table->boolean('is_active')->default(true);
            $table->json('review_config')->nullable();
            $table->timestamps();

            $table->unique(['owner', 'repo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('repositories');
    }
};
