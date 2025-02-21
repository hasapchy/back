<?php

namespace App\Services;

use App\Models\Client;

class ClientService
{
    public function searchClients($searchTerm)
    {
        if (strlen($searchTerm) >= 3) {
            return Client::where('first_name', 'like', '%' . $searchTerm . '%')
                ->orWhereHas('phones', function ($query) use ($searchTerm) {
                    $query->where('phone', 'like', '%' . $searchTerm . '%');
                })
                ->get();
        }

        return [];
    }

    public function getClientById($clientId)
    {
        return Client::find($clientId);
    }

    public function getAllClients()
    {
        return Client::all();
    }
}
