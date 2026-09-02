<?php

namespace App\Http\Requests\Public;

use App\Models\ContactRequest;
use App\Models\Property;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\Rule;

class StoreContactRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'property_id' => [
                'nullable',
                Rule::exists('properties', 'id')->where(fn ($query) => $query
                    ->where('origin', Property::ORIGIN_COMMERCIAL)
                    ->where('status', 'published')
                    ->whereNotNull('published_at')),
            ],
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:160'],
            // Kept optional for the short property form; legacy/general forms remain supported.
            'message' => ['nullable', 'string', 'max:1500'],
            'website' => ['nullable', 'string', 'max:120'],
            'contact_form_token' => ['required', 'string'],
            'cf-turnstile-response' => ['nullable', 'string'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('website')) {
            $this->merge(['website' => '__honeypot_filled__']);
        }
    }

    protected function passedValidation(): void
    {
        // These checks run after field validation and before the controller can persist anything.
        if ($this->filled('website')
            || ! ContactRequest::isFormTokenValid($this->input('contact_form_token'))
            || ! $this->turnstileIsValid()) {
            throw new HttpResponseException(redirect()->back());
        }
    }

    private function turnstileIsValid(): bool
    {
        $secret = config('services.turnstile.secret_key');

        if (blank($secret)) {
            return true;
        }

        $token = $this->input('cf-turnstile-response');

        if (blank($token)) {
            return false;
        }

        try {
            $response = Http::asForm()
                ->timeout((int) config('services.turnstile.timeout', 5))
                ->post(config('services.turnstile.verify_url'), [
                    'secret' => $secret,
                    'response' => $token,
                    'remoteip' => $this->ip(),
                ]);

            return $response->successful() && (bool) $response->json('success');
        } catch (ConnectionException) {
            return false;
        }
    }
}
