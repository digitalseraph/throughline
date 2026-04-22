<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Admin\AdminLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ImpersonationStopController extends Controller
{
    public function __invoke(Request $request, AdminLogger $logger): RedirectResponse
    {
        $originalId = $request->session()->get('original_user_id');
        abort_unless($originalId, 404, 'Not currently impersonating.');

        $admin = User::find($originalId);
        abort_unless($admin, 404);

        $impersonated = $request->user();
        $logger->record($admin, 'user.impersonation_stopped', $impersonated, [], $request->ip());

        Auth::login($admin);
        $request->session()->forget('original_user_id');
        $request->session()->regenerate();

        return redirect()->route('admin.users.show', $impersonated->id);
    }
}
