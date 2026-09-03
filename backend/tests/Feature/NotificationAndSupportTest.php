<?php

namespace Tests\Feature;

use App\Models\NotificationRecord;
use App\Models\PlatformResource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class NotificationAndSupportTest extends TestCase
{
    use RefreshDatabase;

    public function test_passenger_can_manage_notifications_without_accessing_another_users_record(): void
    {
        $passenger = User::factory()->create();
        $otherPassenger = User::factory()->create();
        $notification = NotificationRecord::create([
            'public_id' => Str::uuid(), 'user_id' => $passenger->id, 'code' => Str::uuid(),
            'name' => 'Departure reminder', 'status' => 'sent', 'event_type' => 'departure_reminder',
            'channel' => 'in_app', 'subject' => 'Your bus departs soon',
            'body' => 'Boarding starts in 30 minutes.', 'recipient' => (string) $passenger->id, 'sent_at' => now(),
        ]);

        $this->actingAs($passenger)->getJson('/api/v1/notifications?unread=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.event_type', 'departure_reminder');
        $this->actingAs($otherPassenger)->patchJson("/api/v1/notifications/{$notification->id}/read")->assertNotFound();
        $this->actingAs($passenger)->patchJson("/api/v1/notifications/{$notification->id}/read")->assertOk()->assertJsonPath('id', $notification->id);

        $this->assertNotNull($notification->refresh()->read_at);

        $this->actingAs($passenger)->putJson('/api/v1/notification-preferences', [
            'default_channels' => ['in_app'],
            'channels' => ['payment_confirmation' => ['email', 'in_app']],
        ])->assertOk()->assertJsonPath('data.default_channels.0', 'in_app');
    }

    public function test_passenger_can_open_and_reply_to_a_support_case_but_another_passenger_cannot_view_it(): void
    {
        $passenger = User::factory()->create();
        $otherPassenger = User::factory()->create();
        $preferences = (new PlatformResource)->useModule('notification_preferences');
        $preferences->fill([
            'user_id' => $passenger->id, 'code' => 'preferences:'.$passenger->id,
            'name' => 'Notification preferences', 'status' => 'active',
            'data' => ['default_channels' => ['in_app'], 'channels' => []],
        ])->save();

        $created = $this->actingAs($passenger)->postJson('/api/v1/support-cases', [
            'category' => 'booking', 'priority' => 'high', 'subject' => 'Seat was changed',
            'description' => 'Please restore my original seat.',
        ])->assertCreated()->assertJsonPath('status', 'open')->assertJsonPath('messages.0.message', 'Please restore my original seat.');
        $caseId = $created->json('id');

        $this->actingAs($otherPassenger)->getJson("/api/v1/support-cases/{$caseId}")->assertNotFound();
        $this->actingAs($passenger)->postJson("/api/v1/support-cases/{$caseId}/messages", [
            'message' => 'The issue is still unresolved.',
        ])->assertCreated()->assertJsonPath('message', 'The issue is still unresolved.');

        $this->assertDatabaseHas('support_messages', ['support_case_id' => $caseId, 'user_id' => $passenger->id]);
    }
}
