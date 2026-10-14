<?php

namespace App\Http\Controllers\Client\Account;

use App\Http\Controllers\Controller;
use App\Http\Resources\ClientLoginHistoryResource;
use App\Models\ClientLoginHistory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class LoginHistoryController extends Controller
{
    private const DAYS = 90;

    private const PER_PAGE = 20;

    /** GET /api/client/login-history: this person's sign-ins and failed attempts of the last 90 days, newest first. */
    public function index(Request $request): AnonymousResourceCollection
    {
        return ClientLoginHistoryResource::collection(
            ClientLoginHistory::query()
                ->where('client_user_id', $request->user('client')->getKey())
                ->where('created_at', '>=', now()->subDays(self::DAYS))
                ->latest('created_at')->latest('id')
                ->paginate(self::PER_PAGE),
        );
    }
}
