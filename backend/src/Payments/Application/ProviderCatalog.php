<?php

declare(strict_types=1);

namespace Cronos\Payments\Application;

use Cronos\Payments\Domain\ValueObjects\Provider;

/** What each provider needs to be configured: the credential fields and how a valid one looks per mode. */
final class ProviderCatalog
{
    /** @var array<string, array<string, array{label: string, secret: bool, pattern?: array<string, string>}>> */
    public const FIELDS = [
        Provider::STRIPE => [
            'secret_key' => ['label' => 'Secret key', 'secret' => true, 'pattern' => ['sandbox' => '/^(sk|rk)_test_[A-Za-z0-9]+$/', 'live' => '/^(sk|rk)_live_[A-Za-z0-9]+$/']],
            'publishable_key' => ['label' => 'Publishable key', 'secret' => false, 'pattern' => ['sandbox' => '/^pk_test_[A-Za-z0-9]+$/', 'live' => '/^pk_live_[A-Za-z0-9]+$/']],
            'webhook_secret' => ['label' => 'Webhook signing secret', 'secret' => true, 'pattern' => ['sandbox' => '/^whsec_\S+$/', 'live' => '/^whsec_\S+$/']],
        ],
        Provider::MERCADOPAGO => [
            'access_token' => ['label' => 'Access token', 'secret' => true],
            'public_key' => ['label' => 'Public key', 'secret' => false],
            'webhook_secret' => ['label' => 'Webhook secret', 'secret' => true],
        ],
        Provider::PAYPAL => [
            'client_id' => ['label' => 'Client ID', 'secret' => false],
            'client_secret' => ['label' => 'Client secret', 'secret' => true],
            'webhook_id' => ['label' => 'Webhook ID', 'secret' => false],
        ],
    ];

    public const NAMES = [Provider::STRIPE => 'Stripe', Provider::MERCADOPAGO => 'Mercado Pago', Provider::PAYPAL => 'PayPal'];

    /** @return list<string> */
    public static function requiredFields(string $provider): array
    {
        return array_keys(self::FIELDS[$provider]);
    }

    /** Shows enough to recognise a stored value without exposing it: "sk_test_…a1b2". */
    public static function mask(string $value, bool $secret): string
    {
        if (! $secret) {
            return $value;
        }

        return mb_strlen($value) <= 8 ? '••••' : mb_substr($value, 0, min(8, (int) (mb_strlen($value) / 3))).'…'.mb_substr($value, -4);
    }
}
