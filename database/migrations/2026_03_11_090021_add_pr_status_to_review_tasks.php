<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('review_tasks', function (Blueprint $table) {
            $table->string('pr_status')->default('open')->after('pr_author');
        });
    }

    public function down(): void
    {
        Schema::table('review_tasks', function (Blueprint $table) {
            $table->dropColumn('pr_status');
        });
    }
};
