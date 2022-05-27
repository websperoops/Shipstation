<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class ShipstationOrders extends Model
{
    public $timestamps = true;
    protected $fillable = [
        'iconic_order_number','iconic_order_id','is_uploaded','tracking_no','tracking_no_updated'
    ];
    
    protected $hidden = [
        'created_at', 'updated_at',
    ];
}
