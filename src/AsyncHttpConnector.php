<?php
declare(strict_types=1);

namespace ScriptFUSION\Porter\Net\Http;

use Amp\ByteStream\StreamException;
use Amp\Dns\DnsException;
use Amp\Http\Client\Connection\UnlimitedConnectionPool;
use Amp\Http\Client\Connection\UnprocessedRequestException;
use Amp\Http\Client\Cookie\CookieInterceptor;
use Amp\Http\Client\Cookie\CookieJar;
use Amp\Http\Client\Cookie\InMemoryCookieJar;
use Amp\Http\Client\HttpClient;
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\HttpException;
use Amp\Http\Client\Interceptor\TooManyRedirectsException;
use Amp\Http\Client\InvalidRequestException;
use Amp\Http\Client\ParseException;
use Amp\Http\Client\Request;
use Amp\Http\Client\Response;
use Amp\Http\Client\SocketException;
use Amp\Http\Client\TimeoutException;
use Amp\Promise;
use Amp\Socket\TlsException;
use ScriptFUSION\Porter\Connector\AsyncConnector;
use ScriptFUSION\Porter\Connector\AsyncDataSource;
use function Amp\call;

class AsyncHttpConnector implements AsyncConnector
{
    private $options;

    private $cookieJar;

    private $pool;

    public function __construct(AsyncHttpOptions $options = null, CookieJar $cookieJar = null)
    {
        $this->options = $options ?: new AsyncHttpOptions;
        $this->cookieJar = $cookieJar ?: new InMemoryCookieJar;
        $this->pool = new UnlimitedConnectionPool();
    }

    public function __clone()
    {
        $this->options = clone $this->options;
        $this->cookieJar = clone $this->cookieJar;
        // Sharing the pool is intended and should be harmless.
    }

    public function fetchAsync(AsyncDataSource $source): Promise
    {
        return call(function () use ($source): \Generator {
            if (!$source instanceof AsyncHttpDataSource) {
                throw new \InvalidArgumentException('Source must be of type: AsyncHttpDataSource.');
            }

            $client = $this->createClient();

            try {
                /** @var Response $response */
                $response = yield $client->request($this->createRequest($source));
                $body = yield $response->getBody()->buffer();
            } catch (TooManyRedirectsException|InvalidRequestException|ParseException $exception) {
                // Exclude permanent exceptions that subclass HttpException.
                throw $exception;
            } catch (DnsException|StreamException|HttpException $exception) {
                /*
                 * Retry intermittent DNS failures, low-level stream errors including TLS negotiation failures
                 * and all manner of HTTP exceptions including, but not limited to, socket timeouts and connection
                 * resets by converting them to a recoverable exception type.
                 */
                throw new HttpConnectionException($exception->getMessage(), $exception->getCode(), $exception);
            }

            $response = HttpResponse::fromAmpResponse($response, $body);

            $code = $response->getStatusCode();
            if ($code < 200 || $code >= 400) {
                throw new HttpServerException(
                    // TODO: truncate response in exception message.
                    "HTTP server responded with error: $code \"{$response->getReasonPhrase()}\".\n\n$response",
                    $response
                );
            }

            return $response;
        });
    }

    private function createClient(): HttpClient
    {
        return (new HttpClientBuilder())
            ->usingPool($this->pool)
            ->interceptNetwork(new CookieInterceptor($this->cookieJar))
            ->followRedirects($this->options->getMaxRedirects())
            // We have our own retry implementation.
            ->retry(0)
            ->build()
        ;
    }

    private function createRequest(AsyncHttpDataSource $source): Request
    {
        $request = new Request($source->getUrl(), $source->getMethod());
        $request->setBody($source->getBody());
        $request->setHeaders($source->getHeaders());
        $request->setTransferTimeout($this->options->getTransferTimeout());
        $request->setInactivityTimeout($request->getTransferTimeout());
        $request->setBodySizeLimit($this->options->getMaxBodyLength());

        return $request;
    }

    public function getOptions(): AsyncHttpOptions
    {
        return $this->options;
    }

    public function getCookieJar(): CookieJar
    {
        return $this->cookieJar;
    }
}
