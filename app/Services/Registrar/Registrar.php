<?php

namespace App\Services\Registrar;

use App\Exceptions\ApiException;

/**
 * A domain registrar (Dynadot today). The code asks this interface, never a registrar directly, so another registrar can be
 * added later. Only search exists for now; register, renew and transfer are added when orders and payments are built.
 */
interface Registrar
{
    /**
     * Whether each domain can be registered.
     *
     * @param  list<string>  $domains  lower case, "name.ext"
     * @return array<string, bool> domain => available
     *
     * @throws ApiException 503 `registrar_not_configured` or 502 `registrar_unavailable`
     */
    public function search(array $domains): array;

    /**
     * Every extension the registrar sells, with what it charges us (US cents). Used to fill the admin list once.
     *
     * @return list<array{extension: string, usage: ?string, restrictions: ?string, cost_register: ?int, cost_renew: ?int, cost_transfer: ?int}>
     *
     * @throws ApiException 503 `registrar_not_configured` or 502 `registrar_unavailable`
     */
    public function extensions(): array;
}
