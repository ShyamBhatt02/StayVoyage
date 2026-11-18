<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Faq extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'show_on_home' => 'boolean',
        'is_active' => 'boolean',
    ];
}
