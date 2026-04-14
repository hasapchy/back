<?php

namespace App\Batch;

enum BatchStrategyKind: string
{
    case Bulk = 'bulk';
    case Loop = 'loop';
    case Queue = 'queue';
}
