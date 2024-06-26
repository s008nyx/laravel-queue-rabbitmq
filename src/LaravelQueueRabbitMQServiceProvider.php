<?php

namespace VladimirYuldashev\LaravelQueueRabbitMQ;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Queue\QueueManager;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\ServiceProvider;
use VladimirYuldashev\LaravelQueueRabbitMQ\Console\ConsumeCommand;
use VladimirYuldashev\LaravelQueueRabbitMQ\Queue\Connectors\RabbitMQConnector;

class LaravelQueueRabbitMQServiceProvider extends ServiceProvider
{
    /**
     * Register the service provider.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/rabbitmq.php',
            'queue.connections.rabbitmq'
        );

        if ($this->app->runningInConsole()) {
            $this->app->singleton('rabbitmq.consumer', function ($app) {
                $isDownForMaintenance = function () {
                    return $this->app->isDownForMaintenance();
                };

                $resetScope = function () use ($app) {
                    if (method_exists($app['log']->driver(), 'withoutContext')) {
                        $app['log']->withoutContext();
                    }
    
                    if (method_exists($app['db'], 'getConnections')) {
                        foreach ($app['db']->getConnections() as $connection) {
                            $connection->resetTotalQueryDuration();
                            $connection->allowQueryDurationHandlersToRunAgain();
                        }
                    }
    
                    $app->forgetScopedInstances();
    
                    return Facade::clearResolvedInstances();
                };

                return new Consumer(
                    $this->app['queue'],
                    $this->app['events'],
                    $this->app[ExceptionHandler::class],
                    $isDownForMaintenance,
                    $resetScope
                );
            });

            $this->app->singleton(ConsumeCommand::class, static function ($app) {
                return new ConsumeCommand(
                    $app['rabbitmq.consumer'],
                    $app['cache.store']
                );
            });

            $this->commands([
                Console\ConsumeCommand::class,
            ]);
        }

        $this->commands([
            Console\ExchangeDeclareCommand::class,
            Console\ExchangeDeleteCommand::class,
            Console\QueueBindCommand::class,
            Console\QueueDeclareCommand::class,
            Console\QueueDeleteCommand::class,
            Console\QueuePurgeCommand::class,
        ]);
    }

    /**
     * Register the application's event listeners.
     */
    public function boot(): void
    {
        /** @var QueueManager $queue */
        $queue = $this->app['queue'];

        $queue->addConnector('rabbitmq', function () {
            return new RabbitMQConnector($this->app['events']);
        });
    }
}
