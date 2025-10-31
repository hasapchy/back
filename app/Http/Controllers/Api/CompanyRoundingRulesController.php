<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CompanyRoundingRule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class CompanyRoundingRulesController extends Controller
{
    public function index(Request $request)
    {
        $companyId = $request->header('X-Company-ID');
        $rules = CompanyRoundingRule::where('company_id', $companyId)->get();
        return response()->json(['data' => $rules]);
    }

    public function upsert(Request $request)
    {
        $companyId = $request->header('X-Company-ID');

        $validator = Validator::make($request->all(), [
            'context' => 'required|string|in:orders,receipts,sales,transactions',
            'decimals' => 'required|integer|min:2|max:5',
            'direction' => 'required|string|in:standard,up,down,custom',
            'custom_threshold' => 'nullable|numeric|min:0|max:1',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $data = $validator->validated();

        $rule = CompanyRoundingRule::updateOrCreate(
            [
                'company_id' => $companyId,
                'context' => $data['context'],
            ],
            [
                'decimals' => $data['decimals'],
                'direction' => $data['direction'],
                'custom_threshold' => $data['direction'] === 'custom' ? ($data['custom_threshold'] ?? 0.5) : null,
            ]
        );

        return response()->json(['rule' => $rule]);
    }
}


