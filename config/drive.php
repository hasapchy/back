<?php

return [
    'allowed_file_extensions' => [
        'pdf', 'doc', 'docx', 'xls', 'xlsx', 'png', 'jpg', 'jpeg', 'gif', 'bmp', 'svg',
        'zip', 'rar', '7z', 'txt', 'md', 'csv', 'webp',
    ],

    'image_extensions' => [
        'png', 'jpg', 'jpeg', 'gif', 'webp', 'bmp', 'svg',
    ],

    'browser_view_extensions' => [
        'pdf',
    ],

    'allowed_mime_types_by_extension' => [
        'pdf' => ['application/pdf'],
        'doc' => ['application/msword'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
        'xls' => ['application/vnd.ms-excel'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        'png' => ['image/png'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'gif' => ['image/gif'],
        'bmp' => ['image/bmp', 'image/x-ms-bmp'],
        'svg' => ['image/svg+xml'],
        'webp' => ['image/webp'],
        'zip' => ['application/zip', 'application/x-zip-compressed'],
        'rar' => ['application/vnd.rar', 'application/x-rar-compressed'],
        '7z' => ['application/x-7z-compressed'],
        'txt' => ['text/plain'],
        'md' => ['text/markdown', 'text/plain'],
        'csv' => ['text/csv', 'text/plain', 'application/vnd.ms-excel'],
    ],

    'max_file_bytes' => 10240 * 1024,

    'system_folders' => [
        'projects' => [
            'name' => 'Проекты',
            'icon' => 'fas fa-diagram-project',
            'icon_color' => '#3B82F6',
        ],
    ],

    'folder_icons' => [
        'fas fa-folder',
        'fas fa-folder-open',
        'fas fa-briefcase',
        'fas fa-file-contract',
        'fas fa-users',
        'fas fa-chart-line',
        'fas fa-money-bill-wave',
        'fas fa-image',
        'fas fa-cogs',
        'fas fa-star',
        'fas fa-bookmark',
        'fas fa-archive',
        'fas fa-box',
        'fas fa-cloud',
        'fas fa-lock',
        'fas fa-heart',
    ],

    'folder_icon_color_default' => '#EAB308',
];
