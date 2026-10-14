<?php

namespace App\Support;

enum ChargeOutcome
{
    case PAID;
    case DECLINED;
    case PENDING_REVIEW;
    case UNKNOWN;
}
