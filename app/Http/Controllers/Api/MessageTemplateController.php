<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\StoreMessageTemplateRequest;
use App\Http\Requests\UpdateMessageTemplateRequest;
use App\Models\MessageTemplate;
use App\Repositories\MessageTemplateRepository;
use Illuminate\Http\Request;

/**
 * Контроллер для работы с шаблонами сообщений
 */
class MessageTemplateController extends BaseController
{
    protected MessageTemplateRepository $repository;

    public function __construct(MessageTemplateRepository $repository)
    {
        $this->repository = $repository;
    }

    /**
     * Получить список шаблонов с пагинацией
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $this->getAuthenticatedUserIdOrFail();

        $perPage = (int) $request->input('per_page', 20);
        $page = (int) $request->input('page', 1);

        $filters = [];
        if ($request->has('type')) {
            $filters['type'] = $request->input('type');
        }
        if ($request->has('search')) {
            $filters['search'] = $request->input('search');
        }

        $items = $this->repository->getItemsWithPagination($perPage, $page, $filters);

        return $this->paginatedResponse($items);
    }

    /**
     * Получить все шаблоны
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function all(Request $request)
    {
        $this->getAuthenticatedUserIdOrFail();

        $filters = [];
        if ($request->has('type')) {
            $filters['type'] = $request->input('type');
        }

        $items = $this->repository->getAllItems($filters);

        return response()->json($items);
    }

    /**
     * Получить доступные типы шаблонов из конфига
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getTypes()
    {
        $this->getAuthenticatedUserIdOrFail();

        $companyId = $this->getCurrentCompanyId();
        $types = config('template_types', []);

        // Получаем список занятых типов (активные шаблоны для текущей компании)
        $usedTypes = MessageTemplate::query()
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->when($companyId, function ($query) use ($companyId) {
                $query->where('company_id', $companyId);
            }, function ($query) {
                $query->whereNull('company_id');
            })
            ->pluck('type')
            ->toArray();

        $result = [];

        foreach ($types as $key => $config) {
            $result[] = [
                'value' => $key,
                'label' => ucfirst(str_replace('_', ' ', $key)),
                'variables' => $config['variables'] ?? [],
                'is_used' => in_array($key, $usedTypes),
            ];
        }

        return response()->json(['types' => $result]);
    }

    /**
     * Получить шаблон по ID
     *
     * @param  int  $id  ID шаблона
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($id)
    {
        $this->getAuthenticatedUserIdOrFail();

        try {
            $template = $this->repository->findItemWithRelations($id);

            if (! $template) {
                return $this->notFoundResponse('Шаблон не найден');
            }

            return response()->json(['item' => $template]);
        } catch (\Exception $e) {
            return $this->notFoundResponse('Шаблон не найден');
        }
    }

    /**
     * Создать новый шаблон
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(StoreMessageTemplateRequest $request)
    {
        $userId = $this->getAuthenticatedUserIdOrFail();

        if (! $this->hasPermission('templates_create')) {
            return $this->forbiddenResponse('У вас нет прав на создание шаблонов');
        }

        $validatedData = $request->validated();

        $data = [
            'type' => $validatedData['type'],
            'name' => $validatedData['name'],
            'content' => $validatedData['content'],
            'user_id' => $userId,
            'is_active' => $validatedData['is_active'] ?? true,
        ];

        $created = $this->repository->createItem($data);

        return response()->json(['item' => $created, 'message' => 'Шаблон создан']);
    }

    /**
     * Обновить шаблон
     *
     * @param  int  $id  ID шаблона
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(UpdateMessageTemplateRequest $request, $id)
    {
        $this->getAuthenticatedUserIdOrFail();

        try {
            $template = MessageTemplate::findOrFail($id);

            if (! $this->canPerformAction('templates', 'update', $template)) {
                return $this->forbiddenResponse('У вас нет прав на редактирование этого шаблона');
            }

            $validatedData = $request->validated();

            $data = array_filter([
                'type' => $validatedData['type'] ?? null,
                'name' => $validatedData['name'] ?? null,
                'content' => $validatedData['content'] ?? null,
                'is_active' => $validatedData['is_active'] ?? null,
            ], fn ($value) => $value !== null);

            $this->repository->updateItem($id, $data);
            $template = $this->repository->findItemWithRelations($id);

            return response()->json(['item' => $template, 'message' => 'Шаблон обновлен']);
        } catch (\Exception $e) {
            return $this->notFoundResponse('Шаблон не найден');
        }
    }

    /**
     * Удалить шаблон
     *
     * @param  int  $id  ID шаблона
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($id)
    {
        $this->getAuthenticatedUserIdOrFail();

        try {
            $template = MessageTemplate::findOrFail($id);

            if (! $this->canPerformAction('templates', 'delete', $template)) {
                return $this->forbiddenResponse('У вас нет прав на удаление этого шаблона');
            }

            $this->repository->deleteItem($id);

            return response()->json(['message' => 'Шаблон удален']);
        } catch (\Exception $e) {
            return $this->notFoundResponse('Шаблон не найден');
        }
    }

    /**
     * Пакетное удаление шаблонов
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function batchDelete(Request $request)
    {
        $this->getAuthenticatedUserIdOrFail();

        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'integer',
        ]);

        $ids = $request->input('ids');

        try {
            $deleted = 0;
            foreach ($ids as $id) {
                try {
                    $template = MessageTemplate::findOrFail($id);
                    if ($this->canPerformAction('templates', 'delete', $template)) {
                        $this->repository->deleteItem($id);
                        $deleted++;
                    }
                } catch (\Exception $e) {
                    continue;
                }
            }

            return response()->json([
                'message' => "Удалено шаблонов: $deleted",
                'deleted' => $deleted,
            ]);
        } catch (\Exception $e) {
            return $this->errorResponse('Ошибка при пакетном удалении', 400);
        }
    }
}
