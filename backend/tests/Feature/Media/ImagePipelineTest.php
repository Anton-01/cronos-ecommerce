<?php

namespace Tests\Feature\Media;

use Cronos\Media\Application\ImageSlots;
use Cronos\Media\Domain\ValueObjects\Conversion;
use Cronos\Media\Domain\ValueObjects\ImageInfo;
use Cronos\Media\Domain\ValueObjects\Transform;
use Cronos\Media\Infrastructure\Adapters\GdImageProcessor;
use Cronos\Media\Infrastructure\Persistence\Eloquent\MediaModel;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\GraphQLTestCase;

final class ImagePipelineTest extends GraphQLTestCase
{
    private const UP = 'mutation($file: Upload!, $slot: ImageSlot) { uploadMedia(file: $file, slot: $slot) {
        id collectionName status width height warnings srcset srcsetAvif focal { x y } optimization { originalBytes servedBytes savedPercent } } }';

    private ?string $token = null;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('media');
        $this->token = $this->adminToken();
    }

    private function upload(UploadedFile $file, ?string $slot): TestResponse
    {
        return $this->gqlUpload(self::UP, ['slot' => $slot], ['variables.file' => $file], $this->token);
    }

    /** A flat-colour picture with a bright square: the subject. */
    private function picture(int $w, int $h, int $subjectX, int $subjectY): string
    {
        $im = imagecreatetruecolor($w, $h);
        imagefill($im, 0, 0, (int) imagecolorallocate($im, 30, 30, 30));
        imagefilledrectangle($im, $subjectX - 20, $subjectY - 20, $subjectX + 20, $subjectY + 20, (int) imagecolorallocate($im, 255, 255, 255));
        ob_start();
        imagepng($im);

        return (string) ob_get_clean();
    }

    public function test_a_slot_files_the_picture_where_it_belongs_and_refuses_what_is_too_small_to_look_right(): void
    {
        $ok = $this->upload(UploadedFile::fake()->image('p.jpg', 1000, 1000), 'PRODUCT')->assertJsonMissingPath('errors')->json('data.uploadMedia');
        $this->assertSame('gallery', $ok['collectionName']);
        $this->assertCount(1, $ok['warnings'], 'below the recommended 2000 px: a gentle warning');
        $this->assertStringContainsString('2000×2000', $ok['warnings'][0]);

        $this->upload(UploadedFile::fake()->image('tiny.jpg', 300, 300), 'PRODUCT')->assertJsonPath('errors.0.extensions.code', 'INVALID_IMAGE')
            ->assertJsonPath('errors.0.message', fn ($m) => str_contains($m, 'necesita al menos 800×800'));
        $this->assertSame(1, MediaModel::query()->count(), 'the refused upload left nothing behind');

        $wrongShape = $this->upload(UploadedFile::fake()->image('wide.jpg', 2560, 1067), 'PRODUCT')->json('data.uploadMedia');
        $this->assertTrue(collect($wrongShape['warnings'])->contains(fn ($w) => str_contains($w, 'proporción')), 'a wide picture for a square slot');

        $hero = $this->upload(UploadedFile::fake()->image('hero.jpg', 2560, 1067), 'HERO')->json('data.uploadMedia');
        $this->assertSame(['hero', []], [$hero['collectionName'], $hero['warnings']]);
    }

    public function test_renditions_follow_the_slots_widths_never_exceed_the_cap_and_the_original_is_not_served(): void
    {
        $m = $this->upload(UploadedFile::fake()->image('h.jpg', 3200, 1333), 'HERO')->json('data.uploadMedia');
        $widths = array_map(fn ($c) => (int) explode('w', explode(' ', trim($c))[1] ?? '0')[0], explode(', ', $m['srcset']));
        $widths = array_map(fn (string $c) => (int) preg_replace('/.* (\d+)w$/', '$1', $c), explode(', ', $m['srcset']));
        $this->assertSame([640, 1024, 1440, 1920, 2560], $widths, 'the hero widths, capped at 2560 px even for a 3200 px original');
        $this->assertStringNotContainsString('3200w', $m['srcset']);

        $o = $m['optimization'];
        $this->assertGreaterThan(0, $o['originalBytes']);
        $this->assertGreaterThan(0, $o['servedBytes']);
        $this->assertGreaterThanOrEqual(0, $o['savedPercent']);
    }

    public function test_a_focal_point_steers_every_cover_crop_and_rebuilds_the_renditions(): void
    {
        $id = $this->upload(UploadedFile::fake()->createWithContent('subject.png', $this->picture(1000, 400, 880, 200)), 'CONTENT')->json('data.uploadMedia.id');
        $thumb = fn () => imagecreatefromstring(Storage::disk('media')->get("{$id}/conversions/thumb.webp"));
        $brightness = function (\GdImage $im): float {
            $sum = 0;
            for ($x = 0; $x < imagesx($im); $x += 4) {
                for ($y = 0; $y < imagesy($im); $y += 4) {
                    $sum += (imagecolorat($im, $x, $y) & 0xFF);
                }
            }

            return $sum;
        };
        $centred = $brightness($thumb());

        $r = $this->adminGql('mutation($id: ID!) { setMediaFocalPoint(id: $id, x: 0.88, y: 0.5) { status focal { x y } } }', ['id' => $id], $this->token)->assertJsonMissingPath('errors');
        $this->assertSame(['x' => 0.88, 'y' => 0.5], $r->json('data.setMediaFocalPoint.focal'));
        $this->assertSame('ready', MediaModel::query()->find($id)->status, 'the queue ran: renditions rebuilt');
        $this->assertGreaterThan($centred, $brightness($thumb()), 'the subject at the right edge is now inside the square crop');

        $this->adminGql('mutation($id: ID!) { setMediaFocalPoint(id: $id, x: 1.4, y: 0.5) { id } }', ['id' => $id], $this->token)->assertJsonPath('errors.0.extensions.code', 'INVALID_IMAGE');
        $this->assertSame(['x' => 0.88, 'y' => 0.5], MediaModel::query()->find($id)->focal, 'a focal point survives the pipeline re-run');
    }

    public function test_the_slot_guide_is_available_to_the_back_office(): void
    {
        $slots = $this->adminGql('{ imageSlots { slot label ratio recommendedWidth recommendedHeight minWidth widths focal svg } }', [], $this->token)->assertJsonMissingPath('errors')->json('data.imageSlots');
        $by = collect($slots)->keyBy('slot');
        $this->assertEquals([2000, 2000, 800, 1.0], [$by['PRODUCT']['recommendedWidth'], $by['PRODUCT']['recommendedHeight'], $by['PRODUCT']['minWidth'], $by['PRODUCT']['ratio']]);
        $this->assertSame([true, 2.4], [$by['HERO']['focal'], $by['HERO']['ratio']]);
        $this->assertTrue($by['LOGO']['svg']);
        $this->assertNull($by['LOGO']['ratio']);
    }

    public function test_transform_crops_rotates_flips_and_shrinks_the_same_way_every_time(): void
    {
        $gd = new GdImageProcessor;
        $src = $this->picture(800, 400, 100, 100);

        $cropped = $gd->transform($src, new Transform(0, 0, 400, 400));
        $this->assertSame([400, 400], [$cropped->width, $cropped->height]);

        $rotated = $gd->transform($src, new Transform(0, 0, 800, 400, rotate: 90));
        $this->assertSame([400, 800], [$rotated->width, $rotated->height]);

        $small = $gd->transform($src, new Transform(0, 0, 800, 400, maxWidth: 200));
        $this->assertSame([200, 100], [$small->width, $small->height]);
        $big = $gd->transform($src, new Transform(0, 0, 800, 400, maxWidth: 5000));
        $this->assertSame(800, $big->width, 'never scaled up');

        $outside = $gd->transform($src, new Transform(700, 300, 5000, 5000));
        $this->assertSame([100, 100], [$outside->width, $outside->height], 'a crop beyond the picture is clamped');

        $flipped = imagecreatefromstring($gd->transform($src, new Transform(0, 0, 800, 400, flipX: true))->contents);
        $this->assertSame(255, imagecolorat($flipped, 700, 100) & 0xFF, 'the bright subject moved to the mirrored side');
        $this->assertSame($gd->transform($src, new Transform(0, 0, 400, 400))->contents, $cropped->contents, 'deterministic');
    }

    public function test_transparent_graphics_are_detected_and_encoded_without_loss(): void
    {
        $gd = new GdImageProcessor;
        $im = imagecreatetruecolor(64, 64);
        imagealphablending($im, false);
        imagesavealpha($im, true);
        imagefill($im, 0, 0, (int) imagecolorallocatealpha($im, 0, 0, 0, 127));
        imagefilledellipse($im, 32, 32, 40, 40, (int) imagecolorallocatealpha($im, 200, 20, 20, 0));
        ob_start();
        imagepng($im);
        $logo = (string) ob_get_clean();

        $this->assertTrue($gd->hasAlpha($logo));
        $this->assertFalse($gd->hasAlpha($this->picture(64, 64, 10, 10)));
        $this->assertTrue($gd->canEncode('webp'));
        $this->assertFalse($gd->canEncode('gif'));

        $lossless = $gd->convert($logo, new Conversion('x', 64, 64, 'contain', 'webp', 80, lossless: true));
        $round = imagecreatefromstring($lossless->contents);
        $this->assertSame(200, (imagecolorat($round, 32, 32) >> 16) & 0xFF, 'exact colour kept');
        $this->assertSame(127, (imagecolorat($round, 2, 2) >> 24) & 0x7F, 'transparency kept');
    }

    public function test_image_slots_pick_widths_and_quality(): void
    {
        $slots = app(ImageSlots::class);
        $this->assertSame([300, 600, 900, 1000], $slots->widths('category', 1000));
        $this->assertSame([800, 1200, 1600, 2000, 2560], array_slice($slots->widths('gallery', 6000), 1), 'the slot widths plus one full-size twin, capped by config max_width');
        $this->assertSame([76, 80, 82], [$slots->qualityFor(480), $slots->qualityFor(1024), $slots->qualityFor(1920)]);
        $this->assertSame('product', $slots->forCollection('gallery'));
        $this->assertSame([], $slots->check('library', new ImageInfo(10, 10)));
    }
}
