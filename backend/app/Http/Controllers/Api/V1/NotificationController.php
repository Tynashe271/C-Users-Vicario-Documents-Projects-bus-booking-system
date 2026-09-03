<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\NotificationRecord;
use App\Models\PlatformResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate(['unread' => ['nullable', 'boolean'], 'event_type' => ['nullable', 'string', 'max:100']]);
        $records = NotificationRecord::where('user_id', $request->user()->id)->where('channel', 'in_app')->when($validated['unread'] ?? false, fn ($query) => $query->whereNull('read_at'))->when($validated['event_type'] ?? null, fn ($query, $type) => $query->where('event_type', $type))->latest()->paginate(30);

        return response()->json($records);
    }

    public function read(Request $request, NotificationRecord $notification): JsonResponse
    {
        abort_unless($notification->user_id === $request->user()->id && $notification->channel === 'in_app', 404);
        $notification->update(['read_at' => $notification->read_at ?? now()]);

        return response()->json($notification->refresh());
    }

    public function preferences(Request $request): JsonResponse
    {
        $record = (new PlatformResource)->useModule('notification_preferences')->newQuery()->where('user_id', $request->user()->id)->first();

        return response()->json($record?->data ?? ['default_channels' => ['email', 'in_app'], 'channels' => []]);
    }

    public function updatePreferences(Request $request): JsonResponse
    {
        $validated = $request->validate(['default_channels' => ['required', 'array', 'min:1'], 'default_channels.*' => ['in:email,sms,push,whatsapp,in_app', 'distinct'], 'channels' => ['sometimes', 'array'], 'channels.*' => ['array'], 'channels.*.*' => ['in:email,sms,push,whatsapp,in_app', 'distinct']]);
        $model = (new PlatformResource)->useModule('notification_preferences');
        $record = $model->newQuery()->where('user_id', $request->user()->id)->first() ?? $model;
        $record->fill(['company_id' => $request->user()->company_id, 'user_id' => $request->user()->id, 'code' => 'preferences:'.$request->user()->id, 'name' => 'Notification preferences', 'status' => 'active', 'data' => $validated])->save();

        return response()->json($record->refresh());
    }
}
