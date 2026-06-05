<?php

declare(strict_types=1);

namespace Coyotito\Ipp\Protocol;

use Coyotito\Ipp\Enums\DelimiterTag;
use Coyotito\Ipp\Enums\Operation;
use Coyotito\Ipp\Enums\ResolutionUnit;
use Coyotito\Ipp\Enums\ValueTag;
use Coyotito\Ipp\Exceptions\DecodingException;
use Coyotito\Ipp\Values\Collection;
use Coyotito\Ipp\Values\DateTimeValue;
use Coyotito\Ipp\Values\IntegerRange;
use Coyotito\Ipp\Values\Resolution;
use Coyotito\Ipp\Values\StringWithLanguage;
use DateTimeImmutable;

/**
 * Parses the IPP wire format (RFC 8010 §3) into the typed message model.
 *
 * {@see decode()} reads a response (the 2-byte field is a status-code);
 * {@see decodeRequest()} reads a request (the field is an operation-id). Both
 * share the attribute-group parser, including 1setOf additional values and
 * arbitrarily nested collections (RFC 3382).
 */
final class Decoder
{
    public function decode(string $bytes): Response
    {
        $reader = new ByteReader($bytes);
        $major = $reader->readUInt8();
        $minor = $reader->readUInt8();
        $statusCode = $reader->readUInt16();
        $requestId = $reader->readInt32();

        $groups = $this->parseGroups($reader);

        return new Response($statusCode, $requestId, $groups, $reader->rest(), $major, $minor);
    }

    public function decodeRequest(string $bytes): Request
    {
        $reader = new ByteReader($bytes);
        $major = $reader->readUInt8();
        $minor = $reader->readUInt8();
        $operationId = $reader->readUInt16();
        $requestId = $reader->readInt32();

        $operation = Operation::tryFrom($operationId)
            ?? throw new DecodingException(sprintf('Unknown operation-id 0x%04X.', $operationId));

        $groups = $this->parseGroups($reader);

        return new Request($operation, $requestId, $groups, $reader->rest(), $major, $minor);
    }

    /**
     * @return array<int,AttributeGroup>
     */
    private function parseGroups(ByteReader $reader): array
    {
        $groups = [];
        $current = null;

        while (true) {
            if ($reader->eof()) {
                throw new DecodingException('Message ended before the end-of-attributes tag.');
            }

            $tag = $reader->readUInt8();

            if ($tag === DelimiterTag::EndOfAttributes->value) {
                return $groups;
            }

            if ($tag <= 0x0F) {
                $delimiter = DelimiterTag::tryFrom($tag)
                    ?? throw new DecodingException(sprintf('Unknown delimiter tag 0x%02X.', $tag));
                $current = new AttributeGroup($delimiter);
                $groups[] = $current;

                continue;
            }

            if (! $current instanceof AttributeGroup) {
                throw new DecodingException('Attribute encountered before any delimiter tag.');
            }

            $current->add($this->parseAttribute($reader, $this->valueTag($tag)));
        }
    }

    /**
     * Parse a named attribute (its value tag has already been consumed by the
     * caller, leaving the cursor on the name-length). Gathers any 1setOf
     * additional values that follow.
     */
    private function parseAttribute(ByteReader $reader, ValueTag $tag): Attribute
    {
        $name = $reader->readBytes($reader->readUInt16());

        if ($tag === ValueTag::BegCollection) {
            return new Attribute($name, ValueTag::BegCollection, $this->parseCollectionSet($reader));
        }

        if ($tag->isOutOfBand()) {
            $reader->readBytes($reader->readUInt16()); // value-length (0) + empty value

            return new Attribute($name, $tag, []);
        }

        $values = [$this->readValueBody($reader, $tag)];

        while ($this->peekAdditional($reader, $tag)) {
            $reader->readUInt8();  // value tag (== $tag)
            $reader->readUInt16(); // name-length (0)
            $values[] = $this->readValueBody($reader, $tag);
        }

        return new Attribute($name, $tag, $values);
    }

    /**
     * Read a begCollection set: the opening tag is already consumed; this reads
     * the empty value and one or more collections (a 1setOf of collections).
     *
     * @return array<int,Collection>
     */
    private function parseCollectionSet(ByteReader $reader): array
    {
        $reader->readUInt16(); // begCollection value-length (0)
        $collections = [$this->parseCollection($reader)];

        while ($this->peekAdditional($reader, ValueTag::BegCollection)) {
            $reader->readUInt8();  // begCollection tag
            $reader->readUInt16(); // name-length (0)
            $reader->readUInt16(); // value-length (0)
            $collections[] = $this->parseCollection($reader);
        }

        return $collections;
    }

    /**
     * Parse a collection's members until its endCollection (RFC 3382 §7.1).
     */
    private function parseCollection(ByteReader $reader): Collection
    {
        $members = [];

        while (true) {
            if ($reader->eof()) {
                throw new DecodingException('Unterminated collection: missing endCollection.');
            }

            $tag = $reader->readUInt8();

            if ($tag === ValueTag::EndCollection->value) {
                $reader->readUInt16(); // name-length (0)
                $reader->readUInt16(); // value-length (0)

                return new Collection($members);
            }

            if ($tag !== ValueTag::MemberAttrName->value) {
                throw new DecodingException(
                    sprintf('Expected memberAttrName inside collection, got 0x%02X.', $tag)
                );
            }

            $reader->readUInt16(); // memberAttrName name-length (0)
            $memberName = $reader->readBytes($reader->readUInt16());

            $members[] = $this->parseCollectionMember($reader, $memberName);
        }
    }

    private function parseCollectionMember(ByteReader $reader, string $name): Attribute
    {
        $tag = $this->valueTag($reader->readUInt8());
        $reader->readUInt16(); // member name-length (always 0)

        if ($tag === ValueTag::BegCollection) {
            return new Attribute($name, ValueTag::BegCollection, $this->parseCollectionSet($reader));
        }

        if ($tag->isOutOfBand()) {
            $reader->readBytes($reader->readUInt16()); // value-length (0) + empty value

            return new Attribute($name, $tag, []);
        }

        $values = [$this->readValueBody($reader, $tag)];

        while ($this->peekAdditional($reader, $tag)) {
            $reader->readUInt8();  // value tag (== $tag)
            $reader->readUInt16(); // name-length (0)
            $values[] = $this->readValueBody($reader, $tag);
        }

        return new Attribute($name, $tag, $values);
    }

    /**
     * Read a value-length-prefixed value (the cursor must sit on the
     * value-length). Used for the first value of an attribute, additional
     * 1setOf values and collection members alike.
     */
    private function readValueBody(ByteReader $reader, ValueTag $tag): mixed
    {
        return $this->decodeValue($tag, $reader->readBytes($reader->readUInt16()));
    }

    /**
     * Without consuming, tell whether the next frame is an additional value for
     * the given tag: same value-tag with a zero-length name.
     */
    private function peekAdditional(ByteReader $reader, ValueTag $tag): bool
    {
        if ($reader->remaining() < 3) {
            return false;
        }

        $position = $reader->position();
        $nextTag = $reader->readUInt8();
        $nameLength = $reader->readUInt16();
        $reader->seek($position);

        return $nextTag === $tag->value && $nameLength === 0;
    }

    private function decodeValue(ValueTag $tag, string $bytes): mixed
    {
        return match ($tag) {
            ValueTag::Integer, ValueTag::Enum => ByteReader::toSignedInt32($bytes),
            ValueTag::Boolean => $bytes !== '' && ord($bytes[0]) !== 0,
            ValueTag::DateTime => $this->decodeDateTime($bytes),
            ValueTag::Resolution => $this->decodeResolution($bytes),
            ValueTag::RangeOfInteger => $this->decodeRange($bytes),
            ValueTag::TextWithLanguage, ValueTag::NameWithLanguage => $this->decodeStringWithLanguage($bytes),
            default => $bytes,
        };
    }

    private function decodeDateTime(string $bytes): DateTimeValue
    {
        if (strlen($bytes) !== 11) {
            throw new DecodingException('dateTime value must be 11 octets.');
        }

        /** @var array{1:int} $year */
        $year = unpack('n', substr($bytes, 0, 2));

        $iso = sprintf(
            '%04d-%02d-%02dT%02d:%02d:%02d.%06d%s%02d:%02d',
            $year[1],
            ord($bytes[2]),
            ord($bytes[3]),
            ord($bytes[4]),
            ord($bytes[5]),
            ord($bytes[6]),
            ord($bytes[7]) * 100_000,
            $bytes[8],
            ord($bytes[9]),
            ord($bytes[10]),
        );

        try {
            return new DateTimeValue(new DateTimeImmutable($iso));
        } catch (\Exception $e) {
            throw new DecodingException("Invalid dateTime value: {$iso}.", previous: $e);
        }
    }

    private function decodeResolution(string $bytes): Resolution
    {
        if (strlen($bytes) !== 9) {
            throw new DecodingException('resolution value must be 9 octets.');
        }

        $units = ResolutionUnit::tryFrom(ord($bytes[8]))
            ?? throw new DecodingException(sprintf('Unknown resolution unit 0x%02X.', ord($bytes[8])));

        return new Resolution(
            ByteReader::toSignedInt32(substr($bytes, 0, 4)),
            ByteReader::toSignedInt32(substr($bytes, 4, 4)),
            $units,
        );
    }

    private function decodeRange(string $bytes): IntegerRange
    {
        if (strlen($bytes) !== 8) {
            throw new DecodingException('rangeOfInteger value must be 8 octets.');
        }

        return new IntegerRange(
            ByteReader::toSignedInt32(substr($bytes, 0, 4)),
            ByteReader::toSignedInt32(substr($bytes, 4, 4)),
        );
    }

    private function decodeStringWithLanguage(string $bytes): StringWithLanguage
    {
        $reader = new ByteReader($bytes);
        $language = $reader->readBytes($reader->readUInt16());
        $value = $reader->readBytes($reader->readUInt16());

        return new StringWithLanguage($value, $language);
    }

    private function valueTag(int $tag): ValueTag
    {
        return ValueTag::tryFrom($tag)
            ?? throw new DecodingException(sprintf('Unknown value tag 0x%02X.', $tag));
    }
}
