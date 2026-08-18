<?php

namespace App\Services\Valuation;

use App\Data\PublicValuationResult;
use App\Enums\ValuationStatus;
use App\Models\Valuation;

class PublicValuationResultResolver
{
    public function resolve(Valuation $valuation): PublicValuationResult
    {
        $property = $valuation->property;

        if ($property?->property_type === 'land') {
            return new PublicValuationResult(
                source: 'unavailable',
                estimatedValue: null,
                rangeLow: null,
                rangeHigh: null,
                available: false,
                fallbackReason: 'land_not_supported',
            );
        }

        if (
            config('services.avm_v2.public_result', false)
            && in_array($property?->property_type, ['house', 'apartment'], true)
            && $prediction = $this->validResidentialV2Prediction($valuation)
        ) {
            return new PublicValuationResult(
                source: 'residential_v2',
                estimatedValue: (float) $prediction->estimated_value,
                rangeLow: $prediction->range_low !== null ? (float) $prediction->range_low : null,
                rangeHigh: $prediction->range_high !== null ? (float) $prediction->range_high : null,
                available: true,
                fallbackReason: null,
            );
        }

        if ($valuation->status === ValuationStatus::Completed && $valuation->estimated_value !== null) {
            return new PublicValuationResult(
                source: 'legacy',
                estimatedValue: (float) $valuation->estimated_value,
                rangeLow: $valuation->lower_bound !== null ? (float) $valuation->lower_bound : null,
                rangeHigh: $valuation->upper_bound !== null ? (float) $valuation->upper_bound : null,
                available: true,
                fallbackReason: null,
            );
        }

        return new PublicValuationResult(
            source: 'unavailable',
            estimatedValue: null,
            rangeLow: null,
            rangeHigh: null,
            available: false,
            fallbackReason: $valuation->error_code ?: 'valuation_unavailable',
        );
    }

    private function validResidentialV2Prediction(Valuation $valuation)
    {
        return $valuation->modelPredictions
            ->where('model_name', 'avm_residential_v2')
            ->where('status', 'completed')
            ->where('eligible', true)
            ->filter(fn ($prediction) => $prediction->estimated_value !== null)
            ->sortByDesc('created_at')
            ->first();
    }
}
