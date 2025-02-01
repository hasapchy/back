<?php

namespace App\Livewire\Admin;

use Livewire\Component;
use Livewire\WithFileUploads; // Добавляем трейт для загрузки файлов
use App\Models\Setting;

class Settings extends Component
{
    use WithFileUploads; // Используем трейт

    public $companyName;
    public $companyLogo;

    public function mount()
    {
        $this->companyName = Setting::where('setting_name', 'company_name')->value('setting_value');
        $this->companyLogo = Setting::where('setting_name', 'company_logo')->value('setting_value');
    }

    public function saveSettings()
    {
        $this->validate([
            'companyName' => 'nullable|string|max:255',
            'companyLogo' => $this->companyLogo instanceof \Illuminate\Http\UploadedFile
                ? 'nullable|image|max:2048'
                : 'nullable|string', // Если это строка (старый путь), пропускаем валидацию как изображение
        ]);

        // Сохраняем название компании, если оно изменено или задано
        if (!is_null($this->companyName)) {
            Setting::updateOrCreate(
                ['setting_name' => 'company_name'],
                ['setting_value' => $this->companyName]
            );
        }

        // Сохраняем логотип, если он загружается
        if ($this->companyLogo instanceof \Illuminate\Http\UploadedFile) {
            $logoPath = $this->companyLogo->store('logos', 'public');

            Setting::updateOrCreate(
                ['setting_name' => 'company_logo'],
                ['setting_value' => $logoPath]
            );

            // Обновляем путь к логотипу в компоненте для отображения
            $this->companyLogo = $logoPath;
        }

        // Уведомление пользователя об успешном сохранении
        session()->flash('success', 'Настройки успешно сохранены!');

        // Обновление страницы
        return redirect()->route('admin.settings.index');
    }





    public function render()
    {
        return view('livewire.admin.settings');
    }
}
