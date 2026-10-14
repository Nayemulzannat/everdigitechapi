<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CannedReplyRequest;
use App\Models\CannedReply;
use App\Services\ActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Saved answers for tickets. Everything needs tickets.edit (the people who reply to tickets). */
class CannedReplyController extends Controller
{
    public function __construct(private readonly ActivityLogger $activity) {}

    /** GET /api/admin/canned-replies: by title. */
    public function index(): JsonResponse
    {
        return response()->json(['data' => CannedReply::with('author:id,name')->orderBy('title')->get()->map(fn (CannedReply $r) => $this->present($r))->all()]);
    }

    /** POST /api/admin/canned-replies {title, body} */
    public function store(CannedReplyRequest $request): JsonResponse
    {
        $reply = CannedReply::create([...$request->validated(), 'created_by' => $request->user('admin')->getKey()]);
        $this->activity->log('canned_reply.created', $reply, ['title' => $reply->title]);

        return response()->json(['data' => $this->present($reply->load('author:id,name'))], 201);
    }

    /** PUT /api/admin/canned-replies/{id} {title?, body?} */
    public function update(CannedReplyRequest $request, int $id): JsonResponse
    {
        $reply = $this->find($id);
        $reply->fill($request->validated())->save();
        $this->activity->log('canned_reply.updated', $reply, ['title' => $reply->title]);

        return response()->json(['data' => $this->present($reply->load('author:id,name'))]);
    }

    /** DELETE /api/admin/canned-replies/{id} */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $reply = $this->find($id);
        $reply->delete();
        $this->activity->log('canned_reply.deleted', $reply, ['title' => $reply->title]);

        return response()->json(['message' => 'The saved reply was deleted.']);
    }

    /** @return array<string, mixed> */
    private function present(CannedReply $reply): array
    {
        return [
            'id' => (string) $reply->getKey(),
            'title' => $reply->title,
            'body' => $reply->body,
            'createdBy' => $reply->author?->name,
            'updatedAt' => $reply->updated_at->toIso8601ZuluString(),
        ];
    }

    private function find(int $id): CannedReply
    {
        return CannedReply::find($id) ?? throw new ApiException(404, 'not_found', 'That saved reply does not exist.');
    }
}
