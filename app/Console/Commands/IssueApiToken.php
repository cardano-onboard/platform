<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\ApiAbilities;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Mint a Sanctum token scoped to exactly the abilities named, for a caller that has no
 * profile page to issue one from — an external integration, or a self-hosted operator
 * with shell access and no account UI for this at all, since token issuance is a
 * SaaS-only page.
 *
 * Abilities are checked against App\Support\ApiAbilities so a typo refuses rather than
 * minting a token that silently matches no route. --expires is required and has no
 * never-expiring default: a token minted from a shell is exactly the kind that gets made
 * once and forgotten, and a backfill migration exists only because a global expiration
 * setting once let tokens like that outlive it silently. The plaintext token is printed
 * once, because Sanctum stores only its hash: this command is the only moment it exists
 * to be read back.
 */
class IssueApiToken extends Command
{
    protected $signature = 'api:token
        {user : The account the token acts as, by email}
        {--abilities=* : One or more abilities the token may use (repeat the option for each)}
        {--expires= : When the token stops working, e.g. "2026-12-31" or "+30 days" (required)}
        {--name= : A label for the token, shown on the account\'s token list}';

    protected $description = 'Mint a Sanctum API token scoped to exactly the abilities named';

    public function handle(): int
    {
        $user = User::where('email', $this->argument('user'))->first();

        if (! $user) {
            $this->error('No account has that email address.');

            return self::FAILURE;
        }

        $abilities = $this->option('abilities');

        if ($abilities === []) {
            $this->error('At least one ability is required.');
            $this->line('Known abilities: '.implode(', ', ApiAbilities::known()));

            return self::FAILURE;
        }

        $unknown = array_values(array_diff($abilities, ApiAbilities::known()));

        if ($unknown !== []) {
            $this->error('Unknown abilit'.(count($unknown) === 1 ? 'y' : 'ies').': '.implode(', ', $unknown));
            $this->line('Known abilities: '.implode(', ', ApiAbilities::known()));

            return self::FAILURE;
        }

        $rawExpires = $this->option('expires');

        if ($rawExpires === null || $rawExpires === '') {
            $this->error('An --expires value is required. There is no default that mints a token which never expires.');

            return self::FAILURE;
        }

        try {
            $expiresAt = Carbon::parse($rawExpires);
        } catch (Exception) {
            $this->error("Could not read \"{$rawExpires}\" as a date or a relative time.");

            return self::FAILURE;
        }

        if ($expiresAt->isPast()) {
            $this->error('That expiry is already in the past.');

            return self::FAILURE;
        }

        $name = $this->option('name') ?: implode(',', $abilities);

        $token = $user->createToken($name, $abilities, $expiresAt);

        $this->info("Token minted for {$user->email}.");
        $this->table(['Field', 'Value'], [
            ['Name', $name],
            ['Abilities', implode(', ', $abilities)],
            ['Expires', $expiresAt->toDateTimeString()],
        ]);

        $this->newLine();
        $this->line('This is shown once — copy it now:');
        $this->line($token->plainTextToken);

        return self::SUCCESS;
    }
}
