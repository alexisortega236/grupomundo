<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Http\Requests\Public\StoreContactRequest;
use App\Models\ContactRequest;

class ContactRequestController extends Controller
{
    public function __invoke(StoreContactRequest $request)
    {
        ContactRequest::create($request->validated());

        return back()->with('status', 'Tu solicitud fue enviada. Un asesor te contactara pronto.');
    }
}
