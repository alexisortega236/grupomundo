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

        if (
            config('services.avm_v2.public_result', false)
            && $prediction = $this->validCurrentPrediction($valuation)
        ) {
            return new PublicValuationResult(
                source: $prediction->model_name,
                estimatedValue: (float) $prediction->estimated_value,
                rangeLow: $prediction->range_low !== null ? (float) $prediction->range_low : null,
                rangeHigh: $prediction->range_high !== null ? (float) $prediction->range_high : null,
                available: true,
                fallbackReason: null,
            );
        }

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

    private function validCurrentPrediction(Valuation $valuation)
    {
        $modelNames = $valuation->property?->property_type === 'land'
            ? ['avm_v2_v1']
            : ['avm_residential_v2', 'avm_v2_v1'];

        $predictions = $valuation->modelPredictions
            ->whereIn('model_name', $modelNames)
            ->where('status', 'completed')
            ->where('eligible', true)
            ->filter(fn ($prediction) => $prediction->estimated_value !== null);

        $primaryVersion = $valuation->modelVersion?->version;

        if ($primaryVersion !== null && $predictions->contains('model_version', $primaryVersion)) {
            return $predictions->firstWhere('model_version', $primaryVersion);
        }

        return $predictions->sortByDesc('created_at')->first();
    }
}
