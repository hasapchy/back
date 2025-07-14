<?php

namespace App\Http\Controllers\api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Repositories\ClientsRepository;
use Illuminate\Support\Facades\DB;

class ClientController extends Controller
{
    protected $itemsRepository;

    public function __construct(ClientsRepository $itemsRepository)
    {
        $this->itemsRepository = $itemsRepository;
    }


    public function getClients(Request $request)
    {
        $perPage = $request->input('per_page', 20);
        $items = $this->itemsRepository->getImemsPaginated($perPage);

        return response()->json([
            'items' => $items->items(),  // Список
            'current_page' => $items->currentPage(),  // Текущая страница
            'next_page' => $items->nextPageUrl(),  // Следующая страница
            'last_page' => $items->lastPage(),  // Общее количество страниц
            'total' => $items->total()  // Общее количество
        ]);
    }

    public function search(Request $request)
    {
        $search_request = $request->input('search_request');

        if (!$search_request || empty($search_request)) {
            $items = [];
            // $items = $this->itemsRepository->getImemsPaginated(20);
        } else {
            $items = $this->itemsRepository->searchClient($search_request);
        }

        return response()->json($items);
    }

    public function getBalanceHistory($id)
    {
        try {
            $history = $this->itemsRepository->getBalanceHistory($id);

            return response()->json([
                'history' => $history
            ], 200);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Ошибка при получении истории баланса',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function createClient(Request $request)
    {
        $validatedData = $request->validate([
            'first_name'       => 'required|string',
            'is_conflict'      => 'sometimes|nullable|boolean',
            'is_supplier'      => 'sometimes|nullable|boolean',
            'last_name'        => 'nullable|string',
            'contact_person'   => 'nullable|string',
            'client_type'      => 'required|string|in:company,individual',
            'address'          => 'nullable|string',
            'phones'           => 'required|array',
            'phones.*'         => 'string|distinct|min:6',
            'emails'           => 'sometimes|nullable',
            'emails.*'         => 'nullable|email|distinct',
            'note'             => 'nullable|string',
            'status'           => 'boolean',
            'discount'         => 'nullable|numeric|min:0',
            'discount_type'    => 'nullable|in:fixed,percent',
        ]);

        DB::beginTransaction();
        try {
            $client = $this->itemsRepository->create($validatedData);

            $client->balance()->create([
                'balance' => 0,
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Client created successfully',
                'item' => $client->load('balance', 'phones', 'emails'),
            ], 200);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Ошибка при создании клиента',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    public function updateClient(Request $request, $id)
    {
        $validatedData = $request->validate([
            'first_name'       => 'required|string',
            'is_conflict'      => 'sometimes|nullable|boolean',
            'is_supplier'      => 'sometimes|nullable|boolean',
            'last_name'        => 'nullable|string',
            'contact_person'   => 'nullable|string',
            'client_type'      => 'required|string|in:company,individual',
            'address'          => 'nullable|string',
            'phones'           => 'required|array',
            'phones.*'         => 'string|distinct|min:6',
            'emails'           => 'sometimes|nullable',
            'emails.*'         => 'nullable|email|distinct',
            'note'             => 'nullable|string',
            'status'           => 'boolean',
            'discount'         => 'nullable|numeric|min:0',
            'discount_type'    => 'nullable|in:fixed,percent',
        ]);

        $client = $this->itemsRepository->update($id, $validatedData);

        return response()->json([
            'message' => 'Client updated successfully',
            'client' => $client
        ], 200);
    }
}
