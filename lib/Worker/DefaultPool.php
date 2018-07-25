<?php

namespace Amp\Parallel\Worker;

use Amp\Parallel\Context\StatusError;
use Concurrent\Task as AsyncTask;
use function Concurrent\all;

/**
 * Provides a pool of workers that can be used to execute multiple tasks asynchronously.
 *
 * A worker pool is a collection of worker threads that can perform multiple
 * tasks simultaneously. The load on each worker is balanced such that tasks
 * are completed as soon as possible and workers are used efficiently.
 */
class DefaultPool implements Pool
{
    /** @var bool Indicates if the pool is currently running. */
    private $running = true;

    /** @var int The maximum number of workers the pool should spawn. */
    private $maxSize;

    /** @var WorkerFactory A worker factory to be used to create new workers. */
    private $factory;

    /** @var \SplObjectStorage A collection of all workers in the pool. */
    private $workers;

    /** @var \SplQueue A collection of idle workers. */
    private $idleWorkers;

    /** @var \SplQueue A queue of workers that have been assigned to tasks. */
    private $busyQueue;

    /** @var \Closure */
    private $push;

    /**
     * Creates a new worker pool.
     *
     * @param int                                     $maxSize The maximum number of workers the pool should spawn.
     *     Defaults to `Pool::DEFAULT_MAX_SIZE`.
     * @param \Amp\Parallel\Worker\WorkerFactory|null $factory A worker factory to be used to create
     *     new workers.
     *
     * @throws \Error
     */
    public function __construct(int $maxSize = self::DEFAULT_MAX_SIZE, WorkerFactory $factory = null)
    {
        if ($maxSize < 0) {
            throw new \Error("Maximum size must be a non-negative integer");
        }

        $this->maxSize = $maxSize;

        // Use the global factory if none is given.
        $this->factory = $factory ?: factory();

        $this->workers = new \SplObjectStorage;
        $this->idleWorkers = new \SplQueue;
        $this->busyQueue = new \SplQueue;

        $workers = $this->workers;
        $idleWorkers = $this->idleWorkers;
        $busyQueue = $this->busyQueue;

        $this->push = static function (Worker $worker) use ($workers, $idleWorkers, $busyQueue) {
            \assert($workers->contains($worker), "The provided worker was not part of this queue");

            if (($workers[$worker] -= 1) === 0) {
                // Worker is completely idle, remove from busy queue and add to idle queue.
                foreach ($busyQueue as $key => $busy) {
                    if ($busy === $worker) {
                        unset($busyQueue[$key]);
                        break;
                    }
                }

                $idleWorkers->push($worker);
            }
        };
    }

    public function __destruct()
    {
        if ($this->isRunning()) {
            $this->shutdown();
        }
    }

    /**
     * Checks if the pool is running.
     *
     * @return bool True if the pool is running, otherwise false.
     */
    public function isRunning(): bool
    {
        return $this->running;
    }

    /**
     * Checks if the pool has any idle workers.
     *
     * @return bool True if the pool has at least one idle worker, otherwise false.
     */
    public function isIdle(): bool
    {
        return $this->idleWorkers->count() > 0 || $this->workers->count() === 0;
    }

    /**
     * {@inheritdoc}
     */
    public function getMaxSize(): int
    {
        return $this->maxSize;
    }

    /**
     * {@inheritdoc}
     */
    public function getWorkerCount(): int
    {
        return $this->workers->count();
    }

    /**
     * {@inheritdoc}
     */
    public function getIdleWorkerCount(): int
    {
        return $this->idleWorkers->count();
    }

    /**
     * Enqueues a task to be executed by the worker pool.
     *
     * @param Task $task The task to enqueue.
     *
     * @return mixed The return value of Task::run().
     *
     * @throws StatusError If the pool has been shutdown.
     * @throws TaskException If the task throws an exception.
     */
    public function enqueue(Task $task)
    {
        $worker = $this->pull();

        try {
            return $worker->enqueue($task);
        } finally {
            ($this->push)($worker);
        }
    }

    /**
     * Shuts down the pool and all workers in it.
     *
     * @return int Sum of exit status from all workers.
     *
     * @throws StatusError If the pool has not been started.
     */
    public function shutdown(): int
    {
        if (!$this->isRunning()) {
            throw new StatusError("The pool was shutdown");
        }

        $this->running = false;

        $shutdowns = [];
        foreach ($this->workers as $worker) {
            if ($worker->isRunning()) {
                $shutdowns[] = AsyncTask::async([$worker, 'shutdown']);
            }
        }

        return array_sum($shutdowns ? AsyncTask::await(all($shutdowns)) : []);
    }

    /**
     * Kills all workers in the pool and halts the worker pool.
     */
    public function kill(): void
    {
        $this->running = false;

        foreach ($this->workers as $worker) {
            $worker->kill();
        }
    }

    /**
     * Creates a worker and adds them to the pool.
     *
     * @return Worker The created worker.
     */
    private function createWorker(): Worker
    {
        $worker = $this->factory->create();
        $this->workers->attach($worker, 0);
        return $worker;
    }

    /**
     * {@inheritdoc}
     */
    public function get(): Worker
    {
        return new Internal\PooledWorker($this->pull(), $this->push);
    }

    /**
     * Pulls a worker from the pool. The worker should be put back into the pool with push() to be marked as idle.
     *
     * @return Worker
     *
     * @throws StatusError
     */
    protected function pull(): Worker
    {
        if (!$this->isRunning()) {
            throw new StatusError("The pool was shutdown");
        }

        do {
            if ($this->idleWorkers->isEmpty()) {
                if ($this->getWorkerCount() >= $this->maxSize) {
                    // All possible workers busy, so shift from head (will be pushed back onto tail below).
                    $worker = $this->busyQueue->shift();
                } else {
                    // Max worker count has not been reached, so create another worker.
                    $worker = $this->createWorker();
                    break;
                }
            } else {
                // Shift a worker off the idle queue.
                $worker = $this->idleWorkers->shift();
            }

            if ($worker->isRunning()) {
                break;
            }

            $this->workers->detach($worker);
        } while (true);

        $this->busyQueue->push($worker);
        $this->workers[$worker] += 1;

        return $worker;
    }
}
