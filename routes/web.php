<?php

use App\Http\Controllers\Admin;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\Site;
use Illuminate\Support\Facades\Route;

Route::get('/', Site\HomeController::class)->name('home');
Route::get('/propiedades', [Site\PropertyController::class, 'index'])->name('properties.index');
Route::get('/propiedades/{property:slug}', [Site\PropertyController::class, 'show'])->name('properties.show');
Route::post('/solicitudes', Site\ContactRequestController::class)->middleware('throttle:5,1')->name('contact.requests.store');
Route::view('/servicios', 'public.services')->name('services');
Route::view('/nosotros', 'public.about')->name('about');
Route::view('/contacto', 'public.contact')->name('contact');

Route::redirect('/dashboard', '/admin')->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

Route::middleware(['auth', 'admin.access'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/', Admin\DashboardController::class)->name('dashboard');
    Route::patch('properties/{property}/toggle-published', [Admin\PropertyController::class, 'togglePublished'])->name('properties.toggle-published');
    Route::patch('properties/{property}/archive', [Admin\PropertyController::class, 'archive'])->name('properties.archive');
    Route::post('properties/{property}/restore', [Admin\PropertyController::class, 'restore'])->withTrashed()->name('properties.restore');
    Route::delete('properties/{property}/force', [Admin\PropertyController::class, 'forceDelete'])->withTrashed()->name('properties.force-delete');
    Route::resource('properties', Admin\PropertyController::class);
    Route::resource('amenities', Admin\AmenityController::class)->except('show');
    Route::patch('contact-requests/{contactRequest}/status', [Admin\ContactRequestController::class, 'updateStatus'])->name('contact-requests.status');
    Route::resource('contact-requests', Admin\ContactRequestController::class)->only(['index', 'show', 'destroy']);
    Route::resource('users', Admin\UserController::class)->middleware('can:admin-only')->except('show');
});

require __DIR__.'/auth.php';
