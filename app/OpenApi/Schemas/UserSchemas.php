<?php

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'User',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 1),
        new OA\Property(property: 'name', type: 'string', example: 'John'),
        new OA\Property(property: 'surname', type: 'string', nullable: true, example: 'Doe'),
        new OA\Property(property: 'email', type: 'string', format: 'email', example: 'user@example.com'),
        new OA\Property(property: 'photo', type: 'string', nullable: true),
        new OA\Property(property: 'is_active', type: 'boolean', example: true),
        new OA\Property(property: 'is_admin', type: 'boolean', example: false),
        new OA\Property(property: 'position', type: 'string', nullable: true, example: 'Manager'),
        new OA\Property(property: 'hire_date', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'birthday', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'last_login_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
    ]
)]
#[OA\Schema(
    schema: 'StoreUserRequest',
    type: 'object',
    required: ['name', 'email', 'password', 'companies'],
    properties: [
        new OA\Property(property: 'name', type: 'string', maxLength: 255, example: 'John'),
        new OA\Property(property: 'surname', type: 'string', nullable: true, maxLength: 255, example: 'Doe'),
        new OA\Property(property: 'email', type: 'string', format: 'email', example: 'user@example.com'),
        new OA\Property(property: 'password', type: 'string', minLength: 6, example: 'password123'),
        new OA\Property(property: 'hire_date', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'birthday', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'position', type: 'string', nullable: true, maxLength: 255),
        new OA\Property(property: 'is_active', type: 'boolean', nullable: true, example: true),
        new OA\Property(property: 'is_admin', type: 'boolean', nullable: true, example: false),
        new OA\Property(property: 'photo', type: 'string', format: 'binary', nullable: true, description: 'Фото пользователя (jpeg, png, jpg, gif, max 2MB)'),
        new OA\Property(
            property: 'roles',
            type: 'array',
            items: new OA\Items(type: 'string'),
            nullable: true,
            example: ['manager']
        ),
        new OA\Property(
            property: 'companies',
            type: 'array',
            items: new OA\Items(type: 'integer'),
            minItems: 1,
            example: [1, 2]
        ),
        new OA\Property(
            property: 'company_roles',
            type: 'array',
            items: new OA\Items(
                type: 'object',
                properties: [
                    new OA\Property(property: 'company_id', type: 'integer'),
                    new OA\Property(
                        property: 'role_ids',
                        type: 'array',
                        items: new OA\Items(type: 'string')
                    ),
                ]
            ),
            nullable: true
        ),
        new OA\Property(
            property: 'departments',
            type: 'array',
            items: new OA\Items(type: 'integer'),
            nullable: true,
            example: [1]
        ),
    ]
)]
#[OA\Schema(
    schema: 'UpdateUserRequest',
    type: 'object',
    properties: [
        new OA\Property(property: 'name', type: 'string', maxLength: 255, example: 'John'),
        new OA\Property(property: 'surname', type: 'string', nullable: true, maxLength: 255),
        new OA\Property(property: 'email', type: 'string', format: 'email'),
        new OA\Property(property: 'password', type: 'string', nullable: true, minLength: 6),
        new OA\Property(property: 'hire_date', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'birthday', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'position', type: 'string', nullable: true, maxLength: 255),
        new OA\Property(property: 'is_active', type: 'boolean', nullable: true),
        new OA\Property(property: 'is_admin', type: 'boolean', nullable: true),
        new OA\Property(property: 'photo', type: 'string', format: 'binary', nullable: true),
        new OA\Property(
            property: 'roles',
            type: 'array',
            items: new OA\Items(type: 'string'),
            nullable: true
        ),
        new OA\Property(
            property: 'companies',
            type: 'array',
            items: new OA\Items(type: 'integer'),
            nullable: true
        ),
        new OA\Property(
            property: 'company_roles',
            type: 'array',
            items: new OA\Items(
                type: 'object',
                properties: [
                    new OA\Property(property: 'company_id', type: 'integer'),
                    new OA\Property(
                        property: 'role_ids',
                        type: 'array',
                        items: new OA\Items(type: 'string')
                    ),
                ]
            ),
            nullable: true
        ),
        new OA\Property(
            property: 'departments',
            type: 'array',
            items: new OA\Items(type: 'integer'),
            nullable: true
        ),
    ]
)]
class UserSchemas {}
