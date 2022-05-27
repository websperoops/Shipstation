<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class WebhooksResponse extends Model
{
    protected $fillable = [
        'resource_url','resource_type','response'
    ];
    
    protected $hidden = [
        'created_at', 'updated_at',
    ];
}
