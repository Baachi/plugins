<?php

namespace Http\Client\Plugin;

use Http\Client\Common\EmulatedHttpAsyncClient;
use Http\Client\Exception;
use Http\Client\HttpAsyncClient;
use Http\Client\HttpClient;
use Http\Client\Plugin\Exception\LoopException;
use Http\Promise\FulfilledPromise;
use Http\Promise\RejectedPromise;
use Psr\Http\Message\RequestInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The client managing plugins and providing a decorator around HTTP Clients.
 *
 * @author Joel Wurtz <joel.wurtz@gmail.com>
 */
final class PluginClient implements HttpClient, HttpAsyncClient
{
    /**
     * An HTTP async client.
     *
     * @var HttpAsyncClient
     */
    private $client;

    /**
     * The plugin chain.
     *
     * @var Plugin[]
     */
    private $plugins;

    /**
     * A list of options.
     *
     * @var array
     */
    private $options;

    /**
     * @param HttpClient|HttpAsyncClient $client
     * @param Plugin[]                   $plugins
     * @param array                      $options
     *
     * @throws \RuntimeException if client is not an instance of HttpClient or HttpAsyncClient
     */
    public function __construct($client, array $plugins = [], array $options = [])
    {
        if ($client instanceof HttpAsyncClient) {
            $this->client = $client;
        } elseif ($client instanceof HttpClient) {
            $this->client = new EmulatedHttpAsyncClient($client);
        } else {
            throw new \RuntimeException('Client must be an instance of Http\\Client\\HttpClient or Http\\Client\\HttpAsyncClient');
        }

        $this->plugins = $plugins;
        $this->options = $this->configure($options);
    }

    /**
     * Adds a plugin to the client.
     *
     * @param Plugin $plugin
     */
    public function addPlugin(Plugin $plugin)
    {
        $this->plugins[] = $plugin;
    }

    /**
     * Get all active plugins.
     *
     * @return Plugin[]
     */
    public function getPlugins()
    {
        return $this->plugins;
    }

    /**
     * Assign plugins to the client.
     *
     * @param Plugin[] $plugins
     */
    public function setPlugins(array $plugins = [])
    {
        $this->plugins = $plugins;
    }

    /**
     * {@inheritdoc}
     */
    public function sendRequest(RequestInterface $request)
    {
        // If we don't have an http client, use the async call
        if (!($this->client instanceof HttpClient)) {
            return $this->sendAsyncRequest($request)->wait();
        }

        // Else we want to use the synchronous call of the underlying client, and not the async one in the case
        // we have both an async and sync call
        $pluginChain = $this->createPluginChain($this->plugins, function (RequestInterface $request) {
            try {
                return new FulfilledPromise($this->client->sendRequest($request));
            } catch (Exception $exception) {
                return new RejectedPromise($exception);
            }
        });

        return $pluginChain($request)->wait();
    }

    /**
     * {@inheritdoc}
     */
    public function sendAsyncRequest(RequestInterface $request)
    {
        $pluginChain = $this->createPluginChain($this->plugins, function (RequestInterface $request) {
            return $this->client->sendAsyncRequest($request);
        });

        return $pluginChain($request);
    }

    /**
     * Configure the plugin client.
     *
     * @param array $options
     *
     * @return array
     */
    private function configure(array $options = [])
    {
        $resolver = new OptionsResolver();
        $resolver->setDefaults([
            'max_restarts' => 10,
        ]);

        return $resolver->resolve($options);
    }

    /**
     * Create the plugin chain.
     *
     * @param Plugin[] $pluginList     A list of plugins
     * @param callable $clientCallable Callable making the HTTP call
     *
     * @return callable
     */
    private function createPluginChain($pluginList, callable $clientCallable)
    {
        $firstCallable = $lastCallable = $clientCallable;

        while ($plugin = array_pop($pluginList)) {
            $lastCallable = function (RequestInterface $request) use ($plugin, $lastCallable, &$firstCallable) {
                return $plugin->handleRequest($request, $lastCallable, $firstCallable);
            };

            $firstCallable = $lastCallable;
        }

        $firstCalls = 0;
        $firstCallable = function (RequestInterface $request) use ($lastCallable, &$firstCalls) {
            if ($firstCalls > $this->options['max_restarts']) {
                throw new LoopException('Too many restarts in plugin client', $request);
            }

            ++$firstCalls;

            return $lastCallable($request);
        };

        return $firstCallable;
    }
}
