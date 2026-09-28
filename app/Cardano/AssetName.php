<?php

namespace App\Cardano;

/**
 * An asset name as the chain carries it, split into the label it may begin with and the
 * name behind that label.
 *
 * An asset name is bytes, and a person reading a report wants the text inside them. About
 * half of them hold text. The rest hold a serial, a hash or a counter, and rendering those
 * as though they were text puts mojibake on the page, so the hex stays as the honest
 * answer for them.
 *
 * The complication is that a name may not begin at its first byte. CIP-0068 requires every
 * asset name it governs to be prefixed with a label saying what the token is for, and
 * defers the encoding of that label to CIP-0067, which lays it out as four bytes:
 * `[ 0000 | 16 bits label_num | 8 bits checksum | 0000 ]` (CIP-0067, Specification). The
 * leading four bits are zero, and every label anyone has registered is small enough that
 * the top four bits of the number are zero too, so the first byte of a labelled name is
 * always NUL. Judging those four bytes as though they were part of the name rejects the
 * name at its first byte and hides text that is sitting right behind it.
 *
 * The label is kept rather than thrown away once read. A reference NFT and the user token
 * it describes carry the same name under the same policy and differ only in this prefix
 * (CIP-0068, 222 NFT Standard: "The `user token` and `reference NFT` MUST have an
 * identical name ... preceded by the `asset_name_label` prefix"), so dropping it would
 * print two identical rows for two different assets.
 */
final class AssetName
{
    /** The label is four bytes, which is eight characters of hex. */
    private const LABEL_HEX_LENGTH = 8;

    private function __construct(
        /** The CIP-0067 label number, or null where the name does not begin with one. */
        public readonly ?int $label,
        /** The name behind the label, in hex. The whole string where there is no label. */
        public readonly string $nameHex,
    ) {}

    public static function fromHex(?string $hex): self
    {
        $hex = (string) $hex;

        // Anything that is not an even run of hex digits is not an asset name off the
        // chain. It is kept whole so that it renders as itself rather than as a fragment.
        if ($hex === '' || strlen($hex) % 2 !== 0 || ! ctype_xdigit($hex)) {
            return new self(null, $hex);
        }

        $label = self::labelIn($hex);

        return $label === null
            ? new self(null, $hex)
            : new self($label, substr($hex, self::LABEL_HEX_LENGTH));
    }

    /**
     * The name behind the label as text, or null where those bytes are not text.
     */
    public function readable(): ?string
    {
        $decoded = @hex2bin($this->nameHex);

        if ($decoded === false || $decoded === '') {
            return null;
        }

        return (mb_check_encoding($decoded, 'UTF-8') && preg_match('/^[\PC\s]+$/u', $decoded) === 1)
            ? $decoded
            : null;
    }

    /**
     * The name behind the label as bytes, which is the name CIP-0068 files a token's
     * metadata under. Empty where the hex does not decode.
     */
    public function nameBytes(): string
    {
        $decoded = @hex2bin($this->nameHex);

        return $decoded === false ? '' : $decoded;
    }

    /**
     * What to put in front of a person, or null where the hex is the only honest answer.
     *
     * A label is written the way CIP-0068 writes it throughout, `(222)Test123`, which is
     * the spelling anyone who has read the standard will recognise and is what keeps a
     * reference NFT distinct from the user token sharing its name.
     */
    public function display(): ?string
    {
        $name = $this->readable();

        if ($this->label === null) {
            return $name;
        }

        if ($name !== null) {
            return sprintf('(%d)%s', $this->label, $name);
        }

        // A label with nothing behind it is already fully described; the hex would only
        // repeat the label back in a form that is harder to read.
        return $this->nameHex === '' ? sprintf('(%d)', $this->label) : null;
    }

    /**
     * The CIP-0067 label an asset name begins with, or null where it begins with
     * something else.
     *
     * This is the check CIP-0067 sets out under "Verify a label": take the first four
     * bytes, require the leading and trailing four bits to be zero, then recompute CRC-8
     * over the two label bytes and compare it against the checksum byte the prefix
     * carries. The zero brackets alone would claim any name that happened to start with
     * the right nibbles; the checksum is what the format provides to tell a real label
     * from a coincidence, and CIP-0067 says so in its Rationale.
     */
    private static function labelIn(string $hex): ?int
    {
        if (strlen($hex) < self::LABEL_HEX_LENGTH) {
            return null;
        }

        $prefix = substr($hex, 0, self::LABEL_HEX_LENGTH);

        if ($prefix[0] !== '0' || $prefix[7] !== '0') {
            return null;
        }

        $label = (int) hexdec(substr($prefix, 1, 4));
        $checksum = (int) hexdec(substr($prefix, 5, 2));

        return self::crc8(chr($label >> 8).chr($label & 0xFF)) === $checksum
            ? $label
            : null;
    }

    /**
     * CRC-8 as CIP-0067 specifies it: polynomial 0x07, no initial value, no final xor,
     * most significant bit first, over the two label bytes including their padding.
     *
     * The specification publishes this as a 256-entry lookup table. Computing it a bit at
     * a time gives the same answers over far less to read, and the specification's own
     * test vectors are asserted against this implementation so the two cannot drift.
     */
    private static function crc8(string $bytes): int
    {
        $crc = 0;

        for ($i = 0, $length = strlen($bytes); $i < $length; $i++) {
            $crc ^= ord($bytes[$i]);

            for ($bit = 0; $bit < 8; $bit++) {
                $crc = ($crc & 0x80) !== 0
                    ? (($crc << 1) ^ 0x07) & 0xFF
                    : ($crc << 1) & 0xFF;
            }
        }

        return $crc;
    }
}
