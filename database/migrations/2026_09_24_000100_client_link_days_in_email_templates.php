<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Email templates now say how long links last from Settings ({link_days}) instead of a fixed 7 or 30 days. */
return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('email_templates')->where('body', 'like', '%The link works for %days.%')->get(['id', 'body']) as $t) {
            DB::table('email_templates')->where('id', $t->id)->update([
                'body' => str_replace(['The link works for 7 days.', 'The link works for 30 days.'], 'The link works for {link_days} days.', $t->body),
            ]);
        }
    }

    public function down(): void {}
};
