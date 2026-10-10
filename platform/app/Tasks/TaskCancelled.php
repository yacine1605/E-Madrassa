<?php

namespace App\Tasks;

use RuntimeException;

/** The user cancelled the job; it stops between two steps. */
final class TaskCancelled extends RuntimeException {}
