<?php

declare(strict_types=1);

namespace Daktela\CrmSync\Mapping;

enum MultiValueStrategy: string
{
    /** Keep as array — pass multi-value fields as-is */
    case AsArray = 'as_array';

    /** Join array values into a string with a separator */
    case Join = 'join';

    /** Split a string value into an array by separator */
    case Split = 'split';

    /** Take only the first value from an array */
    case First = 'first';

    /** Take only the last value from an array */
    case Last = 'last';
}
