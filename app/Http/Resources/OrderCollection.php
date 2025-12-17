<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;

class OrderCollection extends ResourceCollection
{
    private ?float $unpaidOrdersTotal = null;

    public function __construct($resource, ?float $unpaidOrdersTotal = null)
    {
        parent::__construct($resource);
        $this->unpaidOrdersTotal = $unpaidOrdersTotal;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        return OrderResource::collection($this->collection)->resolve();
    }

    /**
     * @return array<string, mixed>
     */
    public function paginationInformation($request, $paginated, $default): array
    {
        $meta = [
            'current_page' => $paginated['current_page'],
            'last_page' => $paginated['last_page'],
            'per_page' => $paginated['per_page'],
            'total' => $paginated['total'],
            'next_page' => $paginated['next_page_url'],
            'prev_page' => $paginated['prev_page_url'],
        ];

        if ($this->unpaidOrdersTotal !== null) {
            $meta['unpaid_orders_total'] = $this->unpaidOrdersTotal;
        }

        return [
            'meta' => $meta,
        ];
    }
}

