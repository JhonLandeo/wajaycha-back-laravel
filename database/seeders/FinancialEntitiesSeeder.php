<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class FinancialEntitiesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $entities = [
            // Bancos
            ['name' => 'Banco de Crédito del Perú', 'type' => 'Banco', 'code' => 'BCP', 'address' => 'Av. La Molina 2, Lima', 'website' => 'https://www.viabcp.com'],
            ['name' => 'Interbank', 'type' => 'Banco', 'code' => 'IBK', 'address' => 'Av. Carlos Villarán 140, Lima', 'website' => 'https://interbank.pe'],
            ['name' => 'Banco BBVA Perú', 'type' => 'Banco', 'code' => 'BBVA', 'address' => 'Av. República de Panamá 3055, Lima', 'website' => 'https://www.bbva.pe'],
            ['name' => 'Banco Scotiabank', 'type' => 'Banco', 'code' => 'SCOTIA', 'address' => 'Av. Canaval y Moreyra 522, Lima', 'website' => 'https://www.scotiabank.com.pe'],
            ['name' => 'Banco Pichincha', 'type' => 'Banco', 'code' => 'PICH', 'address' => 'Av. República de Panamá 3071, Lima', 'website' => 'https://www.bancopichincha.pe'],
            ['name' => 'Banco GNB', 'type' => 'Banco', 'code' => 'GNB', 'address' => 'Av. República de Panamá 3621, Lima', 'website' => 'https://www.bancognb.com.pe'],
            ['name' => 'Banco Falabella', 'type' => 'Banco', 'code' => 'FAL', 'address' => 'Av. Manuel Olguín 327, Lima', 'website' => 'https://www.bancofalabella.pe'],
            ['name' => 'Banco Ripley', 'type' => 'Banco', 'code' => 'RIPLEY', 'address' => 'Av. República de Panamá 3042, Lima', 'website' => 'https://www.ripley.com.pe/banco-ripley'],
            ['name' => 'Banco Azteca', 'type' => 'Banco', 'code' => 'AZTECA', 'address' => 'Av. Paseo de la República 136, Lima', 'website' => 'https://www.bancoazteca.com.pe'],
            ['name' => 'Citibank Perú', 'type' => 'Banco', 'code' => 'CITI', 'address' => 'Av. Canaval y Moreyra 380, Lima', 'website' => 'https://www.citibank.com.pe'],

            // Cajas Municipales
            ['name' => 'Caja Arequipa', 'type' => 'Caja Municipal', 'code' => 'AREQUIPA', 'address' => 'Calle Mercaderes 110, Arequipa', 'website' => 'https://www.cajaarequipa.pe'],
            ['name' => 'Caja Trujillo', 'type' => 'Caja Municipal', 'code' => 'TRUJILLO', 'address' => 'Jr. Independencia 376, Trujillo', 'website' => 'https://www.cajatrujillo.com.pe'],
            ['name' => 'Caja Piura', 'type' => 'Caja Municipal', 'code' => 'PIURA', 'address' => 'Av. Grau 120, Piura', 'website' => 'https://www.cajapiura.pe'],
            ['name' => 'Caja Cusco', 'type' => 'Caja Municipal', 'code' => 'CUSCO', 'address' => 'Av. La Cultura 303, Cusco', 'website' => 'https://www.cajacusco.com.pe'],
            ['name' => 'Caja Huancayo', 'type' => 'Caja Municipal', 'code' => 'HUANCAYO', 'address' => 'Jr. Puno 700, Huancayo', 'website' => 'https://www.cajahuancayo.com.pe'],

            // Cooperativas
            ['name' => 'Cooperativa Abaco', 'type' => 'Cooperativa', 'code' => 'ABACO', 'address' => 'Av. Camino Real 456, Lima', 'website' => 'https://www.abaco.pe'],
            ['name' => 'Cooperativa Pacífico', 'type' => 'Cooperativa', 'code' => 'PACIFICO', 'address' => 'Av. Javier Prado Este 2345, Lima', 'website' => 'https://www.cpacifico.pe'],
            ['name' => 'Cooperativa Santa María Magdalena', 'type' => 'Cooperativa', 'code' => 'SMM', 'address' => 'Jr. Amazonas 1245, Lima', 'website' => 'https://www.smm.coop'],

            // Otros
            ['name' => 'Financiera Crediscotia', 'type' => 'Financiera', 'code' => 'CREDISCOTIA', 'address' => 'Av. República de Panamá 3545, Lima', 'website' => 'https://www.crediscotia.com.pe'],
            ['name' => 'Mi Banco', 'type' => 'Banco', 'code' => 'MIBANCO', 'address' => 'Av. Paseo de la República 4675, Lima', 'website' => 'https://www.mibanco.com.pe'],
        ];

        DB::table('financial_entities')->insert($entities);
    }
}
