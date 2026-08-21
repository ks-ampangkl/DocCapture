<?php
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;
use App\Controllers\DocumentController;
use App\Controllers\ChatController;
use App\Controllers\DashboardController;

return function (App $app): void {
    $app->get('/', function (Request $r, Response $s) {
        $s->getBody()->write(json_encode(['name' => 'DocCapture API', 'version' => '1.0.0']));
        return $s->withHeader('Content-Type', 'application/json');
    });

    $app->group('/api', function ($g) {
        $g->get   ('/documents',       [DocumentController::class, 'index']);
        $g->get   ('/documents/{id}',  [DocumentController::class, 'show']);
        $g->post  ('/documents/upload',[DocumentController::class, 'create']);
        $g->put   ('/documents/{id}',  [DocumentController::class, 'update']);
        $g->delete('/documents/{id}',  [DocumentController::class, 'delete']);
        $g->post('/chat/submit', [ChatController::class, 'submit']);
        $g->get('/dashboard', [DashboardController::class, 'index']);
    });
};