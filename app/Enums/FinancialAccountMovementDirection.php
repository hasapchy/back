<?php

namespace App\Enums;

enum FinancialAccountMovementDirection: string
{
    case Increase = 'increase';
    case Decrease = 'decrease';
}
