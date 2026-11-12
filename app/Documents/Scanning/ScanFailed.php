<?php

namespace App\Documents\Scanning;

use RuntimeException;

/** The scanner could not give a verdict; the job retries. */
class ScanFailed extends RuntimeException {}
