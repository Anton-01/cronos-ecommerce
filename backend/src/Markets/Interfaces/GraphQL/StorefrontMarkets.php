<?php

declare(strict_types=1);

namespace Cronos\Markets\Interfaces\GraphQL;

use Cronos\Markets\Application\MarketResolver;
use Cronos\Markets\Domain\Ports\MarketProfileRepository;
use Cronos\Pricing\Interfaces\GraphQL\PublicMarketsQuery;
use Cronos\Shared\Interfaces\GraphQL\ResolvesClientContext;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

final class StorefrontMarkets
{
    use ResolvesClientContext;

    public function __construct(
        private readonly MarketProfileRepository $profiles,
        private readonly MarketResolver $resolver,
        private readonly PublicMarketsQuery $markets,
    ) {}

    /** @param array<string, mixed> $market */
    public function profile(array $market): array
    {
        $p = $this->profiles->profile($market['code']);

        return [
            'legalName' => $p['legal_name'] ?? null, 'tradeName' => $p['trade_name'] ?? null, 'taxIdLabel' => $p['tax_id_label'] ?? 'Tax ID', 'taxId' => $p['tax_id'] ?? null,
            'fiscalAddress' => self::address($p['fiscal_address'] ?? []), 'contactEmail' => $p['contact_email'] ?? null, 'contactPhone' => $p['contact_phone'] ?? null,
            'supportHours' => $p['support_hours'] ?? null, 'countries' => $p['countries'] ?? [],
        ];
    }

    /** @param array<string, mixed> $args */
    public function context(mixed $root, array $args, GraphQLContext $context): array
    {
        $country = $this->clientContext($context)->country;
        $all = collect(($this->markets)())->keyBy('code');
        $detectedCode = $this->resolver->forCountry($country);
        $selectedCode = isset($args['selected']) ? strtoupper($args['selected']) : $detectedCode;
        $mismatch = $selectedCode === null ? null : $this->resolver->mismatch($selectedCode, $country);

        $prompt = null;
        if ($mismatch !== null) {
            $locale = in_array($mismatch['locale'], config('app.supported_locales'), true) ? $mismatch['locale'] : config('app.locale_default');
            $with = ['selected' => $mismatch['selected']['name'], 'detected' => $mismatch['detected']['name']];
            $prompt = [
                'title' => __('market.prompt.title', $with, $locale), 'body' => __('market.prompt.body', $with, $locale),
                'switchLabel' => __('market.prompt.switch', $with, $locale), 'stayLabel' => __('market.prompt.stay', $with, $locale),
            ];
        }

        return [
            'detectedCountry' => $country, 'detectedMarket' => $detectedCode === null ? null : $all->get($detectedCode),
            'selectedMarket' => $selectedCode === null ? null : $all->get($selectedCode), 'outside' => $mismatch !== null, 'prompt' => $prompt,
        ];
    }

    /** @param array<string, mixed> $a @return array<string, mixed> */
    public static function address(array $a): array
    {
        return ['street' => $a['street'] ?? null, 'exteriorNo' => $a['exterior_no'] ?? null, 'interiorNo' => $a['interior_no'] ?? null, 'neighborhood' => $a['neighborhood'] ?? null,
            'city' => $a['city'] ?? null, 'state' => $a['state'] ?? null, 'zip' => $a['zip'] ?? null, 'countryCode' => $a['country_code'] ?? null];
    }
}
