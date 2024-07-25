<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserQrCode extends Model
{
    use HasFactory;
    protected $table = "user_qr_codes";
    protected $guarded = ['id'];
    protected $casts = [
        'user_id'           => 'integer',
        'sender_type'       => 'string',
        'receiver_type'     => 'string',
        'qr_code'           => 'object',
    ];
}
