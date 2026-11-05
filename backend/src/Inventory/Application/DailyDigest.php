<?php

declare(strict_types=1);

namespace Cronos\Inventory\Application;

use Cronos\Inventory\Domain\Ports\AlertStore;
use Cronos\Inventory\Domain\ValueObjects\AlertProcess;

/** One message a day with how the warehouse stands. */
final class DailyDigest
{
    public function __construct(private readonly AlertStore $store, private readonly AlertRaiser $alerts, private readonly PickLists $picks) {}

    /** @return ?string alert id, null when it was already sent today */
    public function send(?string $day = null): ?string
    {
        $day ??= date('Y-m-d');
        $f = $this->store->digestFigures();
        $toPick = count($this->picks->pending(null, 1000));

        $detail = "Productos bajos: {$f['low']}. Críticos: {$f['critical']}. Agotados: {$f['out']}. Ajustes por aprobar: {$f['pending_requests']}. Conteos por revisar: {$f['counts_in_review']}. Líneas de pedido por surtir: {$toPick}.";

        return $this->alerts->raise(AlertProcess::DAILY_DIGEST, "Resumen del almacén · {$day}", $detail, "digest:{$day}", data: $f + ['to_pick' => $toPick]);
    }
}
