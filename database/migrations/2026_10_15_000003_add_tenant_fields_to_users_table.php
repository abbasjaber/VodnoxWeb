<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('username')->unique()->nullable()->after('name');
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete()->after('id');
            $table->foreignId('role_id')->nullable()->constrained()->nullOnDelete()->after('organization_id');
            $table->boolean('active')->default(true)->after('role_id');
            $table->string('extension')->nullable()->after('active');
            $table->string('phone')->nullable()->after('extension');
            $table->string('position')->nullable()->after('phone');
            $table->index(['organization_id', 'active']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('organization_id');
            $table->dropConstrainedForeignId('role_id');
            $table->dropIndex(['organization_id', 'active']);
            $table->dropColumn(['username', 'active', 'extension', 'phone', 'position']);
        });
    }
};
