<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ContactRequest;
use Illuminate\Http\Request;

class ContactRequestController extends Controller
{
    public function index(Request $request)
    {
        return view('admin.contact-requests.index', [
            'requests' => ContactRequest::with('property')->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))->latest()->paginate(20)->withQueryString(),
        ]);
    }

    public function show(ContactRequest $contactRequest)
    {
        return view('admin.contact-requests.show', ['requestItem' => $contactRequest->load('property')]);
    }

    public function updateStatus(Request $request, ContactRequest $contactRequest)
    {
        $request->validate(['status' => ['required', 'in:new,contacted,closed']]);
        $contactRequest->update(['status' => $request->status]);
        return back()->with('status', 'Estado actualizado.');
    }

    public function destroy(ContactRequest $contactRequest)
    {
        $contactRequest->delete();
        return back()->with('status', 'Solicitud eliminada.');
    }
}
