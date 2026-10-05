<?php

namespace App\Modules\Imports;

use RuntimeException;

/**
 * The uploaded file itself is unacceptable. Retrying cannot help, and the
 * message is safe to show to an editor.
 */
class ImportRejected extends RuntimeException {}
