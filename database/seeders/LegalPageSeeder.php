<?php

namespace Database\Seeders;

use App\Models\LegalPage;
use App\Services\Contracts\HtmlSanitizer;
use Illuminate\Database\Seeder;

/** Draft privacy policy and terms of use (for attorney review). Only creates missing pages. */
class LegalPageSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['privacy' => 'Privacy Policy', 'terms' => 'Terms of Use'] as $slug => $title) {
            LegalPage::firstOrCreate(['slug' => $slug], [
                'title' => $title,
                'body_html' => app(HtmlSanitizer::class)->clean((string) file_get_contents(__DIR__."/legal/{$slug}.html")),
            ]);
        }
    }
}
