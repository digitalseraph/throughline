<?php

namespace App\Http\Controllers\Admin;

use App\Models\InfrastructureCost;
use App\Services\Admin\InfrastructureCosts;
use App\Services\SiteSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CostController extends BaseController
{
    public function index(InfrastructureCosts $costs): Response
    {
        $settings = app(SiteSettings::class);

        $lineItems = InfrastructureCost::query()
            ->orderBy('category')
            ->orderBy('name')
            ->get()
            ->map(fn ($c) => [
                'id' => $c->id,
                'name' => $c->name,
                'category' => $c->category,
                'amount_usd' => (float) $c->amount_usd,
                'billing_cycle' => $c->billing_cycle,
                'notes' => $c->notes,
                'is_active' => $c->is_active,
                'monthly_amount' => $c->monthlyAmount(),
            ])
            ->values();

        return Inertia::render('admin/Costs', [
            'lineItems' => $lineItems,
            'fixedMonthlyUsd' => $costs->fixedMonthlyTotalUsd(),
            'aiMonthToDateUsd' => $costs->aiSpendForMonth(\Carbon\CarbonImmutable::now()),
            'aiRolling30DayUsd' => $costs->aiSpendRolling30Days(),
            'currentMonthTotalUsd' => $costs->currentMonthTotalUsd(),
            'thresholdUsd' => $costs->thresholdUsd(),
            'thresholdLastNotifiedMonth' => (string) $settings->get('cost_alert_last_notified_month', ''),
            'history' => $costs->historicalChart(12),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'category' => 'nullable|string|max:60',
            'amount_usd' => 'required|numeric|min:0|max:999999.99',
            'billing_cycle' => 'required|in:monthly,annual',
            'notes' => 'nullable|string|max:1000',
            'is_active' => 'sometimes|boolean',
        ]);

        $cost = InfrastructureCost::create($data + [
            'is_active' => $data['is_active'] ?? true,
            'updated_by_user_id' => auth()->id(),
        ]);

        $this->audit('cost.created', $cost, ['name' => $cost->name, 'amount_usd' => (float) $cost->amount_usd]);

        return back()->with('flash', "Added {$cost->name}.");
    }

    public function update(Request $request, InfrastructureCost $cost): RedirectResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'category' => 'nullable|string|max:60',
            'amount_usd' => 'required|numeric|min:0|max:999999.99',
            'billing_cycle' => 'required|in:monthly,annual',
            'notes' => 'nullable|string|max:1000',
            'is_active' => 'required|boolean',
        ]);

        $cost->update($data + ['updated_by_user_id' => auth()->id()]);

        $this->audit('cost.updated', $cost, ['name' => $cost->name, 'amount_usd' => (float) $cost->amount_usd]);

        return back()->with('flash', "Updated {$cost->name}.");
    }

    public function destroy(InfrastructureCost $cost): RedirectResponse
    {
        $name = $cost->name;
        $id = $cost->id;
        $cost->delete();

        $this->audit('cost.deleted', null, ['id' => $id, 'name' => $name]);

        return back()->with('flash', "Deleted {$name}.");
    }

    public function updateThreshold(Request $request, SiteSettings $settings): RedirectResponse
    {
        $data = $request->validate([
            'threshold_usd' => 'required|numeric|min:0|max:999999.99',
        ]);

        $settings->set('cost_alert_threshold_usd', number_format((float) $data['threshold_usd'], 2, '.', ''), auth()->id());

        $this->audit('cost.threshold.updated', null, ['threshold_usd' => (float) $data['threshold_usd']]);

        return back()->with('flash', 'Threshold updated.');
    }
}
