<?php

declare(strict_types=1);

namespace Cronos\Catalog\Interfaces\GraphQL;

use Cronos\Catalog\Application\ProductImporter;
use Cronos\Catalog\Domain\Exceptions\InvalidProduct;
use Cronos\Inventory\Application\Actor;
use Cronos\Inventory\Application\WarehouseOperations;
use Cronos\Inventory\Domain\Ports\LocationRepository;
use Cronos\Inventory\Domain\Ports\WarehouseRepository;
use Cronos\Shared\Interfaces\GraphQL\ResolvesClientContext;
use Illuminate\Http\UploadedFile;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/** The spreadsheet import: the same quick registration as the form, many products at once, with the initial stock received into its shelves. */
final class ProductImportApi
{
    use ResolvesClientContext;

    private const MAX_BYTES = 5_000_000;

    public function __construct(
        private readonly ProductImporter $importer,
        private readonly WarehouseOperations $operations,
        private readonly LocationRepository $locations,
        private readonly WarehouseRepository $warehouses,
    ) {}

    public function template(): array
    {
        return $this->importer->template();
    }

    /** @param array<string, mixed> $a */
    public function import(mixed $root, array $a, GraphQLContext $context): array
    {
        /** @var UploadedFile $file */
        $file = $a['file'];
        $extension = strtolower($file->getClientOriginalExtension());
        in_array($extension, ['xlsx', 'csv'], true) || throw new InvalidProduct('Upload a .xlsx or .csv file.');
        $file->getSize() <= self::MAX_BYTES || throw new InvalidProduct('The file is too big (5 MB at most).');

        $warehouse = isset($a['warehouseId']) ? $this->warehouses->find((int) $a['warehouseId']) : collect($this->warehouses->all())->firstWhere('is_default', true);
        $warehouse !== null || throw new InvalidProduct('There is no warehouse to receive the initial stock.');
        $user = $context->user();
        $actor = new Actor((string) $user?->getAuthIdentifier(), $this->clientContext($context));
        $batch = substr(hash('sha256', $file->getRealPath().microtime()), 0, 12);

        $report = $this->importer->run($file->getRealPath(), $extension, (bool) $a['apply'], $actor->userId, function (array $product, array $lines) use ($warehouse, $actor, $batch, $user): void {
            $user?->can('warehouse.operate') || throw new InvalidProduct('Only the warehouse team can load initial stock; remove the Ubicación / Cantidad columns or ask for access.');
            foreach ($lines as $line) {
                $quantity = $line['quantity'];
                is_numeric($quantity) && (float) $quantity > 0 || throw new InvalidProduct("row {$line['row']}: the initial quantity must be a number above zero.");
                $locationId = null;
                if ($line['location'] !== '') {
                    $found = $this->locations->findByCode($warehouse['id'], strtoupper(trim($line['location'])));
                    $found !== null || throw new InvalidProduct("row {$line['row']}: the location [{$line['location']}] does not exist in {$warehouse['name']}.");
                    $locationId = $found['id'];
                }
                $this->operations->receive($line['product_id'], $warehouse['id'], $quantity, $actor, $locationId, null, "import:{$batch}:{$line['row']}", 'Carga inicial por importación');
            }
        });

        return [
            'applied' => $report['applied'], 'rows' => $report['rows'], 'products' => $report['products'], 'variants' => $report['variants'], 'errors' => $report['errors'],
            'created' => array_map(fn (array $c) => ['group' => $c['group'], 'productId' => $c['product_id'], 'skus' => $c['skus']], $report['created']),
        ];
    }
}
