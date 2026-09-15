<?php

namespace App\Console\Commands;

use App\Services\GoldenRadioReceiptImporter;
use Illuminate\Console\Command;

class ImportGoldenRadioReceipts extends Command
{
    protected $signature = 'receipts:import-golden-radio {file : Percorso CSV riepilogativo}';

    protected $description = 'Importa riepiloghi mensili Golden Radio nel registro corrispettivi';

    public function handle(GoldenRadioReceiptImporter $importer): int
    {
        $path = (string) $this->argument('file');
        if (! is_file($path)) {
            $this->error("File non trovato: {$path}");

            return self::FAILURE;
        }

        try {
            $result = $importer->import($path);
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Importati: {$result['created']}; duplicati saltati: {$result['skipped']}.");

        return self::SUCCESS;
    }
}
