<?php

namespace App\Services;

use App\Enums\PropertyStatus;
use App\Http\Resources\Api\V1\PropertyResource;
use App\Models\Amenity;
use App\Models\Property;
use App\Models\PropertyChangeOutbox;
use App\Models\PropertyRevision;
use App\Models\PropertyType;
use Closure;
use Illuminate\Support\Facades\DB;
use UnitEnum;
use RuntimeException;

class PropertyRevisionService
{
    private const SEQUENCE_NAME = 'property_changes';

    /** @return mixed */
    public function __construct(private readonly TransactionRetry $transactions)
    {
    }

    public function create(Closure $mutation): mixed
    {
        return $this->transactions->run(function (TransactionSideEffects $effects) use ($mutation): mixed {
            $property = $mutation($effects);

            if (! $property instanceof Property) {
                return $property;
            }

            $property = Property::withTrashed()->findOrFail($property->getKey());
            $this->recordIfNeeded(null, $property);

            return $property;
        }, 'property_revision.create');
    }

    /** @return mixed */
    public function mutate(Property $property, Closure $mutation): mixed
    {
        return $this->transactions->run(function (TransactionSideEffects $effects) use ($property, $mutation): mixed {
            $locked = Property::withTrashed()->whereKey($property->getKey())->lockForUpdate()->firstOrFail();
            $before = $this->snapshot($locked);
            $result = $mutation($locked, $effects);
            $after = Property::withTrashed()->findOrFail($locked->getKey());

            $this->recordIfNeeded($before, $after);

            return $result ?? $after;
        }, 'property_revision.mutate');
    }

    public function softDelete(Property $property): void
    {
        $this->mutate($property, function (Property $locked): void {
            $locked->delete();
        });
    }

    public function restore(Property $property): void
    {
        $this->mutate($property, function (Property $locked): void {
            $locked->restore();
        });
    }

    public function forceDelete(Property $property): void
    {
        $this->transactions->run(function () use ($property): void {
            $locked = Property::withTrashed()->whereKey($property->getKey())->lockForUpdate()->firstOrFail();
            $before = $this->snapshot($locked);

            if ($before['visible']) {
                $revision = $this->nextRevisionNumber($locked->getKey());
                $this->recordEvent(
                    $locked->getKey(),
                    $revision,
                    'property.deleted',
                    false,
                    $this->retirementPayload($locked, $revision, 'property.deleted', 'deleted'),
                );
            }

            $locked->forceDelete();
        }, 'property_revision.force_delete');
    }

    public function updatePropertyType(PropertyType $propertyType, array $data): void
    {
        $this->transactions->run(function () use ($propertyType, $data): void {
            $type = PropertyType::query()->whereKey($propertyType->getKey())->lockForUpdate()->firstOrFail();
            $oldName = $type->name;
            $type->update($data);

            if ($oldName === $type->name) {
                return;
            }

            $properties = Property::withTrashed()
                ->where('property_type_id', $type->getKey())
                ->lockForUpdate()
                ->get();

            foreach ($properties as $property) {
                $before = $this->snapshot($property);
                $property->update(['property_type' => $type->name]);
                $after = Property::withTrashed()->findOrFail($property->getKey());
                $this->recordIfNeeded($before, $after, ['type_changed']);
            }
        }, 'property_revision.update_property_type');
    }

    public function updateAmenity(Amenity $amenity, array $data): void
    {
        $this->transactions->run(function () use ($amenity, $data): void {
            $locked = Amenity::query()->whereKey($amenity->getKey())->lockForUpdate()->firstOrFail();
            $properties = $locked->properties()->withTrashed()->lockForUpdate()->get();
            $before = $properties->mapWithKeys(fn (Property $property) => [$property->getKey() => $this->snapshot($property)]);

            $locked->update($data);

            foreach ($properties as $property) {
                $after = Property::withTrashed()->findOrFail($property->getKey());
                $this->recordIfNeeded($before[$property->getKey()], $after, ['amenities_changed']);
            }
        }, 'property_revision.update_amenity');
    }

    public function deleteAmenity(Amenity $amenity): void
    {
        $this->transactions->run(function () use ($amenity): void {
            $locked = Amenity::query()->whereKey($amenity->getKey())->lockForUpdate()->firstOrFail();
            $properties = $locked->properties()->withTrashed()->lockForUpdate()->get();
            $before = $properties->mapWithKeys(fn (Property $property) => [$property->getKey() => $this->snapshot($property)]);

            $locked->delete();

            foreach ($properties as $property) {
                $after = Property::withTrashed()->findOrFail($property->getKey());
                $this->recordIfNeeded($before[$property->getKey()], $after, ['amenities_changed']);
            }
        }, 'property_revision.delete_amenity');
    }

    private function recordIfNeeded(?array $before, Property $property, array $forcedEvents = []): void
    {
        $after = $this->snapshot($property);

        if ($before === null) {
            if ($after['visible']) {
                $this->recordEvent($property->getKey(), $this->nextRevisionNumber($property->getKey()), 'property.published', true, $this->publicPayload($property));
            }

            return;
        }

        if ($before['visible'] && ! $after['visible']) {
            $revision = $this->nextRevisionNumber($property->getKey());
            $reason = $this->retirementReason($after);
            $eventType = $reason === 'deleted' ? 'property.deleted' : 'property.unpublished';
            $this->recordEvent(
                $property->getKey(),
                $revision,
                $eventType,
                false,
                $this->retirementPayload($property, $revision, $eventType, $reason),
            );

            return;
        }

        if (! $before['visible'] && $after['visible']) {
            $this->recordEvent($property->getKey(), $this->nextRevisionNumber($property->getKey()), 'property.published', true, $this->publicPayload($property));

            return;
        }

        if (! $before['visible'] || ! $after['visible']) {
            return;
        }

        $events = $forcedEvents;
        if ($before['fields'] !== $after['fields']) {
            $events[] = 'property.updated';
        }
        if ($before['images'] !== $after['images']) {
            $events[] = 'property.images_changed';
        }
        if ($before['amenities'] !== $after['amenities']) {
            $events[] = 'property.amenities_changed';
        }
        if ($before['type'] !== $after['type']) {
            $events[] = 'property.type_changed';
        }

        foreach (array_values(array_unique($events)) as $eventType) {
            $this->recordEvent($property->getKey(), $this->nextRevisionNumber($property->getKey()), $eventType, true, $this->publicPayload($property));
        }
    }

    private function snapshot(Property $property): array
    {
        $property->loadMissing(['propertyType', 'amenities', 'images']);

        return [
            'visible' => $property->origin === Property::ORIGIN_COMMERCIAL
                && $property->status === PropertyStatus::Published
                && $property->published_at !== null
                && $property->deleted_at === null,
            'fields' => $this->normalise([
                'title' => $property->title,
                'slug' => $property->slug,
                'short_description' => $property->short_description,
                'description' => $property->description,
                'operation_type' => $property->operation_type,
                'price' => $property->price,
                'currency' => $property->currency,
                'rent_period' => $property->rent_period,
                'street' => $property->street,
                'exterior_number' => $property->exterior_number,
                'interior_number' => $property->interior_number,
                'neighborhood' => $property->neighborhood,
                'locality' => $property->locality,
                'municipality' => $property->municipality,
                'city' => $property->city,
                'state' => $property->state,
                'postal_code' => $property->postal_code,
                'bedrooms' => $property->bedrooms,
                'full_bathrooms' => $property->full_bathrooms,
                'half_bathrooms' => $property->half_bathrooms,
                'bathrooms' => $property->bathrooms,
                'parking_spaces' => $property->parking_spaces,
                'construction_area' => $property->construction_area,
                'land_area' => $property->land_area,
                'property_age_years' => $property->property_age_years,
                'latitude' => $property->latitude,
                'longitude' => $property->longitude,
                'show_exact_location' => $property->show_exact_location,
                'status' => $property->status,
                'deleted_at' => $property->deleted_at?->toIso8601String(),
            ]),
            'type' => [$property->property_type_id, $property->property_type, $property->propertyType?->name, $property->propertyType?->classification],
            'amenities' => $property->amenities->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all(),
            'images' => $property->images->map(fn ($image) => [
                'id' => (int) $image->id,
                'path' => $image->path,
                'card_path' => $image->card_path,
                'thumb_path' => $image->thumb_path,
                'alt_text' => $image->alt_text,
                'position' => (int) $image->position,
                'is_cover' => (bool) $image->is_cover,
                'width' => $image->width,
                'height' => $image->height,
            ])->values()->all(),
        ];
    }

    private function publicPayload(Property $property): array
    {
        $property->load(['propertyType', 'amenities', 'images']);

        return (new PropertyResource($property))->resolve();
    }

    private function retirementPayload(Property $property, int $revision, string $eventType, string $reason): array
    {
        return [
            'external_id' => (int) $property->getKey(),
            'revision' => $revision,
            'event_type' => $eventType,
            'reason' => $reason,
            'timestamp' => now()->toIso8601String(),
        ];
    }

    private function retirementReason(array $snapshot): string
    {
        if (($snapshot['fields']['status'] ?? null) === PropertyStatus::Archived->value) {
            return 'archived';
        }
        if (($snapshot['fields']['status'] ?? null) === PropertyStatus::Sold->value) {
            return 'sold';
        }
        if (($snapshot['fields']['status'] ?? null) === PropertyStatus::Rented->value) {
            return 'rented';
        }
        if (($snapshot['fields']['status'] ?? null) === PropertyStatus::Draft->value) {
            return 'draft';
        }
        if (($snapshot['fields']['deleted_at'] ?? null) !== null) {
            return 'deleted';
        }

        return 'unpublished';
    }

    private function nextRevisionNumber(int|string $propertyId): int
    {
        return ((int) PropertyRevision::query()->where('property_id', $propertyId)->max('revision_number')) + 1;
    }

    private function recordEvent(int|string $propertyId, int $revisionNumber, string $eventType, bool $visible, array $payload): void
    {
        $sequence = DB::table('property_change_sequences')->where('name', self::SEQUENCE_NAME)->lockForUpdate()->first();
        if (! $sequence) {
            throw new RuntimeException('Property change sequence is not configured.');
        }
        $feedSequence = ((int) $sequence->last_value) + 1;

        DB::table('property_change_sequences')->where('name', self::SEQUENCE_NAME)->update([
            'last_value' => $feedSequence,
            'updated_at' => now(),
        ]);

        $committedAt = now();
        PropertyRevision::query()->create([
            'property_id' => $propertyId,
            'revision_number' => $revisionNumber,
            'event_type' => $eventType,
            'is_visible' => $visible,
            'payload' => $payload,
            'committed_at' => $committedAt,
        ]);

        PropertyChangeOutbox::query()->create([
            'property_id' => $propertyId,
            'revision_number' => $revisionNumber,
            'feed_sequence' => $feedSequence,
            'event_type' => $eventType,
            'payload' => $payload,
            'status' => 'pending',
            'available_at' => $committedAt,
            'committed_at' => $committedAt,
        ]);
    }

    private function normalise(array $values): array
    {
        return array_map(function ($value) {
            if ($value instanceof UnitEnum) {
                return $value instanceof \BackedEnum ? $value->value : $value->name;
            }

            return $value;
        }, $values);
    }
}
