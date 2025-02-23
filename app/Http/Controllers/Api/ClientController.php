<?php

namespace App\Http\Controllers\api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Repositories\ClientsRepository;

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
            'discount_value'   => 'nullable|numeric|min:0',
            'discount_type'    => 'nullable|in:fixed,percentage',
        ]);

        $client = $this->itemsRepository->create($validatedData);

        return response()->json([
            'message' => 'Client created successfully',
            'client' => $client
        ], 200);
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
            'discount_value'   => 'nullable|numeric|min:0',
            'discount_type'    => 'nullable|in:fixed,percentage',
        ]);

        $client = $this->itemsRepository->update($id, $validatedData);

        return response()->json([
            'message' => 'Client updated successfully',
            'client' => $client
        ], 200);
    }
}
