<?php

namespace App\Http\Controllers\Admin;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends BaseController
{
    public function index(Request $request): Response
    {
        $q = $request->string('q')->trim()->toString();

        $query = User::query()->select('id', 'name', 'email', 'role', 'last_login_at', 'deactivated_at')
            ->withCount(['resumes', 'applications'])
            ->orderBy('email');

        if ($q !== '') {
            $query->where(function ($w) use ($q) {
                $w->where('email', 'ilike', "%{$q}%")
                    ->orWhere('name', 'ilike', "%{$q}%");
            });
        }

        return Inertia::render('admin/users/Index', [
            'users' => $query->paginate(25)->withQueryString(),
            'filters' => ['q' => $q],
        ]);
    }

    public function show(User $user): Response
    {
        $user->loadCount(['resumes', 'applications']);

        return Inertia::render('admin/users/Show', [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'last_login_at' => $user->last_login_at,
                'deactivated_at' => $user->deactivated_at,
                'resume_count' => $user->resumes_count,
                'application_count' => $user->applications_count,
            ],
        ]);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate([
            'role' => ['sometimes', Rule::in(['user', 'admin'])],
            'deactivate' => ['sometimes', 'boolean'],
            'reactivate' => ['sometimes', 'boolean'],
        ]);

        if (array_key_exists('role', $data) && $data['role'] !== $user->role) {
            $from = $user->role;
            $user->role = $data['role'];
            $user->save();
            $this->audit('user.role_changed', $user, ['from' => $from, 'to' => $data['role']]);
        }

        if (! empty($data['deactivate']) && ! $user->deactivated_at) {
            $user->deactivated_at = now();
            $user->save();
            $this->audit('user.deactivated', $user);
        }

        if (! empty($data['reactivate']) && $user->deactivated_at) {
            $user->deactivated_at = null;
            $user->save();
            $this->audit('user.reactivated', $user);
        }

        return redirect()->back()->with('status', 'User updated.');
    }
}
