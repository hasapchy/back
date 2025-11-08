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
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        $perPage = $request->input('per_page', 20);
        $fields = $this->repository->getItemsWithPagination($userUuid, $perPage);

        return $this->paginatedResponse($fields);
    }


    public function show($id)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        $field = $this->repository->getItemById($id, $userUuid);

        if (!$field) {
            return $this->notFoundResponse('Поле не найдено');
        }

        return response()->json(['field' => $field]);
    }


    public function store(Request $request)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'type' => 'required|in:string,int,date,boolean,select,datetime',
            'options' => 'nullable|array',
            'options.*' => 'string|max:255',
            'required' => 'boolean',
            'default' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator);
        }

        try {
            $data = [
                'name' => $request->name,
                'type' => $request->type,
                'options' => $request->options,
                'required' => $request->boolean('required', false),
                'default' => $request->default,
                'user_id' => $userUuid,
            ];

            $field = $this->repository->createItem($data);

            return response()->json(['field' => $field, 'message' => 'Дополнительное поле успешно создано'], 201);
        } catch (\Throwable $th) {
            return $this->errorResponse('Ошибка создания поля: ' . $th->getMessage(), 400);
        }
    }


    public function update(Request $request, $id)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:255',
            'type' => 'sometimes|required|in:string,int,date,boolean,select,datetime',
            'options' => 'nullable|array',
            'options.*' => 'string|max:255',
            'required' => 'sometimes|boolean',
            'default' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator);
        }

        try {
            $data = $request->only([
                'name',
                'type',
                'options',
                'required',
                'default'
            ]);

            if (isset($data['required'])) {
                $data['required'] = (bool) $data['required'];
            }

            $field = $this->repository->updateItem($id, $data, $userUuid);

            return response()->json(['field' => $field, 'message' => 'Поле успешно обновлено']);
        } catch (\Throwable $th) {
            return $this->errorResponse('Ошибка обновления: ' . $th->getMessage(), 400);
        }
    }


    public function destroy($id)
    {
        $userUuid = $this->getAuthenticatedUserIdOrFail();

        try {
            $field = $this->repository->deleteItem($id, $userUuid);

            return response()->json(['field' => $field, 'message' => 'Поле успешно удалено']);
        } catch (\Throwable $th) {
            return $this->errorResponse('Ошибка удаления: ' . $th->getMessage(), 400);
        }
    }


    public function getFieldTypes()
    {
        $types = $this->repository->getFieldTypes();

        return response()->json(['types' => $types]);
    }
}
