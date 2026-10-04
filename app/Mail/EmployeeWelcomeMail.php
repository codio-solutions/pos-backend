<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class EmployeeWelcomeMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public string $plainPassword,
        public bool $isReset = false
    ) {}

    public function build()
    {
        return $this->subject(
                $this->isReset
                    ? 'Your Khan Enterprises portal password was reset'
                    : 'Your Khan Enterprises portal login'
            )
            ->view('emails.employee-welcome')
            ->with([
                'name' => $this->user->name,
                'email' => $this->user->email,
                'password' => $this->plainPassword,
                'loginUrl' => rtrim(config('services.frontend.url'), '/') . '/login',
                'isReset' => $this->isReset,
            ]);
    }
}
