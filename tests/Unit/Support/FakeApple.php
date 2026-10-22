<?php

declare(strict_types=1);

namespace OCA\AppleSync\Tests\Unit\Support;

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use OCP\Http\Client\IResponse;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Scripted stand-in for Apple's servers. Routes are matched on
 * "METHOD /path" (no host, no query); every request is recorded.
 * Requests go through a real Guzzle stack, so cookies behave as in production.
 */
final class FakeApple {
	/** @var array<string, callable(RequestInterface): ResponseInterface> */
	private array $routes = [];
	/** @var list<RequestInterface> */
	public array $requests = [];
	private ?Client $guzzle = null;

	/**
	 * @param callable(RequestInterface): ResponseInterface|ResponseInterface $response
	 */
	public function on(string $methodAndPath, callable|ResponseInterface $response): self {
		$this->routes[$methodAndPath] = $response instanceof ResponseInterface ? static fn () => $response : $response;
		return $this;
	}

	public static function json(int $status, array $body = [], array $headers = []): Response {
		return new Response($status, $headers + ['Content-Type' => 'application/json'], $body === [] ? '' : json_encode($body));
	}

	/** Last recorded request for a route, or null. */
	public function last(string $methodAndPath): ?RequestInterface {
		for ($i = count($this->requests) - 1; $i >= 0; $i--) {
			if (self::key($this->requests[$i]) === $methodAndPath) {
				return $this->requests[$i];
			}
		}
		return null;
	}

	public function lastJson(string $methodAndPath): array {
		$request = $this->last($methodAndPath);
		TestCase::assertNotNull($request, "No request to $methodAndPath");
		return json_decode((string)$request->getBody(), true) ?? [];
	}

	/**
	 * Use as the callback for a mocked IClient::request().
	 */
	public function handle(string $method, string $uri, array $options = []): IResponse {
		$this->guzzle ??= new Client(['handler' => HandlerStack::create(function (RequestInterface $request, array $options) {
			$this->requests[] = $request;
			$route = $this->routes[self::key($request)] ?? null;
			$response = $route !== null ? $route($request) : new Response(404, [], 'no route: ' . self::key($request));
			return Create::promiseFor($response);
		})]);
		return new FakeResponse($this->guzzle->request($method, $uri, $options));
	}

	private static function key(RequestInterface $request): string {
		return $request->getMethod() . ' ' . $request->getUri()->getPath();
	}
}

final class FakeResponse implements IResponse {
	public function __construct(
		private ResponseInterface $response,
	) {
	}

	public function getBody() {
		return (string)$this->response->getBody();
	}

	public function getStatusCode(): int {
		return $this->response->getStatusCode();
	}

	public function getHeader(string $key): string {
		return $this->response->getHeader($key)[0] ?? '';
	}

	public function getHeaders(): array {
		return $this->response->getHeaders();
	}
}
