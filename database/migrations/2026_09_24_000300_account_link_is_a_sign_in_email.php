<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** The account email now invites clients to sign in (accounts are password-protected). Only updates the untouched default. */
return new class extends Migration
{
    public function up(): void
    {
        $t = DB::table('email_templates')->where('key', 'account_link')->first();
        if (! $t || ! str_contains($t->body, 'Here’s your link to see your agreement')) {
            return;
        }
        $sign = str_contains($t->body, "\n\n— ") ? substr($t->body, strrpos($t->body, "\n\n— ")) : '';
        DB::table('email_templates')->where('id', $t->id)->update([
            'name' => 'Account sign-in link',
            'subject' => 'Sign in to your RightAlly account',
            'body' => "Hi {first_name},\n\nSign in with your email and password to see your agreement, invoices and receipts, and to update your payment method for {company_name}.\n\n{button:Sign in to your account|{account_link}}\n\nForgot your password? Use “Forgot password?” on the sign-in page.".$sign,
        ]);
    }

    public function down(): void {}
};
