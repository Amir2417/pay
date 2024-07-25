<?php

namespace App\Imports;

use App\Models\Agent;
use App\Models\User;
use Exception;
use Maatwebsite\Excel\Facades\Excel;
use Maatwebsite\Excel\Concerns\ToArray;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class BulkMoneyTransferImport implements ToArray, WithHeadingRow
{
    private $data;

    public function array(array $array)
    {
        
        $this->data = $array;

    }    
}

