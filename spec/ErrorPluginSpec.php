<?php

namespace spec\Http\Client\Plugin;

use Http\Promise\FulfilledPromise;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use PhpSpec\ObjectBehavior;
use Prophecy\Argument;

class ErrorPluginSpec extends ObjectBehavior
{
    function it_is_initializable()
    {
        $this->beAnInstanceOf('Http\Client\Plugin\ErrorPlugin');
    }

    function it_is_a_plugin()
    {
        $this->shouldImplement('Http\Client\Plugin\Plugin');
    }

    function it_throw_client_error_exception_on_4xx_error(RequestInterface $request, ResponseInterface $response)
    {
        $response->getStatusCode()->willReturn('400');
        $response->getReasonPhrase()->willReturn('Bad request');

        $next = function (RequestInterface $receivedRequest) use($request, $response) {
            if (Argument::is($request->getWrappedObject())->scoreArgument($receivedRequest)) {
                return new FulfilledPromise($response->getWrappedObject());
            }
        };

        $promise = $this->handleRequest($request, $next, function () {});
        $promise->shouldReturnAnInstanceOf('Http\Promise\RejectedPromise');
        $promise->shouldThrow('Http\Client\Plugin\Exception\ClientErrorException')->duringWait();
    }

    function it_throw_server_error_exception_on_5xx_error(RequestInterface $request, ResponseInterface $response)
    {
        $response->getStatusCode()->willReturn('500');
        $response->getReasonPhrase()->willReturn('Server error');

        $next = function (RequestInterface $receivedRequest) use($request, $response) {
            if (Argument::is($request->getWrappedObject())->scoreArgument($receivedRequest)) {
                return new FulfilledPromise($response->getWrappedObject());
            }
        };

        $promise = $this->handleRequest($request, $next, function () {});
        $promise->shouldReturnAnInstanceOf('Http\Promise\RejectedPromise');
        $promise->shouldThrow('Http\Client\Plugin\Exception\ServerErrorException')->duringWait();
    }

    function it_returns_response(RequestInterface $request, ResponseInterface $response)
    {
        $response->getStatusCode()->willReturn('200');

        $next = function (RequestInterface $receivedRequest) use($request, $response) {
            if (Argument::is($request->getWrappedObject())->scoreArgument($receivedRequest)) {
                return new FulfilledPromise($response->getWrappedObject());
            }
        };

        $this->handleRequest($request, $next, function () {})->shouldReturnAnInstanceOf('Http\Promise\FulfilledPromise');
    }
}
