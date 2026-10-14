<?php

namespace App\Http\Resources;

use App\Models\AdminLoginHistory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @property AdminLoginHistory $resource */
class LoginHistoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'ipAddress' => $this->ip_address,
            'browser' => $this->browser,
            'platform' => $this->platform,
            'successful' => $this->successful,
            'failureReason' => $this->failure_reason,
            'createdAt' => $this->created_at,
        ];
    }
}
