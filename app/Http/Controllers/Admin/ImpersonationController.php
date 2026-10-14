<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ClientAccount;
use App\Services\Impersonation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ImpersonationController extends Controller
{
    /** POST /api/admin/clients/{account}/impersonate {client_user_id?}: a one-minute link that signs staff in as the owner (or a member). */
    public function store(Request $request, int $account, Impersonation $impersonation): JsonResponse
    {
        $data = $request->validate(['client_user_id' => ['nullable', 'integer']]);

        $url = $impersonation->issue(
            $request->user('admin'),
            ClientAccount::findOrFail($account),
            isset($data['client_user_id']) ? (int) $data['client_user_id'] : null,
        );

        return response()->json(['url' => $url]);
    }
}
