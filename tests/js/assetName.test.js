import { describe, expect, it } from "vitest";
import { assetNameLabel, assetNameOrHex, displayAssetName, readableAssetName } from "@/utils/assetName.js";

const hex = (text) =>
    Array.from(text)
        .map((character) => character.charCodeAt(0).toString(16).padStart(2, "0"))
        .join("");

/**
 * The ten vectors published in CIP-0067 under "Test Vectors": a label number, and the
 * whole four-byte prefix it encodes to including brackets and checksum.
 *
 * These are asserted here and in the server's own reader, so neither implementation is
 * checked against the other and both are checked against the format.
 */
const publishedLabels = [
    [0, "00000000"],
    [1, "00001070"],
    [23, "00017650"],
    [99, "000632e0"],
    [533, "00215410"],
    [2000, "007d0550"],
    [4567, "011d7690"],
    [11111, "02b670b0"],
    [49328, "0c0b0f40"],
    [65535, "0ffff240"],
];

describe("reading an asset name", () => {
    it.each(publishedLabels)("recognises published label %i and reads the name behind it", (label, prefix) => {
        const assetHex = prefix + hex("MyToken");

        expect(assetNameLabel(assetHex)).toBe(label);
        expect(readableAssetName(assetHex)).toBe("MyToken");
        expect(displayAssetName(assetHex)).toBe(`(${label})MyToken`);
    });

    it("names the CIP-68 tokens that used to render as four junk characters and a name", () => {
        // Decoding these whole puts the label's own bytes, one of them NUL, in front of
        // the name on the page.
        expect(displayAssetName("000643b0" + hex("GenToken"))).toBe("(100)GenToken");
        expect(displayAssetName("000de140" + hex("GiveYouUp"))).toBe("(222)GiveYouUp");
    });

    it("tells a reference NFT from the user token that shares its name", () => {
        const reference = "000643b0" + hex("Test123");
        const user = "000de140" + hex("Test123");

        expect(displayAssetName(reference)).toBe("(100)Test123");
        expect(displayAssetName(user)).toBe("(222)Test123");
        expect(displayAssetName(reference)).not.toBe(displayAssetName(user));
    });

    it("reads an unlabelled name whole", () => {
        expect(assetNameLabel(hex("Mascot"))).toBeNull();
        expect(displayAssetName(hex("Mascot"))).toBe("Mascot");
    });

    it("refuses a prefix whose checksum does not match", () => {
        // 0x000de140 is label 222. 0x14 is its checksum; 0x15 is not.
        expect(assetNameLabel("000de150" + hex("MyToken"))).toBeNull();
    });

    it("refuses a prefix without its zero brackets", () => {
        expect(assetNameLabel("000de141" + hex("MyToken"))).toBeNull();
        expect(assetNameLabel("100de140" + hex("MyToken"))).toBeNull();
    });

    it("has nothing to display for bytes that are not text", () => {
        expect(displayAssetName("ff00ff")).toBeNull();
        expect(displayAssetName("000de140ff00ff")).toBeNull();
    });

    it("shows a label that has nothing behind it", () => {
        expect(displayAssetName("000de140")).toBe("(222)");
    });

    it("has nothing to display for something that is not an asset name", () => {
        expect(displayAssetName("")).toBeNull();
        expect(displayAssetName("abc")).toBeNull();
        expect(displayAssetName("zzzz")).toBeNull();
        expect(displayAssetName(null)).toBeNull();
    });

    it("reads hex that arrived in upper case", () => {
        expect(displayAssetName(("000de140" + hex("MyToken")).toUpperCase())).toBe("(222)MyToken");
    });
});

describe("naming an asset for the page", () => {
    it("falls back to the hex where there is no name, which is what the cost statement shows", () => {
        expect(assetNameOrHex("ff00ff")).toBe("ff00ff");
        expect(assetNameOrHex("")).toBe("");
        expect(assetNameOrHex(null)).toBe("");
    });

    it("gives the name where there is one", () => {
        expect(assetNameOrHex("000de140" + hex("Mascot"))).toBe("(222)Mascot");
        expect(assetNameOrHex(hex("Mascot"))).toBe("Mascot");
    });
});
