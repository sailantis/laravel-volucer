<?php

declare(strict_types=1);

namespace Sailantis\LaravelVolucer\Tests;

use PHPUnit\Framework\TestCase;
use Sailantis\LaravelVolucer\RequestFactory;
use Volucer\Request as VolucerRequest;

/**
 * The translation, and the two fields a worker cannot copy.
 *
 * Laravel re-parses only JSON from `$request->content`, because under a SAPI `$_POST` is already
 * populated by PHP. A worker has no `$_POST`, so the form body has to be carried across
 * explicitly — and without it every controller reads "the client sent nothing", which is the kind
 * of bug that only shows up under a form.
 */
final class RequestFactoryTest extends TestCase
{
    private RequestFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new RequestFactory();
    }

    private static function request(array $overrides = []): VolucerRequest
    {
        return new VolucerRequest(
            method: $overrides['method'] ?? 'GET',
            uri: $overrides['uri'] ?? '/',
            protocol: $overrides['protocol'] ?? 'HTTP/1.1',
            headers: $overrides['headers'] ?? [],
            remoteAddr: $overrides['remoteAddr'] ?? '127.0.0.1',
            scriptFilename: $overrides['scriptFilename'] ?? '/srv/app/public/index.php',
            https: $overrides['https'] ?? false,
            body: $overrides['body'] ?? '',
        );
    }

    public function test_the_request_line_and_server_variables(): void
    {
        $laravel = $this->factory->create(self::request([
            'method' => 'POST',
            'uri' => '/submit?page=2',
            'headers' => [
                'Host' => 'example.test:8443',
                'Content-Type' => 'application/x-www-form-urlencoded',
                'Content-Length' => '7',
                'Accept' => 'application/json',
            ],
            'body' => 'a=1&b=2',
        ]));

        self::assertSame('POST', $laravel->method());
        self::assertSame('submit', $laravel->path());
        self::assertSame('page=2', $laravel->getQueryString());
        self::assertSame('example.test', $laravel->getHost());
        self::assertSame(8443, $laravel->getPort());
        self::assertSame('application/json', $laravel->header('Accept'));
        self::assertSame('127.0.0.1', $laravel->ip());
    }

    public function test_a_url_encoded_body_reaches_the_request_bag(): void
    {
        // The defect this test exists for: `createFromBase()` does not carry `$request->request`
        // when the content type is a form, because it assumes a SAPI filled it.
        $laravel = $this->factory->create(self::request([
            'method' => 'POST',
            'uri' => '/submit',
            'headers' => ['Host' => 'example.test', 'Content-Type' => 'application/x-www-form-urlencoded'],
            'body' => 'a=1&b=two&list%5B%5D=x',
        ]));

        self::assertSame(
            ['a' => '1', 'b' => 'two', 'list' => ['x']],
            $laravel->request->all(),
        );
    }

    public function test_a_json_body_is_not_a_request_bag(): void
    {
        // Laravel re-parses JSON itself (`$request->request = $request->json()`), which is a
        // different rule from the form one and must not be broken by carrying the form body.
        $laravel = $this->factory->create(self::request([
            'method' => 'POST',
            'uri' => '/api',
            'headers' => ['Host' => 'example.test', 'Content-Type' => 'application/json'],
            'body' => '{"a":1}',
        ]));

        self::assertSame('{"a":1}', $laravel->getContent());
        self::assertSame(1, $laravel->json('a'));
    }

    public function test_a_multipart_body_is_deliberately_not_parsed(): void
    {
        $laravel = $this->factory->create(self::request([
            'method' => 'POST',
            'uri' => '/upload',
            'headers' => ['Host' => 'example.test', 'Content-Type' => 'multipart/form-data; boundary=x'],
            'body' => "--x\r\nContent-Disposition: form-data; name=\"a\"\r\n\r\n1\r\n--x--\r\n",
        ]));

        self::assertSame([], $laravel->request->all());
        self::assertSame([], $laravel->allFiles(), 'no files are invented from a body nobody parsed');
    }

    public function test_cookies_reach_the_request(): void
    {
        // Without this every session looks new, and every request starts one.
        $laravel = $this->factory->create(self::request([
            'uri' => '/',
            'headers' => ['Host' => 'example.test', 'Cookie' => 'laravel_session=abc123; theme=dark'],
        ]));

        self::assertSame('abc123', $laravel->cookies->get('laravel_session'));
        self::assertSame('dark', $laravel->cookies->get('theme'));
    }

    public function test_the_scheme_and_port_come_from_the_frame(): void
    {
        $secure = $this->factory->create(self::request([
            'uri' => '/',
            'headers' => ['Host' => 'example.test'],
            'https' => true,
        ]));
        self::assertSame('https', $secure->getScheme());
        self::assertSame(443, $secure->getPort());

        $plain = $this->factory->create(self::request([
            'uri' => '/',
            'headers' => ['Host' => 'example.test'],
        ]));
        self::assertSame('http', $plain->getScheme());
        self::assertSame(80, $plain->getPort());
    }

    public function test_an_ipv6_host_header_keeps_its_brackets(): void
    {
        self::assertSame(['[::1]', '8080'], RequestFactory::splitHost('[::1]:8080', false));
        self::assertSame(['[::1]', '80'], RequestFactory::splitHost('[::1]', false));
        self::assertSame(['localhost', '80'], RequestFactory::splitHost(null, false));
    }

    public function test_a_trusted_proxy_changes_the_reported_ip(): void
    {
        // The safety property is the negative case: with no trust list the header is ignored,
        // which is what stops a client from choosing its own address.
        $request = self::request([
            'uri' => '/',
            'remoteAddr' => '10.1.2.3',
            'headers' => ['Host' => 'example.test', 'X-Forwarded-For' => '203.0.113.9'],
        ]);

        self::assertSame('10.1.2.3', $this->factory->create($request)->ip());
        self::assertSame('203.0.113.9', $this->factory->create($request, ['10.1.2.3'])->ip());
    }
}
