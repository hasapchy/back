<?php

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'ErrorResponse',
    type: 'object',
    properties: [
        new OA\Property(property: 'error', type: 'string', example: 'Error message'),
        new OA\Property(property: 'message', type: 'string', example: 'Error message'),
    ]
)]
#[OA\Schema(
    schema: 'ValidationErrorResponse',
    type: 'object',
    properties: [
        new OA\Property(property: 'message', type: 'string', example: 'Ошибка валидации'),
        new OA\Property(
            property: 'errors',
            type: 'object',
            additionalProperties: new OA\AdditionalProperties(
                type: 'array',
                items: new OA\Items(type: 'string')
            ),
            example: ['email' => ['The email field is required.']]
        ),
    ]
)]
#[OA\Schema(
    schema: 'SuccessResponse',
    type: 'object',
    properties: [
        new OA\Property(property: 'message', type: 'string', example: 'Operation successful'),
        new OA\Property(property: 'data', type: 'object', nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'PaginationResponse',
    type: 'object',
    properties: [
        new OA\Property(property: 'items', type: 'array', items: new OA\Items(type: 'object')),
        new OA\Property(property: 'current_page', type: 'integer', example: 1),
        new OA\Property(property: 'next_page', type: 'string', nullable: true, example: 'http://example.com/api/resource?page=2'),
        new OA\Property(property: 'last_page', type: 'integer', example: 10),
        new OA\Property(property: 'total', type: 'integer', example: 200),
    ]
)]
class CommonSchemas {}
