<?php

namespace App\Domain\Customers\ProductionIdentity\Notifications;

/** Only a transport positively knowing no message DATA bytes were sent may use this classification. */
final class DefinitelyNotSubmitted extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('The notification was not submitted.');
    }
}
