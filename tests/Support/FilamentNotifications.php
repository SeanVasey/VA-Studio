<?php

namespace Tests\Support;

use Filament\Notifications\Livewire\Notifications;
use Filament\Notifications\Notification;
use Filament\Notifications\NotificationMailbox;
use PHPUnit\Framework\Assert;

/**
 * Reads the Filament notifications a request has queued, without naming how Filament stores them.
 *
 * Filament 5.8 queued flash notifications in the session; 5.10 moved them to a cache-backed mailbox, so tests that
 * read the session keys see only a mailbox id there (and nothing at all once assertNotified() has run). Filament has
 * no public method that returns the queued notifications, so this follows the read path of its own
 * Notification::assertNotified(): publish what was queued in this process, mount the public Notifications Livewire
 * component, let it pull what the installed version holds, and serialize it with Notification::toArray().
 *
 * The one internal touched is NotificationMailbox::flush(), which 5.10 needs and 5.8 does not have; it is the same
 * call assertNotified() makes first, and it is skipped when the class does not exist.
 */
final class FilamentNotifications
{
    /**
     * Every notification currently queued, as Notification::toArray() returns it (id, title, body, status, color,
     * icon, duration, actions, view and view data), oldest first.
     *
     * Filament's own pull is destructive. This method sends the notifications again after reading them, so a later
     * assertNotified() on the same page still finds them. A test that calls it twice in a row sees the same
     * notifications twice.
     *
     * @return list<array<string, mixed>>
     */
    public static function flashed(): array
    {
        // 5.10 holds a notification in memory until it is published; assertNotified() publishes it first, so do the same.
        if (class_exists(NotificationMailbox::class)) {
            app(NotificationMailbox::class)->flush();
        }

        // 5.8 pulls in mount() and 5.10 only in pullNotificationsFromSession(); the second call finds nothing on 5.8.
        $component = new Notifications;
        $component->mount();
        $component->pullNotificationsFromSession();

        $sent = array_values($component->notifications->map(fn (Notification $notification): array => $notification->toArray())->all());

        foreach ($sent as $notification) {
            Notification::fromArray($notification)->send();
        }

        return $sent;
    }

    /**
     * The one queued notification with this title. Fails when none or several carry it, so a privacy check built on
     * the result can never pass because nothing was found.
     *
     * @return array<string, mixed>
     */
    public static function sole(string $title, ?string $status = null): array
    {
        $found = array_values(array_filter(self::flashed(), fn (array $notification): bool => $notification['title'] === $title));

        Assert::assertCount(1, $found, "Expected exactly one \"{$title}\" notification, found ".count($found).'.');

        if ($status !== null) {
            Assert::assertSame($status, $found[0]['status'], "The \"{$title}\" notification has an unexpected status.");
        }

        return $found[0];
    }

    /**
     * Fails if the given text appears anywhere in any queued notification (title, body, actions or view data), after
     * first requiring that at least one notification was queued.
     *
     * @param  list<string>  $texts
     */
    public static function assertNoneContain(array $texts): void
    {
        $sent = self::flashed();
        Assert::assertNotEmpty($sent, 'No notification was queued, so the privacy check would prove nothing.');

        $haystack = json_encode($sent, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        foreach ($texts as $text) {
            Assert::assertStringNotContainsString($text, $haystack);
        }
    }
}
