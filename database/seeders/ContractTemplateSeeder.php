<?php

namespace Database\Seeders;

use App\Enums\ContractType;
use App\Models\ContractTemplate;
use App\Services\Contracts\HtmlSanitizer;
use Illuminate\Database\Seeder;

/**
 * Publishes v1.0 of the initial and renewal agreement templates if missing.
 * Safe to run more than once.
 */
class ContractTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $this->seed(ContractType::Initial, 'RightAlly Platform Subscription and Implementation Agreement', 'initial-agreement-v1.html');
        $this->seed(ContractType::Renewal, 'RightAlly Renewal Agreement', 'renewal-agreement-v1.html');
        $this->seed(ContractType::Existing, 'RightAlly Platform Subscription Agreement (existing clients)', 'existing-client-agreement-v1.html');
    }

    private function seed(ContractType $type, string $title, string $file): void
    {
        if (ContractTemplate::where('type', $type)->exists()) {
            return;
        }

        ContractTemplate::create([
            'type' => $type,
            'version' => '1.0',
            'title' => $title,
            'body_html' => app(HtmlSanitizer::class)->clean((string) file_get_contents(__DIR__.'/templates/'.$file)),
            'is_active' => true,
            'published_at' => now(),
        ]);
    }
}
