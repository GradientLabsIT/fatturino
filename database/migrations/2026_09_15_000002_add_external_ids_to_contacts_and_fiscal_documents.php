<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->string('external_source')->nullable()->after('notes');
            $table->string('external_id')->nullable()->after('external_source');
            $table->unique(['external_source', 'external_id'], 'contacts_external_source_id_unique');
        });

        Schema::table('fiscal_documents', function (Blueprint $table) {
            $table->string('external_source')->nullable()->after('source');
            $table->string('external_id')->nullable()->after('external_source');
            $table->unique(['external_source', 'external_id'], 'fiscal_documents_external_source_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('fiscal_documents', function (Blueprint $table) {
            $table->dropUnique('fiscal_documents_external_source_id_unique');
            $table->dropColumn(['external_source', 'external_id']);
        });

        Schema::table('contacts', function (Blueprint $table) {
            $table->dropUnique('contacts_external_source_id_unique');
            $table->dropColumn(['external_source', 'external_id']);
        });
    }
};
