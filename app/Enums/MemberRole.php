<?php

namespace App\Enums;

enum MemberRole: string
{
    case Owner = 'owner';
    case Member = 'member';
}
