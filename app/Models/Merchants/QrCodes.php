<?php

namespace App\Models\Merchants;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class QrCodes extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected $casts    = [
        'id'            => 'integer',
        'slug'          => 'string',
        'merchant_id'   => 'integer',
        'receiver_type' => 'string',
        'amount'        => 'decimal:8',
        'qrcodes'       => 'string'
    ];

    public function merchant(){
        return $this->belongsTo(Merchant::class,'merchant_id');
    }
    public function scopeAuth($q){
        return $q->where('merchant_id',auth()->user()->id);
    }

}
