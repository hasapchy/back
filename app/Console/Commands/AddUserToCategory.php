<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\CategoryUser;
use App\Models\User;

class AddUserToCategory extends Command
{
    protected $signature = 'category:copy-user-categories {source_user_id} {target_user_id}';

    protected $description = 'Копирует категории от одного пользователя к другому';

    public function handle()
    {
        $sourceUserId = (int) $this->argument('source_user_id');
        $targetUserId = (int) $this->argument('target_user_id');

        $sourceUser = User::find($sourceUserId);
        $targetUser = User::find($targetUserId);

        if (!$sourceUser) {
            $this->error("Пользователь с ID {$sourceUserId} не найден");
            return 1;
        }

        if (!$targetUser) {
            $this->error("Пользователь с ID {$targetUserId} не найден");
            return 1;
        }

        $sourceCategories = CategoryUser::where('user_id', $sourceUserId)
            ->pluck('category_id')
            ->toArray();

        if (empty($sourceCategories)) {
            $this->warn("У пользователя {$sourceUserId} нет категорий");
            return 0;
        }

        $this->info("Найдено категорий у пользователя {$sourceUserId}: " . count($sourceCategories));
        $this->line("Категории: " . implode(', ', $sourceCategories));

        $existingCategories = CategoryUser::where('user_id', $targetUserId)
            ->pluck('category_id')
            ->toArray();

        $categoriesToAdd = array_diff($sourceCategories, $existingCategories);

        if (empty($categoriesToAdd)) {
            $this->info("Пользователь {$targetUserId} уже имеет все эти категории");
            return 0;
        }

        $now = now();
        $insertData = [];
        foreach ($categoriesToAdd as $categoryId) {
            $insertData[] = [
                'user_id' => $targetUserId,
                'category_id' => $categoryId,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        CategoryUser::insert($insertData);

        $this->info("Добавлено категорий пользователю {$targetUserId}: " . count($categoriesToAdd));
        $this->line("Добавленные категории: " . implode(', ', $categoriesToAdd));

        return 0;
    }
}
