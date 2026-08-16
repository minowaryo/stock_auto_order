<?php

namespace App\Exceptions\Import;

use RuntimeException;

/**
 * Thrown when a CSV file cannot be parsed at all (unrecognized structure,
 * invalid encoding, missing expected header row). This is distinct from a
 * single unparsable data row, which is skipped and counted as an error
 * instead of failing the whole import (UC-001業務ルール).
 */
class CsvStructureException extends RuntimeException {}
