<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ContactMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContactController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:80'],
            'email' => ['required', 'email', 'max:150'],
            'phone' => ['nullable', 'string', 'regex:/^\+?[\d\s-]{8,18}$/'],
            'subject' => ['required', 'string', 'min:3', 'max:150'],
            'message' => ['required', 'string', 'min:10', 'max:3000'],
            'website' => ['nullable', 'size:0'], // honeypot — bots fill hidden fields
        ]);
        unset($data['website']);

        ContactMessage::query()->create(array_merge(array_map(fn ($v) => is_string($v) ? strip_tags($v) : $v, $data), [
            'user_id' => $request->user()?->id,
            'ip_address' => $request->ip(),
            'status' => 'new',
        ]));

        return $this->created(null, 'Thanks for reaching out! Our travel team replies within one business day.');
    }
}
