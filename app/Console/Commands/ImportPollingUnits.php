<?php

namespace App\Console\Commands;

use App\Services\PollingUnitImporter;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('pu:import {file? : CSV with code,name,ward,lga,registered_voters (default: the bundled Ebonyi register)}')]
#[Description('Import or update the polling unit register')]
class ImportPollingUnits extends Command
{
    public function handle(PollingUnitImporter $importer): int
    {
        $result = $importer->import($this->argument('file') ?? PollingUnitImporter::bundledPath());

        $this->info("{$result['created']} polling units added, {$result['updated']} updated.");
        foreach ($result['errors'] as $error) {
            $this->warn($error);
        }

        return $result['errors'] === [] ? self::SUCCESS : self::FAILURE;
    }
}
