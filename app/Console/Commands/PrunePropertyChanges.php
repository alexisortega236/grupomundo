<?php

namespace App\Console\Commands;

use App\Models\PropertyChangeOutbox;
use App\Models\PropertyRevision;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PrunePropertyChanges extends Command
{
    protected $signature = 'property-changes:prune {--days= : Retention period in days} {--force : Actually delete eligible records}';

    protected $description = 'Reports or removes published property changes outside the retention window.';

    public function handle(): int
    {
        $days = max(1, (int) ($this->option('days') ?: config('integration.property_changes.retention_days', 90)));
        $before = now()->subDays($days);
        $query = PropertyChangeOutbox::query()
            ->where('status', 'published')
            ->where('created_at', '<', $before)
            ->whereNotExists(function ($subquery): void {
                $subquery->selectRaw('1')
                    ->from('property_change_outbox as pending_events')
                    ->whereColumn('pending_events.property_id', 'property_change_outbox.property_id')
                    ->whereColumn('pending_events.revision_number', 'property_change_outbox.revision_number')
                    ->where('pending_events.status', '!=', 'published');
            });

        $count = (clone $query)->count();
        if (! $this->option('force')) {
            $this->line("{$count} event(s) are eligible for retention cleanup. Nothing was deleted; use --force in an authorized maintenance window.");

            return self::SUCCESS;
        }

        DB::transaction(function () use ($query): void {
            $query->orderBy('feed_sequence')->chunkById(100, function ($events): void {
                foreach ($events as $event) {
                    PropertyChangeOutbox::query()->whereKey($event->getKey())->delete();
                    PropertyRevision::query()
                        ->where('property_id', $event->property_id)
                        ->where('revision_number', $event->revision_number)
                        ->delete();
                }
            }, 'feed_sequence');
        });

        $this->info("Deleted {$count} published event(s) and their revisions.");

        return self::SUCCESS;
    }
}
