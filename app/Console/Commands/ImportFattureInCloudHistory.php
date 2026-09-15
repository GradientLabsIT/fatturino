<?php

namespace App\Console\Commands;

use App\Services\FattureInCloudHistoryImporter;
use Illuminate\Console\Command;

class ImportFattureInCloudHistory extends Command
{
    protected $signature = 'fic:import-history
        {--company= : ID azienda Fatture in Cloud}
        {--year= : Anno da importare}
        {--skip-files : Non scaricare PDF e XML}
        {--dry-run : Leggi API e mostra conteggio senza scrivere}';

    protected $description = 'Importa storico Fatture in Cloud via API senza inviare documenti a SDI';

    public function handle(FattureInCloudHistoryImporter $importer): int
    {
        $token = (string) env('FIC_IMPORT_TOKEN', '');
        $companyId = (int) $this->option('company');
        $year = (int) $this->option('year');

        if ($token === '' || $companyId <= 0 || $year <= 0) {
            $this->error('Servono FIC_IMPORT_TOKEN, --company e --year.');

            return self::FAILURE;
        }

        try {
            $stats = $importer->importYear(
                $token,
                $companyId,
                $year,
                ! (bool) $this->option('skip-files'),
                (bool) $this->option('dry-run'),
            );
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info(implode('; ', [
            "trovati: {$stats['found']}",
            "creati: {$stats['created']}",
            "saltati: {$stats['skipped']}",
            "contatti: {$stats['contacts_created']}",
            "PDF: {$stats['pdf_downloaded']}",
            "XML: {$stats['xml_downloaded']}",
            "errori file: {$stats['file_errors']}",
        ]));

        return self::SUCCESS;
    }
}
