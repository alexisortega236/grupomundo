<?php

namespace App\Http\Controllers\Admin;

use App\Enums\OperationType;
use App\Http\Controllers\Controller;
use App\Models\ContactRequest;
use App\Models\Property;

class DashboardController extends Controller
{
    public function __invoke()
    {
        return view('admin.dashboard', [
            'stats' => [
                'Total de propiedades' => Property::withTrashed()->count(),
                'Publicadas' => Property::where('status', 'published')->count(),
                'Borradores' => Property::where('status', 'draft')->count(),
                'En venta' => Property::where('operation_type', OperationType::Sale)->count(),
                'En renta' => Property::where('operation_type', OperationType::Rent)->count(),
                'Venta/Renta' => Property::where('operation_type', OperationType::SaleRent)->count(),
                'Preventa' => Property::where('operation_type', OperationType::Presale)->count(),
                'Vendidas' => Property::where('status', 'sold')->count(),
                'Rentadas' => Property::where('status', 'rented')->count(),
                'Solicitudes nuevas' => ContactRequest::where('status', 'new')->count(),
            ],
            'latestProperties' => Property::latest()->take(5)->get(),
            'latestRequests' => ContactRequest::with('property')->latest()->take(5)->get(),
        ]);
    }
}
