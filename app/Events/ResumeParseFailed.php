<?php

namespace App\Events;

use App\Models\Resume;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ResumeParseFailed implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Resume $resume) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel("users.{$this->resume->user_id}");
    }

    public function broadcastAs(): string
    {
        return 'ResumeParseFailed';
    }

    public function broadcastWith(): array
    {
        return [
            'resume_id' => $this->resume->id,
            'parse_status' => 'parse_failed',
            'has_embedding' => false,
        ];
    }
}
