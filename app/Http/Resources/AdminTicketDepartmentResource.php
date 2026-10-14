<?php

namespace App\Http\Resources;

use App\Models\TicketDepartment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** A department for the admin panel (camelCase). Load `tickets_count` with withCount('tickets'). @property TicketDepartment $resource */
class AdminTicketDepartmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $department = $this->resource;

        return [
            'id' => (string) $department->getKey(),
            'name' => $department->name,
            'description' => $department->description,
            'isActive' => $department->is_active,
            'sortOrder' => $department->sort_order,
            'ticketsCount' => (int) ($department->tickets_count ?? $department->tickets()->count()),
        ];
    }
}
