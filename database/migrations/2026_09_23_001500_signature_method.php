<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** How the client signed: drew a signature, or adopted their typed name (keyboard and screen-reader friendly). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->string('signature_method', 10)->default('drawn')->after('client_signature_path');
        });
    }

    public function down(): void
    {
        Schema::table('contracts', fn (Blueprint $t) => $t->dropColumn('signature_method'));
    }
};
