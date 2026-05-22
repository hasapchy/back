<?php

namespace App\Http\Resources;

use App\Models\Transaction;
use App\Repositories\TransactionsRepository;

class TransactionResource extends BaseDomainResource
{
    /**
     * @param \Illuminate\Http\Request $request
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        if ($this->resource instanceof Transaction) {
            $this->resource->loadMissing([
                'creator:id,name,surname',
                'category:id,name,type',
                'cashRegister:id,name,currency_id,is_cash,icon,color',
                'cashRegister.currency:id,name,symbol',
                'currency:id,name,symbol',
                'client.phones:id,client_id,phone',
                'client.emails:id,client_id,email',
                'project:id,name',
            ]);

            return $this->normalizeCreator(
                app(TransactionsRepository::class)->transactionToListArray($this->resource)
            );
        }

        return parent::toArray($request);
    }
}
