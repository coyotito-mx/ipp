<?php

declare(strict_types=1);

namespace Coyotito\Ipp\Enums;

/**
 * Value tags (RFC 8010 §3.5.2) describing the syntax of an attribute value.
 *
 * Grouped into out-of-band values, integer types, octetString types and
 * character-string types. The numeric values are the on-the-wire byte.
 */
enum ValueTag: int
{
    // Out-of-band values (no value bytes, except where noted).
    case Unsupported = 0x10;
    case Unknown = 0x12;
    case NoValue = 0x13;
    case NotSettable = 0x15;   // RFC 3380
    case DeleteAttribute = 0x16; // RFC 3380
    case AdminDefine = 0x17;   // RFC 3380

    // Integer types (4-byte signed, except boolean).
    case Integer = 0x21;
    case Boolean = 0x22;
    case Enum = 0x23;

    // octetString types.
    case OctetString = 0x30;
    case DateTime = 0x31;
    case Resolution = 0x32;
    case RangeOfInteger = 0x33;
    case BegCollection = 0x34; // RFC 3382
    case TextWithLanguage = 0x35;
    case NameWithLanguage = 0x36;
    case EndCollection = 0x37; // RFC 3382

    // character-string types.
    case TextWithoutLanguage = 0x41;
    case NameWithoutLanguage = 0x42;
    case Keyword = 0x44;
    case Uri = 0x45;
    case UriScheme = 0x46;
    case Charset = 0x47;
    case NaturalLanguage = 0x48;
    case MimeMediaType = 0x49;
    case MemberAttrName = 0x4A; // RFC 3382

    /**
     * Out-of-band tags carry no value bytes and represent special semantics
     * (unsupported / unknown / no-value …) rather than data.
     */
    public function isOutOfBand(): bool
    {
        return match ($this) {
            self::Unsupported,
            self::Unknown,
            self::NoValue,
            self::NotSettable,
            self::DeleteAttribute,
            self::AdminDefine => true,
            default => false,
        };
    }

    /**
     * Integer-family tags are encoded as a 4-byte signed integer
     * (boolean is the 1-byte exception, handled by the codec).
     */
    public function isInteger(): bool
    {
        return match ($this) {
            self::Integer, self::Enum => true,
            default => false,
        };
    }

    /**
     * Character-string tags carry US-ASCII / UTF-8 text without an embedded
     * natural language.
     */
    public function isCharacterString(): bool
    {
        return match ($this) {
            self::TextWithoutLanguage,
            self::NameWithoutLanguage,
            self::Keyword,
            self::Uri,
            self::UriScheme,
            self::Charset,
            self::NaturalLanguage,
            self::MimeMediaType,
            self::MemberAttrName => true,
            default => false,
        };
    }
}
