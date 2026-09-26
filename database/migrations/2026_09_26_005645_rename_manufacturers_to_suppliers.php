<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Polymorphic columns that store the model class.
     *
     * @var array<string, string>
     */
    private const MORPH_COLUMNS = [
        'notes' => 'notable_type',
        'attachments' => 'attachable_type',
        'activities' => 'subject_type',
    ];

    /**
     * "Hersteller" became "Lieferanten": groups order from producers, farm
     * shops and wholesalers alike. Notes, documents and activities keep
     * pointing to their entries.
     */
    public function up(): void
    {
        Schema::rename('manufacturers', 'suppliers');

        Schema::table('suppliers', function (Blueprint $table) {
            $table->renameIndex('manufacturers_visibility_group_id_index', 'suppliers_visibility_group_id_index');
            $table->renameIndex('manufacturers_group_id_slug_unique', 'suppliers_group_id_slug_unique');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->renameColumn('manufacturer_id', 'supplier_id');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->renameIndex('products_manufacturer_id_slug_unique', 'products_supplier_id_slug_unique');
        });

        $this->renameMorphType('App\Models\Manufacturer', 'App\Models\Supplier');
    }

    public function down(): void
    {
        $this->renameMorphType('App\Models\Supplier', 'App\Models\Manufacturer');

        Schema::table('products', function (Blueprint $table) {
            $table->renameIndex('products_supplier_id_slug_unique', 'products_manufacturer_id_slug_unique');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->renameColumn('supplier_id', 'manufacturer_id');
        });

        Schema::table('suppliers', function (Blueprint $table) {
            $table->renameIndex('suppliers_visibility_group_id_index', 'manufacturers_visibility_group_id_index');
            $table->renameIndex('suppliers_group_id_slug_unique', 'manufacturers_group_id_slug_unique');
        });

        Schema::rename('suppliers', 'manufacturers');
    }

    private function renameMorphType(string $from, string $to): void
    {
        foreach (self::MORPH_COLUMNS as $table => $column) {
            DB::table($table)->where($column, $from)->update([$column => $to]);
        }
    }
};
