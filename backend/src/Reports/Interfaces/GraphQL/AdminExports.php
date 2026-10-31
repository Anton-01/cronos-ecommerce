<?php

declare(strict_types=1);

namespace Cronos\Reports\Interfaces\GraphQL;

use Cronos\Reports\Application\ExportService;
use Cronos\Reports\Domain\Ports\ExportRepository;
use Cronos\Shared\Interfaces\GraphQL\ResolvesClientContext;
use Illuminate\Support\Facades\URL;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

final class AdminExports
{
    use ResolvesClientContext;

    public function __construct(private readonly ExportService $exports, private readonly ExportRepository $repository) {}

    /** @param array<string, mixed> $args */
    public function list(mixed $root, array $args, GraphQLContext $context): array
    {
        return array_map($this->present(...), $this->repository->forUser((string) $context->user()->getAuthIdentifier(), max(1, min((int) ($args['first'] ?? 20), 50))));
    }

    /** @param array<string, mixed> $args */
    public function request(mixed $root, array $args, GraphQLContext $context): array
    {
        $client = $this->clientContext($context);
        $job = $this->exports->request((string) $context->user()->getAuthIdentifier(), $args['type'], (array) ($args['filters'] ?? []), $client->timezone ?? 'UTC', $client);

        return $this->present($job);
    }

    /** @param array<string, mixed> $export */
    public function downloadUrl(array $export): ?string
    {
        return $export['status'] === 'ready' ? URL::temporarySignedRoute('exports.download', now()->addMinutes(15), ['export' => $export['id']]) : null;
    }

    /** @param array<string, mixed> $j @return array<string, mixed> */
    private function present(array $j): array
    {
        return [
            'id' => $j['id'], 'type' => $j['type'], 'status' => $j['status'], 'filters' => $j['filters'], 'rowCount' => $j['row_count'], 'truncated' => $j['truncated'], 'fileSize' => $j['file_size'],
            'error' => $j['error'], 'downloadCount' => $j['download_count'], 'createdAt' => $j['created_at'], 'finishedAt' => $j['finished_at'], 'expiresAt' => $j['expires_at'],
        ];
    }
}
