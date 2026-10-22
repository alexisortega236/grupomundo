<?php

namespace App\Services;

use App\Models\PropertyChangeOutbox;

class PropertyChangePublisher
{
    public function __construct(private readonly TransactionRetry $transactions)
    {
    }

    public function publishAvailable(int $limit = 100): int
    {
        $published = 0;

        while ($published < $limit && $this->publishNext()) {
            $published++;
        }

        return $published;
    }

    public function publishNext(): bool
    {
        return $this->transactions->run(function (): bool {
            $event = PropertyChangeOutbox::query()
                ->where('status', '!=', 'published')
                ->orderBy('feed_sequence')
                ->lockForUpdate()
                ->first();

            if (! $event || $event->available_at?->isFuture()) {
                return false;
            }

            $event->update([
                'status' => 'published',
                'attempts' => $event->attempts + 1,
                'locked_at' => null,
                'published_at' => now(),
                'last_error' => null,
            ]);

            return true;
        }, 'property_change_publisher.publish_next');
    }

    public function markFailed(PropertyChangeOutbox $event, \Throwable $exception): void
    {
        $event->update([
            'status' => 'failed',
            'attempts' => $event->attempts + 1,
            'available_at' => now()->addSeconds(min(3600, 2 ** min($event->attempts + 1, 10))),
            'locked_at' => null,
            'last_error' => mb_substr($exception::class.': code '.$exception->getCode(), 0, 255),
        ]);
    }
}
