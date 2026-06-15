<?php

namespace App\Support;

class MovementHashBuilder
{
    /**
     * @param  int  $scopeId
     * @param  int  $transactionId
     * @param  int|string  $ruleKey
     * @param  string  $movementType
     * @return string
     */
    public static function build(int $scopeId, int $transactionId, int|string $ruleKey, string $movementType): string
    {
        return hash('sha256', $scopeId.'|'.$transactionId.'|'.$ruleKey.'|'.$movementType);
    }

    /**
     * @param  string  $movementHash
     * @param  int  $movementId
     * @return string
     */
    public static function deletedHash(string $movementHash, int $movementId): string
    {
        return hash('sha256', $movementHash.':deleted:'.$movementId);
    }
}
