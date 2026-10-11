<?php

namespace App\Support;

use RuntimeException;

/** Ends an import preview's dry run: everything it applied is rolled back (FR-IMP-01 AC3). */
final class DryRunOver extends RuntimeException {}
