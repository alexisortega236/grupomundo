<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\OperationType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Pagination\Cursor;
use Illuminate\Validation\Rule;

class PropertyIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'cursor' => ['nullable', 'string', 'max:2000'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'operation_type' => ['nullable', Rule::enum(OperationType::class)],
            'property_type' => ['nullable', 'string', 'max:120'],
            'state' => ['nullable', 'string', 'max:120'],
        ];
    }

    public function prepareForValidation(): void
    {
        if ($this->query->has('cursor') && $this->input('cursor') === null) {
            $this->merge(['cursor' => '']);
        }
    }

    public function after(): array
    {
        return [function ($validator): void {
            $cursor = $this->input('cursor');

            if ($cursor === null) {
                return;
            }

            if ($cursor === '') {
                $validator->errors()->add('cursor', 'The cursor cannot be empty.');

                return;
            }

            $decoded = Cursor::fromEncoded($cursor);
            $parameters = $decoded?->toArray();

            if (! is_array($parameters)
                || ! array_key_exists('id', $parameters)
                || ! array_key_exists('_pointsToNextItems', $parameters)
                || count($parameters) !== 2
                || (! is_int($parameters['id']) && ! (is_string($parameters['id']) && ctype_digit($parameters['id'])))
                || ! is_bool($parameters['_pointsToNextItems'])) {
                $validator->errors()->add('cursor', 'The cursor is invalid.');
            }
        }];
    }
}
