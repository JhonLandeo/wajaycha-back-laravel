<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The three buttons on the plan message. The backing value is the callback
 * suffix: `p:{cycleId}:y|n|w`.
 */
enum PaydayAnswer: string
{
    /** Ya separé. */
    case Separated = 'y';

    /** Este mes no. */
    case Skip = 'n';

    /** Todavía no cobré. */
    case Wait = 'w';
}
