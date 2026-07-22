<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Http\Requests\Site\StoreContactRequest;
use App\Models\ContactRequest;

class ContactRequestController extends Controller
{
    public function __invoke(StoreContactRequest $request)
    {
        ContactRequest::create($request->safe()->except('website'));

        return back()->with('status', 'Recibimos tu solicitud. Un asesor se pondrá en contacto contigo.');
    }
}
