<?php

declare(strict_types=1);

namespace Observability\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

/**
 * Middleware PSR-15. Cocok untuk Slim, Mezzio, Laminas, dan framework lain
 * yang memakai antarmuka PSR-15.
 */
final class Psr15Middleware implements MiddlewareInterface
{
    public function __construct(
        private readonly HttpServerTelemetry $http,
        private readonly RouteTemplateResolver $routes = new RouteTemplateResolver(),
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $exchange = $this->http->beginFromHeaders(
            $request->getMethod(),
            $this->routes->resolve($request),
            $request->getHeaders(),
        );

        try {
            $response = $handler->handle($request);
            $exchange->setStatus($response->getStatusCode());

            return $response;
        } catch (Throwable $exception) {
            $exchange->setStatus(500);
            $exchange->recordException($exception);

            throw $exception;
        } finally {
            $exchange->end();
        }
    }
}
