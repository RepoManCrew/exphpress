<?php

namespace RepoMan\Exphpress\Contracts
{
    use Exception;

    interface Request
    {
        public function uri (): string;
        public function method (): string;
        public function header (string $name);
        public function headers (): array;
        public function body ();
    }

    interface Response
    {
        public function status (int $status): self;
        public function set (array $headers): self;
        public function json (array $body): self;
        public function end (): void;
    }

    interface Route
    {
        public function address (): string;
        public function method (): string;
        public function matches (Request $request): bool;
        public function handle (Request $request, Response &$response): void;
    }

    interface BodyParser
    {
        public function acceptableContentTypes (): array;
        public function matches (array $headers, string $body): bool;
        public function parse (array $headers, string $body);
    }

    interface HttpException
    {
        public function status (): int;
        public function message (): string;
        public function details (): array;
        public function headers (): array;
    }
}

namespace RepoMan\Exphpress\BodyParsers
{
    use RepoMan\Exphpress\Contracts;

    abstract class BaseParser implements Contracts\BodyParser
    {
        abstract public function acceptableContentTypes (): array;

        public function matches (array $headers, string $body): bool
        {
            foreach ($this->acceptableContentTypes() as $content_type) {
                if (\strpos($headers['content-type'], $content_type) > -1) {
                    return true;
                }
            }

            return false;
        }
    }

    class Form extends BaseParser
    {
        public function acceptableContentTypes (): array
        {
            return [ 'application/x-www-form-urlencoded' ];
        }

        public function parse (array $headers, string $body): array
        {
            return $_POST;
        }
    }

    class Json extends BaseParser
    {
        public function acceptableContentTypes (): array
        {
            return [ 'application/json' ];
        }

        public function parse (array $headers, string $body): array
        {
            return \json_decode($body, true);
        }
    }

    class PlainText extends BaseParser
    {
        protected $is_fallback;

        public function __construct (bool $is_fallback = false)
        {
            $this->is_fallback = $is_fallback;
        }

        public function acceptableContentTypes (): array
        {
            return [ 'text/plain' ];
        }

        public function matches (array $headers, string $body): bool
        {
            if ($this->is_fallback) {
                return true;
            }

            return parent::matches($headers, $body);
        }

        public function parse (array $headers, string $body): string
        {
            return $body;
        }
    }
}

namespace RepoMan\Exphpress\HTTP
{
    use Exception;
    use RepoMan\Exphpress\Contracts;

    class HttpException extends Exception implements Contracts\HttpException
    {
        protected $status;
        protected $message;
        protected $details;
        protected $headers;

        public function __construct (int $status, string $message, array $details = [], array $headers = [])
        {
            $this->status = $status;
            $this->message = $message;
            $this->details = $details;
            $this->headers = $headers;

            parent::__construct($message);
        }

        public function status (): int
        {
            return $this->status;
        }

        public function message (): string
        {
            return $this->message;
        }

        public function details (): array
        {
            return $this->details;
        }

        public function headers (): array
        {
            return $this->headers;
        }

        public static function unsupportedMediaType (string $content_type, array $allowed_contet_types = []): self
        {
            $message = !empty($content_type)
                ? "Unsupported content type: $content_type"
                : "No content type given";

            return new self(415, $message, [ "allowed_content_types" => $allowed_contet_types ]);
        }

        public static function internalServerError (string $message, array $details = []): self
        {
            return new self(500, $message, $details);
        }
    }

    class Request implements Contracts\Request
    {
        protected $method;
        protected $uri;
        protected $headers;
        protected $body;

        public function __construct ($method, $uri, array $headers = [], $body)
        {
            $this->method = $method;
            $this->uri = $uri;
            $this->body = $body;

            foreach ($headers as $key => $value) {
                $this->headers[strtolower($key)] = $value;
            }
        }

        public function method (): string
        {
            return $this->method;
        }

        public function uri (): string
        {
            return $this->uri;
        }

        public function header ($name)
        {
            $name = strtolower($name);

            if (!isset($this->headers[$name])) {
                return null;
            }

            return $this->headers[$name];
        }

        public function headers (): array
        {
            return $this->headers;
        }

        public function body ()
        {
            return $this->body;
        }

        public static function factory (array $parsers = []): self
        {
            $script_path = $_SERVER['SCRIPT_FILENAME'];
            $script_directory = $_SERVER['DOCUMENT_ROOT'];
            $dirty_uri = \rtrim($_SERVER['REQUEST_URI'], '/');
            $script_name = \str_replace($script_directory, '', $script_path);

            $method = \strtoupper($_SERVER['REQUEST_METHOD']);
            $uri = \str_replace($script_name, '', $dirty_uri) ?: '/';
            $input = @\file_get_contents('php://input');
            $headers = [];
            $body = null;

            foreach (\getallheaders() as $key => $value) {
                $headers[\strtolower($key)] = $value;
            }

            if (!empty($input)) {
                $matched_parsers = \array_filter($parsers, function (Contracts\BodyParser $parser) use ($headers, $input) {
                    return $parser->matches($headers, $input);
                });

                if (\count($matched_parsers) === 0) {
                    $allowed_contet_types = array_reduce($parsers, function (array $types, Contracts\BodyParser $parser) {
                        return array_merge($types, $parser->acceptableContentTypes());
                    }, []);

                    throw HttpException::unsupportedMediaType($headers['content-type'] ?: "", $allowed_contet_types);
                }

                $body = \array_shift($matched_parsers)->parse($headers, $input);
            }

            return new self($method, $uri, $headers, $body);
        }
    }

    class Response implements Contracts\Response
    {
        protected $status = 200;
        protected $body = null;
        protected $headers = [];

        public function set (array $headers): self
        {
            $this->headers = array_merge($this->headers, $headers);

            return $this;
        }

        public function status (int $status): self
        {
            $this->status = $status;

            return $this;
        }

        public function json (array $body): self
        {
            $payload = empty($body)
                ? new \stdClass()
                : $body;

            return $this
                ->set(['content-type' => 'application/json; charset=utf-8'])
                ->body(\json_encode($payload));
        }

        public function text (string $body)
        {
            return $this
                ->set(['content-type' => 'text/plain; charset=utf-8'])
                ->body($body);
        }

        public function body (string $body)
        {
            $this->body = $body;

            return $this;
        }

        public function end (): void
        {
            http_response_code($this->status);

            foreach ($this->headers as $key => $value) {
                header("${key}: ${value}");
            }

            if (!$this->isBodylessStatus() && !empty($this->body)) {
                header(sprintf("content-length: %s", strlen($this->body)));
                echo $this->body;
            }

            exit;
        }

        protected function isBodylessStatus ()
        {
            return $this->status < 200 || $this->status === 204;
        }
    }
}

namespace RepoMan\Exphpress
{
    use Exception;
    use RepoMan\Exphpress\Contracts;
    use RepoMan\Exphpress\HTTP\HttpException;
    use RepoMan\Exphpress\HTTP\Request;
    use RepoMan\Exphpress\HTTP\Response;

    class RouteAddress
    {
        protected $raw;
        protected $regex;
        protected $variables;

        public function __construct (string $raw, string $regex, array $variables)
        {
            $this->raw = $raw;
            $this->regex = $regex;
            $this->variables = $variables;
        }

        public function raw (): string
        {
            return $this->raw;
        }

        public function regex (): string
        {
            return $this->regex;
        }

        public function variables (): array
        {
            return $this->variables;
        }

        public function matches (string $requestPath): bool
        {
            return (bool) preg_match($this->regex, $requestPath);
        }

        public static function factory ($raw): RouteAddress
        {
            $variables = [];
            $pieces = [];

            if ($raw === '*') {
                return [
                    'regex' => '/.*/',
                    'variables' => []
                ];
            }

            foreach (explode('/', $raw) as $node) {
                if (empty($node)) {
                    continue;
                }

                $is_variable = (bool) preg_match('/^{(?<name>\w+)(:(?<regex>.*))?}$/', $node, $matches);

                if (!$is_variable) {
                    $pieces[] = $node;
                    continue;
                }

                $name = $matches['name'];
                $regex = '.*';

                if (isset($matches['regex']) && !empty($matches['regex'])) {
                    $regex = $matches['regex'];
                }

                $variables[] = $name;
                $pieces[] = "(?<${name}>${regex})";
            }

            $regex = '#^/' . implode('/', $pieces) . '$#';

            return new self($raw, $regex, $variables);
        }
    }

    class Route implements Contracts\Route
    {
        protected $method = 'GET';
        protected $address;
        protected $params = [];
        protected $handler;

        public function __construct (string $method, string $address, callable $handler)
        {
            $this->method = \strtoupper($method);
            $this->address = RouteAddress::factory($address);
            $this->handler = $handler;
        }

        public function method (): string
        {
            return $this->method;
        }

        public function address (): string
        {
            return $this->address->raw();
        }

        public function matches (Contracts\Request $request): bool
        {
            return $this->method === $request->method()
                && $this->address->matches($request->uri());
        }

        public function handle (Contracts\Request $request, Contracts\Response &$response): void
        {
            $params = $this->getParams($request, $response);

            try {
                \call_user_func($this->handler, $request, $response, $params);
            } catch (Contracts\HttpException $e) {
                throw $e;
            } catch (Exception $e) {
                throw HttpException::internalServerError($e->getMessage());
            }
        }

        protected function getParams (Contracts\Request $request, Contracts\Response &$response): array
        {
            preg_match($this->address->regex(), $request->uri(), $matches);

            return array_reduce($this->address->variables(), function (array $params, string $variable) use ($matches) {
                return array_merge($params, [ $variable => $matches[$variable] ]);
            }, []);
        }
    }

    class Router
    {
        const CUSTOM_SORT = [
            'HEAD'   => '0',
            'GET'    => '1',
            'POST'   => '2',
            'PUT'    => '3',
            'PATCH'  => '4',
            'DELETE' => '5'
        ];

        protected $routes = [];

        public function register (Route $route)
        {
            if ($route->method() !== 'HEAD') {
                $handler = function (Contracts\Request $request, Contracts\Response $response) {
                    $response
                        ->status(200)
                        ->end();
                };

                array_push(
                    $this->routes,
                    new Route('HEAD', $route->address(), $handler)
                );
            }

            array_push($this->routes, $route);

            return $this;
        }

        public function handle (Contracts\Request $request, Contracts\Response &$response)
        {
            $routes = array_filter($this->routes, function (Route $route) use ($request) {
                return $route->matches($request);
            });

            if (!$routes) {
                $response
                    ->status(404)
                    ->json([
                        'status' => 404,
                        'error' => [
                            'code' => 'not_found',
                            'message' => 'resource not found'
                        ]
                    ])
                    ->end();
            }

            $route = array_shift($routes);
            $route->handle($request, $response);
        }

        public function routes ()
        {
            $keys = \array_map(function ($route) {
                $order = isset(self::CUSTOM_SORT[$route->method()])
                    ? self::CUSTOM_SORT[$route->method()]
                    : '6';

                return $route->address() . "#" . $order . '#' . $route->method();
            }, $this->routes);

            \sort($keys);

            return array_map(function ($key) {
                list($address, , $method) = \explode('#', $key);

                return [
                    'address' => $address,
                    'method' => $method
                ];
            }, $keys);
        }
    }

    class App
    {
        protected $router;
        protected $parsers;

        public function __construct (array $parsers = [])
        {
            $this->router = new Router();
            $this->parsers = $parsers;
        }

        public function head (string $address, callable $handler)
        {
            return $this->router->register(new Route('HEAD', $address, $handler));
        }

        public function get (string $address, callable $handler)
        {
            return $this->router->register(new Route('GET', $address, $handler));
        }

        public function post (string $address, callable $handler)
        {
            return $this->router->register(new Route('POST', $address, $handler));
        }

        public function put (string $address, callable $handler)
        {
            return $this->router->register(new Route('PUT', $address, $handler));
        }

        public function patch (string $address, callable $handler)
        {
            return $this->router->register(new Route('PATCH', $address, $handler));
        }

        public function delete (string $address, callable $handler)
        {
            return $this->router->register(new Route('DELETE', $address, $handler));
        }

        public function route (string $method, string $address, callable $handler)
        {
            return $this->router->register(new Route($method, $address, $handler));
        }

        public function routes ()
        {
            return $this->router->routes();
        }

        public function start (Contracts\Request $request = null, Contracts\Response $response = null)
        {
            try {
                if (\is_null($request)) {
                    $request = Request::factory($this->parsers);
                }

                if (\is_null($response)) {
                    $response = new Response();
                }

                $this->router->handle($request, $response);
            } catch (Contracts\HttpException $e) {
                (new Response())
                    ->status($e->status())
                    ->set($e->headers())
                    ->json([
                        'status' => $e->status(),
                        'error' => [
                            'code' => sprintf("http_error_%s", $e->status()),
                            'message' => $e->message(),
                            'details' => $e->details() ?: new \stdClass()
                        ]
                    ])
                    ->end();
            }
        }
    }
}
