<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Legal entity: Global MLM Software LLC, doing business as RightAlly.
 * Updates the saved company name and unsigned (draft) agreements only.
 * Signed agreements are legal records and keep the name they were signed under.
 */
return new class extends Migration
{
    private const OLD = 'Mayura Consultancy Services LLC';

    private const NEW = 'Global MLM Software LLC';

    public function up(): void
    {
        DB::table('settings')->where('group', 'company')->where('key', 'legal_name')->where('value', self::OLD)->update(['value' => self::NEW]);
        DB::table('contracts')->whereNull('signed_at')->where('company_legal_name', self::OLD)->update(['company_legal_name' => self::NEW]);
        Cache::forget('rightally.settings.v1');
    }

    public function down(): void {}
};
