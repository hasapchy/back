<?php

namespace App\Services\Timeline;

use Carbon\Carbon;
use InvalidArgumentException;

class TimelineCursor
{
    public const SOURCE_COMMENT = 'comment';

    public const SOURCE_ACTIVITY = 'activity';

    public const SOURCE_ORDER_TRANSACTION = 'order_transaction';

    /**
     * @param Carbon $createdAt
     * @param string $source
     * @param int $id
     */
    public function __construct(
        public Carbon $createdAt,
        public string $source,
        public int $id
    ) {}

    /**
     * @param array{id: int, created_at: mixed, source: string} $row
     * @return self
     */
    public static function fromRow(array $row): self
    {
        return new self(
            Carbon::parse($row['created_at']),
            (string) $row['source'],
            (int) $row['id']
        );
    }

    /**
     * @param string|null $encoded
     * @return self|null
     */
    public static function decode(?string $encoded): ?self
    {
        if ($encoded === null || $encoded === '') {
            return null;
        }

        $json = base64_decode($encoded, true);
        if ($json === false) {
            throw new InvalidArgumentException('Invalid timeline cursor');
        }

        $data = json_decode($json, true);
        if (! is_array($data)) {
            throw new InvalidArgumentException('Invalid timeline cursor');
        }

        $source = (string) ($data['source'] ?? '');
        if (! in_array($source, [self::SOURCE_COMMENT, self::SOURCE_ACTIVITY, self::SOURCE_ORDER_TRANSACTION], true)) {
            throw new InvalidArgumentException('Invalid timeline cursor source');
        }

        return new self(
            Carbon::parse($data['created_at']),
            $source,
            (int) ($data['id'] ?? 0)
        );
    }

    /**
     * @return string
     */
    public function encode(): string
    {
        $payload = json_encode([
            'created_at' => $this->createdAt->toIso8601String(),
            'source' => $this->source,
            'id' => $this->id,
        ], JSON_THROW_ON_ERROR);

        return base64_encode($payload);
    }

    /**
     * @param array{id: int, created_at: mixed, source: string} $row
     * @param self|null $cursor
     * @return bool
     */
    public static function rowIsOlderThanCursor(array $row, ?self $cursor): bool
    {
        if ($cursor === null) {
            return true;
        }

        return self::compareRows($row, [
            'id' => $cursor->id,
            'created_at' => $cursor->createdAt,
            'source' => $cursor->source,
        ]) > 0;
    }

    /**
     * @param array{id: int, created_at: mixed, source: string} $a
     * @param array{id: int, created_at: mixed, source: string} $b
     * @return int Negative if $a is newer than $b in DESC sort order
     */
    public static function compareRows(array $a, array $b): int
    {
        $ca = Carbon::parse($a['created_at']);
        $cb = Carbon::parse($b['created_at']);

        if (! $ca->equalTo($cb)) {
            return $cb <=> $ca;
        }

        $sourceCmp = strcmp((string) $b['source'], (string) $a['source']);
        if ($sourceCmp !== 0) {
            return $sourceCmp;
        }

        return (int) $b['id'] <=> (int) $a['id'];
    }
}
