<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpsertCompanyRoundingRuleRequest;
use App\Models\CompanyRoundingRule;
use Illuminate\Http\Request;

/**
 * Контроллер для работы с правилами округления компаний
 */
class CompanyRoundingRulesController extends Controller
{
    /**
     * Получить правила округления текущей компании
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $companyId = $this->getCurrentCompanyId();
        $rules = CompanyRoundingRule::where('company_id', $companyId)->get();
        return $this->dataResponse($rules);
    }

    /**
     * Создать или обновить правило округления
     *
     * @param UpsertCompanyRoundingRuleRequest $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function upsert(UpsertCompanyRoundingRuleRequest $request)
    {
        $companyId = $this->getCurrentCompanyId();
        $data = $request->validated();

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

        return $this->dataResponse(['rule' => $rule]);
    }
}


