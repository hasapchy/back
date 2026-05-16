<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;

final class ReferenceTelemetry
{
    /**
     * @param  string  $label  Короткий идентификатор запроса (например, путь или budget key)
     */
    public static function maybeLogReferenceRequest(string $label, int $dataJsonBytes): void
    {
        if (! config('features.reference_telemetry')) {
            return;
        }

        Log::info('reference_telemetry', [
            'label' => $label,
            'data_json_bytes' => $dataJsonBytes,
        ]);
    }
}
