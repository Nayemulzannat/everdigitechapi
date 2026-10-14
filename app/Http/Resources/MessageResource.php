<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** { "message": "..." }, for actions that return nothing but a confirmation. */
class MessageResource extends JsonResource
{
    public static $wrap = null;

    public function __construct(private readonly string $message, private readonly array $extra = [])
    {
        parent::__construct(null);
    }

    public function toArray(Request $request): array
    {
        return ['message' => $this->message, ...$this->extra];
    }
}
