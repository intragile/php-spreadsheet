<?php

declare(strict_types=1);

namespace IntrAgile\Spreadsheet;

/**
 * Spreadsheet file formats this library can read and write.
 */
enum Format: string
{
    case Xlsx = 'xlsx';
    case Ods = 'ods';
    case Csv = 'csv';
}
