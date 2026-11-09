<?php

declare(strict_types=1);

namespace IntrAgile\Spreadsheet\Exception;

/**
 * The file exceeded a safety limit: too many entries, too many decompressed bytes or an unsafe name.
 */
class LimitExceeded extends SpreadsheetException {}
