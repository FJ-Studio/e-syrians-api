<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class VerificationReceived
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public User $sender;

    public User $recipient;

    /**
     * Create a new event instance.
     */
    public function __construct(User $sender, User $recipient)
    {
        $this->sender = $sender;
        $this->recipient = $recipient;
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('channel-name'),
        ];
    }
}
