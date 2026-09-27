<?php

namespace App\Services\Irev;

use RuntimeException;

/** IReV's image store refused the download (it serves approved sites only). */
class IrevBlocked extends RuntimeException {}
