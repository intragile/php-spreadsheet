<?php

declare(strict_types=1);

namespace IntrAgile\Spreadsheet\Exception;

/**
 * The file is damaged, encrypted or structurally invalid for its format; retrying will not help.
 */
class CorruptFile extends SpreadsheetException {}
