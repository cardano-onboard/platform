/**
 * Reading an asset name as the chain carries it, including the label it may begin with.
 *
 * An asset name is bytes. About half of them hold text worth showing somebody; the rest
 * hold a serial or a hash, and rendering those as text puts control characters and
 * mojibake on the page.
 *
 * The part that is easy to miss is that a name need not begin at its first byte. CIP-0068
 * requires every asset name it governs to carry a four-byte label saying what the token is
 * for, and defers the encoding to CIP-0067, which lays it out as
 * `[ 0000 | 16 bits label_num | 8 bits checksum | 0000 ]`. Every registered label is small
 * enough that the first of those bytes is NUL, so decoding the whole string and showing
 * the result puts four junk characters in front of a name that was perfectly readable.
 *
 * This is the same reading the server does for the cost statement, so a token is named the
 * same way wherever it appears. Both are held to the ten test vectors CIP-0067 publishes
 * rather than to each other, which is what keeps them from drifting apart.
 */

/** The label is four bytes, which is eight characters of hex. */
const LABEL_HEX_LENGTH = 8;

/**
 * CRC-8 as CIP-0067 specifies it: polynomial 0x07, no initial value, no final xor, most
 * significant bit first. The specification publishes it as a 256-entry lookup table; this
 * computes the same values a bit at a time.
 */
function crc8(bytes) {
    let crc = 0;

    for (const byte of bytes) {
        crc ^= byte;

        for (let bit = 0; bit < 8; bit++) {
            crc = crc & 0x80 ? ((crc << 1) ^ 0x07) & 0xff : (crc << 1) & 0xff;
        }
    }

    return crc;
}

function isHex(hex) {
    return typeof hex === "string" && hex.length > 0 && hex.length % 2 === 0 && /^[0-9a-f]+$/i.test(hex);
}

function bytesOf(hex) {
    const bytes = new Uint8Array(hex.length / 2);

    for (let i = 0; i < bytes.length; i++) {
        bytes[i] = parseInt(hex.substr(i * 2, 2), 16);
    }

    return bytes;
}

/**
 * The CIP-0067 label an asset name begins with, or null where it begins with something
 * else.
 *
 * This is the check CIP-0067 sets out under "Verify a label": take the first four bytes,
 * require the leading and trailing four bits to be zero, then recompute CRC-8 over the two
 * label bytes and compare it with the checksum byte the prefix carries. The zero brackets
 * on their own would claim any name that happened to start with the right nibbles; the
 * checksum is what the format provides to tell a real label from a coincidence.
 */
export function assetNameLabel(hex) {
    if (!isHex(hex) || hex.length < LABEL_HEX_LENGTH) {
        return null;
    }

    const prefix = hex.slice(0, LABEL_HEX_LENGTH);

    if (prefix[0] !== "0" || prefix[7] !== "0") {
        return null;
    }

    const label = parseInt(prefix.slice(1, 5), 16);
    const checksum = parseInt(prefix.slice(5, 7), 16);

    return crc8([label >> 8, label & 0xff]) === checksum ? label : null;
}

/**
 * The name behind the label as text, or null where those bytes are not text.
 */
export function readableAssetName(hex) {
    if (!isHex(hex)) {
        return null;
    }

    const label = assetNameLabel(hex);
    const nameHex = label === null ? hex : hex.slice(LABEL_HEX_LENGTH);

    if (nameHex === "") {
        return null;
    }

    let decoded;

    try {
        decoded = new TextDecoder("utf-8", { fatal: true }).decode(bytesOf(nameHex));
    } catch {
        return null;
    }

    // Anything in Unicode's "Other" category is a control, a format character or an
    // unassigned code point, none of which belong on a page. Whitespace is let back in
    // because a space is a control character by that reckoning and is fine in a name.
    return /^(?:[^\p{C}]|\s)+$/u.test(decoded) ? decoded : null;
}

/**
 * What to show a person for an asset name, or null where the hex is the only honest
 * answer.
 *
 * A label is written the way CIP-0068 writes it throughout, `(222)Test123`. It is kept
 * rather than dropped because a reference NFT and the user token it describes share a name
 * under one policy and differ only in this prefix, so dropping it would show the same name
 * for two different assets.
 */
export function displayAssetName(hex) {
    const label = assetNameLabel(hex);
    const name = readableAssetName(hex);

    if (label === null) {
        return name;
    }

    if (name !== null) {
        return `(${label})${name}`;
    }

    return hex.length === LABEL_HEX_LENGTH ? `(${label})` : null;
}

/**
 * A name for an asset that is always safe to put on the page: the text where there is
 * text, and the hex where there is not, which is what the cost statement shows too.
 */
export function assetNameOrHex(hex) {
    return displayAssetName(hex) ?? (typeof hex === "string" ? hex : "");
}
