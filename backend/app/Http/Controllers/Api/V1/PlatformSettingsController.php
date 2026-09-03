<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PlatformResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlatformSettingsController extends Controller
{
    /** @var array<string, mixed> */
    private const DEFAULTS = [
        'platform_name' => 'BusBooking',
        'logo_path' => null,
        'branding_colors' => ['primary' => '#0f172a', 'secondary' => '#2563eb'],
        'supported_languages' => ['en'],
        'supported_currencies' => ['USD'],
        'default_timezone' => 'Africa/Harare',
        'seat_lock_minutes' => 10,
        'booking_reference_format' => 'BK{RANDOM8}',
        'ticket_number_format' => 'TK{RANDOM10}',
        'default_commission_percent' => 5,
        'supported_payment_methods' => ['ecocash', 'onemoney', 'visa', 'mastercard', 'bank_transfer'],
        'file_upload_restrictions' => ['max_size_kb' => 10240, 'allowed_mime_types' => ['pdf', 'jpg', 'jpeg', 'png']],
        'security_settings' => ['two_factor_required_for_staff' => false, 'session_lifetime_minutes' => 120],
        'data_retention_settings' => ['audit_log_days' => 365, 'notification_log_days' => 90],
        'maintenance_mode' => false,
        'maintenance_message' => null,
    ];

    public function show(): JsonResponse
    {
        return response()->json($this->current());
    }

    public function update(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('platform.manage'), 403);
        $validated = $request->validate([
            'platform_name' => ['sometimes', 'string', 'max:150'], 'branding_colors' => ['sometimes', 'array'],
            'supported_languages' => ['sometimes', 'array', 'min:1'], 'supported_languages.*' => ['string', 'size:2'],
            'supported_currencies' => ['sometimes', 'array', 'min:1'], 'supported_currencies.*' => ['string', 'size:3'],
            'default_timezone' => ['sometimes', 'timezone'], 'seat_lock_minutes' => ['sometimes', 'integer', 'between:1,60'],
            'booking_reference_format' => ['sometimes', 'string', 'max:50'], 'ticket_number_format' => ['sometimes', 'string', 'max:50'],
            'default_commission_percent' => ['sometimes', 'numeric', 'between:0,100'], 'supported_payment_methods' => ['sometimes', 'array'],
            'file_upload_restrictions' => ['sometimes', 'array'], 'security_settings' => ['sometimes', 'array'],
            'data_retention_settings' => ['sometimes', 'array'], 'maintenance_mode' => ['sometimes', 'boolean'],
            'maintenance_message' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);
        $this->save([...$this->current(), ...$validated]);

        return response()->json($this->current());
    }

    public function logo(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('platform.manage'), 403);
        $validated = $request->validate(['logo' => ['required', 'image', 'mimes:jpg,jpeg,png,webp,svg', 'max:2048']]);
        $path = $validated['logo']->store('platform/branding', ['disk' => 'public']);
        $this->save([...$this->current(), 'logo_path' => $path]);

        return response()->json(['logo_path' => $path]);
    }

    /** @return array<string, mixed> */
    private function current(): array
    {
        $record = (new PlatformResource)->useModule('system_settings')->newQuery()->where('code', 'platform')->first();

        return [...self::DEFAULTS, ...($record?->data ?? [])];
    }

    /** @param array<string, mixed> $data */
    private function save(array $data): void
    {
        $record = (new PlatformResource)->useModule('system_settings')->newQuery()->firstOrNew(['code' => 'platform']);
        $record->fill(['name' => 'Platform settings', 'status' => 'active', 'data' => $data])->save();
    }
}
