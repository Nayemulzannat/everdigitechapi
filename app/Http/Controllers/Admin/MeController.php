<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\AdminMeResource;
use App\Http\Resources\LoginHistoryResource;
use App\Models\AdminLoginHistory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class MeController extends Controller
{
    private const HISTORY_LIMIT = 50;

    private const HISTORY_PER_PAGE = 10;

    /** GET /api/admin/me */
    public function show(Request $request): AdminMeResource
    {
        return new AdminMeResource($request->user('admin'));
    }

    /** GET /api/admin/me/login-history: the 50 most recent sign-ins and failed attempts, 10 per page. */
    public function loginHistory(Request $request): AnonymousResourceCollection
    {
        $latest = AdminLoginHistory::query()
            ->where('admin_id', $request->user('admin')->getKey())
            ->latest('created_at')->latest('id')
            ->limit(self::HISTORY_LIMIT)
            ->pluck('id');

        return LoginHistoryResource::collection(
            AdminLoginHistory::query()
                ->whereIn('id', $latest)
                ->latest('created_at')->latest('id')
                ->paginate(self::HISTORY_PER_PAGE),
        );
    }
}
