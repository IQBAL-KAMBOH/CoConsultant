<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class FileActionNotification extends Notification implements ShouldQueue
{
    use Queueable;

    protected $action;
    protected $file;

    public function __construct($action, $file)
    {
        $this->action = $action;
        $this->file = $file;
    }

    public function via($notifiable)
    {
        return ['database']; // you can also add 'mail', 'broadcast'
    }

    public function toArray($notifiable)
    {
        return [
            'action' => $this->action,
            'file'   => [
                'id'   => $this->file->id ?? null,
                'name' => $this->file->name ?? null,
            ],
            'message' => "File '{$this->file->name}' was {$this->action}."
        ];
    }
}
