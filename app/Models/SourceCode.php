<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * A short opaque code issued before the thing that carries it is published, so a visit can
 * be traced back to the post, sticker or partner mention that produced it.
 *
 * Codes are minted here rather than reconstructed afterwards, which is the whole point: the
 * code has to exist when the artwork goes to print.
 */
class SourceCode extends Model
{
    public $incrementing = false;

    protected $primaryKey = 'code';

    protected $keyType = 'string';

    protected $guarded = [];

    /** The query parameter a code travels on. Short, because it is printed on cards. */
    public const PARAM = 's';

    /** Where a captured code waits between landing and registration. */
    public const SESSION_KEY = 'source_code';

    private const LENGTH = 4;

    public function visits()
    {
        return $this->hasMany(SourceCodeVisit::class, 'code', 'code');
    }

    public function users()
    {
        return $this->hasMany(User::class, 'source_code', 'code');
    }

    /**
     * A candidate code reduced to its canonical form, or null if it is not one.
     *
     * Case is folded rather than rejected, because a code is read off a printed card and
     * typed by hand. Anything that is not four hex characters is not a code, and is treated
     * as absent rather than looked up.
     */
    public static function normalise(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = strtolower(trim($value));

        return preg_match('/^[0-9a-f]{'.self::LENGTH.'}$/', $value) === 1 ? $value : null;
    }

    /**
     * Mint a code against the dimensions it is being issued for.
     *
     * Retries on collision rather than checking first, so two people issuing codes at once
     * cannot both be told the same unused value is free.
     */
    public static function issue(array $attributes): self
    {
        foreach (range(1, 10) as $ignored) {
            try {
                return static::create($attributes + ['code' => Str::lower(bin2hex(random_bytes(2)))]);
            } catch (UniqueConstraintViolationException) {
                continue;
            }
        }

        throw new RuntimeException('Could not mint an unused source code after ten attempts.');
    }

    /**
     * Add one to a code's visit count for today.
     *
     * Per day rather than per visit: the question a source code answers is how much traffic
     * a channel produced and how it moved over time, and a row per hit would collect
     * per-visitor detail that nothing here reports on.
     */
    public static function recordVisit(string $code): void
    {
        $date = now()->toDateString();

        if (self::incrementVisits($code, $date) > 0) {
            return;
        }

        try {
            DB::table('source_code_visits')->insert([
                'code' => $code,
                'date' => $date,
                'visits' => 1,
            ]);
        } catch (UniqueConstraintViolationException) {
            self::incrementVisits($code, $date);
        }
    }

    /**
     * Visits and accounts per code, with the dimensions each code was issued against.
     *
     * Accounts created is the figure worth having. A visit says a link was followed; an
     * account says the link did its job.
     */
    public static function funnel(): Collection
    {
        return static::query()
            // Aliased away from the relation names, so the summed attribute does not
            // shadow the visits relation on the model it is loaded onto.
            ->withSum('visits as visits_total', 'visits')
            ->withCount('users as accounts_total')
            ->orderBy('channel')
            ->orderBy('campaign')
            ->get()
            ->map(static fn (self $code) => [
                'code' => $code->code,
                'channel' => $code->channel,
                'campaign' => $code->campaign,
                'placement' => $code->placement,
                'variant' => $code->variant,
                'visits' => (int) $code->visits_total,
                'accounts' => (int) $code->accounts_total,
            ]);
    }

    private static function incrementVisits(string $code, string $date): int
    {
        return DB::table('source_code_visits')
            ->where('code', $code)
            ->where('date', $date)
            ->increment('visits');
    }
}
