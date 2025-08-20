<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\OrderAfRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class OrderAfController extends Controller
{
    protected $repository;

    public function __construct(OrderAfRepository $repository)
    {
        $this->repository = $repository;
    }


    public function index(Request $request)
    {
        $userUuid = optional(auth('api')->user())->id;
        if (!$userUuid) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $perPage = $request->input('per_page', 20);

        $fields = $this->repository->getItemsWithPagination($userUuid, $perPage);

        return response()->json([
            'items' => $fields->items(),
            'current_page' => $fields->currentPage(),
            'next_page' => $fields->nextPageUrl(),
            'last_page' => $fields->lastPage(),
            'total' => $fields->total()
        ]);
    }


    public function show($id)
    {
        $userUuid = optional(auth('api')->user())->id;
        if (!$userUuid) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $field = $this->repository->getItemById($id, $userUuid);

        if (!$field) {
            return response()->json(['message' => 'Поле не найдено'], 404);
        }

        return response()->json($field);
    }


    public function store(Request $request)
    {
        $userUuid = optional(auth('api')->user())->id;
        if (!$userUuid) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'type' => 'required|in:string,int,date,boolean,select,datetime',
            'category_ids' => 'required|array|min:1',
            'category_ids.*' => 'integer|exists:order_categories,id',
            'options' => 'nullable|array',
            'options.*' => 'string|max:255',
            'required' => 'boolean',
            'default' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Ошибка валидации',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $data = [
                'name' => $request->name,
                'type' => $request->type,
                'category_ids' => $request->category_ids,
                'options' => $request->options,
                'required' => $request->boolean('required', false),
                'default' => $request->default,
                'user_id' => $userUuid,
            ];

            $field = $this->repository->createItem($data);

            return response()->json([
                'message' => 'Дополнительное поле успешно создано',
                'field' => $field->load('categories')
            ], 201);
        } catch (\Throwable $th) {
            return response()->json([
                'message' => 'Ошибка создания поля: ' . $th->getMessage()
            ], 400);
        }
    }


    public function update(Request $request, $id)
    {
        $userUuid = optional(auth('api')->user())->id;
        if (!$userUuid) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:255',
            'type' => 'sometimes|required|in:string,int,date,boolean,select,datetime',
            'category_ids' => 'sometimes|required|array|min:1',
            'category_ids.*' => 'integer|exists:order_categories,id',
            'options' => 'nullable|array',
            'options.*' => 'string|max:255',
            'required' => 'sometimes|boolean',
            'default' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Ошибка валидации',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $data = $request->only([
                'name',
                'type',
                'category_ids',
                'options',
                'required',
                'default'
            ]);

            if (isset($data['required'])) {
                $data['required'] = (bool) $data['required'];
            }

            $field = $this->repository->updateItem($id, $data, $userUuid);

            return response()->json([
                'message' => 'Поле успешно обновлено',
                'field' => $field->load('categories')
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'message' => 'Ошибка обновления: ' . $th->getMessage()
            ], 400);
        }
    }


    public function destroy($id)
    {
        $userUuid = optional(auth('api')->user())->id;
        if (!$userUuid) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        try {
            $field = $this->repository->deleteItem($id, $userUuid);

            return response()->json([
                'message' => 'Поле успешно удалено',
                'field' => $field
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'message' => 'Ошибка удаления: ' . $th->getMessage()
            ], 400);
        }
    }


    public function getByCategory($categoryId)
    {
        $userUuid = optional(auth('api')->user())->id;
        if (!$userUuid) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $fields = $this->repository->getFieldsByCategory($categoryId, $userUuid);

        return response()->json([
            'category_id' => $categoryId,
            'fields' => $fields
        ]);
    }


    public function getFieldTypes()
    {
        $types = $this->repository->getFieldTypes();

        return response()->json([
            'types' => $types
        ]);
    }


    public function getByCategories(Request $request)
    {
        $userUuid = optional(auth('api')->user())->id;
        if (!$userUuid) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $validator = Validator::make($request->all(), [
            'category_ids' => 'required|array|min:1',
            'category_ids.*' => 'integer|exists:order_categories,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Ошибка валидации',
                'errors' => $validator->errors()
            ], 422);
        }

        $fields = $this->repository->getFieldsByCategories($request->category_ids, $userUuid);

        return response()->json([
            'category_ids' => $request->category_ids,
            'fields' => $fields
        ]);
    }
}
