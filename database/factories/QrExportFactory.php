<?php

namespace Database\Factories;

use App\Models\Campaign;
use App\Models\QrExport;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<QrExport>
 */
class QrExportFactory extends Factory
{
    protected $model = QrExport::class;

    public function definition(): array
    {
        $key = hash('sha256', Str::uuid()->toString());

        return [
            'campaign_id' => Campaign::factory(),
            'cache_key' => $key,
            'status' => QrExport::STATUS_READY,
            'settings' => [
                'format' => 'pdf',
                'size' => 1.0,
                'dpi' => 203,
                'ecc' => 'L',
                'header' => false,
                'footer' => false,
            ],
            'disk' => 'local',
            'path' => 'qr-exports/'.Str::ulid().'/'.$key.'.zip',
            'bytes' => 4096,
            'codes_total' => 2,
            'manifest' => ['layout' => 'flat', 'extension' => 'pdf', 'entries' => 2],
            'expires_at' => now()->addDays(7),
        ];
    }

    public function expired(): static
    {
        return $this->state(fn () => [
            'status' => QrExport::STATUS_EXPIRED,
            'path' => null,
            'expires_at' => now()->subDay(),
        ]);
    }
}
