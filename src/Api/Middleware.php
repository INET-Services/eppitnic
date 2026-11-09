<?php

namespace Eppitnic\Api;

use Eppitnic\Config;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Slim\App;
use Slim\Exception\HttpException;

/**
 * The application's global middleware stack: body parsing, trailing-slash
 * normalization, JSON error responses and CORS.
 *
 * @category    Net
 * @package     Eppitnic\Api\Middleware
 * @author      Günther Mair <info@inet-services.it>
 * @license     http://opensource.org/licenses/bsd-license.php New BSD License
 */
final class Middleware
{
    // -----------------------------------------------------------------
    // Slim middleware (CORS, trailing-slash normalization, error handling)
    // -----------------------------------------------------------------

    /**
     * register the app's global middleware stack: body-parsing, trailing-slash
     * normalization, error handling, and CORS. Called once from
     * public/index.php right after $app is constructed.
     *
     * @param App $app the Slim application instance
     */
    public static function register(App $app): void {
        // Add the BodyParsingMiddleware in order to automatically parse the
        // Request-Body and provide it as a PHP array/object through
        // $request->getParsedBody()
        $app->addBodyParsingMiddleware();

        $app->add(function (Request $request, RequestHandler $handler): Response {
            $uri  = $request->getUri();
            $path = $uri->getPath();
            if ($path !== '/' && str_ends_with($path, '/')) {
                $request = $request->withUri($uri->withPath(rtrim($path, '/')));
            }
            return $handler->handle($request);
        });

        // Before the CORS middleware, so errors carry its headers, with Slim's
        // HTML handler replaced below by a JSON one. Details need
        // EPPITNIC_DEBUG -- from the environment, since this runs when the
        // database does not
        $displayErrorDetails = filter_var(getenv('EPPITNIC_DEBUG') ?: 'false', FILTER_VALIDATE_BOOL);

        $errorMiddleware = $app->addErrorMiddleware($displayErrorDetails, true, true);
        $errorMiddleware->setDefaultErrorHandler(
            function (Request $request, \Throwable $exception, bool $displayErrorDetails) use ($app): Response {
                // an HttpException carries the status the route intended
                // (401/403/404/405); anything else escaped a handler and is a
                // 500
                $status = ($exception instanceof HttpException) ? $exception->getCode() : 500;

                $body = ['error' => $exception->getMessage()];
                if ($exception instanceof SessionRequirement) {
                    $body += $exception->details;
                }
                if ($displayErrorDetails) {
                    $body['exception'] = get_class($exception);
                    $body['file'] = $exception->getFile();
                    $body['line'] = $exception->getLine();
                    $body['trace'] = $exception->getTraceAsString();
                } elseif ($status === 500) {
                    // never leak an internal failure's message to a client;
                    // logErrors is on, so the real one is in the server log
                    $body['error'] = 'Internal server error';
                }

                return Json::response($app->getResponseFactory()->createResponse(), $body, $status);
            }
        );

        // This CORS middleware will append the response header
        // Access-Control-Allow-Methods with all allowed methods
        $app->add(function (Request $request, RequestHandler $handler) use ($app): Response {
            $origin = $request->getHeaderLine('Origin');

            // No Origin means no browser cross-origin request, so nothing to
            // police. As "" in allowed_origins this hung on an invisible empty
            // string and broke every scripted client once a real list arrived
            if ($origin === '') {
                $response = $handler->handle($request);
                if (ob_get_contents()) {
                    ob_clean();
                }
                return $response;
            }

            $isAllowed = in_array($origin, Config::get('allowed_origins'), strict: true);

            if ($request->getMethod() === 'OPTIONS') {
                $statusCode = $isAllowed ? 204 : 403;
                $response = $app->getResponseFactory()->createResponse($statusCode);
            } else {
                if (!$isAllowed) {
                    $response = $app->getResponseFactory()->createResponse(403);
                    $response->getBody()->write(json_encode(['error' => "CORS: Origin [{$origin}] not allowed"]));
                    // by NOT calling $handler->handle() the middleware-chain is
                    // broken and no further routes will be executed
                    return $response->withHeader('Content-Type', 'application/json');
                }
                $response = $handler->handle($request);
            }

            if ($isAllowed) {
                $response = $response
                    ->withHeader('Access-Control-Allow-Credentials', 'true')
                    ->withHeader('Access-Control-Allow-Origin', $origin)
                    ->withHeader('Access-Control-Allow-Headers', implode(', ', Config::get('allowed_headers')))
                    ->withHeader('Access-Control-Allow-Methods', implode(', ', Config::get('allowed_methods')))
                    ->withHeader('Access-Control-Expose-Headers', 'Content-Disposition') // filename=".."
                    ->withHeader('Vary', 'Origin'); // important in case of dynamic origins!
            }

            if (ob_get_contents()) {
                ob_clean();
            }

            return $response;
        });

        // Outermost: tokens and credentials are never cacheable, whatever
        // the origin or status
        $app->add(function (Request $request, RequestHandler $handler): Response {
            return $handler->handle($request)
                ->withHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
                ->withHeader('Pragma', 'no-cache');
        });
    }
}
