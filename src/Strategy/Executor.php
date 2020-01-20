<?php

declare(strict_types=1);
/**
 * This file is part of Hyperf.
 *
 * @link     https://www.hyperf.io
 * @document https://doc.hyperf.io
 * @contact  group@hyperf.io
 * @license  https://github.com/hyperf/hyperf/blob/master/LICENSE
 */

namespace Hyperf\Crontab\Strategy;

use Carbon\Carbon;
use Closure;
use Hyperf\Contract\ApplicationInterface;
use Hyperf\Contract\StdoutLoggerInterface;
use Hyperf\Crontab\Crontab;
use Hyperf\Crontab\LoggerInterface;
use Hyperf\Crontab\Mutex\RedisServerMutex;
use Hyperf\Crontab\Mutex\RedisTaskMutex;
use Hyperf\Crontab\Mutex\ServerMutex;
use Hyperf\Crontab\Mutex\TaskMutex;
use Hyperf\Utils\Coroutine;
use Psr\Container\ContainerInterface;
use Swoole\Timer;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

class Executor
{
    /**
     * @var \Psr\Container\ContainerInterface
     */
    protected $container;

    /**
     * @var null|\Hyperf\Crontab\LoggerInterface
     */
    protected $logger;

    /**
     * @var \Hyperf\Crontab\Mutex\TaskMutex
     */
    protected $taskMutex;

    /**
     * @var \Hyperf\Crontab\Mutex\ServerMutex
     */
    protected $serverMutex;

    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
        if ($container->has(LoggerInterface::class)) {
            $this->logger = $container->get(LoggerInterface::class);
        } elseif ($container->has(StdoutLoggerInterface::class)) {
            $this->logger = $container->get(StdoutLoggerInterface::class);
        }
    }

    public function execute(Crontab $crontab)
    {
        if (! $crontab instanceof Crontab || ! $crontab->getExecuteTime()) {
            return;
        }
        $diff = $crontab->getExecuteTime()->diffInRealSeconds(new Carbon());
        $callback = null;
        switch ($crontab->getType()) {
            case 'callback':
                [$class, $method] = $crontab->getCallback();
                $parameters = $crontab->getCallback()[2] ?? null;
                if ($class && $method && class_exists($class) && method_exists($class, $method)) {
                    $callback = function () use ($class, $method, $parameters, $crontab) {
                        $runnable = function () use ($class, $method, $parameters, $crontab) {
                            try {
                                $result = true;
                                $instance = make($class);
                                if ($parameters && is_array($parameters)) {
                                    $instance->{$method}(...$parameters);
                                } else {
                                    $instance->{$method}();
                                }
                            } catch (\Throwable $throwable) {
                                $result = false;
                            } finally {
                                if ($this->logger) {
                                    if ($result) {
                                        $this->logger->info(sprintf('Crontab task [%s] execute success at %s.', $crontab->getName(), date('Y-m-d H:i:s')));
                                    } else {
                                        $this->logger->error(sprintf('Crontab task [%s] execute failure at %s.', $crontab->getName(), date('Y-m-d H:i:s')));
                                    }
                                }
                            }
                        };

                        if ($crontab->isSingleton()) {
                            $runnable = $this->runInSingleton($crontab, $runnable);
                        }

                        if ($crontab->isOnOneServer()) {
                            $runnable = $this->runOnOneServer($crontab, $runnable);
                        }

                        Coroutine::create($runnable);
                    };
                }
                break;
            case 'command':
                $input = new ArrayInput($crontab->getCallback());
                $output = new NullOutput();
                $application = $this->container->get(ApplicationInterface::class);
                $application->setAutoExit(false);
                $callback = function () use ($application, $input, $output) {
                    $application->run($input, $output);
                };
                break;
            case 'eval':
                $callback = function () use ($crontab) {
                    eval($crontab->getCallback());
                };
                break;
        }
        $callback && Timer::after($diff > 0 ? $diff * 1000 : 1, $callback);
    }

    protected function runInSingleton(Crontab $crontab, Closure $runnable): Closure
    {
        return function () use ($crontab, $runnable) {
            $taskMutex = $this->getTaskMutex();

            if ($taskMutex->exists($crontab) || ! $taskMutex->create($crontab)) {
                $this->logger->info(sprintf('Crontab task [%s] skip to execute at %s.', $crontab->getName(), date('Y-m-d H:i:s')));
                return;
            }

            try {
                $runnable();
            } finally {
                $taskMutex->remove($crontab);
            }
        };
    }

    protected function getTaskMutex(): TaskMutex
    {
        if (! $this->taskMutex) {
            $this->taskMutex = $this->container->has(TaskMutex::class)
            ? $this->container->get(TaskMutex::class)
            : $this->container->get(RedisTaskMutex::class);
        }
        return $this->taskMutex;
    }

    protected function runOnOneServer(Crontab $crontab, Closure $runnable): Closure
    {
        return function () use ($crontab, $runnable) {
            $taskMutex = $this->getServerMutex();

            if (! $taskMutex->attempt($crontab)) {
                $this->logger->info(sprintf('Crontab task [%s] skip to execute at %s.', $crontab->getName(), date('Y-m-d H:i:s')));
                return;
            }

            $runnable();
        };
    }

    protected function getServerMutex(): ServerMutex
    {
        if (! $this->serverMutex) {
            $this->serverMutex = $this->container->has(ServerMutex::class)
            ? $this->container->get(ServerMutex::class)
            : $this->container->get(RedisServerMutex::class);
        }
        return $this->serverMutex;
    }
}
