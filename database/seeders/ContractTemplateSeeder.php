<?php

namespace Database\Seeders;

use App\Enums\ContractType;
use App\Models\ContractTemplate;
use App\Services\Contracts\HtmlSanitizer;
use Illuminate\Database\Seeder;

/**
 * Publishes agreement template v1.0 if no initial template exists yet.
 * Safe to run more than once.
 */
class ContractTemplateSeeder extends Seeder
{
    public function run(): void
    {
        if (ContractTemplate::where('type', ContractType::Initial)->exists()) {
            return;
        }

        ContractTemplate::create([
            'type' => ContractType::Initial,
            'version' => '1.0',
            'title' => 'RightAlly Platform Subscription and Implementation Agreement',
            'body_html' => app(HtmlSanitizer::class)->clean((string) file_get_contents(__DIR__.'/templates/initial-agreement-v1.html')),
            'is_active' => true,
            'published_at' => now(),
        ]);
    }
}
