<?php

namespace G2Bulk;

use G2Bulk\Resource\Account;
use G2Bulk\Resource\Catalog;
use G2Bulk\Resource\Games;
use G2Bulk\Resource\Purchases;
use G2Bulk\Resource\Transactions;
use G2Bulk\Resource\Webhooks;

class Client
{
    public const VERSION = '0.1.0';

    public Account $account;
    public Catalog $catalog;
    public Purchases $purchases;
    public Transactions $transactions;
    public Games $games;
    public Webhooks $webhooks;

    public function __construct(?string $apiKey = null, array $options = [])
    {
        $http = new HttpClient($apiKey, $options);
        $this->account = new Account($http);
        $this->catalog = new Catalog($http);
        $this->purchases = new Purchases($http);
        $this->transactions = new Transactions($http);
        $this->games = new Games($http);
        $this->webhooks = new Webhooks($http);
    }
}
