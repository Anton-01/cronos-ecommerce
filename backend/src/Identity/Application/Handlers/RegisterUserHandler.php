<?php

declare(strict_types=1);

namespace Cronos\Identity\Application\Handlers;

use Cronos\Identity\Application\AuthResult;
use Cronos\Identity\Application\Commands\RegisterUser;
use Cronos\Identity\Domain\Events\UserRegistered;
use Cronos\Identity\Domain\Events\VerificationEmailRequested;
use Cronos\Identity\Domain\Exceptions\EmailAlreadyRegistered;
use Cronos\Identity\Domain\Ports\PasswordHasher;
use Cronos\Identity\Domain\Ports\SecurityEventRecorder;
use Cronos\Identity\Domain\Ports\TokenIssuer;
use Cronos\Identity\Domain\Ports\UserRepository;
use Cronos\Shared\Domain\DomainEventDispatcher;

final class RegisterUserHandler
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly PasswordHasher $hasher,
        private readonly TokenIssuer $tokens,
        private readonly DomainEventDispatcher $events,
        private readonly SecurityEventRecorder $security,
    ) {}

    public function __invoke(RegisterUser $command): AuthResult
    {
        $email = mb_strtolower(trim($command->email));

        if ($this->users->findByEmail($email) !== null) {
            throw new EmailAlreadyRegistered;
        }

        $user = $this->users->create($email, $this->hasher->hash($command->password), [
            'first_name' => $command->firstName,
            'last_name' => $command->lastName,
            'locale' => $command->locale,
        ]);
        $this->users->assignDefaultRole($user->id);

        $this->security->record('user.registered', $user->id, $email, $command->client);

        // Heavy work (verification email) happens in a queued listener.
        $this->events->dispatch(new UserRegistered($user->id, $email));
        $this->events->dispatch(new VerificationEmailRequested($user->id, $email));

        return new AuthResult($user, $this->tokens->issue($user->id, 'registration', ['storefront']));
    }
}
