<?php

namespace Tests\Feature\Media;

use Cronos\Media\Infrastructure\Persistence\Eloquent\MediaModel;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\GraphQLTestCase;

class AvatarTest extends GraphQLTestCase
{
    private const MUTATION = 'mutation($file: Upload!) { updateMyAvatar(file: $file) { id avatarUrl } }';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('media');
    }

    private function customerToken(): string
    {
        $this->makeUser('ana@example.com');

        return $this->gql('mutation { login(input: {email: "ana@example.com", password: "'.self::PASSWORD.'"}) { token } }')->json('data.login.token');
    }

    public function test_a_customer_sets_an_avatar_and_a_new_one_replaces_it(): void
    {
        $token = $this->customerToken();

        $first = $this->gqlUpload(self::MUTATION, [], ['variables.file' => UploadedFile::fake()->image('me.png', 400, 400)], $token, '/graphql')
            ->assertJsonMissingPath('errors')->json('data.updateMyAvatar');

        $this->assertStringEndsWith('/conversions/thumb.webp', $first['avatarUrl']);
        $firstMedia = MediaModel::where('collection_name', 'avatar')->firstOrFail();
        $this->assertSame($firstMedia->id, \DB::table('user_profiles')->value('avatar_media_id'));

        $this->gqlUpload(self::MUTATION, [], ['variables.file' => UploadedFile::fake()->image('me2.jpg', 300, 300)], $token, '/graphql')->assertJsonMissingPath('errors');

        $this->assertSoftDeleted('media', ['id' => $firstMedia->id]);
        $this->assertSame(1, MediaModel::where('collection_name', 'avatar')->count(), 'single-file collection');
        $this->assertNotSame($firstMedia->id, \DB::table('user_profiles')->value('avatar_media_id'));
        $this->gql('{ me { avatarUrl } }', [], $token)->assertJsonPath('data.me.avatarUrl', fn ($u) => str_contains($u, 'thumb.webp'));
    }

    public function test_only_small_raster_images_are_accepted_as_avatar(): void
    {
        $token = $this->customerToken();
        $try = fn (UploadedFile $f) => $this->gqlUpload(self::MUTATION, [], ['variables.file' => $f], $token, '/graphql');

        $this->assertSame('UNSUPPORTED_MEDIA_TYPE', $this->errorCode($try(UploadedFile::fake()->createWithContent('cv.pdf', "%PDF-1.4\n%%EOF"))));
        $this->assertSame('UNSUPPORTED_MEDIA_TYPE', $this->errorCode($try(UploadedFile::fake()->image('anim.gif', 50, 50))));
        $this->assertSame('MEDIA_TOO_LARGE', $this->errorCode($try(UploadedFile::fake()->create('huge.jpg', 6000, 'image/jpeg')->mimeType('image/jpeg'))));
        $this->assertSame(0, MediaModel::count());
    }

    public function test_anonymous_users_cannot_upload_avatars(): void
    {
        $this->gqlUpload(self::MUTATION, [], ['variables.file' => UploadedFile::fake()->image('me.png', 50, 50)], null, '/graphql')
            ->assertJsonPath('errors.0.message', 'Unauthenticated.');
    }
}
