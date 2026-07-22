<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    public function index() { return view('admin.users.index', ['users' => User::orderBy('name')->paginate(20)]); }
    public function create() { return view('admin.users.form', ['user' => new User]); }
    public function edit(User $user) { return view('admin.users.form', compact('user')); }

    public function store(Request $request)
    {
        $data = $request->validate(['name' => ['required'], 'email' => ['required', 'email', 'unique:users'], 'role' => ['required', 'in:admin,editor'], 'password' => ['required', 'min:8']]);
        $data['password'] = Hash::make($data['password']);
        User::create($data);
        return redirect()->route('admin.users.index')->with('status', 'Usuario creado.');
    }

    public function update(Request $request, User $user)
    {
        $data = $request->validate(['name' => ['required'], 'email' => ['required', 'email', 'unique:users,email,'.$user->id], 'role' => ['required', 'in:admin,editor'], 'password' => ['nullable', 'min:8']]);
        if ($data['password'] ?? false) $data['password'] = Hash::make($data['password']); else unset($data['password']);
        $user->update($data);
        return redirect()->route('admin.users.index')->with('status', 'Usuario actualizado.');
    }

    public function destroy(User $user)
    {
        abort_if($user->is(auth()->user()), 422);
        $user->delete();
        return back()->with('status', 'Usuario eliminado.');
    }
}
