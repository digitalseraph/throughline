<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminActivityLog;
use App\Models\User;
use App\Services\Admin\AdminLogger;

// Note (Laravel 11+): The project's base Controller is a plain abstract class that does
// not extend Illuminate\Routing\Controller, so $this->middleware() is not available.
// EnsureAdmin + auth middleware are applied at the route group level (see routes/web.php).
// This class's sole value is the $this->audit() convenience helper.
class BaseController extends Controller
{
    protected function audit(string $action, mixed $target = null, array $payload = []): ?AdminActivityLog
    {
        /** @var User|null $admin */
        $admin = auth()->user();
        if (! $admin) {
            return null;
        }

        return app(AdminLogger::class)->record($admin, $action, $target, $payload, request()->ip());
    }
}
