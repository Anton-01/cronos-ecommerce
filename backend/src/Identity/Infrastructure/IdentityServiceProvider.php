<?php

declare(strict_types=1);

namespace Cronos\Identity\Infrastructure;

use Cronos\Identity\Application\Listeners\SendPasswordResetEmail;
use Cronos\Identity\Application\Listeners\SendVerificationEmail;
use Cronos\Identity\Domain\Events\PasswordResetRequested;
use Cronos\Identity\Domain\Events\VerificationEmailRequested;
use Cronos\Identity\Domain\Ports\AvatarStore;
use Cronos\Identity\Domain\Ports\EmailVerificationLinks;
use Cronos\Identity\Domain\Ports\IdentityNotifier;
use Cronos\Identity\Domain\Ports\PasswordHasher;
use Cronos\Identity\Domain\Ports\PasswordResetTokenStore;
use Cronos\Identity\Domain\Ports\SecurityEventRecorder;
use Cronos\Identity\Domain\Ports\SocialIdentityProvider;
use Cronos\Identity\Domain\Ports\TokenIssuer;
use Cronos\Identity\Domain\Ports\UserRepository;
use Cronos\Identity\Infrastructure\Adapters\DatabasePasswordResetTokenStore;
use Cronos\Identity\Infrastructure\Adapters\DatabaseSecurityEventRecorder;
use Cronos\Identity\Infrastructure\Adapters\HmacEmailVerificationLinks;
use Cronos\Identity\Infrastructure\Adapters\LaravelPasswordHasher;
use Cronos\Identity\Infrastructure\Adapters\MailIdentityNotifier;
use Cronos\Identity\Infrastructure\Adapters\MediaLibraryAvatarStore;
use Cronos\Identity\Infrastructure\Adapters\SanctumTokenIssuer;
use Cronos\Identity\Infrastructure\Adapters\SocialiteGoogleProvider;
use Cronos\Identity\Infrastructure\Persistence\EloquentUserRepository;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/** Wires the Identity ports to their adapters (the only place that knows both sides). */
final class IdentityServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    public array $bindings = [
        UserRepository::class => EloquentUserRepository::class,
        PasswordHasher::class => LaravelPasswordHasher::class,
        TokenIssuer::class => SanctumTokenIssuer::class,
        PasswordResetTokenStore::class => DatabasePasswordResetTokenStore::class,
        EmailVerificationLinks::class => HmacEmailVerificationLinks::class,
        SocialIdentityProvider::class => SocialiteGoogleProvider::class,
        SecurityEventRecorder::class => DatabaseSecurityEventRecorder::class,
        IdentityNotifier::class => MailIdentityNotifier::class,
        AvatarStore::class => MediaLibraryAvatarStore::class,
    ];

    public function boot(): void
    {
        Event::listen(VerificationEmailRequested::class, SendVerificationEmail::class);
        Event::listen(PasswordResetRequested::class, SendPasswordResetEmail::class);
    }
}
