<?php

namespace bwd\imagor;

use RuntimeException;

/**
 * imagor can't be reached or reports itself unavailable (502/503/504), as opposed to failing on one
 * image. Craft stops calling it for a few minutes.
 */
class UnavailableException extends RuntimeException
{
}
