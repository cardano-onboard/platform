<?php

namespace Tests\Unit\Support;

use App\Support\QrExportFolders;
use Tests\TestCase;

/**
 * The rule that turns a partner's name into a folder inside a sticker archive.
 *
 * Two things are being defended here, and they are not the same thing. One is that the
 * archive can be trusted: two partners in one folder makes every count in that folder
 * wrong, and the counts are what the feature is for. The other is that the archive is safe
 * to open: the name is eighty characters an operator typed, the archive is unzipped on
 * somebody's laptop, and a name that reaches a path unmodified is the Zip Slip shape.
 *
 * Names are passed as plain arrays rather than models, so the rule is exercised on its own
 * rather than through a schema that happens to hold it.
 */
class QrExportFoldersTest extends TestCase
{
    /** ULIDs from one millisecond: the same ten leading characters, different random tails. */
    private const SAME_MOMENT_A = '01K5A1B2C3ZZQ8N4MFJXW7T0RA';

    private const SAME_MOMENT_B = '01K5A1B2C3D9K2P6VHSBY5E1QC';

    public function test_a_plain_name_becomes_its_slug(): void
    {
        $folders = QrExportFolders::forPartners([
            ['id' => self::SAME_MOMENT_A, 'name' => 'Vendor A'],
        ]);

        $this->assertSame('vendor-a', $folders->folderFor(self::SAME_MOMENT_A));
        $this->assertSame('Vendor A', $folders->nameFor('vendor-a'));
    }

    public function test_two_partners_whose_names_slug_the_same_never_share_a_folder(): void
    {
        // The case the whole feature rests on. "Vendor A" and "vendor a!" are one slug, and
        // filing both under it would make the folder's header, its code list and the files
        // beside them describe two partners' codes as one partner's.
        $folders = QrExportFolders::forPartners([
            ['id' => self::SAME_MOMENT_A, 'name' => 'Vendor A'],
            ['id' => self::SAME_MOMENT_B, 'name' => 'vendor a!'],
        ]);

        $a = $folders->folderFor(self::SAME_MOMENT_A);
        $b = $folders->folderFor(self::SAME_MOMENT_B);

        $this->assertNotSame($a, $b, 'two partners were filed in one folder');
        $this->assertStringStartsWith('vendor-a', $a);
        $this->assertStringStartsWith('vendor-a', $b);

        // Each folder still names the partner it holds, or the manifest inside it would
        // credit the stack to the wrong person.
        $this->assertSame('Vendor A', $folders->nameFor($a));
        $this->assertSame('vendor a!', $folders->nameFor($b));
    }

    public function test_partners_added_in_the_same_moment_are_still_told_apart(): void
    {
        // A ULID's first ten characters are the millisecond it was created in, so two
        // partners added in the same few minutes share their leading characters entirely.
        // A disambiguator taken from the front of the id would be the same disambiguator.
        $this->assertSame(
            substr(self::SAME_MOMENT_A, 0, 10),
            substr(self::SAME_MOMENT_B, 0, 10),
            'the fixture no longer covers two ids from one moment',
        );

        $folders = QrExportFolders::forPartners([
            ['id' => self::SAME_MOMENT_A, 'name' => 'Booth'],
            ['id' => self::SAME_MOMENT_B, 'name' => 'BOOTH'],
        ]);

        $this->assertNotSame(
            $folders->folderFor(self::SAME_MOMENT_A),
            $folders->folderFor(self::SAME_MOMENT_B),
        );
    }

    public function test_the_same_partners_always_produce_the_same_folders(): void
    {
        // A reprint has to land in the folder the printer already has, and the archive is
        // rebuilt from scratch every time the cache key moves.
        $partners = [
            ['id' => self::SAME_MOMENT_B, 'name' => 'Vendor A'],
            ['id' => self::SAME_MOMENT_A, 'name' => 'vendor a'],
        ];

        $first = QrExportFolders::forPartners($partners)->all();

        // Queried in a different order, which is the one thing a database does not promise
        // to keep the same between two runs.
        $second = QrExportFolders::forPartners(array_reverse($partners))->all();

        $this->assertSame($first, $second);
    }

    public function test_a_name_that_slugs_to_nothing_still_gets_a_folder(): void
    {
        $folders = QrExportFolders::forPartners([
            ['id' => self::SAME_MOMENT_A, 'name' => '!!! ...'],
            ['id' => self::SAME_MOMENT_B, 'name' => '🎪🎟️'],
        ]);

        foreach ([self::SAME_MOMENT_A, self::SAME_MOMENT_B] as $id) {
            $folder = $folders->folderFor($id);

            $this->assertStringStartsWith('partner-', $folder);
            $this->assertMatchesRegularExpression('/^[a-z0-9][a-z0-9-]*$/', $folder);
        }

        $this->assertNotSame(
            $folders->folderFor(self::SAME_MOMENT_A),
            $folders->folderFor(self::SAME_MOMENT_B),
        );
    }

    public function test_a_partner_named_unassigned_does_not_take_the_reserved_folder(): void
    {
        // The unassigned folder means "nobody was given these". A partner sitting in it
        // would put somebody's stack among the leftovers, and the folder's manifest would
        // name a partner for codes that have none.
        $folders = QrExportFolders::forPartners([
            ['id' => self::SAME_MOMENT_A, 'name' => 'Unassigned'],
        ]);

        $folder = $folders->folderFor(self::SAME_MOMENT_A);

        $this->assertNotSame(QrExportFolders::UNASSIGNED, $folder);
        $this->assertStringStartsWith(QrExportFolders::UNASSIGNED.'-', $folder);
        $this->assertSame(QrExportFolders::UNASSIGNED_LABEL, $folders->nameFor(QrExportFolders::UNASSIGNED));
    }

    public function test_no_name_can_reach_outside_the_archive(): void
    {
        $escapes = [
            '../../etc/passwd',
            '..',
            '../',
            '/etc/passwd',
            'C:\\Windows\\System32',
            'a/b/c',
            '....//....//',
            ".\u{200b}.",
        ];

        $partners = [];

        foreach ($escapes as $i => $name) {
            $partners[] = ['id' => sprintf('01K5A1B2C3%016d', $i), 'name' => $name];
        }

        $folders = QrExportFolders::forPartners($partners);

        foreach ($partners as $partner) {
            $folder = $folders->folderFor($partner['id']);

            $this->assertMatchesRegularExpression(
                '/^[a-z0-9][a-z0-9-]*$/',
                $folder,
                "the name {$partner['name']} produced a folder that is not a plain slug",
            );
            $this->assertStringNotContainsString('..', $folder);
            $this->assertStringNotContainsString('/', $folder);
            $this->assertStringNotContainsString('\\', $folder);
        }

        // Every one of them is still its own folder: escaping is prevented by replacing the
        // name, not by dropping partners into one bucket.
        $this->assertCount(count($escapes), array_unique($folders->all()));
    }

    public function test_a_long_name_is_cut_to_a_length_a_path_can_hold(): void
    {
        $folders = QrExportFolders::forPartners([
            ['id' => self::SAME_MOMENT_A, 'name' => str_repeat('Cardano Summit Booth ', 6)],
        ]);

        $this->assertLessThanOrEqual(40, strlen($folders->folderFor(self::SAME_MOMENT_A)));
    }

    public function test_a_code_with_no_partner_belongs_to_the_unassigned_folder(): void
    {
        $folders = QrExportFolders::forPartners([
            ['id' => self::SAME_MOMENT_A, 'name' => 'Vendor A'],
        ]);

        $this->assertSame(QrExportFolders::UNASSIGNED, $folders->folderFor(null));
        $this->assertSame(QrExportFolders::UNASSIGNED, $folders->folderFor(''));

        // A partner this campaign does not have. Nothing should ever ask, but answering with
        // the reserved folder puts the code in the archive, where answering with the id
        // would put the id in a path.
        $this->assertSame(QrExportFolders::UNASSIGNED, $folders->folderFor('01KNOTAPARTNERONTHISCAMPAI'));
    }
}
