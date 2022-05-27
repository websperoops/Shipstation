<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class DailyIconicOrders extends Model
{
	public $timestamps = true;
    protected $fillable = [
        'order_id','webhook_response'
    ];
    
    protected $hidden = [
        'created_at', 'updated_at',
    ];
}
