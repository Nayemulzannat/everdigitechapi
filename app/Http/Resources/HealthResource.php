<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** GET /api/health → { "status": "ok" } (no `data` wrapper, unlike other resources). */
class HealthResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        return ['status' => 'ok'];
    }
}
