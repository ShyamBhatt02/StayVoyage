<?php

namespace App\Events;

use App\Models\Membership;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MembershipActivated
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly Membership $membership) {}
}
