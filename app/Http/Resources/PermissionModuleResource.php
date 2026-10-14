<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** One entry of config/permissions.php, as the frontend's PermissionModule. */
class PermissionModuleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'key' => $this->resource['key'],
            'label' => $this->resource['label'],
            'description' => $this->resource['description'],
            'actions' => $this->resource['actions'],
            'special' => $this->resource['special'],
        ];
    }
}
