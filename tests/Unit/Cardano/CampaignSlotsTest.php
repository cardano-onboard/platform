<?php

namespace Tests\Unit\Cardano;

use App\Cardano\CampaignSlots;
use App\Cardano\ChainData;
use DateTimeImmutable;
use RuntimeException;
use Tests\TestCase;

/**
 * Turning a date into a slot, and refusing where the answer would be a guess.
 */
class CampaignSlotsTest extends TestCase
{
    /** A tip somewhere in preprod's history, so the arithmetic has a real anchor to move from. */
    private const TIP_SLOT = 140_000_000;

    private const TIP_TIME = 1_789_000_000;

    private function slots(?array $tip = null): CampaignSlots
    {
        $chain = new class($tip ?? ['slot' => self::TIP_SLOT, 'time' => self::TIP_TIME]) extends ChainData
        {
            public function __construct(private readonly array $answer) {}

            public function tip(string $network): array
            {
                return $this->answer;
            }
        };

        return new CampaignSlots($chain);
    }

    private function at(int $offsetSeconds): DateTimeImmutable
    {
        return new DateTimeImmutable('@'.(self::TIP_TIME + $offsetSeconds));
    }

    public function test_a_slot_is_the_tip_plus_the_seconds_since_it(): void
    {
        $this->assertSame(self::TIP_SLOT, $this->slots()->slotAt('preprod', $this->at(0)));
        $this->assertSame(self::TIP_SLOT + 1, $this->slots()->slotAt('preprod', $this->at(1)));
        $this->assertSame(self::TIP_SLOT + 86_400, $this->slots()->slotAt('preprod', $this->at(86_400)));
    }

    /**
     * A campaign cannot expire in the past.
     */
    public function test_a_date_before_the_tip_is_refused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('cannot expire in the past');

        $this->slots()->slotAt('preprod', $this->at(-1));
    }

    /**
     * Past the horizon the answer would be a guess about whether a slot is still a second, and a
     * guess here goes inside an address that cannot be changed afterwards.
     */
    public function test_a_date_past_the_horizon_is_refused_rather_than_guessed(): void
    {
        config([
            'campaign_script.authorisation_days' => 180,
        ]);

        // 300 days of window plus margin, so 301 is over and 299 is under.
        $this->assertIsInt($this->slots()->slotAt('preprod', $this->at(299 * 86_400)));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('past the chain tip');

        $this->slots()->slotAt('preprod', $this->at(301 * 86_400));
    }

    /**
     * The expiry is the campaign's end plus the grace settlement needs.
     */
    public function test_an_expiry_is_the_end_date_plus_the_grace_window(): void
    {
        $end = $this->at(10 * 86_400);
        $expiry = $this->slots()->expiryFor('preprod', $end);

        $this->assertSame(self::TIP_SLOT + (100 * 86_400), $expiry);
    }

    /**
     * The grace window is 90 days whatever the environment says. It goes inside every campaign's
     * address, so no setting can move it, and both directions use the same figure.
     */
    public function test_the_grace_window_is_ninety_days_and_no_configuration_changes_it(): void
    {
        config(['campaign_script.grace_days' => 30]);

        $end = $this->at(5 * 86_400);
        $expiry = $this->slots()->expiryFor('preprod', $end);

        $this->assertSame(90, CampaignSlots::GRACE_DAYS);
        $this->assertSame(self::TIP_SLOT + (95 * 86_400), $expiry);
    }
}
