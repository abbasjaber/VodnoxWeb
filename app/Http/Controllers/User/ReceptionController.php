<?php

namespace App\Http\Controllers\User;

/**
 * Reception workspace. It shares the incoming-call persistence (notes,
 * complaints, follow-ups and the wrap-up draft/completion rule) and adds the
 * "finish session" action through the same store endpoint.
 */
class ReceptionController extends IncomingController
{
    protected string $view = 'user.reception';

    protected string $route = 'user.reception.index';

    protected string $auditPrefix = 'agent.reception';
}
