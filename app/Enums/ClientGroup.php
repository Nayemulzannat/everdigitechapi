<?php

namespace App\Enums;

enum ClientGroup: string
{
    case Regular = 'regular';
    case Reseller = 'reseller';
    case Vip = 'vip';
}
