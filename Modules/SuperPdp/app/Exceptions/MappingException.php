<?php

namespace Modules\SuperPdp\Exceptions;

use RuntimeException;

/** The Invoice Ninja document can't be expressed as a valid French e-invoice; data must be fixed by hand. */
class MappingException extends RuntimeException
{
}
