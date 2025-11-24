<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\ResourceCollection;

class BaseResourceCollection extends ResourceCollection
{
    /**
     * Преобразовать коллекцию ресурса в массив
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    public function toArray($request)
    {
        $data = [
            'items' => $this->collection,
        ];

        if ($this->resource instanceof \Illuminate\Contracts\Pagination\LengthAwarePaginator) {
            $data['current_page'] = $this->resource->currentPage();
            $data['next_page'] = $this->resource->nextPageUrl();
            $data['last_page'] = $this->resource->lastPage();
            $data['total'] = $this->resource->total();
        }

        return $data;
    }
}

