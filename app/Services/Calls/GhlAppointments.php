<?php

namespace App\Services\Calls;

use App\Enums\CustomerStatus;
use App\Models\CallBooking;
use App\Models\Customer;
use App\Services\Audit\AuditLogger;
use App\Support\BusinessClock;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Turns a GoHighLevel workflow webhook (appointment booked / status changed)
 * into a CallBooking. Reads the fields from GHL's standard payload, or from the
 * webhook's "Custom Data" keys when set (see docs/gohighlevel-calls.md).
 */
final class GhlAppointments
{
    private const STATUS = [
        'booked' => 'scheduled', 'confirmed' => 'scheduled', 'new' => 'scheduled', 'rescheduled' => 'scheduled', 'scheduled' => 'scheduled',
        'cancelled' => 'cancelled', 'canceled' => 'cancelled', 'invalid' => 'cancelled',
        'showed' => 'completed', 'completed' => 'completed',
        'noshow' => 'no_show', 'no_show' => 'no_show', 'no-show' => 'no_show',
    ];

    public function __construct(private readonly AuditLogger $audit) {}

    public function record(array $p): CallBooking
    {
        $custom = (array) ($p['customData'] ?? $p['custom_data'] ?? []);
        $cal = (array) ($p['calendar'] ?? $p['appointment'] ?? []);
        $pick = function (array $keys) use ($custom, $cal, $p) {
            foreach ([$custom, $cal, $p] as $src) {
                foreach ($keys as $k) {
                    $v = Arr::get($src, $k);
                    if (is_scalar($v) && trim((string) $v) !== '') {
                        return trim((string) $v);
                    }
                }
            }

            return null;
        };

        $appointmentId = $pick(['appointment_id', 'appointmentId', 'id']);
        $email = strtolower((string) $pick(['email', 'contact.email']));
        $start = $this->time($pick(['start_time', 'startTime', 'start']));
        $statusRaw = strtolower(str_replace(' ', '', (string) $pick(['status', 'appointmentStatus', 'appoinmentStatus'])));
        $utm = array_filter([
            'utm_source' => $pick(['utm_source', 'attributionSource.utmSource', 'contact.attributionSource.utmSource']),
            'utm_medium' => $pick(['utm_medium', 'attributionSource.utmMedium']),
            'utm_campaign' => $pick(['utm_campaign', 'attributionSource.campaign', 'attributionSource.utmCampaign']),
        ]);
        $coupon = $pick(['coupon', 'coupon_code', 'Coupon code', 'Coupon Code']);

        $booking = ($appointmentId ? CallBooking::firstWhere('ghl_appointment_id', $appointmentId) : null)
            ?? ($email && $start ? CallBooking::where('email', $email)->where('starts_at', $start)->first() : null)
            ?? new CallBooking;

        $booking->fill(array_filter([
            'ghl_appointment_id' => $appointmentId,
            'ghl_contact_id' => $pick(['contact_id', 'contactId', 'contact.id']),
            'starts_at' => $start,
            'ends_at' => $this->time($pick(['end_time', 'endTime', 'end'])),
            'client_timezone' => $pick(['timezone', 'selectedTimezone', 'contact.timezone']),
            'name' => $pick(['name', 'full_name', 'contact_name']) ?? trim(($pick(['first_name', 'firstName']) ?? '').' '.($pick(['last_name', 'lastName']) ?? '')),
            'email' => $email ?: null,
            'phone' => $pick(['phone', 'contact.phone']),
            'company_name' => $pick(['company', 'company_name', 'companyName']),
            'coupon_code' => $coupon ? strtoupper(mb_substr($coupon, 0, 40)) : null,
            'utm' => $utm ?: null,
            'source' => $utm['utm_campaign'] ?? $utm['utm_source'] ?? $pick(['source', 'contact_source']),
            'meeting_link' => $pick(['meeting_link', 'address', 'meetingLocation']),
        ], fn ($v) => $v !== null && $v !== ''));

        if ($statusRaw !== '' && isset(self::STATUS[$statusRaw]) && $booking->status !== 'onboarded') {
            $booking->status = self::STATUS[$statusRaw];
        }
        $booking->last_payload = $p;
        $new = ! $booking->exists;

        // Already a customer with this email? Link it.
        if ($booking->email && ! $booking->customer_id) {
            $customer = Customer::where('email', $booking->email)->first();
            if ($customer) {
                $booking->customer_id = $customer->id;
                if (! in_array($customer->status, [CustomerStatus::Draft, CustomerStatus::ContractSigned], true)) {
                    $booking->status = 'onboarded';
                }
            }
        }
        $booking->save();

        $this->audit->log($new ? 'call.booked' : 'call.updated', ($new ? 'Call booked' : 'Call updated').': '.($booking->company_name ?: $booking->name ?: $booking->email)
            .($booking->starts_at ? ' for '.$booking->starts_at->setTimezone(BusinessClock::timezone())->format('M j, g:i A T') : '')
            .($booking->coupon_code ? " (coupon {$booking->coupon_code})" : ''), $booking, null, 'system');

        return $booking;
    }

    /** When a client pays their deposit, mark their calls as onboarded. */
    public static function markOnboarded(Customer $customer): void
    {
        CallBooking::where('email', $customer->email)->whereIn('status', ['scheduled', 'completed', 'no_show'])
            ->update(['status' => 'onboarded', 'customer_id' => $customer->id]);
    }

    private function time(?string $v): ?Carbon
    {
        if (! $v) {
            return null;
        }
        try {
            return is_numeric($v) ? Carbon::createFromTimestamp((int) (strlen($v) > 11 ? $v / 1000 : $v)) : Carbon::parse($v)->utc();
        } catch (Throwable) {
            return null;
        }
    }
}
