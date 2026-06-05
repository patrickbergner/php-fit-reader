<?php

declare(strict_types=1);

namespace Emontis\FitReader\Protocol;

use Emontis\FitReader\Exception\CrcMismatchException;
use Emontis\FitReader\Exception\InvalidFileException;
use Emontis\FitReader\Io\BinaryStream;
use Emontis\FitReader\Message\Message;
use Emontis\FitReader\Message\MessageKind;
use Emontis\FitReader\Profile\FieldDef;
use Emontis\FitReader\Profile\Profile;
use Emontis\FitReader\Value\FitTimestamp;
use Emontis\FitReader\Value\GeoPoint;
use Emontis\FitReader\Value\Semicircles;
use Emontis\FitReader\Value\ValueDecoder;

/**
 * Streaming FIT decoder. Public API:
 *
 *     foreach ((new Decoder($stream))->messages() as $message) { … }
 *
 * Yields profile-resolved Message objects. Verifies header and file CRC.
 */
final class Decoder
{
    private const COMPRESSED_TIMESTAMP_FIELD_NUM = 253;
    private const FIELD_DESCRIPTION_GLOBAL_NUM   = 206;

    /**
     * Developer (Connect IQ) field descriptions seen so far, keyed
     * "developerDataIndex:fieldDefNumber".
     *
     * @var array<string, array{name: string, baseType: BaseType, scale: ?int, offset: ?int, units: ?string}>
     */
    private array $devFieldDescriptions = [];

    public function __construct(
        private readonly BinaryStream $stream,
        private readonly bool $verifyCrc = true,
        private readonly bool $strict = false,
    ) {
    }

    /** @return \Generator<int, Message, mixed, FileHeader> */
    public function messages(): \Generator
    {
        $header   = FileHeader::readFrom($this->stream, $this->verifyCrc);
        $dataEnd  = $this->stream->position() + $header->dataSize;
        $defs     = []; // localType => MessageDefinition
        $lastTs   = null; // int|null — seconds since FIT epoch

        while ($this->stream->position() < $dataEnd) {
            $headerByte = $this->stream->u8();
            $rh         = RecordHeader::parse($headerByte);

            if ($rh->isDefinition) {
                $defs[$rh->localType] = $this->readDefinition($rh);
                continue;
            }

            if (!isset($defs[$rh->localType])) {
                throw new InvalidFileException(
                    "Data message with no prior definition for local type {$rh->localType} at byte {$this->stream->position()}"
                );
            }
            $def = $defs[$rh->localType];

            [$message, $newTs] = $this->readDataMessage($def, $rh, $lastTs);
            if ($newTs !== null) {
                $lastTs = $newTs;
            }
            if ($def->globalNum === self::FIELD_DESCRIPTION_GLOBAL_NUM) {
                $this->registerFieldDescription($message);
            }
            yield $message;
        }

        // Trailing 2-byte file CRC — read WITHOUT updating the running CRC.
        $expected = $this->stream->runningCrc()->value();
        $crcBytes = $this->stream->readRaw(2);
        $actual   = ord($crcBytes[0]) | (ord($crcBytes[1]) << 8);

        if ($this->verifyCrc && $actual !== $expected) {
            throw new CrcMismatchException('file', $expected, $actual);
        }

        return $header;
    }

    private function readDefinition(RecordHeader $rh): MessageDefinition
    {
        $reserved = $this->stream->u8();
        $arch     = $this->stream->u8();
        $littleEndian = $arch === 0;
        $globalNum = $littleEndian ? $this->stream->u16Le() : $this->stream->u16Be();
        $numFields = $this->stream->u8();

        $fields = [];
        for ($i = 0; $i < $numFields; $i++) {
            $defNum = $this->stream->u8();
            $size   = $this->stream->u8();
            $type   = $this->stream->u8();
            $bt     = BaseType::tryFromByte($type);
            if ($bt === null) {
                if ($this->strict) {
                    throw new InvalidFileException("Unknown base type 0x" . dechex($type));
                }
                // Treat unknown types as opaque bytes so we can keep going.
                $bt = BaseType::Byte;
            }
            $fields[] = new FieldDefinition($defNum, $size, $bt);
        }

        $devFields = [];
        if ($rh->hasDeveloperData) {
            $numDev = $this->stream->u8();
            for ($i = 0; $i < $numDev; $i++) {
                $devFields[] = new DevFieldDefinition(
                    $this->stream->u8(),
                    $this->stream->u8(),
                    $this->stream->u8(),
                );
            }
        }

        return new MessageDefinition(
            localType: $rh->localType,
            littleEndian: $littleEndian,
            globalNum: $globalNum,
            fields: $fields,
            devFields: $devFields,
        );
    }

    /**
     * @return array{0: Message, 1: ?int}  message + new lastTs (if any)
     */
    private function readDataMessage(MessageDefinition $def, RecordHeader $rh, ?int $lastTs): array
    {
        $messageDef = Profile::message($def->globalNum);
        $fields     = [];
        $newTs      = null;

        // Inject compressed timestamp first (it represents field 253 of the message).
        if ($rh->compressedTimestamp) {
            if ($lastTs === null) {
                throw new InvalidFileException(
                    'Compressed-timestamp header before any full timestamp has been seen'
                );
            }
            $referenceLow = $lastTs & 0x1F;
            $offset       = $rh->timeOffset;
            $newTs        = $offset >= $referenceLow
                ? ($lastTs - $referenceLow + $offset)
                : ($lastTs - $referenceLow + $offset + 0x20);
            $this->writeField(
                $fields,
                $messageDef?->fields[self::COMPRESSED_TIMESTAMP_FIELD_NUM] ?? null,
                self::COMPRESSED_TIMESTAMP_FIELD_NUM,
                $newTs,
            );
        }

        foreach ($def->fields as $fdef) {
            $raw     = $this->stream->read($fdef->size);
            $decoded = ValueDecoder::decode($raw, $fdef->baseType, $def->littleEndian);

            $profileField = $messageDef?->fields[$fdef->fieldDefNum] ?? null;

            // Track full timestamps for subsequent compressed-timestamp messages.
            if ($fdef->fieldDefNum === self::COMPRESSED_TIMESTAMP_FIELD_NUM && is_int($decoded)) {
                $newTs = $decoded;
            }

            $this->writeField($fields, $profileField, $fdef->fieldDefNum, $decoded);
        }

        // Developer (Connect IQ) fields: resolve against any field_description
        // seen so far — name, base type, scale and offset; fall back to raw
        // bytes under dev_field_N when the field hasn't been described.
        foreach ($def->devFields as $dev) {
            $raw  = $this->stream->read($dev->size);
            $desc = $this->devFieldDescriptions["{$dev->devDataIndex}:{$dev->fieldNum}"] ?? null;
            if ($desc === null) {
                $fields["dev_field_{$dev->fieldNum}"] = $raw;
                continue;
            }
            $fields[$desc['name']] = $this->decodeDevField($raw, $desc, $def->littleEndian);
        }

        $this->collapsePosition($fields);

        $message = new Message(
            globalNum: $def->globalNum,
            name: $messageDef?->name,
            fields: $fields,
            kind: MessageKind::tryFromGlobal($def->globalNum),
        );
        return [$message, $newTs];
    }

    /**
     * Record a developer `field_description` (global 206) so that later data
     * messages carrying that developer field can be decoded by name, base
     * type, scale and offset rather than left as raw bytes.
     */
    private function registerFieldDescription(Message $message): void
    {
        $devIdx   = $message->fields['developer_data_index'] ?? null;
        $fieldNum = $message->fields['field_definition_number'] ?? null;
        if (!is_int($devIdx) || !is_int($fieldNum)) {
            return;
        }

        $baseType = $this->resolveBaseType($message->fields['fit_base_type_id'] ?? null);
        if ($baseType === null) {
            return;
        }

        $name   = $message->fields['field_name'] ?? null;
        $scale  = $message->fields['scale'] ?? null;
        $offset = $message->fields['offset'] ?? null;
        $units  = $message->fields['units'] ?? null;

        $this->devFieldDescriptions["{$devIdx}:{$fieldNum}"] = [
            'name'     => is_string($name) && $name !== '' ? $name : "dev_field_{$fieldNum}",
            'baseType' => $baseType,
            'scale'    => is_int($scale) ? $scale : null,
            'offset'   => is_int($offset) ? $offset : null,
            'units'    => is_string($units) ? $units : null,
        ];
    }

    /**
     * The `fit_base_type_id` reaches us either as an enum label (e.g. "uint16")
     * or, if unmapped, the raw byte. Resolve either to a {@see BaseType}.
     */
    private function resolveBaseType(mixed $fitBaseTypeId): ?BaseType
    {
        if (is_int($fitBaseTypeId)) {
            return BaseType::tryFromByte($fitBaseTypeId);
        }
        if (is_string($fitBaseTypeId)) {
            $byte = Profile::enumValue('fit_base_type', $fitBaseTypeId);
            return $byte !== null ? BaseType::tryFromByte($byte) : null;
        }
        return null;
    }

    /**
     * Decode one developer field's raw bytes using its description, applying
     * scale/offset to numeric values the way native profile fields are.
     *
     * @param array{name: string, baseType: BaseType, scale: ?int, offset: ?int, units: ?string} $desc
     */
    private function decodeDevField(string $raw, array $desc, bool $littleEndian): mixed
    {
        $value = ValueDecoder::decode($raw, $desc['baseType'], $littleEndian);
        if (!is_int($value) && !is_float($value)) {
            return $value; // strings / arrays / null pass through unscaled
        }
        if ($desc['scale'] !== null && $desc['scale'] !== 0) {
            $value = $value / $desc['scale'];
        }
        if ($desc['offset'] !== null) {
            $value -= $desc['offset'];
        }
        return $value;
    }

    /**
     * @param array<string|int, mixed> $fields
     */
    private function writeField(array &$fields, ?FieldDef $profileField, int $fieldDefNum, mixed $decoded): void
    {
        if ($decoded === null) {
            // Still record the key as null so callers can distinguish
            // "declared but invalid" from "not declared". Use the resolved
            // name when available.
            $key          = $profileField !== null ? $profileField->name : $fieldDefNum;
            $fields[$key] = null;
            return;
        }

        if ($profileField === null) {
            $fields[$fieldDefNum] = $decoded;
            return;
        }

        $fields[$profileField->name] = $this->applyProfile($profileField, $decoded);
    }

    private function applyProfile(FieldDef $f, mixed $value): mixed
    {
        // Recurse into arrays element-wise.
        if (is_array($value)) {
            return array_map(fn ($v) => $v === null ? null : $this->applyProfile($f, $v), $value);
        }

        // FIT date_time → DateTimeImmutable (UTC).
        if ($f->typeName === 'date_time' && is_int($value)) {
            return (new FitTimestamp($value))->toDateTimeImmutable();
        }

        // Semicircles → degrees.
        if ($f->units === 'semicircles' && is_int($value)) {
            return Semicircles::degreesOf($value);
        }

        // Enum mapping (string label) when the field type is a known FIT
        // type and the value is an integer.
        if ($f->typeName !== null && is_int($value)) {
            $label = Profile::enumLabel($f->typeName, $value);
            if ($label !== null) {
                return $label;
            }
        }

        // Scale + offset.
        if (is_int($value) || is_float($value)) {
            if ($f->scale !== 0.0 && ($f->scale !== 1.0 || $f->offset !== 0.0)) {
                return ($value / $f->scale) - $f->offset;
            }
        }

        return $value;
    }

    /**
     * If a record has both position_lat and position_long resolved as floats,
     * add a convenience `position` GeoPoint alongside the raw coords.
     *
     * @param array<string|int, mixed> $fields
     */
    private function collapsePosition(array &$fields): void
    {
        $lat = $fields['position_lat'] ?? null;
        $lng = $fields['position_long'] ?? null;
        if (is_float($lat) && is_float($lng)) {
            $fields['position'] = new GeoPoint($lat, $lng);
        }
    }
}
