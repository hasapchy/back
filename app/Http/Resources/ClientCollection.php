<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\ResourceCollection;

class ClientCollection extends ResourceCollection
{
    /**
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        return ClientResource::collection($this->collection)->resolve();
    }

    /**
     * @param \Illuminate\Http\Request $request
     * @return array<string, mixed>
     */
    public function paginationInformation($request, $paginated, $default): array
    {
        return [
            'meta' => [
                'current_page' => $paginated['current_page'],
                'last_page' => $paginated['last_page'],
                'per_page' => $paginated['per_page'],
                'total' => $paginated['total'],
                'next_page' => $paginated['next_page_url'],
                'prev_page' => $paginated['prev_page_url'],
            ],
        ];
    }
}

