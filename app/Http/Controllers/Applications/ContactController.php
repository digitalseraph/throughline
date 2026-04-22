<?php

namespace App\Http\Controllers\Applications;

use App\Http\Controllers\Controller;
use App\Http\Requests\Applications\StoreContactRequest;
use App\Http\Requests\Applications\UpdateContactRequest;
use App\Models\Application;
use App\Models\ApplicationContact;
use App\Services\Applications\EventWriter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ContactController extends Controller
{
    public function __construct(protected EventWriter $events) {}

    public function store(StoreContactRequest $request, Application $application): RedirectResponse
    {
        DB::transaction(function () use ($request, $application) {
            $contact = $application->contacts()->create($request->validated());
            $this->events->logContactAdded($application, $contact);
        });

        return redirect()->back()->with('status', 'Contact added.');
    }

    public function update(UpdateContactRequest $request, Application $application, ApplicationContact $contact): RedirectResponse
    {
        abort_unless($contact->application_id === $application->id, 404);

        $contact->update($request->validated());

        return redirect()->back()->with('status', 'Contact updated.');
    }

    public function destroy(Application $application, ApplicationContact $contact, Request $request): RedirectResponse
    {
        abort_unless((int) $application->user_id === $request->user()->id, 403);
        abort_unless($contact->application_id === $application->id, 404);

        $contact->delete();

        return redirect()->back()->with('status', 'Contact removed.');
    }
}
