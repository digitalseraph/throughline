<?php

namespace App\Http\Controllers\SearchProfiles;

use App\Http\Controllers\Controller;
use App\Http\Requests\SearchProfiles\StoreSearchProfileRequest;
use App\Http\Requests\SearchProfiles\UpdateSearchProfileRequest;
use App\Models\SearchProfile;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class SearchProfileController extends Controller
{
    public function index(): Response
    {
        $profiles = auth()->user()->searchProfiles()
            ->with('resume:id,name')
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get();

        return Inertia::render('search-profiles/Index', [
            'profiles' => $profiles,
        ]);
    }

    public function create(): Response
    {
        return $this->renderForm(new SearchProfile([
            'remote_mode' => 'any',
            'is_active' => true,
        ]));
    }

    public function edit(SearchProfile $profile): Response
    {
        abort_unless($profile->user_id === auth()->id(), 403);

        return $this->renderForm($profile);
    }

    public function store(StoreSearchProfileRequest $request): RedirectResponse
    {
        $profile = auth()->user()->searchProfiles()->create($request->validated());

        return redirect()->route('search-profiles.index')->with('status', "Created '{$profile->name}'.");
    }

    public function update(UpdateSearchProfileRequest $request, SearchProfile $profile): RedirectResponse
    {
        $profile->update($request->validated());

        return redirect()->route('search-profiles.index')->with('status', 'Profile updated.');
    }

    public function destroy(SearchProfile $profile): RedirectResponse
    {
        abort_unless($profile->user_id === auth()->id(), 403);
        $profile->delete();

        return redirect()->route('search-profiles.index')->with('status', 'Profile deleted.');
    }

    protected function renderForm(SearchProfile $profile): Response
    {
        return Inertia::render('search-profiles/Edit', [
            'profile' => $profile,
            'resumes' => auth()->user()->resumes()->get(['id', 'name']),
        ]);
    }
}
