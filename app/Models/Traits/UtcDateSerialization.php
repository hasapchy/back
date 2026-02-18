<?php

namespace App\Models\Traits;

use DateTimeInterface;
use DateTimeZone;

trait UtcDateSerialization
{
    protected function serializeDate(DateTimeInterface $date): string
    {
        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }
}

