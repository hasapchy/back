<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\ProjectsRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use App\Models\Project;

class ProjectsController extends Controller
{
    protected $itemsRepository;

    public function __construct(ProjectsRepository $itemsRepository)
    {
        $this->itemsRepository = $itemsRepository;
    }
    // Метод для получения проектов с пагинацией
    public function index(Request $request)
    {
        $userUuid = optional(auth('api')->user())->id;
        if (!$userUuid) {
            return response()->json(array('message' => 'Unauthorized'), 401);
        }
        // Получаем с пагинацией
        $items = $this->itemsRepository->getItemsWithPagination($userUuid, 20);

        return response()->json([
            'items' => $items->items(),  // Список
            'current_page' => $items->currentPage(),  // Текущая страница
            'next_page' => $items->nextPageUrl(),  // Следующая страница
            'last_page' => $items->lastPage(),  // Общее количество страниц
            'total' => $items->total()  // Общее количество
        ]);
    }

    // Метод для получения всех проектов
    public function all(Request $request)
    {
        $userUuid = optional(auth('api')->user())->id;
        if (!$userUuid) {
            return response()->json(array('message' => 'Unauthorized'), 401);
        }

        $items = $this->itemsRepository->getAllItems($userUuid);

        return response()->json($items);
    }

    // Метод для создания проекта
    public function store(Request $request)
    {
        $userUuid = optional(auth('api')->user())->id;
        if (!$userUuid) {
            return response()->json(array('message' => 'Unauthorized'), 401);
        }
        // Валидация данных
        $request->validate([
            'name' => 'required|string',
            'budget' => 'required|numeric',
            'date' => 'nullable|sometimes|date',
            'client_id' => 'required|exists:clients,id',
            'users' => 'required|array',
            'users.*' => 'exists:users,id'
        ]);

        // Создаем категорию
        $item_created = $this->itemsRepository->createItem([
            'name' => $request->name,
            'budget' => $request->budget,
            'date' => $request->date,
            'user_id' => $userUuid,
            'client_id' => $request->client_id,
            'users' => $request->users
        ]);

        if (!$item_created) {
            return response()->json([
                'message' => 'Ошибка создания проекта'
            ], 400);
        }
        return response()->json([
            'message' => 'Проект создан'
        ]);
    }

    // Метод для обновления проекта
    public function update(Request $request, $id)
    {
        $userUuid = optional(auth('api')->user())->id;
        if (!$userUuid) {
            return response()->json(array('message' => 'Unauthorized'), 401);
        }
        // Валидация данных
        $request->validate([
            'name' => 'required|string',
            'budget' => 'required|numeric',
            'date' => 'nullable|sometimes|date',
            'client_id' => 'required|exists:clients,id',
            'users' => 'required|array',
            'users.*' => 'exists:users,id'
        ]);

        // Обновляем проект
        $category_updated = $this->itemsRepository->updateItem($id, [
            'name' => $request->name,
            'budget' => $request->budget,
            'date' => $request->date,
            'user_id' => $userUuid,
            'client_id' => $request->client_id,
            'users' => $request->users
        ]);

        if (!$category_updated) {
            return response()->json([
                'message' => 'Ошибка обновления проекта'
            ], 400);
        }
        return response()->json([
            'message' => 'Проект обновлен'
        ]);
    }

    // // Метод для удаления кассы
    // public function destroy($id)
    // {
    //     $userUuid = optional(auth('api')->user())->id;
    //     if(!$userUuid){
    //         return response()->json(array('message' => 'Unauthorized'), 401);
    //     }
    //     // Удаляем кассу
    //     $category_deleted = $this->itemsRepository->deleteItem($id);

    //     if (!$category_deleted) {
    //         return response()->json([
    //             'message' => 'Ошибка удаления кассы'
    //         ], 400);
    //     }
    //     return response()->json([
    //         'message' => 'Категория удалена'
    //     ]);
    // }

    public function uploadFiles(Request $request, $id)
    {
        $files = $request->file('files');

        // Если файлов нет, присваиваем пустой массив
        if (is_null($files)) {
            $files = [];
        }
        // Если пришел один файл, оборачиваем его в массив
        elseif ($files instanceof \Illuminate\Http\UploadedFile) {
            $files = [$files];
        }
        // Если $files уже массив, оставляем как есть

        if (count($files) == 0) {
            return response()->json(['message' => 'No files uploaded'], 400);
        }

        $project = Project::findOrFail($id);
        $storedFiles = $project->files ?? [];

        foreach ($files as $file) {
            $filename = Str::uuid() . '.' . $file->getClientOriginalExtension();
            $path = $file->storeAs('projects/' . $project->id, $filename, 'public');
            $storedFiles[] = [
                'name' => $file->getClientOriginalName(),
                'path' => $path,
                'url' => Storage::disk('public')->url($path),
                'uploaded_at' => now()->toDateTimeString(),
            ];
        }

        $project->update(['files' => $storedFiles]);

        return response()->json([
            'message' => 'Files uploaded',
            'files' => $storedFiles
        ]);
    }


    // Удаление файла по индексу или имени
    public function deleteFile(Request $request, $id)
    {
        try {
            $userId = optional(auth('api')->user())->id;
            if (!$userId) {
                return response()->json(['error' => 'Unauthorized'], 401);
            }

            $project = Project::find($id);
            if (!$project) {
                return response()->json(['error' => 'Проект не найден'], 404);
            }

            $filePath = $request->input('path');
            if (!$filePath) {
                return response()->json(['error' => 'Путь файла не указан'], 400);
            }

            $files = $project->files ?? [];
            $updatedFiles = [];

            $found = false;
            foreach ($files as $file) {
                if ($file['path'] === $filePath) {
                    // Проверяем, существует ли файл
                    if (Storage::disk('public')->exists($filePath)) {
                        Storage::disk('public')->delete($filePath);
                    }
                    $found = true;
                    continue;
                }
                $updatedFiles[] = $file;
            }

            if (!$found) {
                return response()->json(['error' => 'Файл не найден в проекте'], 404);
            }

            // Обновляем только поле files
            $project->files = $updatedFiles;
            $project->save();

            return response()->json([
                'message' => 'Файл удалён',
                'files' => $updatedFiles
            ]);
        } catch (\Exception $e) {
            \Log::error('Ошибка удаления файла: ' . $e->getMessage(), [
                'project_id' => $id,
                'file_path' => $request->input('path'),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json(['error' => 'Внутренняя ошибка сервера'], 500);
        }
    }
}
