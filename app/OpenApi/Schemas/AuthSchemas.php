<?php

namespace App\OpenApi\Schemas;

use OpenApi\Annotations as OA;

/**
 * @OA\Schema(
 *     schema="AuthUser",
 *     type="object",
 *     @OA\Property(property="id", type="integer", format="int64", example=1),
 *     @OA\Property(property="name", type="string", example="John Doe"),
 *     @OA\Property(property="email", type="string", format="email", example="user@example.com"),
 *     @OA\Property(property="photo", type="string", nullable=true, example="https://cdn.example.com/avatars/1.png"),
 *     @OA\Property(property="is_admin", type="boolean", example=false),
 *     @OA\Property(
 *         property="roles",
 *         type="array",
 *         @OA\Items(type="string")
 *     ),
 *     @OA\Property(
 *         property="permissions",
 *         type="array",
 *         @OA\Items(type="string")
 *     )
 * )
 * @OA\Schema(
 *     schema="AuthTokensResponse",
 *     type="object",
 *     @OA\Property(property="access_token", type="string", example="1|abc123def456..."),
 *     @OA\Property(property="token_type", type="string", example="bearer"),
 *     @OA\Property(property="expires_in", type="integer", example=86400),
 *     @OA\Property(property="user", ref="#/components/schemas/AuthUser")
 * )
 */
class AuthSchemas
{
}

