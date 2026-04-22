<?php

namespace App\Http\Controllers\Admin;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ImpersonationController extends BaseController
{
    public function start(Request $request, User $user): RedirectResponse
    {
        abort_if($request->session()->has('original_user_id'), 403, 'Nested impersonation not allowed.');
        abort_if($user->role === 'admin', 403, 'Cannot impersonate another admin.');

        /** @var User $admin */
        $admin = $request->user();

        $this->audit('user.impersonation_started', $user, ['admin_id' => $admin->id]);

        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->put('original_user_id', $admin->id); // regenerate wipes; re-set after

        return redirect('/dashboard');
    }
}
