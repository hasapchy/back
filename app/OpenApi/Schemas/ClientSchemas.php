<?php

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'Client',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 1),
        new OA\Property(property: 'client_type', type: 'string', enum: ['company', 'individual', 'employee', 'investor'], example: 'individual'),
        new OA\Property(property: 'balance', type: 'number', format: 'float', example: 1000.50),
        new OA\Property(property: 'is_supplier', type: 'boolean', example: false),
        new OA\Property(property: 'is_conflict', type: 'boolean', example: false),
        new OA\Property(property: 'first_name', type: 'string', example: 'John'),
        new OA\Property(property: 'last_name', type: 'string', nullable: true, example: 'Doe'),
        new OA\Property(property: 'patronymic', type: 'string', nullable: true),
        new OA\Property(property: 'contact_person', type: 'string', nullable: true),
        new OA\Property(property: 'position', type: 'string', nullable: true),
        new OA\Property(property: 'address', type: 'string', nullable: true),
        new OA\Property(property: 'note', type: 'string', nullable: true),
        new OA\Property(property: 'status', type: 'boolean', example: true),
        new OA\Property(property: 'discount_type', type: 'string', nullable: true, enum: ['fixed', 'percent']),
        new OA\Property(property: 'discount', type: 'number', format: 'float', nullable: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
        new OA\Property(
            property: 'emails',
            type: 'array',
            items: new OA\Items(
                type: 'object',
                properties: [
                    new OA\Property(property: 'id', type: 'integer'),
                    new OA\Property(property: 'email', type: 'string', format: 'email'),
                ]
            )
        ),
        new OA\Property(
            property: 'phones',
            type: 'array',
            items: new OA\Items(
                type: 'object',
                properties: [
                    new OA\Property(property: 'id', type: 'integer'),
                    new OA\Property(property: 'phone', type: 'string'),
                ]
            )
        ),
    ]
)]
class ClientSchemas {}
