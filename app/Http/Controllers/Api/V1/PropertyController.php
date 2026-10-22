<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\PropertyIndexRequest;
use App\Http\Resources\Api\V1\PropertyResource;
use App\Models\Property;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PropertyController extends Controller
{
    public function index(PropertyIndexRequest $request): AnonymousResourceCollection
    {
        $validated = $request->validated();
        $perPage = (int) ($validated['per_page'] ?? 50);

        $properties = Property::published()
            ->with(['propertyType', 'amenities', 'images'])
            ->when(isset($validated['operation_type']), fn ($query) => $query->where('operation_type', $validated['operation_type']))
            ->when(isset($validated['property_type']), fn ($query) => $query->where('property_type', $validated['property_type']))
            ->when(isset($validated['state']), fn ($query) => $query->where('state', $validated['state']))
            ->orderBy('id')
            ->cursorPaginate($perPage, ['*'], 'cursor', $validated['cursor'] ?? null);

        return PropertyResource::collection($properties)->additional([
            'meta' => [
                'api_version' => 'v1',
                'pagination_consistency' => 'live_catalog',
                'pagination_note' => 'Cursor pagination is not a consistent snapshot. Use the future snapshot delivery for complete imports.',
            ],
        ]);
    }

    public function show(int $id): PropertyResource|JsonResponse
    {
        $property = Property::published()
            ->with(['propertyType', 'amenities', 'images'])
            ->find($id);

        if (! $property) {
            return response()->json([
                'error' => [
                    'code' => 'not_found',
                    'message' => 'The requested resource was not found.',
                ],
            ], 404);
        }

        return new PropertyResource($property);
    }
}
