<?php

namespace Tests\Unit\Cardano;

use App\Cardano\AssetName;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Reading an asset name off the chain, including the label it may begin with.
 *
 * The label is the part that is easy to get wrong, because it does not look like a
 * prefix. CIP-0067 lays it out as `[ 0000 | 16 bits label_num | 8 bits checksum | 0000 ]`,
 * and every label anyone has registered is small enough that its first byte is NUL. Any
 * check that asks whether an asset name is printable, applied to the whole name, therefore
 * rejects every name CIP-0068 governs at its first byte, which is exactly the set of names
 * worth printing.
 *
 * The parsing is held to the ten test vectors CIP-0067 publishes rather than to examples
 * written here, so an error in this repository's reading of the format is an error against
 * the format's own numbers.
 */
class AssetNameTest extends TestCase
{
    /**
     * The vectors published in CIP-0067 under "Test Vectors": a label number, and the
     * whole four-byte prefix it encodes to including brackets and checksum.
     *
     * @return array<string, array{int, string}>
     */
    public static function publishedLabels(): array
    {
        return [
            'label 0' => [0, '00000000'],
            'label 1' => [1, '00001070'],
            'label 23' => [23, '00017650'],
            'label 99' => [99, '000632e0'],
            'label 533' => [533, '00215410'],
            'label 2000' => [2000, '007d0550'],
            'label 4567' => [4567, '011d7690'],
            'label 11111' => [11111, '02b670b0'],
            'label 49328' => [49328, '0c0b0f40'],
            'label 65535' => [65535, '0ffff240'],
        ];
    }

    /**
     * Every label CIP-0067 publishes is recognised, and the name behind it is read.
     *
     * The checksum is recomputed rather than the prefix being matched against a list, so
     * this is the whole label range working and not ten values that happen to be known.
     */
    #[DataProvider('publishedLabels')]
    public function test_a_published_label_is_recognised_and_the_name_behind_it_read(int $label, string $prefix): void
    {
        $name = AssetName::fromHex($prefix.bin2hex('MyToken'));

        $this->assertSame($label, $name->label);
        $this->assertSame('MyToken', $name->readable());
        $this->assertSame("({$label})MyToken", $name->display());
    }

    /**
     * The failure the fix exists for.
     *
     * These are the two names CIP-0068 itself writes out, a reference NFT and a user
     * token. Judged whole, each is rejected at its first byte, and the statement falls
     * back to hex for the tokens naming exists to serve.
     */
    public function test_the_cip68_names_that_used_to_render_as_hex_now_read(): void
    {
        $this->assertSame('(100)GenToken', AssetName::fromHex('000643b0'.bin2hex('GenToken'))->display());
        $this->assertSame('(222)GiveYouUp', AssetName::fromHex('000de140'.bin2hex('GiveYouUp'))->display());
    }

    /**
     * The reason the label is carried through rather than stripped and dropped.
     *
     * CIP-0068 requires a reference NFT and its user token to share a name under one
     * policy and differ only in the label. A report that removed the label would print
     * the same row twice for two assets that are not the same asset.
     */
    public function test_a_reference_nft_and_its_user_token_do_not_render_alike(): void
    {
        $reference = AssetName::fromHex('000643b0'.bin2hex('Test123'));
        $user = AssetName::fromHex('000de140'.bin2hex('Test123'));

        $this->assertSame('(100)Test123', $reference->display());
        $this->assertSame('(222)Test123', $user->display());
        $this->assertNotSame($reference->display(), $user->display());

        // The name behind the label really is the same name; only the label separates them.
        $this->assertSame($reference->readable(), $user->readable());
    }

    /**
     * A name with no label is read exactly as it was before, which is most of them.
     */
    public function test_an_unlabelled_name_is_read_whole(): void
    {
        $name = AssetName::fromHex(bin2hex('Mascot'));

        $this->assertNull($name->label);
        $this->assertSame('Mascot', $name->readable());
        $this->assertSame('Mascot', $name->display());
    }

    /**
     * A name that is not text stays hex, which is the honest answer for it.
     */
    public function test_a_name_that_is_not_text_has_nothing_to_display(): void
    {
        $name = AssetName::fromHex('ff00ff');

        $this->assertNull($name->label);
        $this->assertNull($name->display());
    }

    /**
     * What the checksum is for.
     *
     * The zero brackets alone are a weak test: four bytes of a hash will wear them roughly
     * one time in two hundred and fifty six. CIP-0067 carries a CRC-8 over the label
     * number so that a coincidence can be told from a label, and recomputing it is what
     * makes recognition safe rather than a guess. Here the brackets are correct and the
     * checksum byte is one out, so the four bytes are not a label and the name is whatever
     * those bytes are.
     */
    public function test_a_prefix_with_the_wrong_checksum_is_not_a_label(): void
    {
        // 0x000de140 is label 222. 0x15 is not the checksum for 222; 0x14 is.
        $name = AssetName::fromHex('000de150'.bin2hex('MyToken'));

        $this->assertNull($name->label);
        $this->assertNull($name->display(), 'The unparsed bytes lead with NUL, so there is no readable name.');
    }

    /**
     * The brackets are checked too, and cost nothing.
     */
    public function test_a_prefix_without_its_zero_brackets_is_not_a_label(): void
    {
        // Label 222's own bytes with the trailing four bits set, which CIP-0067 requires
        // to be zero.
        $this->assertNull(AssetName::fromHex('000de141'.bin2hex('MyToken'))->label);

        // And with the leading four bits set.
        $this->assertNull(AssetName::fromHex('100de140'.bin2hex('MyToken'))->label);
    }

    /**
     * A name that looks like a label and is one.
     *
     * Printable ASCII cannot collide with a label, because a label's first byte is below
     * 0x10 and no printable character is. The collision that can happen is a name of raw
     * bytes, and there the label reading is correct: the issuer said so in the checksum.
     */
    public function test_a_label_with_bytes_behind_it_shows_no_name(): void
    {
        $name = AssetName::fromHex('000de140ff00ff');

        $this->assertSame(222, $name->label);
        $this->assertNull($name->readable());
        $this->assertNull($name->display(), 'A label over bytes that are not text still has no name to print.');
    }

    /**
     * A label and nothing else is still fully understood, so the hex would only repeat it
     * back in a form that is harder to read.
     */
    public function test_a_label_with_nothing_behind_it_shows_the_label(): void
    {
        $name = AssetName::fromHex('000de140');

        $this->assertSame(222, $name->label);
        $this->assertNull($name->readable());
        $this->assertSame('(222)', $name->display());
    }

    /**
     * The name behind a label is what CIP-0068 files a token's metadata under, so it is
     * available as bytes and not only as something to print.
     */
    public function test_the_name_behind_the_label_is_available_as_bytes(): void
    {
        $this->assertSame('Test123', AssetName::fromHex('000de140'.bin2hex('Test123'))->nameBytes());
        $this->assertSame('Mascot', AssetName::fromHex(bin2hex('Mascot'))->nameBytes());
    }

    /**
     * Nothing that is not an asset name off the chain is mistaken for one.
     *
     * @return array<string, array{?string}>
     */
    public static function notAssetNames(): array
    {
        return [
            'null' => [null],
            'empty' => [''],
            'odd length' => ['abc'],
            'not hexadecimal' => ['zzzz'],
            'shorter than a label' => ['000de1'],
        ];
    }

    #[DataProvider('notAssetNames')]
    public function test_something_that_is_not_an_asset_name_has_no_label_and_nothing_to_display(?string $hex): void
    {
        $name = AssetName::fromHex($hex);

        $this->assertNull($name->label);
        $this->assertNull($name->display());
    }

    /**
     * An asset name is at most 32 bytes, so a labelled name has at most 28 left for the
     * name. The longest one still reads.
     */
    public function test_a_labelled_name_at_the_full_length_of_an_asset_name_reads(): void
    {
        $longest = str_repeat('a', 28);

        $name = AssetName::fromHex('000de140'.bin2hex($longest));

        $this->assertSame(32, strlen('000de140'.bin2hex($longest)) / 2);
        $this->assertSame($longest, $name->readable());
        $this->assertSame("(222){$longest}", $name->display());
    }

    /**
     * Hex arrives from more than one place and not all of it is lower case.
     */
    public function test_an_upper_case_name_is_read_the_same_way(): void
    {
        $name = AssetName::fromHex(strtoupper('000de140'.bin2hex('MyToken')));

        $this->assertSame(222, $name->label);
        $this->assertSame('MyToken', $name->readable());
    }
}
