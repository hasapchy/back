<?php

namespace App\Services;

use App\Models\Project;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ProjectFileService
{
    /**
     * Загрузить файлы для проекта
     *
     * @param Project $project
     * @param array|UploadedFile $files
     * @return array
     * @throws \Exception
     */
    public function uploadFiles(Project $project, $files): array
    {
        if (is_null($files)) {
            $files = [];
        } elseif ($files instanceof UploadedFile) {
            $files = [$files];
        }

        if (count($files) === 0) {
            throw new \Exception('No files uploaded');
        }

        $storedFiles = $project->files ?? [];

        foreach ($files as $file) {
            $filename = Str::uuid() . '.' . $file->getClientOriginalExtension();
            $path = $file->storeAs('projects/' . $project->id, $filename, 'public');

            $storedFiles[] = [
                'name' => $file->getClientOriginalName(),
                'path' => $path,
                'size' => $file->getSize(),
                'mime_type' => $file->getMimeType(),
                'uploaded_at' => now()->toDateTimeString(),
            ];
        }

        $project->update(['files' => $storedFiles]);

        return $storedFiles;
    }

    /**
     * Удалить файл проекта
     *
     * @param Project $project
     * @param string $filePath
     * @return array
     * @throws \Exception
     */
    public function deleteFile(Project $project, string $filePath): array
    {
        $files = $project->files ?? [];
        $updatedFiles = [];
        $deletedFile = null;

        foreach ($files as $file) {
            if ($file['path'] === $filePath) {
                $deletedFile = $file;
                if (Storage::disk('public')->exists($filePath)) {
                    Storage::disk('public')->delete($filePath);
                }
                continue;
            }
            $updatedFiles[] = $file;
        }

        if (!$deletedFile) {
            throw new \Exception('Файл не найден в проекте');
        }

        $project->update(['files' => $updatedFiles]);

        return $updatedFiles;
    }

    /**
     * Получить список файлов проекта
     *
     * @param Project $project
     * @return array
     */
    public function getFiles(Project $project): array
    {
        return $project->files ?? [];
    }
}

