<?php

namespace App\Data\Avm;

class AvmPrediction
{
    public function __construct(
        public readonly ?float $estimatedValue,
        public readonly ?float $estimatedPriceM2,
        public readonly ?float $lowerBound,
        public readonly ?float $upperBound,
        public readonly ?float $confidenceScore,
        public readonly ?string $modelVersion,
        public readonly ?string $currency = null,
        public readonly ?string $zoneInferred = null,
        public readonly array $derivedFeatures = [],
        public readonly array $pois = [],
        public readonly array $rawResponse = [],
    ) {
    }

    public static function fromArray(array $data): self
    {
        if (array_key_exists('precio_estimado', $data)) {
            return new self(
                estimatedValue: isset($data['precio_estimado']) ? (float) $data['precio_estimado'] : null,
                estimatedPriceM2: null,
                lowerBound: null,
                upperBound: null,
                confidenceScore: null,
                modelVersion: 'legacy-gcp-joblib',
                currency: $data['moneda'] ?? 'MXN',
                zoneInferred: $data['zona_inferida'] ?? null,
                derivedFeatures: $data['features_derivadas'] ?? [],
                pois: $data['pois'] ?? [],
                rawResponse: $data,
            );
        }

        return new self(
            estimatedValue: isset($data['estimated_value']) ? (float) $data['estimated_value'] : null,
            estimatedPriceM2: isset($data['estimated_price_m2']) ? (float) $data['estimated_price_m2'] : null,
            lowerBound: isset($data['lower_bound']) ? (float) $data['lower_bound'] : null,
            upperBound: isset($data['upper_bound']) ? (float) $data['upper_bound'] : null,
            confidenceScore: isset($data['confidence_score']) ? (float) $data['confidence_score'] : null,
            modelVersion: $data['model_version'] ?? null,
            rawResponse: $data,
        );
    }
}
