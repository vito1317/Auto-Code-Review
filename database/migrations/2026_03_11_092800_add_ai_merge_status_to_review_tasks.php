<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('review_tasks', function (Blueprint $table) {
            $table->string('ai_merge_status')->nullable()->after('pr_status');
            $table->text('ai_merge_message')->nullable()->after('ai_merge_status');
        });
    }

    public function down(): void
    {
        Schema::table('review_tasks', function (Blueprint $table) {
            $table->dropColumn(['ai_merge_status', 'ai_merge_message']);
        });
    }
};
