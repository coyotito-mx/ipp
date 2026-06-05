<?php

declare(strict_types=1);

namespace Coyotito\Ipp\Protocol;

use Coyotito\Ipp\Exceptions\DecodingException;

/**
 * A forward-only cursor over a binary string with the big-endian readers the
 * IPP wire format needs. Supports save/restore of the position so the decoder
 * can peek ahead to detect 1setOf additional values.
 */
final class ByteReader
{
    private int $position = 0;

    private readonly int $length;

    public function __construct(private readonly string $bytes)
    {
        $this->length = strlen($bytes);
    }

    public function position(): int
    {
        return $this->position;
    }

    public function seek(int $position): void
    {
        $this->position = $position;
    }

    public function eof(): bool
    {
        return $this->position >= $this->length;
    }

    public function remaining(): int
    {
        return $this->length - $this->position;
    }

    public function readBytes(int $count): string
    {
        if ($count < 0 || $this->position + $count > $this->length) {
            throw new DecodingException(
                "Unexpected end of message: tried to read {$count} bytes at offset {$this->position}."
            );
        }

        $slice = substr($this->bytes, $this->position, $count);
        $this->position += $count;

        return $slice;
    }

    public function rest(): string
    {
        $slice = substr($this->bytes, $this->position);
        $this->position = $this->length;

        return $slice;
    }

    public function readUInt8(): int
    {
        return ord($this->readBytes(1));
    }

    public function readUInt16(): int
    {
        /** @var array{1:int} $parts */
        $parts = unpack('n', $this->readBytes(2));

        return $parts[1];
    }

    /**
     * Read a 4-byte big-endian value as a signed 32-bit integer (IPP integers
     * are SIGNED-INTEGER).
     */
    public function readInt32(): int
    {
        return self::toSignedInt32($this->readBytes(4));
    }

    public function peekUInt8(): ?int
    {
        return $this->eof() ? null : ord($this->bytes[$this->position]);
    }

    /**
     * Interpret a 4-byte big-endian string as a signed 32-bit integer.
     */
    public static function toSignedInt32(string $bytes): int
    {
        /** @var array{1:int} $parts */
        $parts = unpack('N', $bytes);
        $value = $parts[1];

        return $value >= 0x80000000 ? $value - 0x100000000 : $value;
    }
}
