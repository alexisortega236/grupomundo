<?php

namespace App\Enums;

enum ImportRunStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
}
