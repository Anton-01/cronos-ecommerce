<?php

namespace Tests\Feature\Media;

use Cronos\Media\Application\Listeners\ProcessUploadedMedia;
use Cronos\Media\Domain\Events\MediaUploaded;
use Cronos\Media\Infrastructure\Persistence\Eloquent\MediaModel;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\GraphQLTestCase;

class MediaLibraryTest extends GraphQLTestCase
{
    private const UPLOAD = 'mutation($file: Upload!, $collection: String) { uploadMedia(file: $file, collection: $collection) {
        id name fileName mimeType size width height status isImage url thumbUrl collectionName
        conversions { name width height url } } }';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('media');
    }

    private ?string $defaultToken = null;

    private function upload(UploadedFile $file, ?string $token = null, ?string $collection = null): TestResponse
    {
        return $this->gqlUpload(self::UPLOAD, ['collection' => $collection], ['variables.file' => $file], $token ?? ($this->defaultToken ??= $this->adminToken()));
    }

    public function test_upload_stores_the_original_then_builds_metadata_and_conversions(): void
    {
        $response = $this->upload(UploadedFile::fake()->image('Foto Tienda.jpg', 1200, 800))->assertJsonMissingPath('errors');
        $media = $response->json('data.uploadMedia');

        $this->assertSame(26, strlen($media['id']));
        $this->assertSame('Foto Tienda', $media['name']);
        $this->assertSame('image/jpeg', $media['mimeType']);
        $this->assertSame('READY', $media['status'], 'sync queue in tests processes the job inline');
        $this->assertSame([1200, 800], [$media['width'], $media['height']]);
        $this->assertSame('library', $media['collectionName']);

        // Stored under our own name, never the client's.
        $this->assertSame($media['id'].'.jpg', $media['fileName']);
        Storage::disk('media')->assertExists($media['id'].'/'.$media['fileName']);

        $conversions = collect($media['conversions'])->keyBy('name');
        $this->assertSame(['thumb', 'medium', 'large'], $conversions->keys()->all());
        $this->assertSame([160, 160], [$conversions['thumb']['width'], $conversions['thumb']['height']], 'cover crop is exact');
        $this->assertSame([640, 427], [$conversions['medium']['width'], $conversions['medium']['height']], 'contain keeps the ratio');
        $this->assertSame([1200, 800], [$conversions['large']['width'], $conversions['large']['height']], 'never upscales');
        foreach (['thumb', 'medium', 'large'] as $name) {
            Storage::disk('media')->assertExists("{$media['id']}/conversions/{$name}.webp");
        }
        $this->assertStringEndsWith('/conversions/thumb.webp', $media['thumbUrl']);

        $info = getimagesizefromstring(Storage::disk('media')->get("{$media['id']}/conversions/thumb.webp"));
        $this->assertSame([160, 160, IMAGETYPE_WEBP], [$info[0], $info[1], $info[2]]);
    }

    public function test_the_mutation_only_queues_the_heavy_work(): void
    {
        Queue::fake();

        $media = $this->upload(UploadedFile::fake()->image('a.png', 300, 300))->json('data.uploadMedia');

        $this->assertSame('PENDING', $media['status']);
        $this->assertSame([], $media['conversions']);
        $this->assertSame($media['id'], MediaModel::first()->id);
        Queue::assertPushedOn('media', CallQueuedListener::class, fn ($job) => $job->class === ProcessUploadedMedia::class);
    }

    public function test_processing_is_idempotent_and_a_final_failure_is_recorded(): void
    {
        Queue::fake();
        $id = $this->upload(UploadedFile::fake()->image('a.png', 100, 100))->json('data.uploadMedia.id');
        $listener = app(ProcessUploadedMedia::class);

        $listener->handle(new MediaUploaded($id));
        $first = MediaModel::find($id)->generated_conversions;
        $listener->handle(new MediaUploaded($id));
        $this->assertSame($first, MediaModel::find($id)->generated_conversions);
        $this->assertSame('ready', MediaModel::find($id)->status);

        $other = $this->upload(UploadedFile::fake()->image('b.png', 100, 100))->json('data.uploadMedia.id');
        $listener->failed(new MediaUploaded($other), new \RuntimeException('disk exploded'));
        $this->assertSame(['failed', 'disk exploded'], [MediaModel::find($other)->status, MediaModel::find($other)->failure_reason]);
    }

    public function test_hostile_file_names_never_reach_the_filesystem(): void
    {
        $media = $this->upload(UploadedFile::fake()->image('../../etc/evil.php.jpg', 50, 50))->json('data.uploadMedia');

        $this->assertSame($media['id'].'.jpg', $media['fileName']);
        $this->assertSame('evil.php', $media['name']);
        $this->assertSame([], array_filter(Storage::disk('media')->allFiles(), fn ($f) => str_contains($f, 'evil')));
    }

    public function test_the_real_file_type_wins_over_the_extension_and_client_mime(): void
    {
        // A script disguised as a JPEG.
        $disguised = UploadedFile::fake()->createWithContent('shell.jpg', "<?php system(\$_GET['c']); ?>");
        $this->assertSame('UNSUPPORTED_MEDIA_TYPE', $this->errorCode($this->upload($disguised)));

        // SVG can carry scripts: refused even though it is an "image".
        $svg = UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
        $this->assertSame('UNSUPPORTED_MEDIA_TYPE', $this->errorCode($this->upload($svg)));

        $this->assertSame(0, MediaModel::count());
        $this->assertSame([], Storage::disk('media')->allFiles());
    }

    public function test_size_and_pixel_limits_are_enforced_before_decoding(): void
    {
        config(['media.max_upload_kb' => 1]);
        $this->assertSame('MEDIA_TOO_LARGE', $this->errorCode($this->upload(UploadedFile::fake()->image('big.jpg', 800, 800))));

        config(['media.max_upload_kb' => 10240, 'media.max_pixels' => 10_000]);
        $this->assertSame('INVALID_IMAGE', $this->errorCode($this->upload(UploadedFile::fake()->image('bomb.png', 200, 200))));
    }

    public function test_pdfs_are_stored_without_conversions(): void
    {
        $pdf = UploadedFile::fake()->createWithContent('catalogo.pdf', "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF");

        $media = $this->upload($pdf)->json('data.uploadMedia');

        $this->assertSame(['application/pdf', 'READY', false, null, []], [$media['mimeType'], $media['status'], $media['isImage'], $media['thumbUrl'], $media['conversions']]);
    }

    public function test_permissions_gate_every_operation(): void
    {
        $file = UploadedFile::fake()->image('a.jpg', 50, 50);

        $this->assertSame('Unauthenticated.', $this->gqlUpload(self::UPLOAD, [], ['variables.file' => $file], null)->json('errors.0.message'));

        $support = $this->adminToken('help@example.com', 'support');
        $this->assertStringContainsString('unauthorized', strtolower($this->upload($file, $support)->json('errors.0.message')), 'support is read-only');
        $this->adminGql('{ media(first: 5) { edges { node { id } } } }', [], $support)->assertJsonMissingPath('errors');

        $customer = $this->makeUser('ana@example.com');
        $storefront = $this->gql('mutation { login(input: {email: "ana@example.com", password: "'.self::PASSWORD.'"}) { token } }')->json('data.login.token');
        $this->assertNotNull($customer);
        $this->assertStringContainsString('unauthorized', strtolower($this->adminGql('{ media(first: 5) { edges { node { id } } } }', [], $storefront)->json('errors.0.message')));
    }

    public function test_rename_keeps_the_stored_file_and_translations_are_per_locale(): void
    {
        $token = $this->adminToken();
        $media = $this->upload(UploadedFile::fake()->image('a.jpg', 80, 80), $token)->json('data.uploadMedia');
        $update = 'mutation($id: ID!, $name: String, $t: [MediaTranslationInput!]) { updateMedia(id: $id, name: $name, translations: $t) {
            name fileName url translations { locale alt title } } }';

        $result = $this->adminGql($update, ['id' => $media['id'], 'name' => 'Logo principal', 't' => [
            ['locale' => 'es-MX', 'alt' => 'Logotipo de la tienda'], ['locale' => 'en-US', 'alt' => 'Store logo', 'title' => 'Logo'],
        ]], $token)->assertJsonMissingPath('errors')->json('data.updateMedia');

        $this->assertSame(['Logo principal', $media['fileName'], $media['url']], [$result['name'], $result['fileName'], $result['url']]);
        $this->assertEqualsCanonicalizing(['Logotipo de la tienda', 'Store logo'], array_column($result['translations'], 'alt'));

        // Updating again replaces, not duplicates.
        $this->adminGql($update, ['id' => $media['id'], 'name' => null, 't' => [['locale' => 'es-MX', 'alt' => 'Nuevo alt']]], $token);
        $this->assertSame(2, DB::table('media_translations')->where('media_id', $media['id'])->count());
        $this->assertSame('Nuevo alt', DB::table('media_translations')->where(['media_id' => $media['id'], 'locale' => 'es-MX'])->value('alt'));

        $this->assertSame('UNKNOWN_LOCALE', $this->errorCode($this->adminGql($update, ['id' => $media['id'], 'name' => null, 't' => [['locale' => 'fr-FR', 'alt' => 'x']]], $token)));
        $this->assertSame('MEDIA_NOT_FOUND', $this->errorCode($this->adminGql($update, ['id' => str_repeat('0', 26), 'name' => 'x', 't' => null], $token)));
    }

    public function test_delete_is_soft_and_listing_supports_search_kind_and_pagination(): void
    {
        $token = $this->adminToken();
        $keep = $this->upload(UploadedFile::fake()->image('banner-verano.jpg', 60, 60), $token)->json('data.uploadMedia.id');
        $gone = $this->upload(UploadedFile::fake()->image('banner-invierno.jpg', 60, 60), $token)->json('data.uploadMedia.id');
        $this->upload(UploadedFile::fake()->createWithContent('lista.pdf', "%PDF-1.4\n%%EOF"), $token);

        $list = 'query($s: String, $k: MediaKind, $after: String) { media(first: 2, search: $s, kind: $k, after: $after) {
            pageInfo { hasNextPage endCursor total } edges { node { id name } } } }';

        $this->assertSame(3, $this->adminGql($list, [], $token)->json('data.media.pageInfo.total'));
        $this->assertSame(['banner-verano', 'banner-invierno'], array_reverse(array_column(array_column($this->adminGql($list, ['s' => 'banner'], $token)->json('data.media.edges'), 'node'), 'name')));
        $this->assertSame(1, $this->adminGql($list, ['k' => 'DOCUMENT'], $token)->json('data.media.pageInfo.total'));
        $this->assertSame(2, $this->adminGql($list, ['k' => 'IMAGE'], $token)->json('data.media.pageInfo.total'));

        $page1 = $this->adminGql($list, [], $token)->json('data.media');
        $this->assertTrue($page1['pageInfo']['hasNextPage']);
        $page2 = $this->adminGql($list, ['after' => $page1['pageInfo']['endCursor']], $token)->json('data.media');
        $this->assertCount(1, $page2['edges']);
        $this->assertCount(3, array_unique(array_merge(array_column(array_column($page1['edges'], 'node'), 'id'), array_column(array_column($page2['edges'], 'node'), 'id'))));

        $this->adminGql('mutation($id: ID!) { deleteMedia(id: $id) }', ['id' => $gone], $token)->assertJsonPath('data.deleteMedia', true);
        $this->assertSoftDeleted('media', ['id' => $gone]);
        $this->assertNotNull(MediaModel::withTrashed()->find($keep));
        $this->assertSame(2, $this->adminGql($list, [], $token)->json('data.media.pageInfo.total'));
        Storage::disk('media')->assertExists("{$gone}/{$gone}.jpg");
    }

    public function test_uploads_are_audited_with_the_actor(): void
    {
        $token = $this->adminToken();
        $id = $this->upload(UploadedFile::fake()->image('a.jpg', 60, 60), $token)->json('data.uploadMedia.id');

        $audit = DB::table('audits')->where(['auditable_id' => $id, 'event' => 'created'])->first();
        $this->assertNotNull($audit);
        $this->assertNotNull($audit->user_id);
        $this->assertSame('PHPUnit', $audit->user_agent);
        $this->assertCount(1, DB::table('audits')->where('auditable_id', $id)->get(), 'pipeline status changes do not flood the audit trail');
    }
}
