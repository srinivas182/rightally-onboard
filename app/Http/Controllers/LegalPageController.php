<?php

namespace App\Http\Controllers;

use App\Models\LegalPage;
use App\Services\Audit\AuditLogger;
use App\Services\Contracts\HtmlSanitizer;
use App\Services\Settings\SettingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Public privacy policy and terms pages, and their admin editor. */
class LegalPageController extends Controller
{
    public function show(string $slug, SettingsService $settings): View
    {
        $page = LegalPage::where('slug', $slug)->firstOrFail();

        return view('legal.show', ['page' => $page, 'body' => self::fill($page, $settings)]);
    }

    public function edit(LegalPage $page): View
    {
        return view('admin.legal.edit', ['page' => $page, 'others' => LegalPage::where('id', '!=', $page->id)->get()]);
    }

    public function update(Request $request, LegalPage $page, HtmlSanitizer $sanitizer, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validate(['title' => ['required', 'string', 'max:120'], 'body_html' => ['required', 'string', 'max:200000']]);
        $page->update(['title' => $data['title'], 'body_html' => $sanitizer->clean($data['body_html']), 'updated_by' => $request->user('admin')->id]);
        $audit->log('legal.updated', "Edited {$page->title}", $page);

        return back()->with('success', "{$page->title} saved and published.");
    }

    /** Company details from Settings, so the pages follow the legal name and address. */
    public static function fill(LegalPage $page, SettingsService $settings): string
    {
        $c = $settings->group('company');
        $values = [
            'company_legal_name' => $c['legal_name'], 'company_dba' => (string) $c['dba'], 'company_address' => $c['address'],
            'support_email' => $c['support_email'], 'updated_date' => $page->updated_at->format('F j, Y'),
        ];

        return (string) preg_replace_callback('/\{\{\s*([a-z_]+)\s*\}\}/', fn ($m) => array_key_exists($m[1], $values) ? e($values[$m[1]]) : $m[0], $page->body_html);
    }
}
