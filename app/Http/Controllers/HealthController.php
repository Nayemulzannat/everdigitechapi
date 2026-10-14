<?php

namespace App\Http\Controllers;

use App\Http\Resources\HealthResource;

class HealthController extends Controller
{
    public function __invoke(): HealthResource
    {
        return new HealthResource(null);
    }
}
