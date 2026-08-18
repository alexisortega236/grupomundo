<?php

namespace App\Enums;

enum ModelVersionStatus: string
{
    case Draft = 'draft';
    case Training = 'training';
    case Active = 'active';
    case Archived = 'archived';
}
