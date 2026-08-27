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
                'Total de propiedades' => Property::commercial()->withTrashed()->count(),
                'Publicadas' => Property::commercial()->where('status', 'published')->count(),
                'Borradores' => Property::commercial()->where('status', 'draft')->count(),
                'En venta' => Property::commercial()->where('operation_type', OperationType::Sale)->count(),
                'En renta' => Property::commercial()->where('operation_type', OperationType::Rent)->count(),
                'Venta y renta' => Property::commercial()->where('operation_type', OperationType::SaleRent)->count(),
                'Preventa' => Property::commercial()->where('operation_type', OperationType::Presale)->count(),
                'Vendidas' => Property::commercial()->where('status', 'sold')->count(),
                'Rentadas' => Property::commercial()->where('status', 'rented')->count(),
                'Solicitudes nuevas' => ContactRequest::where('status', 'new')->count(),
            ],
            'latestProperties' => Property::commercial()->latest()->take(5)->get(),
            'latestRequests' => ContactRequest::with('property')->latest()->take(5)->get(),
        ]);
    }
}
