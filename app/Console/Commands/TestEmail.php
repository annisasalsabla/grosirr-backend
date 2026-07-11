<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class TestEmail extends Command
{
    protected $signature = 'email:test {email?}';
    protected $description = 'Test email sending';

    public function handle()
    {
        $email = $this->argument('email') ?? 'test@test.com';

        try {
            Mail::raw('Test email from Laravel', function ($message) use ($email) {
                $message->to($email)->subject('Test');
            });

            $this->info("Email sent successfully to {$email}");
            return 0;
        } catch (\Exception $e) {
            $this->error("Failed: " . $e->getMessage());
            return 1;
        }
    }
}