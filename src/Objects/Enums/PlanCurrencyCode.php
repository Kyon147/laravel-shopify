<?php

namespace Osiset\ShopifyApp\Objects\Enums;

enum PlanCurrencyCode: string
{
    /**
     * Currency: USD.
     *
     * @var string
     */
    case USD = 'USD';

    /**
     * Currency: British Pound.
     *
     * @var string
     */
    case GBP = 'GBP';

    /**
     * Currency: Euro.
     *
     * @var string
     */
    case EUR = 'EUR';

    /**
     * Currency: Canadian Dollar.
     *
     * @var string
     */
    case CAD = 'CAD';

    /**
     * Currency: Australian Dollar.
     *
     * @var string
     */
    case AUD = 'AUD';

    /**
     * Currency: Japanese Yen.
     *
     * @var string
     */
    case JPY = 'JPY';
}
