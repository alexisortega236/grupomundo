<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\PropertyChangeCursorException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\PropertyChangesRequest;
use App\Models\PropertyChangeOutbox;
use App\Services\PropertyChangeCursor;
use Illuminate\Http\JsonResponse;

class PropertyChangeController extends Controller
{
    public function index(PropertyChangesRequest $request, PropertyChangeCursor $cursorService): JsonResponse
    {
        $validated = $request->validated();
        $after = array_key_exists('after', $validated)
            ? $cursorService->decode($validated['after'])
            : 0;
        $limit = (int) ($validated['limit'] ?? 100);

        $minimum = PropertyChangeOutbox::query()->where('status', 'published')->min('feed_sequence');
        if ($after > 0 && $minimum !== null && $after < ((int) $minimum - 1)) {
            throw new PropertyChangeCursorException(
                'cursor_expired',
                410,
                'The property change cursor is outside the retention window; run a full reconciliation.',
            );
        }

        $firstUnavailable = PropertyChangeOutbox::query()
            ->where('status', '!=', 'published')
            ->orderBy('feed_sequence')
            ->value('feed_sequence');
        $safeSequence = $firstUnavailable === null
            ? (int) (PropertyChangeOutbox::query()->where('status', 'published')->max('feed_sequence') ?? 0)
            : ((int) $firstUnavailable - 1);

        $events = PropertyChangeOutbox::query()
            ->where('status', 'published')
            ->where('feed_sequence', '>', $after)
            ->where('feed_sequence', '<=', $safeSequence)
            ->orderBy('feed_sequence')
            ->limit($limit + 1)
            ->get();

        $hasMore = $events->count() > $limit;
        $items = $events->take($limit);
        $next = $hasMore && $items->isNotEmpty()
            ? $cursorService->encode((int) $items->last()->feed_sequence)
            : null;

        return response()->json([
            'data' => $items->map(fn (PropertyChangeOutbox $event) => [
                'event_id' => $event->id,
                'event_type' => $event->event_type,
                'external_id' => (string) $event->property_id,
                'revision' => (int) $event->revision_number,
                'committed_at' => $event->committed_at?->toIso8601String(),
                'payload' => $event->payload,
            ])->values(),
            'links' => ['next' => $next],
            'meta' => [
                'api_version' => 'v1',
                'has_more' => $hasMore,
                'safe_sequence' => $safeSequence,
            ],
        ]);
    }
}
