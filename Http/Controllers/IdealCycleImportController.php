<?php

namespace Modules\JkControl\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\JkControl\Services\IdealCycleService;

/**
 * POST /api/v1/jk/ideal-cycles — ideal cycles sent by the ERP, the same rows as
 * the spreadsheet import. 200 when every row went in, 207 when some did not.
 */
class IdealCycleImportController extends Controller
{
    public function __construct(private readonly IdealCycleService $cycles) {}

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'rows' => ['required', 'array', 'min:1', 'max:5000'],
            'rows.*' => ['array'],
        ]);

        $result = $this->cycles->upsertRows($request->input('rows'), 'erp');

        return response()->json(['data' => $result], $result['errors'] === [] ? 200 : 207);
    }
}
