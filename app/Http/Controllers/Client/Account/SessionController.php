<?php

namespace App\Http\Controllers\Client\Account;

use App\Http\Controllers\Controller;
use App\Http\Requests\Client\RevokeSessionsRequest;
use App\Http\Resources\ClientSessionResource;
use App\Http\Resources\MessageResource;
use App\Services\ClientSessionTracker;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SessionController extends Controller
{
    public function __construct(private readonly ClientSessionTracker $sessions) {}

    /** GET /api/client/sessions: where this person is signed in. This browser comes first. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $current = $request->session()->getId();

        $rows = $this->sessions->activeFor($request->user('client'))->get()
            ->sortBy([fn ($a, $b) => ($b->session_id === $current) <=> ($a->session_id === $current)])
            ->values();

        return ClientSessionResource::collection($rows);
    }

    /** DELETE /api/client/sessions/others {password}: signs out every other browser. This one stays. */
    public function destroyOthers(RevokeSessionsRequest $request): MessageResource
    {
        $signedOut = $this->sessions->revokeOthers($request->user('client'), $request->session());

        return new MessageResource($signedOut === 0
            ? 'You are not signed in anywhere else.'
            : "Signed out of {$signedOut} other ".($signedOut === 1 ? 'device' : 'devices').'.', ['signed_out' => $signedOut]);
    }
}
