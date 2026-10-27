<?php

namespace App\Console\Commands;

use App\Support\EnvListing;
use App\Support\EnvReadiness;
use Illuminate\Console\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Terminal;

/**
 * Judges this process's environment against a kind of deployment and exits non-zero when
 * anything is missing or wrong, so it can sit in front of a deploy. Read-only: it never
 * prints a secret, and the live probes only read.
 *
 * With --all it does not judge anything. It lists every variable the application reads, the
 * value it is running with and the default, which is how to find the variable behind a
 * behaviour. Values that could be credentials are hidden in every mode.
 */
class EnvCheck extends Command
{
    protected $signature = 'poo:env-check
        {--profile=production : production, staging or self-hosted}
        {--live : Also probe the database, queue, transaction backend, Koios and mail transport, read-only}
        {--timeout=5 : Seconds each live probe may take}
        {--strict : Treat warnings as failures too}
        {--all : List every environment variable the application reads with its value and default, instead of checking}
        {--grep= : With --all, only variables whose name, config key, group or purpose contain every word given}
        {--changed : With --all, only variables whose value differs from the default}
        {--group= : With --all, only these config files, comma separated}
        {--json : With --all, print JSON instead of text}';

    protected $description = 'Check that the environment is complete and right for a deployment profile';

    public function handle(): int
    {
        if ($this->option('all')) {
            return $this->listAll();
        }

        if ($this->option('grep') !== null || $this->option('changed') || $this->option('group') !== null || $this->option('json')) {
            $this->error('--grep, --changed, --group and --json go with --all.');

            return self::FAILURE;
        }

        $profile = (string) $this->option('profile');

        if (! in_array($profile, EnvReadiness::PROFILES, true)) {
            $this->error('--profile must be production, staging or self-hosted.');

            return self::FAILURE;
        }

        $check = new EnvReadiness($profile);
        $findings = $check->run();

        $notes = [];

        if ($this->option('live')) {
            $findings = array_merge($findings, $check->live((int) $this->option('timeout')));
            $notes = $check->liveNotes();
        }

        $this->table(
            ['Name', 'Status', 'Reason'],
            array_map(fn (array $f) => [$f['name'], strtoupper($f['status']), $f['reason']], $findings),
        );

        foreach ($notes as $note) {
            $this->line("NOTE  {$note}");
        }

        $count = fn (string $status) => count(array_filter($findings, fn (array $f) => $f['status'] === $status));

        $this->line(sprintf(
            'Profile %s: %d ok, %d warning, %d missing, %d wrong.',
            $profile,
            $count(EnvReadiness::OK),
            $count(EnvReadiness::WARNING),
            $count(EnvReadiness::MISSING),
            $count(EnvReadiness::WRONG),
        ));

        return EnvReadiness::fails($findings, (bool) $this->option('strict')) ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Every variable the application reads, one row each, with nothing in it that could be a
     * credential. The check options do not apply and are ignored.
     */
    private function listAll(): int
    {
        $listing = new EnvListing;

        $groups = null;

        if ($this->option('group') !== null) {
            $groups = array_values(array_filter(array_map('trim', explode(',', (string) $this->option('group')))));
            $known = array_map('strtolower', $listing->groups());

            foreach ($groups as $group) {
                if (! in_array(strtolower($group), $known, true)) {
                    $this->error("No config file named \"{$group}\". The files are: ".implode(', ', $listing->groups()).'.');

                    return self::FAILURE;
                }
            }
        }

        $total = count($listing->rows());
        $rows = $listing->filter($this->option('grep'), (bool) $this->option('changed'), $groups);

        if ($this->option('json')) {
            $this->output->writeln((string) json_encode([
                'shown' => count($rows),
                'total' => $total,
                'variables' => array_map(fn (array $row) => EnvListing::export($row), $rows),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), OutputInterface::OUTPUT_RAW);

            return self::SUCCESS;
        }

        $width = EnvListing::clamp((new Terminal)->getWidth());

        foreach (EnvListing::lines($rows, $width) as $line) {
            $this->output->writeln($line, OutputInterface::OUTPUT_RAW);
        }

        $changed = count(array_filter($rows, fn (array $row) => $row['changed']));
        $variables = count(array_unique(array_column($rows, 'variable')));

        $this->output->writeln('', OutputInterface::OUTPUT_RAW);
        $this->output->writeln($rows === []
            ? "No variable matches. There are {$total} rows in all."
            : sprintf('Showing %d of %d rows, %d variables, %d differ from their default.', count($rows), $total, $variables, $changed), OutputInterface::OUTPUT_RAW);
        $this->output->writeln(wordwrap('* poo:env-check has a rule for this variable. "default" can also mean the variable is set to its default. A value that could be a credential is shown only as set (hidden) or unset.', $width, "\n", true), OutputInterface::OUTPUT_RAW);

        return self::SUCCESS;
    }
}
