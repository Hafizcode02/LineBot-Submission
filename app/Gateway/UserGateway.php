<?php

namespace App\Gateway;

use Illuminate\Database\ConnectionInterface;

class UserGateway
{
    /**
     * @var ConnectionInterface
     */
    private $db;

    public function __construct()
    {
        $this->db = app('db');
    }
}
