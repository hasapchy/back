<?php

namespace App\Support;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Support\Facades\DB;

final class ChatBroadcastChannels
{
    /**
     * @return array<int, PrivateChannel>
     */
    public static function chatAndInboxes(int $companyId, int $chatId): array
    {
        $channels = [
            new PrivateChannel("company.{$companyId}.chat.{$chatId}"),
        ];

        $userIds = DB::table('chat_participants')
            ->where('chat_id', $chatId)
            ->pluck('user_id');

        foreach ($userIds as $userId) {
            $channels[] = new PrivateChannel("company.{$companyId}.user.{$userId}.chats");
        }

        return $channels;
    }
}
