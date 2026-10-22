<?php

namespace App\Console\Commands;

use App\Services\PropertyChangePublisher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class PublishPropertyChanges extends Command
{
    protected $signature = 'property-changes:publish {--limit=100 : Maximum number of events to publish}';

    protected $description = 'Publishes contiguous pending property change events to the API feed.';

    public function handle(PropertyChangePublisher $publisher): int
    {
        $lock = Cache::lock(
            'property-change-publisher',
            (int) config('integration.property_changes.publisher_lock_timeout_seconds', 300),
        );

        if (! $lock->get()) {
            $this->line('Another property change publisher is already running.');

            return self::SUCCESS;
        }

        $limit = max(1, min(1000, (int) $this->option('limit')));

        try {
            $count = $publisher->publishAvailable($limit);
        } finally {
            $lock->release();
        }

        $this->info("Published {$count} property change event(s).");

        return self::SUCCESS;
    }
}
