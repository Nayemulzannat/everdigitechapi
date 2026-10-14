<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\PermissionModuleResource;
use App\Support\PermissionCatalog;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PermissionController extends Controller
{
    /** GET /api/admin/permissions: the catalog, grouped by module. */
    public function index(): AnonymousResourceCollection
    {
        return PermissionModuleResource::collection(PermissionCatalog::modules());
    }
}
