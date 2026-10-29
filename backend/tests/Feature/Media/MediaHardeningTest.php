<?php

namespace Tests\Feature\Media;

use Cronos\Media\Application\PurgeDeletedMedia;
use Cronos\Media\Domain\Exceptions\ScannerUnavailable;
use Cronos\Media\Domain\Ports\MalwareScanner;
use Cronos\Media\Infrastructure\Adapters\ClamAvMalwareScanner;
use Cronos\Media\Infrastructure\Persistence\Eloquent\MediaModel;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\GraphQLTestCase;

final class MediaHardeningTest extends GraphQLTestCase
{
    private const UPLOAD = 'mutation($file: Upload!) { uploadMedia(file: $file) { id status srcset conversions { name } } }';

    private ?string $token = null;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('media');
    }

    private function upload(UploadedFile $file): TestResponse
    {
        return $this->gqlUpload(self::UPLOAD, [], ['variables.file' => $file], $this->token ??= $this->adminToken());
    }

    public function test_responsive_renditions_are_generated_below_the_original_width_and_exposed_as_srcset(): void
    {
        $media = $this->upload(UploadedFile::fake()->image('a.jpg', 1000, 500))->assertJsonMissingPath('errors')->json('data.uploadMedia');

        foreach ([320, 640, 960] as $w) {
            Storage::disk('media')->assertExists("{$media['id']}/responsive/w{$w}.webp");
        }
        Storage::disk('media')->assertMissing("{$media['id']}/responsive/w1280.webp");
        $info = getimagesizefromstring(Storage::disk('media')->get("{$media['id']}/responsive/w640.webp"));
        $this->assertSame([640, 320], [$info[0], $info[1]], 'height follows the 2:1 ratio');

        $srcset = explode(', ', $media['srcset']);
        $this->assertCount(4, $srcset, '3 renditions + the original');
        $this->assertStringEndsWith('/responsive/w320.webp 320w', $srcset[0]);
        $this->assertStringEndsWith('1000w', $srcset[3]);
    }

    public function test_an_infected_upload_is_refused_and_nothing_is_stored(): void
    {
        $this->app->bind(MalwareScanner::class, fn () => new class implements MalwareScanner
        {
            public function scan(string $contents): ?string
            {
                return 'Fake.Virus';
            }
        });

        $this->upload(UploadedFile::fake()->image('a.jpg', 100, 100))->assertJsonPath('errors.0.extensions.code', 'MALWARE_DETECTED');
        $this->assertSame(0, MediaModel::query()->count());
        $this->assertSame([], Storage::disk('media')->allFiles());
    }

    public function test_a_scanner_that_cannot_be_reached_refuses_uploads_when_failing_closed(): void
    {
        config(['media.antivirus.driver' => 'clamav', 'media.antivirus.socket' => '/nonexistent/clamd.sock', 'media.antivirus.timeout' => 1]);

        $this->upload(UploadedFile::fake()->image('a.jpg', 100, 100))->assertJsonPath('errors.0.extensions.code', 'SCANNER_UNAVAILABLE');

        config(['media.antivirus.fail_closed' => false]);
        $r = $this->upload(UploadedFile::fake()->image('b.jpg', 100, 100));
        $r->assertJsonMissingPath('errors');
    }

    public function test_the_clamav_adapter_speaks_the_instream_protocol(): void
    {
        $socket = sys_get_temp_dir().'/clamd-test-'.uniqid().'.sock';
        $scanner = new ClamAvMalwareScanner;

        foreach (['clean bytes' => null, 'X5O!P%@AP[4\PZX54(P^)7CC)7}$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*' => 'Eicar-Test-Signature'] as $payload => $expected) {
            $process = proc_open([PHP_BINARY, __DIR__.'/../../Support/clamd_stub.php', $socket], [1 => ['pipe', 'w']], $pipes);
            fgets($pipes[1]); // "ready"
            config(['media.antivirus.socket' => $socket, 'media.antivirus.timeout' => 5]);

            $this->assertSame($expected, $scanner->scan(str_repeat('a', 20000).$payload), $payload);
            proc_close($process);
        }
        @unlink($socket);
    }

    public function test_purge_removes_the_files_of_old_deleted_media_but_keeps_recent_ones_and_the_row(): void
    {
        $token = $this->token ??= $this->adminToken();
        $old = $this->gqlUpload(self::UPLOAD, [], ['variables.file' => UploadedFile::fake()->image('old.jpg', 800, 600)], $token)->json('data.uploadMedia.id');
        $recent = $this->gqlUpload(self::UPLOAD, [], ['variables.file' => UploadedFile::fake()->image('new.jpg', 800, 600)], $token)->json('data.uploadMedia.id');
        $kept = $this->gqlUpload(self::UPLOAD, [], ['variables.file' => UploadedFile::fake()->image('kept.jpg', 800, 600)], $token)->json('data.uploadMedia.id');

        foreach ([$old, $recent] as $id) {
            MediaModel::query()->findOrFail($id)->delete();
        }
        MediaModel::onlyTrashed()->whereKey($old)->update(['deleted_at' => now()->subDays(45)]);

        $this->artisan('media:purge')->assertSuccessful();

        $this->assertSame([], Storage::disk('media')->allFiles($old), 'original, conversions and responsive renditions are gone');
        $this->assertNotEmpty(Storage::disk('media')->allFiles($recent), 'inside the retention window');
        $this->assertNotEmpty(Storage::disk('media')->allFiles($kept), 'not deleted');
        $this->assertNotNull(MediaModel::onlyTrashed()->find($old)->purged_at);
        $this->assertNull(MediaModel::onlyTrashed()->find($recent)->purged_at);

        $this->assertSame(0, app(PurgeDeletedMedia::class)(new \DateTimeImmutable('-30 days')), 'a second run finds nothing');
    }

    public function test_scanner_unavailable_is_a_domain_error_code(): void
    {
        $this->assertSame('SCANNER_UNAVAILABLE', (new ScannerUnavailable)->errorCode());
    }
}
