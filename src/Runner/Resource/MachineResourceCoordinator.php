<?php

declare(strict_types=1);

namespace Greenlight\Runner\Resource;

use Greenlight\Core\ErrorTrap;
use Greenlight\Discovery\ExecutionPlan;

/**
 * Coordinates named capacity between local Greenlight processes with advisory
 * file locks. It acquires all required slots as one permit without a wait.
 *
 * @internal
 */
final class MachineResourceCoordinator
{
    private const int DEFINITION_RETRIES = 200;
    private const int DEFINITION_RETRY_MICROSECONDS = 10_000;

    /**
     * @var array<string, resource>
     */
    private array $definitionHandles = [];

    /**
     * @var array<non-empty-string, non-empty-string>
     */
    private array $resourceDirectories = [];

    /**
     * @var array<int, MachineResourcePermit>
     */
    private array $permits = [];

    private bool $closed = false;

    /**
     * @param array<non-empty-string, positive-int> $limits
     */
    private function __construct(
        private readonly array $limits,
        private readonly string $namespace,
        private readonly string $rootDirectory,
    ) {}

    /**
     * @param array<non-empty-string, positive-int> $limits
     * @param non-empty-string|null $namespace
     *
     * @throws ResourceCoordinationError
     */
    public static function open(array $limits, ?string $namespace, ?string $rootDirectory = null): self
    {
        $rootDirectory ??= self::defaultRootDirectory();
        $coordinator = new self($limits, $namespace ?? '', $rootDirectory);

        if ($limits === []) {
            return $coordinator;
        }

        if ($namespace === null) {
            throw new \LogicException('Machine resource limits require a coordination namespace.');
        }

        $coordinator->initialize();

        return $coordinator;
    }

    /**
     * @param array<non-empty-string, positive-int> $limits
     * @param non-empty-string|null $namespace
     *
     * @throws ResourceCoordinationError
     */
    public static function openForPlan(
        ExecutionPlan $plan,
        array $limits,
        ?string $namespace,
        ?string $rootDirectory = null,
    ): self {
        $used = [];

        foreach ($plan->entries as $entry) {
            foreach ($entry->metadata->resources as $resource) {
                if (isset($limits[$resource])) {
                    $used[$resource] = $limits[$resource];
                }
            }
        }

        return self::open($used, $namespace, $rootDirectory);
    }

    /**
     * @param list<non-empty-string> $resources
     *
     * @return MachineResourcePermit|false false when capacity is unavailable
     *
     * @throws ResourceCoordinationError
     */
    public function tryAcquire(array $resources): MachineResourcePermit|false
    {
        if ($this->closed) {
            throw new \LogicException('Machine resource coordination has already closed.');
        }

        $required = [];

        foreach ($resources as $resource) {
            if (isset($this->limits[$resource])) {
                $required[$resource] = $resource;
            }
        }

        \ksort($required, \SORT_STRING);
        $handles = [];
        $keys = [];

        foreach ($required as $resource) {
            $handle = $this->tryAcquireSlot($resource);

            if ($handle === false) {
                $this->closeHandles($handles);

                return false;
            }

            $handles[$resource] = $handle;
            $keys[] = $this->coordinationKey($resource);
        }

        $permit = new MachineResourcePermit($handles, $keys);
        $this->permits[\spl_object_id($permit)] = $permit;

        return $permit;
    }

    public function release(MachineResourcePermit $permit): void
    {
        $id = \spl_object_id($permit);

        if (($this->permits[$id] ?? null) !== $permit) {
            throw new \LogicException('The machine resource permit is unknown or has already been released.');
        }

        unset($this->permits[$id]);
        $permit->close();
    }

    public function hasLimits(): bool
    {
        return $this->limits !== [];
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;

        foreach ($this->permits as $permit) {
            $permit->close();
        }

        $this->permits = [];
        $this->closeHandles($this->definitionHandles);
        $this->definitionHandles = [];
    }

    public function __destruct()
    {
        $this->close();
    }

    /**
     * @throws ResourceCoordinationError
     */
    private function initialize(): void
    {
        $namespaceDirectory = $this->rootDirectory . '/' . \hash('sha256', $this->namespace);
        $this->ensureDirectory($this->rootDirectory);
        $this->ensureDirectory($namespaceDirectory);
        $inherited = MachineResourceEnvironment::inherited();
        $limits = $this->limits;
        \ksort($limits, \SORT_STRING);

        try {
            foreach ($limits as $resource => $limit) {
                if (\in_array($this->coordinationKey($resource), $inherited, true)) {
                    throw ResourceCoordinationError::nestedAcquisition($resource, $this->namespace);
                }

                $resourceDirectory = $namespaceDirectory . '/' . \hash('sha256', $resource);
                $this->ensureDirectory($resourceDirectory);
                $this->resourceDirectories[$resource] = $resourceDirectory;
                $this->registerDefinition($resource, $limit, $resourceDirectory . '/definition.lock');
            }
        } catch (\Throwable $threw) {
            $this->close();

            throw $threw;
        }
    }

    /**
     * @param positive-int $limit
     *
     * @throws ResourceCoordinationError
     */
    private function registerDefinition(string $resource, int $limit, string $path): void
    {
        $handle = $this->openFile($path);
        $wouldBlock = 0;
        $exclusive = ErrorTrap::run(
            static function () use ($handle, &$wouldBlock): bool {
                return \flock($handle, \LOCK_EX | \LOCK_NB, $wouldBlock);
            },
            $warning,
        );

        if ($exclusive) {
            $this->writeDefinition($handle, $path, $limit);

            if (!ErrorTrap::run(static fn(): bool => \flock($handle, \LOCK_SH), $warning)) {
                \fclose($handle);

                throw ResourceCoordinationError::cannotLock($path, $warning);
            }

            $this->definitionHandles[$resource] = $handle;

            return;
        }

        if ($wouldBlock !== 1) {
            \fclose($handle);

            throw ResourceCoordinationError::cannotLock($path, $warning);
        }

        $shared = false;

        for ($attempt = 0; $attempt < self::DEFINITION_RETRIES; ++$attempt) {
            $wouldBlock = 0;
            $shared = ErrorTrap::run(
                static function () use ($handle, &$wouldBlock): bool {
                    return \flock($handle, \LOCK_SH | \LOCK_NB, $wouldBlock);
                },
                $warning,
            );

            if ($shared) {
                break;
            }

            if ($wouldBlock !== 1) {
                \fclose($handle);

                throw ResourceCoordinationError::cannotLock($path, $warning);
            }

            \usleep(self::DEFINITION_RETRY_MICROSECONDS);
        }

        if (!$shared) {
            \fclose($handle);

            throw ResourceCoordinationError::definitionBusy($resource, $this->namespace);
        }

        \rewind($handle);
        $raw = \stream_get_contents($handle);
        $activeLimit = \is_string($raw) && \preg_match('/^[1-9]\d*$/', \trim($raw)) === 1
            ? (int) \trim($raw)
            : 0;

        if ($activeLimit < 1) {
            \fclose($handle);

            throw ResourceCoordinationError::invalidDefinition($resource, $this->namespace);
        }

        if ($activeLimit !== $limit) {
            \fclose($handle);

            throw ResourceCoordinationError::conflictingLimit($resource, $this->namespace, $activeLimit, $limit);
        }

        $this->definitionHandles[$resource] = $handle;
    }

    /**
     * @param resource $handle
     *
     * @throws ResourceCoordinationError
     */
    private function writeDefinition(mixed $handle, string $path, int $limit): void
    {
        $contents = $limit . "\n";
        $written = ErrorTrap::run(static function () use ($handle, $contents): int|false {
            \rewind($handle);

            if (!\ftruncate($handle, 0)) {
                return false;
            }

            $written = \fwrite($handle, $contents);

            if ($written !== \strlen($contents) || !\fflush($handle)) {
                return false;
            }

            return $written;
        }, $warning);

        if ($written === false) {
            \fclose($handle);

            throw ResourceCoordinationError::cannotWriteDefinition($path, $warning);
        }
    }

    /**
     * @return resource|false
     *
     * @throws ResourceCoordinationError
     */
    private function tryAcquireSlot(string $resource): mixed
    {
        $directory = $this->resourceDirectories[$resource];

        for ($slot = 1; $slot <= $this->limits[$resource]; ++$slot) {
            $path = $directory . '/slot-' . $slot . '.lock';
            $handle = $this->openFile($path);
            $wouldBlock = 0;
            $locked = ErrorTrap::run(
                static function () use ($handle, &$wouldBlock): bool {
                    return \flock($handle, \LOCK_EX | \LOCK_NB, $wouldBlock);
                },
                $warning,
            );

            if ($locked) {
                return $handle;
            }

            \fclose($handle);

            if ($wouldBlock !== 1) {
                throw ResourceCoordinationError::cannotLock($path, $warning);
            }
        }

        return false;
    }

    /**
     * @return resource
     *
     * @throws ResourceCoordinationError
     */
    private function openFile(string $path): mixed
    {
        $handle = ErrorTrap::run(static fn() => \fopen($path, 'c+b'), $warning);

        if (!\is_resource($handle)) {
            throw ResourceCoordinationError::cannotOpen($path, $warning);
        }

        return $handle;
    }

    /**
     * @throws ResourceCoordinationError
     */
    private function ensureDirectory(string $path): void
    {
        if (\is_dir($path)) {
            return;
        }

        $created = ErrorTrap::run(static fn(): bool => \mkdir($path, 0o700, true), $warning);

        if (!$created && !\is_dir($path)) {
            throw ResourceCoordinationError::cannotCreateDirectory($path, $warning);
        }
    }

    /**
     * @return non-empty-string
     */
    private function coordinationKey(string $resource): string
    {
        return \hash('sha256', $this->namespace . "\0" . $resource);
    }

    private static function defaultRootDirectory(): string
    {
        $uid = \function_exists('posix_geteuid') ? \posix_geteuid() : \getmyuid();

        return \rtrim(\sys_get_temp_dir(), '/\\') . '/greenlight-resource-locks-v1-' . $uid;
    }

    /**
     * @param array<array-key, resource> $handles
     */
    private function closeHandles(array $handles): void
    {
        foreach ($handles as $handle) {
            ErrorTrap::run(static function () use ($handle): void {
                \flock($handle, \LOCK_UN);
                \fclose($handle);
            });
        }
    }
}
