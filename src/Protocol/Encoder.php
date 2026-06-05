<?php

declare(strict_types=1);

namespace Coyotito\Ipp\Protocol;

use Coyotito\Ipp\Enums\DelimiterTag;
use Coyotito\Ipp\Enums\ValueTag;
use Coyotito\Ipp\Exceptions\EncodingException;
use Coyotito\Ipp\Values\Collection;
use Coyotito\Ipp\Values\DateTimeValue;
use Coyotito\Ipp\Values\IntegerRange;
use Coyotito\Ipp\Values\Resolution;
use Coyotito\Ipp\Values\StringWithLanguage;
use Stringable;

/**
 * Serializes an IPP {@see Request} to the binary wire format (RFC 8010 §3).
 *
 * Layout: version (2 bytes) · operation-id (2 bytes) · request-id (4 bytes) ·
 * attribute groups · end-of-attributes-tag (0x03) · document data.
 */
final class Encoder
{
    /** Maximum value of an IPP SIGNED-SHORT length field. */
    private const int MAX_SHORT = 0x7FFF;

    public function encode(Request $request): string
    {
        return $this->header($request->versionMajor, $request->versionMinor, $request->operation->value, $request->requestId)
            .$this->body($request->groups, $request->data);
    }

    /**
     * Serialize a {@see Response}. The on-the-wire layout is identical to a
     * request except the 2-byte field carries the status-code; handy for tests,
     * mocks and fixtures.
     */
    public function encodeResponse(Response $response): string
    {
        return $this->header($response->versionMajor, $response->versionMinor, $response->statusCode, $response->requestId)
            .$this->body($response->groups, $response->data);
    }

    private function header(int $versionMajor, int $versionMinor, int $field, int $requestId): string
    {
        return chr($versionMajor).chr($versionMinor)
            .pack('n', $field)
            .pack('N', $requestId & 0xFFFFFFFF);
    }

    /**
     * @param  array<int,AttributeGroup>  $groups
     */
    private function body(array $groups, string $data): string
    {
        $out = '';

        foreach ($groups as $group) {
            $out .= chr($group->tag->value);

            foreach ($group->attributes as $attribute) {
                $out .= $this->encodeAttribute($attribute);
            }
        }

        return $out.chr(DelimiterTag::EndOfAttributes->value).$data;
    }

    private function encodeAttribute(Attribute $attribute): string
    {
        if ($attribute->tag === ValueTag::BegCollection) {
            return $this->encodeCollectionAttribute($attribute);
        }

        if ($attribute->isOutOfBand()) {
            // Out-of-band attributes carry the name but a zero-length value.
            return $this->frame($attribute->tag, $attribute->name, '');
        }

        if ($attribute->values === []) {
            throw new EncodingException(
                "Attribute '{$attribute->name}' ({$attribute->tag->name}) has no values."
            );
        }

        $out = '';

        foreach ($attribute->values as $index => $value) {
            // The first value carries the attribute name; additional values of
            // a 1setOf use a zero-length name (RFC 8010 §3.1.7).
            $name = $index === 0 ? $attribute->name : '';
            $out .= $this->frame($attribute->tag, $name, $this->encodeValue($attribute->tag, $value));
        }

        return $out;
    }

    private function encodeCollectionAttribute(Attribute $attribute): string
    {
        if ($attribute->values === []) {
            throw new EncodingException("Collection attribute '{$attribute->name}' has no values.");
        }

        $out = '';

        foreach ($attribute->values as $index => $collection) {
            if (! $collection instanceof Collection) {
                throw new EncodingException(
                    "Collection attribute '{$attribute->name}' must hold Collection values."
                );
            }

            $name = $index === 0 ? $attribute->name : '';
            $out .= $this->frame(ValueTag::BegCollection, $name, '');
            $out .= $this->encodeCollectionMembers($collection);
            $out .= $this->frame(ValueTag::EndCollection, '', '');
        }

        return $out;
    }

    private function encodeCollectionMembers(Collection $collection): string
    {
        $out = '';

        foreach ($collection->members as $member) {
            // Each member is announced once by name, then its value(s) follow
            // with a zero-length name (RFC 3382 §7.1).
            $out .= $this->frame(ValueTag::MemberAttrName, '', $member->name);

            if ($member->tag === ValueTag::BegCollection) {
                foreach ($member->values as $nested) {
                    if (! $nested instanceof Collection) {
                        throw new EncodingException(
                            "Nested collection member '{$member->name}' must hold Collection values."
                        );
                    }

                    $out .= $this->frame(ValueTag::BegCollection, '', '');
                    $out .= $this->encodeCollectionMembers($nested);
                    $out .= $this->frame(ValueTag::EndCollection, '', '');
                }

                continue;
            }

            if ($member->isOutOfBand()) {
                $out .= $this->frame($member->tag, '', '');

                continue;
            }

            foreach ($member->values as $value) {
                $out .= $this->frame($member->tag, '', $this->encodeValue($member->tag, $value));
            }
        }

        return $out;
    }

    /**
     * Encode one tagged value: value-tag · name-length · name · value-length · value.
     */
    private function frame(ValueTag $tag, string $name, string $value): string
    {
        return chr($tag->value)
            .$this->shortLength(strlen($name)).$name
            .$this->shortLength(strlen($value)).$value;
    }

    /**
     * Encode the raw value bytes for a tag (no length prefix).
     */
    private function encodeValue(ValueTag $tag, mixed $value): string
    {
        return match ($tag) {
            ValueTag::Integer, ValueTag::Enum => $this->int32((int) $value),
            ValueTag::Boolean => chr($value ? 1 : 0),
            ValueTag::DateTime => $this->encodeDateTime($this->asDateTime($value)),
            ValueTag::Resolution => $this->encodeResolution($this->asResolution($value)),
            ValueTag::RangeOfInteger => $this->encodeRange($this->asRange($value)),
            ValueTag::TextWithLanguage, ValueTag::NameWithLanguage => $this->encodeStringWithLanguage($value),
            default => $this->asString($value),
        };
    }

    private function encodeDateTime(DateTimeValue $value): string
    {
        $dt = $value->value;
        $offset = $dt->getOffset();
        $absMinutes = intdiv(abs($offset), 60);

        return pack('n', (int) $dt->format('Y'))
            .chr((int) $dt->format('n'))
            .chr((int) $dt->format('j'))
            .chr((int) $dt->format('G'))
            .chr((int) $dt->format('i'))
            .chr((int) $dt->format('s'))
            .chr(intdiv((int) $dt->format('u'), 100_000))
            .($offset < 0 ? '-' : '+')
            .chr(intdiv($absMinutes, 60))
            .chr($absMinutes % 60);
    }

    private function encodeResolution(Resolution $value): string
    {
        return $this->int32($value->crossFeed)
            .$this->int32($value->feed)
            .chr($value->units->value);
    }

    private function encodeRange(IntegerRange $value): string
    {
        return $this->int32($value->lower).$this->int32($value->upper);
    }

    private function encodeStringWithLanguage(mixed $value): string
    {
        if (! $value instanceof StringWithLanguage) {
            throw new EncodingException('text/nameWithLanguage requires a StringWithLanguage value.');
        }

        return $this->shortLength(strlen($value->language)).$value->language
            .$this->shortLength(strlen($value->value)).$value->value;
    }

    private function int32(int $value): string
    {
        return pack('N', $value & 0xFFFFFFFF);
    }

    private function shortLength(int $length): string
    {
        if ($length < 0 || $length > self::MAX_SHORT) {
            throw new EncodingException(
                "Length {$length} exceeds the IPP SIGNED-SHORT maximum of ".self::MAX_SHORT.'.'
            );
        }

        return pack('n', $length);
    }

    private function asString(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        if ($value instanceof Stringable) {
            return (string) $value;
        }

        throw new EncodingException('Expected a string value, got '.get_debug_type($value).'.');
    }

    private function asDateTime(mixed $value): DateTimeValue
    {
        return $value instanceof DateTimeValue
            ? $value
            : throw new EncodingException('dateTime requires a DateTimeValue.');
    }

    private function asResolution(mixed $value): Resolution
    {
        return $value instanceof Resolution
            ? $value
            : throw new EncodingException('resolution requires a Resolution value.');
    }

    private function asRange(mixed $value): IntegerRange
    {
        return $value instanceof IntegerRange
            ? $value
            : throw new EncodingException('rangeOfInteger requires an IntegerRange value.');
    }
}
