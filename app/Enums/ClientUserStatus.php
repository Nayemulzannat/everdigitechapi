<?php

namespace App\Enums;

enum ClientUserStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';
}
