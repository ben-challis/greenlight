<?php

declare(strict_types=1);

namespace Greenlight\Cli\Watch;

use FFI\CData;
use FFI\Exception as FfiException;

/**
 * Watches directories with Linux inotify through FFI.
 *
 * open() returns null when the system is not Linux, FFI is not available, or
 * the kernel refuses a new inotify instance. The descriptor is nonblocking, so
 * changed() never waits.
 *
 * Each directory uses one watch. Events in a directory include changes to its
 * files. A watch does not include subdirectories.
 *
 * A full event queue drops events, so changed() returns true and the next scan
 * finds the changes. A failure that a scan cannot correct closes the
 * descriptor. An example is the system watch limit. After such a failure,
 * changed() always returns true, so each poll scans.
 *
 * @internal
 */
final class LinuxInotify implements DirectoryNotifier
{
    private const string DECLARATIONS = <<<'C'
        int inotify_init1(int flags);
        int inotify_add_watch(int fd, const char *pathname, uint32_t mask);
        int inotify_rm_watch(int fd, int wd);
        ssize_t read(int fd, void *buf, size_t count);
        int close(int fd);
        int *__errno_location(void);
        C;

    private const int IN_NONBLOCK = 0x800;
    private const int IN_CLOEXEC = 0x80000;
    private const int IN_MODIFY = 0x2;
    private const int IN_ATTRIB = 0x4;
    private const int IN_CLOSE_WRITE = 0x8;
    private const int IN_MOVED_FROM = 0x40;
    private const int IN_MOVED_TO = 0x80;
    private const int IN_CREATE = 0x100;
    private const int IN_DELETE = 0x200;
    private const int IN_DELETE_SELF = 0x400;
    private const int IN_ONLYDIR = 0x1000000;
    private const int IN_DONT_FOLLOW = 0x2000000;
    private const int IN_EXCL_UNLINK = 0x4000000;
    private const int IN_MOVE_SELF = 0x800;
    private const int IN_IGNORED = 0x8000;
    private const int WATCH_MASK = self::IN_MODIFY | self::IN_ATTRIB | self::IN_CLOSE_WRITE
        | self::IN_MOVED_FROM | self::IN_MOVED_TO | self::IN_CREATE | self::IN_DELETE
        | self::IN_DELETE_SELF | self::IN_MOVE_SELF
        | self::IN_ONLYDIR | self::IN_DONT_FOLLOW | self::IN_EXCL_UNLINK;
    private const int EVENT_HEADER_BYTES = 16;
    private const int BUFFER_BYTES = 65_536;

    private const int EINTR = 4;
    private const int EAGAIN = 11;

    /** Errors for one path that the next scan corrects. */
    private const array PATH_ERRORS = [
        2, // ENOENT
        13, // EACCES
        20, // ENOTDIR
        40, // ELOOP
    ];

    /** @var array<non-empty-string, int> directory to watch descriptor */
    private array $watches = [];

    private bool $failed = false;

    private bool $closed = false;

    private function __construct(
        private readonly \FFI $ffi,
        private readonly CData $buffer,
        private readonly int $descriptor,
    ) {}

    public function __destruct()
    {
        $this->close();
    }

    public static function open(): ?self
    {
        if (\PHP_OS_FAMILY !== 'Linux' || !\extension_loaded('ffi')) {
            return null;
        }

        try {
            $ffi = \FFI::cdef(self::DECLARATIONS);
            $descriptor = self::call($ffi, 'inotify_init1', self::IN_NONBLOCK | self::IN_CLOEXEC);
            $buffer = $ffi->new('char[' . self::BUFFER_BYTES . ']');
        } catch (FfiException) {
            return null;
        }

        if ($descriptor < 0) {
            return null;
        }

        return new self($ffi, $buffer, $descriptor);
    }

    #[\Override]
    public function watch(array $directories): bool
    {
        if ($this->failed) {
            return false;
        }

        $wanted = \array_fill_keys($directories, true);

        foreach ($this->watches as $directory => $descriptor) {
            if (isset($wanted[$directory])) {
                continue;
            }

            unset($this->watches[$directory]);

            if (!\in_array($descriptor, $this->watches, true)) {
                self::call($this->ffi, 'inotify_rm_watch', $this->descriptor, $descriptor);
            }
        }

        $started = false;

        foreach ($directories as $directory) {
            if (isset($this->watches[$directory])) {
                continue;
            }

            $started = true;
            $descriptor = self::call($this->ffi, 'inotify_add_watch', $this->descriptor, $directory, self::WATCH_MASK);

            if ($descriptor >= 0) {
                $this->watches[$directory] = $descriptor;

                continue;
            }

            if (!\in_array($this->errno(), self::PATH_ERRORS, true)) {
                $this->fail();

                return true;
            }
        }

        return $started;
    }

    #[\Override]
    public function changed(): bool
    {
        $changed = false;

        while (!$this->failed) {
            $length = self::call($this->ffi, 'read', $this->descriptor, $this->buffer, self::BUFFER_BYTES);

            if ($length > 0) {
                $changed = $this->readEvents(\FFI::string($this->buffer, $length)) || $changed;

                continue;
            }

            $errno = $length < 0 ? $this->errno() : 0;

            if ($errno === self::EINTR) {
                continue;
            }

            if ($errno !== self::EAGAIN) {
                $this->fail();
            }

            break;
        }

        return $changed || $this->failed;
    }

    private function readEvents(string $events): bool
    {
        $changed = false;
        $offset = 0;
        $length = \strlen($events);

        while ($offset + self::EVENT_HEADER_BYTES <= $length) {
            /** @var array{descriptor: int, mask: int, cookie: int, nameLength: int} $event */
            $event = \unpack('ldescriptor/Lmask/Lcookie/LnameLength', $events, $offset);
            $offset += self::EVENT_HEADER_BYTES + $event['nameLength'];

            if (($event['mask'] & (self::IN_IGNORED | self::IN_MOVE_SELF)) !== 0) {
                // The path no longer names the watched directory. The next
                // watch() call must start a new watch for that path.
                $this->forget($event['descriptor'], ($event['mask'] & self::IN_MOVE_SELF) !== 0);
            }

            if (($event['mask'] & self::IN_IGNORED) === 0) {
                $changed = true;
            }
        }

        return $changed;
    }

    private function forget(int $descriptor, bool $remove): void
    {
        foreach (\array_keys($this->watches, $descriptor, true) as $directory) {
            unset($this->watches[$directory]);
        }

        if ($remove) {
            // A moved directory keeps its watch at the new path.
            self::call($this->ffi, 'inotify_rm_watch', $this->descriptor, $descriptor);
        }
    }

    private function errno(): int
    {
        $location = self::invoke($this->ffi, '__errno_location');
        $errno = $location instanceof CData ? $location[0] : null;

        return \is_int($errno) ? $errno : 0;
    }

    /**
     * Calls a declared C function that returns an integer.
     *
     * @param non-empty-string $function
     */
    private static function call(\FFI $ffi, string $function, mixed ...$arguments): int
    {
        $result = self::invoke($ffi, $function, ...$arguments);

        return \is_int($result) ? $result : -1;
    }

    /** @param non-empty-string $function */
    private static function invoke(\FFI $ffi, string $function, mixed ...$arguments): mixed
    {
        return $ffi->{$function}(...$arguments); // @phpstan-ignore method.dynamicName (FFI::cdef() declares the C functions at runtime.)
    }

    private function fail(): void
    {
        $this->failed = true;
        $this->close();
    }

    private function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;
        $this->watches = [];
        self::call($this->ffi, 'close', $this->descriptor);
    }
}
