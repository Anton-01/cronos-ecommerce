<?php

declare(strict_types=1);

namespace Cronos\Inventory\Domain\ValueObjects;

use Cronos\Inventory\Domain\Exceptions\InvalidMovement;

/** Every process that can speak. Who hears about each one is chosen by the administrator (people of the system only). */
final class AlertProcess
{
    public const STOCK_LOW = 'stock_low';

    public const STOCK_CRITICAL = 'stock_critical';

    public const STOCK_OUT = 'stock_out';

    public const STOCK_RECOVERED = 'stock_recovered';

    public const ADJUSTMENT_REQUESTED = 'adjustment_requested';

    public const ADJUSTMENT_DECIDED = 'adjustment_decided';

    public const COUNT_REVIEW = 'count_review';

    public const PICK_PENDING = 'pick_pending';

    public const DAILY_DIGEST = 'daily_digest';

    public const INTEGRITY_FAILURE = 'integrity_failure';

    /** @var array<string, array{label: string, description: string, severity: string}> */
    public const ALL = [
        self::STOCK_LOW => ['label' => 'Existencias bajas', 'description' => 'Un producto bajó de su mínimo.', 'severity' => 'warning'],
        self::STOCK_CRITICAL => ['label' => 'Existencias críticas', 'description' => 'Un producto bajó de su nivel crítico.', 'severity' => 'critical'],
        self::STOCK_OUT => ['label' => 'Producto agotado', 'description' => 'Un producto se quedó sin existencias.', 'severity' => 'critical'],
        self::STOCK_RECOVERED => ['label' => 'Existencias recuperadas', 'description' => 'Un producto volvió a un nivel normal.', 'severity' => 'info'],
        self::ADJUSTMENT_REQUESTED => ['label' => 'Ajuste por aprobar', 'description' => 'Alguien pidió un ajuste que supera el límite sin aprobación.', 'severity' => 'warning'],
        self::ADJUSTMENT_DECIDED => ['label' => 'Ajuste resuelto', 'description' => 'Un ajuste fue aprobado o rechazado.', 'severity' => 'info'],
        self::COUNT_REVIEW => ['label' => 'Conteo por revisar', 'description' => 'Dos conteos no coinciden y alguien debe decidir.', 'severity' => 'warning'],
        self::PICK_PENDING => ['label' => 'Pedido por surtir', 'description' => 'Se pagó un pedido y hay piezas por sacar del anaquel.', 'severity' => 'info'],
        self::DAILY_DIGEST => ['label' => 'Resumen diario', 'description' => 'Estado del almacén al iniciar el día.', 'severity' => 'info'],
        self::INTEGRITY_FAILURE => ['label' => 'Falla de integridad', 'description' => 'La conciliación nocturna encontró diferencias en los libros.', 'severity' => 'critical'],
    ];

    public static function assertKnown(string $code): void
    {
        isset(self::ALL[$code]) || throw new InvalidMovement("Unknown alert process [{$code}].", 'UNKNOWN_ALERT_PROCESS');
    }
}
