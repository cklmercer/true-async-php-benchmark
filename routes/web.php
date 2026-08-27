<?php

declare(strict_types=1);

use App\Http\Controllers\LoadTestController;
use App\Http\Controllers\MessageController;
use App\Routing\Router;

/**
 * The route table, as Laravel's routes/web.php declares one.
 *
 * Registered once per worker and then compiled; see Router for what "compiled"
 * buys. Controllers arrive already constructed — Laravel would resolve them
 * from the container per request, which is one of the costs this benchmark
 * states it does not pay.
 */
return static function (Router $router, LoadTestController $loadtest, MessageController $messages): void {
    $router->get('/loadtest', $loadtest->show(...));

    $router->get('/messages', $messages->index(...));
    $router->post('/messages', $messages->store(...));

    // The only route with a parameter, so it is the one that exercises the
    // compiled regex matcher and route-model binding.
    $router->get('/messages/{message}', $messages->show(...))
        ->bind('message', $messages->resolve(...));
};
