<?php

use App\Http\Controllers\Admin\AmenityController as AdminAmenityController;
use App\Http\Controllers\Admin\ContactRequestController as AdminContactRequestController;
use App\Http\Controllers\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Admin\PropertyController as AdminPropertyController;
use App\Http\Controllers\Admin\UserController as AdminUserController;
use App\Http\Controllers\Admin\ValuationController as AdminValuationController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\Public\ContactRequestController;
use App\Http\Controllers\Public\HomeController;
use App\Http\Controllers\Public\LocationController;
use App\Http\Controllers\Public\PageController;
use App\Http\Controllers\Public\PropertyController;
use App\Http\Controllers\Public\ValuationController;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->name('home');
Route::get('/propiedades', [PropertyController::class, 'index'])->name('properties.index');
Route::get('/propiedades/{property:slug}', [PropertyController::class, 'show'])->name('properties.show');
Route::get('/valuador', [ValuationController::class, 'create'])->name('valuation.create');
Route::get('/valuador/geocode', [LocationController::class, 'geocode'])->middleware('throttle:20,1')->name('valuation.geocode');
Route::get('/valuador/reverse-geocode', [LocationController::class, 'reverse'])->middleware('throttle:10,1')->name('valuation.reverse-geocode');
Route::post('/valuador', [ValuationController::class, 'store'])->middleware('throttle:10,1')->name('valuation.store');
Route::get('/valuador/{uuid}', [ValuationController::class, 'show'])->name('valuation.show');
Route::get('/servicios', [PageController::class, 'services'])->name('services');
Route::get('/nosotros', [PageController::class, 'about'])->name('about');
Route::get('/contacto', [PageController::class, 'contact'])->name('contact');
Route::post('/solicitudes', ContactRequestController::class)->middleware('throttle:contact')->name('contact-requests.store');
Route::get('/sitemap.xml', [PageController::class, 'sitemap'])->name('sitemap');

Route::get('/dashboard', function () {
    return redirect()->route('admin.dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware(['auth', 'staff'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/', AdminDashboardController::class)->name('dashboard');
    Route::patch('propiedades/{property}/toggle-published', [AdminPropertyController::class, 'togglePublished'])->name('properties.toggle-published');
    Route::patch('propiedades/{property}/archive', [AdminPropertyController::class, 'archive'])->name('properties.archive');
    Route::post('propiedades/{property}/restore', [AdminPropertyController::class, 'restore'])->name('properties.restore');
    Route::delete('propiedades/{property}/force', [AdminPropertyController::class, 'forceDelete'])->name('properties.force-delete');
    Route::resource('propiedades', AdminPropertyController::class)->parameters(['propiedades' => 'property'])->names('properties');
    Route::resource('valuaciones', AdminValuationController::class)->parameters(['valuaciones' => 'valuation'])->only(['index', 'create', 'store', 'show'])->names('valuations');
    Route::resource('amenidades', AdminAmenityController::class)->parameters(['amenidades' => 'amenity'])->except('show')->names('amenities');
    Route::resource('solicitudes', AdminContactRequestController::class)->parameters(['solicitudes' => 'contactRequest'])->only(['index', 'show', 'update', 'destroy'])->names('contact-requests');
    Route::resource('usuarios', AdminUserController::class)->parameters(['usuarios' => 'user'])->middleware('can:admin-only')->names('users');
});

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
