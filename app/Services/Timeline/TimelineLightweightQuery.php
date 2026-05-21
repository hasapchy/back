<?php

namespace App\Services\Timeline;

use App\Models\Order;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class TimelineLightweightQuery
{
    /**
     * @return array{rows: list<array{id: int, created_at: Carbon, source: string}>, has_more: bool}
     */
    public function fetchPage(
        string $modelClass,
        int $entityId,
        bool $includeOrderTransactionLogs,
        int $limit,
        ?TimelineCursor $cursor
    ): array {
        $limit = max(1, min(100, $limit));
        $bindings = [];
        $parts = [];

        $parts[] = 'SELECT comments.id AS id, comments.created_at AS created_at, ? AS source'
            .' FROM comments WHERE comments.commentable_type = ? AND comments.commentable_id = ?';
        $bindings[] = TimelineCursor::SOURCE_COMMENT;
        $bindings[] = $modelClass;
        $bindings[] = $entityId;

        $parts[] = 'SELECT activity_log.id AS id, activity_log.created_at AS created_at, ? AS source'
            .' FROM activity_log WHERE activity_log.subject_type = ? AND activity_log.subject_id = ?';
        $bindings[] = TimelineCursor::SOURCE_ACTIVITY;
        $bindings[] = $modelClass;
        $bindings[] = $entityId;

        if ($includeOrderTransactionLogs) {
            $parts[] = 'SELECT activity_log.id AS id, activity_log.created_at AS created_at, ? AS source'
                .' FROM activity_log'
                .' INNER JOIN transactions ON transactions.id = activity_log.subject_id'
                .' AND activity_log.subject_type = ?'
                .' WHERE transactions.source_type = ? AND transactions.source_id = ?';
            $bindings[] = TimelineCursor::SOURCE_ORDER_TRANSACTION;
            $bindings[] = Transaction::class;
            $bindings[] = Order::class;
            $bindings[] = $entityId;
        }

        $unionSql = '('.implode(' UNION ALL ', $parts).') AS timeline_union';

        $sql = "SELECT id, created_at, source FROM {$unionSql}";
        if ($cursor !== null) {
            $sql .= ' WHERE (
                created_at < ?
                OR (created_at = ? AND source < ?)
                OR (created_at = ? AND source = ? AND id < ?)
            )';
            $cursorAt = $cursor->createdAt->toDateTimeString();
            $bindings[] = $cursorAt;
            $bindings[] = $cursorAt;
            $bindings[] = $cursor->source;
            $bindings[] = $cursorAt;
            $bindings[] = $cursor->source;
            $bindings[] = $cursor->id;
        }

        $sql .= ' ORDER BY created_at DESC, source DESC, id DESC LIMIT ?';
        $bindings[] = $limit + 1;

        $rawRows = DB::select($sql, $bindings);
        $hasMore = count($rawRows) > $limit;
        if ($hasMore) {
            $rawRows = array_slice($rawRows, 0, $limit);
        }

        $rows = [];
        foreach ($rawRows as $row) {
            $rows[] = [
                'id' => (int) $row->id,
                'created_at' => Carbon::parse($row->created_at),
                'source' => (string) $row->source,
            ];
        }

        return [
            'rows' => $rows,
            'has_more' => $hasMore,
        ];
    }
}
