<?php

namespace App\Http\Requests\Admin;

use App\Enums\ClientGroup;
use App\Enums\ClientStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** GET /clients?page=&status=&group=&search=&sort= ("all" and empty values mean no filter). */
class ClientIndexRequest extends FormRequest
{
    /** Sort keys; a leading "-" means descending. */
    public const SORTS = ['name', 'joined', 'balance', 'services', 'domains'];

    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'status' => ['sometimes', Rule::in(['all', ...array_column(ClientStatus::cases(), 'value')])],
            'group' => ['sometimes', Rule::in(['all', ...array_column(ClientGroup::cases(), 'value')])],
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'sort' => ['sometimes', Rule::in([...self::SORTS, ...array_map(fn ($s) => '-'.$s, self::SORTS)])],
        ];
    }

    public function status(): ?ClientStatus
    {
        return ClientStatus::tryFrom((string) $this->validated('status'));
    }

    public function group(): ?ClientGroup
    {
        return ClientGroup::tryFrom((string) $this->validated('group'));
    }
}
