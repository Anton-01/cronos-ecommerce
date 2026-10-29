<?php

namespace Database\Seeders;

use Cronos\Fraud\Application\FraudRuleManager;
use Cronos\Fraud\Domain\Ports\FraudRepository;
use Illuminate\Database\Seeder;

/** Conservative starting rules; the back-office tunes them. Only created when the table is empty. */
class FraudRulesSeeder extends Seeder
{
    public function run(FraudRepository $repository, FraudRuleManager $rules): void
    {
        if ($repository->rules() !== []) {
            return;
        }

        foreach ([
            ['name' => 'Muchos intentos desde una IP', 'type' => 'velocity_ip', 'params' => ['max' => 5, 'window_minutes' => 10], 'score' => 40],
            ['name' => 'Muchos intentos con un correo', 'type' => 'velocity_email', 'params' => ['max' => 3, 'window_minutes' => 10], 'score' => 30],
            ['name' => 'Pagos rechazados repetidos', 'type' => 'failed_payments', 'params' => ['max' => 3, 'window_minutes' => 30], 'score' => 50],
            ['name' => 'Monto alto', 'type' => 'amount_over', 'params' => ['amounts' => ['MXN' => '15000', 'USD' => '800']], 'score' => 30],
            ['name' => 'País de facturación distinto al de envío', 'type' => 'country_mismatch', 'params' => [], 'score' => 20],
        ] as $rule) {
            $rules->create($rule);
        }
    }
}
