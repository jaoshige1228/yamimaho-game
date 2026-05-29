<?php

namespace App\Services\MasterData;

use RuntimeException;

class CsvMasterReader
{
    /**
     * @return list<array<string, string>>
     */
    public static function read(string $filename): array
    {
        $path = database_path('csv/'.$filename);
        if (! is_readable($path)) {
            throw new RuntimeException("CSV not found: {$path}");
        }

        $handle = fopen($path, 'r');
        if ($handle === false) {
            throw new RuntimeException("Cannot open CSV: {$path}");
        }

        $header = fgetcsv($handle);
        if ($header === false) {
            fclose($handle);

            return [];
        }

        $rows = [];
        while (($data = fgetcsv($handle)) !== false) {
            if ($data === [null] || $data === []) {
                continue;
            }
            /** @var array<string, string> $row */
            $row = array_combine($header, $data);
            $rows[] = $row;
        }

        fclose($handle);

        return $rows;
    }
}
