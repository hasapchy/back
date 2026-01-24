<?php

return [
    'birthday' => [
        'model' => \App\Models\User::class,
        'date_field' => 'birthday',
        'variables' => ['name', 'surname', 'fullName'],
    ],
    'holiday' => [
        'model' => \App\Models\CompanyHoliday::class,
        'date_field' => 'date',
        'variables' => ['name'],
    ],
];
