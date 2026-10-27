<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * The wrapping key encrypts every campaign's signing key, so running the quickstart again must
 * never replace it: a new key cannot read what the old one wrote.
 */
class QuickstartWrappingKeyTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/quickstart-'.bin2hex(random_bytes(4));
        File::makeDirectory($this->dir);
        File::copy(base_path('quickstart.sh'), $this->dir.'/quickstart.sh');
        File::copy(base_path('.env.docker'), $this->dir.'/.env.docker');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);

        parent::tearDown();
    }

    private function quickstart(string ...$arguments): void
    {
        $process = new Process(['bash', 'quickstart.sh', '--yes', ...$arguments], $this->dir);
        $process->mustRun();
    }

    private function wrappingKey(string $file = '.env'): string
    {
        preg_match('/^CAMPAIGN_WRAPPING_KEY_1=(.+)$/m', File::get($this->dir.'/'.$file), $match);

        return $match[1] ?? '';
    }

    public function test_a_key_is_generated_and_survives_running_it_again_with_force(): void
    {
        $this->quickstart();
        $first = $this->wrappingKey();

        $this->assertSame(32, strlen(base64_decode($first, true)));

        $this->quickstart('--force');
        $this->quickstart('--force');

        $this->assertSame($first, $this->wrappingKey());
        $this->assertCount(2, glob($this->dir.'/.env.bak.*'));
    }
}
