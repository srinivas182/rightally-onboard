<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** Public contact email is info@rightally.io (footer, emails, legal pages). Only changes the old default. */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('settings')->where('group', 'company')->where('key', 'support_email')->where('value', 'support@rightally.io')->update(['value' => 'info@rightally.io']);
        Cache::forget('rightally.settings.v1');
    }

    public function down(): void {}
};
