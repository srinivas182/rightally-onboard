<?php

namespace App\Http\Controllers\Onboarding;

use App\Http\Controllers\Controller;
use App\Services\Settings\SettingsService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** /book-a-call: the GoHighLevel calendar embedded on our site, carrying the coupon and campaign. */
class BookCallController extends Controller
{
    public function show(Request $request, SettingsService $settings): View
    {
        abort_unless((string) $settings->get('calls', 'enabled') === '1' && filled($settings->get('calls', 'ghl_calendar_id')), 404);

        // Coupon and campaign: from the link, else from where the visitor entered the onboarding pages.
        $tracking = (array) $request->session()->get('onboarding.tracking', []);
        $coupon = strtoupper(mb_substr((string) ($request->query('coupon') ?: ($tracking['coupon_link'] ?? '')), 0, 40));
        $utm = array_filter($request->only(['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term'])) ?: (array) ($tracking['utm'] ?? []);

        $params = array_filter($utm + [
            'coupon' => $coupon ?: null,
            'coupon_code' => $coupon ?: null, // GHL custom field key (see docs/gohighlevel-calls.md)
            'redirect_url' => route('book.thanks', array_filter(['coupon' => $coupon ?: null])),
        ]);
        $calendarId = (string) $settings->get('calls', 'ghl_calendar_id');

        return view('book.show', [
            'src' => 'https://api.leadconnectorhq.com/widget/booking/'.$calendarId.($params ? '?'.http_build_query($params) : ''),
            'frameId' => $calendarId.'_rightally',
            'coupon' => $coupon,
        ]);
    }

    public function thanks(Request $request): View
    {
        return view('book.thanks', ['coupon' => strtoupper(mb_substr((string) $request->query('coupon'), 0, 40))]);
    }
}
