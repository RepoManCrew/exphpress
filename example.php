<?php

require 'exphpress.php';

use RepoMan\Exphpress\App;
use RepoMan\Exphpress\BodyParsers;
use RepoMan\Exphpress\Contracts;

$app = new App([
    new BodyParsers\Form(),
    new BodyParsers\Json(),
    new BodyParsers\PlainText(),
]);

$app->get('/', function (Contracts\Request $request, Contracts\Response $response) {
    throw new Exception('foo');
});

$app->get('/ping', function (Contracts\Request $request, Contracts\Response $response) {
    $response
        ->status(200)
        ->text('pong!')
        ->end();
});

$app->get('/sitemap.json', function (Contracts\Request $request, Contracts\Response $response) use ($app) {
    $response
        ->status(200)
        ->json($app->routes())
        ->end();
});

$app->post('/echo', function (Contracts\Request $request, Contracts\Response $response) {
    $response
        ->status(200)
        ->json([
            "headers" => $request->headers(),
            "payload" => $request->body()
        ])
        ->end();
});

$app->get('/{name}', function (Contracts\Request $request, Contracts\Response $response, array $params) {
    $response
        ->status(200)
        ->text(sprintf("Helo, %s!", $params['name']))
        ->end();
});

$app->start();
