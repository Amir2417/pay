<?php

namespace App\Notifications\Merchant;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class BulkMoneyReceiveNotification extends Notification
{
    use Queueable;

    public $user;
    public $amount;
    public $trx_id;
    /**
     * Create a new notification instance.
     *
     * @return void
     */
    public function __construct($user,$amount,$trx_id)
    {
        $this->user     = $user;
        $this->amount   = $amount;
        $this->trx_id   = $trx_id;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @param  mixed  $notifiable
     * @return array
     */
    public function via($notifiable)
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     *
     * @param  mixed  $notifiable
     * @return \Illuminate\Notifications\Messages\MailMessage
     */
    public function toMail($notifiable)
    {
        $user       = $this->user;
        $amount     = $this->amount;
        $trx_id     = $this->trx_id;
        return (new MailMessage)
            ->greeting("Hello ".$user->fullname." !")
            ->subject("Bulk Money")
            ->line("Details Of Bulk Money:")
            ->line("Transaction Id: " .$trx_id)
            ->line("Total Amount: " . get_amount($amount) . ' ' .get_default_currency_code())
            ->line("Received.")
            ->line('Thank you for using our application!');
    }

    /**
     * Get the array representation of the notification.
     *
     * @param  mixed  $notifiable
     * @return array
     */
    public function toArray($notifiable)
    {
        return [
            //
        ];
    }
}
