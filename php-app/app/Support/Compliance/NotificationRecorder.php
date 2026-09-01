<?php

namespace App\Support\Compliance;

use App\Models\Notification;
use App\Models\NotificationDelivery;
use Illuminate\Support\Str;

/**
 * Ported from lib/data/compliance-repository.ts's notificationRecord -- the
 * shared notification-creation path five call sites in this phase's slice
 * (case opened, dispute filed, obligation created, risk escalated to case)
 * all route through, rather than each hand-rolling its own insert. Always
 * writes one IN_APP notification_deliveries row alongside the notification
 * itself: the in-app notification centre is not a channel a preference can
 * disable, since the notifications table row *is* that channel's delivery.
 * Additional channels (EMAIL/SMS/PORTAL) are only ever attempted by the
 * still-unported standalone queueNotification command, which can check a
 * user's notification_preferences first -- these five call sites all
 * target a taxpayer broadly (user_id always null), not a specific user, so
 * there is no single user's preference to check here.
 */
class NotificationRecorder
{
    public static function record(?string $userId, ?string $taxpayerId, string $notificationType, string $title, string $message, string $severity, ?string $actionUrl, \DateTimeInterface $now): void
    {
        $notification = Notification::create([
            'id' => (string) Str::uuid(), 'user_id' => $userId, 'taxpayer_id' => $taxpayerId,
            'notification_type' => $notificationType, 'title' => $title, 'message' => $message,
            'severity' => $severity, 'status' => 'UNREAD', 'action_url' => $actionUrl, 'created_at' => $now,
            'read_at' => null, 'cancelled_by' => null, 'cancelled_at' => null, 'cancellation_reason' => null,
        ]);
        NotificationDelivery::create([
            'id' => (string) Str::uuid(), 'notification_id' => $notification->id, 'channel' => 'IN_APP',
            'status' => 'QUEUED', 'attempted_at' => $now,
        ]);
    }
}
