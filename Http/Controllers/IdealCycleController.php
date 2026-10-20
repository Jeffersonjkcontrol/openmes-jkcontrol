<?php

namespace Modules\JkControl\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Modules\JkControl\Models\IdealCycle;
use Modules\JkControl\Services\IdealCycleService;

/** Ideal cycles typed in on the machine OEE screen. */
class IdealCycleController extends Controller
{
    public function __construct(private readonly IdealCycleService $cycles) {}

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'product_type_id' => ['nullable', 'integer', 'exists:product_types,id', 'required_without:workstation_id'],
            'workstation_id' => ['nullable', 'integer', 'exists:workstations,id', 'required_without:product_type_id'],
            'cycle_seconds' => ['required', 'numeric', 'gt:0', 'max:100000'],
        ]);

        $this->cycles->save($data['product_type_id'] ?? null, $data['workstation_id'] ?? null, (float) $data['cycle_seconds'], 'manual');

        return back()->with('success', __('Ideal cycle saved.'));
    }

    public function destroy(IdealCycle $idealCycle): RedirectResponse
    {
        $idealCycle->delete();

        return back()->with('success', __('Ideal cycle removed.'));
    }
}
