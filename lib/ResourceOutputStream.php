<?php

namespace Amp\ByteStream;

use Revolt\EventLoop\Loop;
use Revolt\Future\Deferred;

/**
 * Output stream abstraction for PHP's stream resources.
 */
final class ResourceOutputStream implements OutputStream
{
    private const MAX_CONSECUTIVE_EMPTY_WRITES = 3;
    private const LARGE_CHUNK_SIZE = 128 * 1024;

    /** @var resource|null */
    private $resource;

    /** @var string */
    private string $watcher;

    /** @var \SplQueue<array> */
    private \SplQueue $writes;

    /** @var bool */
    private bool $writable = true;

    /** @var int|null */
    private ?int $chunkSize = null;

    /**
     * @param resource $stream Stream resource.
     * @param int|null $chunkSize Chunk size per `fwrite()` operation.
     */
    public function __construct($stream, int $chunkSize = null)
    {
        if (!\is_resource($stream) || \get_resource_type($stream) !== 'stream') {
            throw new \Error("Expected a valid stream");
        }

        $meta = \stream_get_meta_data($stream);

        if (\str_contains($meta["mode"], "r") && !\str_contains($meta["mode"], "+")) {
            throw new \Error("Expected a writable stream");
        }

        \stream_set_blocking($stream, false);
        \stream_set_write_buffer($stream, 0);

        $this->resource = $stream;
        $this->chunkSize = &$chunkSize;

        $writes = $this->writes = new \SplQueue;
        $writable = &$this->writable;
        $resource = &$this->resource;

        $this->watcher = Loop::onWritable($stream, static function ($watcher, $stream) use (
            $writes,
            &$chunkSize,
            &$writable,
            &$resource
        ): void {
            static $emptyWrites = 0;

            try {
                while (!$writes->isEmpty()) {
                    /** @var Deferred|null $deferred */
                    [$data, $previous, $deferred] = $writes->shift();
                    $length = \strlen($data);

                    if ($length === 0) {
                        $deferred?->complete(null);
                        continue;
                    }

                    if (!\is_resource($stream)
                        || \get_resource_type($stream) !== 'stream'
                        || (($metaData = @\stream_get_meta_data($stream)) && $metaData['eof'])
                    ) {
                        throw new ClosedException("The stream was closed by the peer");
                    }

                    // Error reporting suppressed since fwrite() emits E_WARNING if the pipe is broken or the buffer is full.
                    // Use conditional, because PHP doesn't like getting null passed
                    if ($chunkSize) {
                        $written = @\fwrite($stream, $data, $chunkSize);
                    } else {
                        $written = @\fwrite($stream, $data);
                    }

                    \assert(
                        $written !== false || \PHP_VERSION_ID >= 70400, // PHP 7.4+ returns false on EPIPE.
                        "Trying to write on a previously fclose()'d resource. Do NOT manually fclose() resources the still referenced in the loop."
                    );

                    // PHP 7.4.0 and 7.4.1 may return false on EAGAIN.
                    if ($written === false && \PHP_VERSION_ID >= 70402) {
                        $message = "Failed to write to stream";
                        if ($error = \error_get_last()) {
                            $message .= \sprintf("; %s", $error["message"]);
                        }
                        throw new StreamException($message);
                    }

                    // Broken pipes between processes on macOS/FreeBSD do not detect EOF properly.
                    if ($written === 0 || $written === false) {
                        if ($emptyWrites++ > self::MAX_CONSECUTIVE_EMPTY_WRITES) {
                            $message = "Failed to write to stream after multiple attempts";
                            if ($error = \error_get_last()) {
                                $message .= \sprintf("; %s", $error["message"]);
                            }
                            throw new StreamException($message);
                        }

                        $writes->unshift([$data, $previous, $deferred]);
                        return;
                    }

                    $emptyWrites = 0;

                    if ($length > $written) {
                        $data = \substr($data, $written);
                        $writes->unshift([$data, $written + $previous, $deferred]);
                        return;
                    }

                    $deferred?->complete(null);
                }
            } catch (\Throwable $exception) {
                $resource = null;
                $writable = false;

                /** @psalm-suppress PossiblyUndefinedVariable */
                $deferred?->error($exception);
                while (!$writes->isEmpty()) {
                    [, , $deferred] = $writes->shift();
                    $deferred?->error($exception);
                }

                Loop::cancel($watcher);
            } finally {
                if ($writes->isEmpty()) {
                    Loop::disable($watcher);
                }
            }
        });

        Loop::disable($this->watcher);
    }

    /**
     * Writes data to the stream.
     *
     * @param string $data Bytes to write.
     *
     * @throws ClosedException If the stream has already been closed.
     */
    public function write(string $data): void
    {
        $this->send($data, false);
    }

    /**
     * Closes the stream after all pending writes have been completed. Optionally writes a final data chunk before.
     *
     * @param string $finalData Bytes to write.
     *
     * @throws ClosedException If the stream has already been closed.
     */
    public function end(string $finalData = ""): void
    {
        $this->send($finalData, true);
    }

    /**
     * Closes the stream forcefully. Multiple `close()` calls are ignored.
     */
    public function close(): void
    {
        if (\is_resource($this->resource) && \get_resource_type($this->resource) === 'stream') {
            // Error suppression, as resource might already be closed
            $meta = @\stream_get_meta_data($this->resource);

            if ($meta && \str_contains($meta["mode"], "+")) {
                @\stream_socket_shutdown($this->resource, \STREAM_SHUT_WR);
            } else {
                /** @psalm-suppress InvalidPropertyAssignmentValue psalm reports this as closed-resource */
                @\fclose($this->resource);
            }
        }

        $this->free();
    }

    /**
     * @return resource|null Stream resource or null if end() has been called or the stream closed.
     */
    public function getResource()
    {
        return $this->resource;
    }

    public function setChunkSize(int $chunkSize): void
    {
        $this->chunkSize = $chunkSize;
    }

    public function __destruct()
    {
        $this->free();
    }

    /**
     * @throws ClosedException
     * @throws StreamException
     */
    private function send(string $data, bool $end = false): void
    {
        if (!$this->writable) {
            throw new ClosedException("The stream is not writable");
        }

        $length = \strlen($data);
        $written = 0;

        if ($end) {
            $this->writable = false;
        }

        if ($this->writes->isEmpty()) {
            if ($length === 0) {
                if ($end) {
                    $this->close();
                }

                return;
            }

            if (!\is_resource($this->resource) || (($metaData = @\stream_get_meta_data($this->resource)) && $metaData['eof'])) {
                throw new ClosedException("The stream was closed by the peer");
            }

            // Error reporting suppressed since fwrite() emits E_WARNING if the pipe is broken or the buffer is full.
            // Use conditional, because PHP doesn't like getting null passed.
            if ($this->chunkSize) {
                $written = @\fwrite($this->resource, $data, $this->chunkSize);
            } else {
                $written = @\fwrite($this->resource, $data);
            }

            \assert(
                $written !== false || \PHP_VERSION_ID >= 70400, // PHP 7.4+ returns false on EPIPE.
                "Trying to write on a previously fclose()'d resource. Do NOT manually fclose() resources the still referenced in the loop."
            );

            // PHP 7.4.0 and 7.4.1 may return false on EAGAIN.
            if ($written === false && \PHP_VERSION_ID >= 70402) {
                $message = "Failed to write to stream";
                if ($error = \error_get_last()) {
                    $message .= \sprintf("; %s", $error["message"]);
                }
                throw new StreamException($message);
            }

            $written = (int) $written; // Cast potential false to 0.

            if ($length === $written) {
                if ($end) {
                    $this->close();
                }

                return;
            }

            $data = \substr($data, $written);
        }

        if ($length - $written > self::LARGE_CHUNK_SIZE) {
            $chunks = \str_split($data, self::LARGE_CHUNK_SIZE);
            $data = \array_pop($chunks);
            foreach ($chunks as $chunk) {
                $this->writes->push([$chunk, $written, null]);
                $written += self::LARGE_CHUNK_SIZE;
            }
        }

        Loop::enable($this->watcher);
        $this->writes->push([$data, $written, $deferred = new Deferred]);
        $deferred->getFuture()->join();

        if ($end) {
            $this->close();
        }
    }

    /**
     * Nulls reference to resource, marks stream unwritable, and fails any pending write.
     */
    private function free(): void
    {
        if ($this->resource === null) {
            return;
        }

        $this->resource = null;
        $this->writable = false;

        if (!$this->writes->isEmpty()) {
            Loop::defer(function (): void {
                $exception = new ClosedException("The socket was closed before writing completed");
                do {
                    /** @var Deferred|null $deferred */
                    [, , $deferred] = $this->writes->shift();
                    $deferred?->error($exception);
                } while (!$this->writes->isEmpty());
            });
        }

        Loop::cancel($this->watcher);
    }
}
