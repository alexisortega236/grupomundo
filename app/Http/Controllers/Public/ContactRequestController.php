<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Http\Requests\Public\StoreContactRequest;
use App\Models\ContactRequest;
use App\Models\Property;
use App\Models\Valuation;

class ContactRequestController extends Controller
{
    public function __invoke(StoreContactRequest $request)
    {
        $data = $request->safe()->only([
            'property_id',
            'name',
            'phone',
            'email',
            'message',
        ]);

        if ($request->filled('valuation_uuid')) {
            $valuation = Valuation::with('property')
                ->where('uuid', $request->string('valuation_uuid')->toString())
                ->where('source', 'public')
                ->firstOrFail();
            $data['valuation_id'] = $valuation->id;
            $data['property_id'] = $valuation->property_id;
        }

        if (blank($data['message'] ?? null)) {
            $title = isset($data['property_id'])
                ? Property::find($data['property_id'])?->title
                : null;
            $data['message'] = $title
                ? 'Solicitud de contacto desde la propiedad: '.$title
                : 'Solicitud de contacto general';
        }

        ContactRequest::create($data);

        return back()->with('status', 'Tu solicitud fue enviada. Un asesor te contactará pronto.');
    }
}
