<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PaymentServicesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $services = [
            [
                'name' => 'Yape',
                'operator_id' => DB::table('financial_entities')->where('name', 'Banco de Crédito del Perú')->value('id'),
                'type' => 'Billetera Digital',
                'website' => 'https://www.yape.com.pe',
            ],
            [
                'name' => 'Plin',
                'operator_id' => null, // Operado por múltiples bancos
                'type' => 'Servicio de Pago Interbancario',
                'website' => 'https://www.plin.pe',
            ],
        ];

        DB::table('payment_services')->insert($services);
    }
}
