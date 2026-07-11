<?php

namespace App\Services;

use Kreait\Firebase\Factory;
use Kreait\Firebase\Messaging;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification;
use Kreait\Firebase\Exception\MessagingException;
use Kreait\Firebase\Exception\FirebaseException;
use Kreait\Firebase\Exception\Messaging\NotFound;
use App\Models\FcmToken;

class FirebasePushService
{
    protected ?Messaging $messaging = null;

    /**
     * Get or create Messaging instance
     */
    protected function getMessaging(): Messaging
    {
        if ($this->messaging === null) {
            $factory = new Factory();

            // Get credentials file path - kreait 8.x requires withServiceAccount()
            $credentialsPath = base_path('storage/app/firebase/firebase-credentials.json');

            if (!file_exists($credentialsPath)) {
                throw new \RuntimeException('Firebase credentials file not found: ' . $credentialsPath);
            }

            // Use correct method: withServiceAccount() - accepts file path (string) or array
            $factory = $factory->withServiceAccount($credentialsPath);

            $this->messaging = $factory->createMessaging();
        }

        return $this->messaging;
    }

    /**
     * Send push notification to a single FCM token
     *
     * @param string $fcmToken Device token from client app
     * @param string $title Notification title
     * @param string $body Notification body
     * @param array $data Additional data payload (optional)
     * @return bool True if successful, false otherwise
     */
    public function sendToToken(string $fcmToken, string $title, string $body, array $data = []): bool
    {
        try {
            // kreait/firebase-php 8.x uses withToken() not withTargetToken()
            $message = CloudMessage::new()
                ->withToken($fcmToken)
                ->withNotification(Notification::create($title, $body));

            if (!empty($data)) {
                $message = $message->withData($data);
            }

            $this->getMessaging()->send($message);

            return true;
        } catch (MessagingException | FirebaseException $e) {
            \Log::error('Firebase Push Error (sendToToken): ' . $e->getMessage());
            report($e);
            return false;
        }
    }

    /**
     * Send push notification to multiple FCM tokens (DEBUG VERSION - returns detailed results)
     *
     * @param array $tokens Array of device tokens
     * @param string $title Notification title
     * @param string $body Notification body
     * @param array $data Additional data payload (optional)
     * @return array Results with each token's success/failure status and error message
     */
    public function sendToMultipleTokens(array $tokens, string $title, string $body, array $data = []): array
    {
        if (empty($tokens)) {
            return [
                'all_success' => false,
                'results' => [['token' => 'none', 'success' => false, 'error_message' => 'No tokens provided']]
            ];
        }

        $messaging = $this->getMessaging();
        $results = [];

        foreach ($tokens as $token) {
            try {
                // kreait/firebase-php 8.x uses withToken() not withTargetToken()
                $message = CloudMessage::new()
                    ->withToken($token)
                    ->withNotification(Notification::create($title, $body));

                if (!empty($data)) {
                    $message = $message->withData($data);
                }

                $result = $messaging->send($message);
                $results[] = [
                    'token' => substr($token, 0, 30) . '...',
                    'success' => true,
                    'message_id' => is_object($result) && method_exists($result, 'messageId') ? $result->messageId() : 'sent'
                ];
            } catch (NotFound $e) {
                // Token expired/invalid - auto-deactivate in database
                FcmToken::where('fcm_token', $token)->update(['is_active' => false]);
                \Log::warning('FCM token deactivated (NotFound): ' . substr($token, 0, 30) . '...');
                $results[] = [
                    'token' => substr($token, 0, 30) . '...',
                    'success' => false,
                    'error_class' => get_class($e),
                    'error_message' => $e->getMessage(),
                    'token_deactivated' => true
                ];
            } catch (\Throwable $e) {
                $results[] = [
                    'token' => substr($token, 0, 30) . '...',
                    'success' => false,
                    'error_class' => get_class($e),
                    'error_message' => $e->getMessage()
                ];
            }
        }

        $allSuccess = collect($results)->every(fn($r) => $r['success']);
        return [
            'all_success' => $allSuccess,
            'results' => $results
        ];
    }

    /**
     * Send to all subscribed topics (for topic-based messaging)
     *
     * @param string $topic Topic name (e.g., 'admins', 'cashiers')
     * @param string $title Notification title
     * @param string $body Notification body
     * @param array $data Additional data payload (optional)
     * @return bool True if successful, false otherwise
     */
    public function sendToTopic(string $topic, string $title, string $body, array $data = []): bool
    {
        try {
            $message = CloudMessage::new()->withTopic($topic)
                ->withNotification(Notification::create($title, $body));

            if (!empty($data)) {
                $message = $message->withData($data);
            }

            $this->getMessaging()->send($message);

            return true;
        } catch (MessagingException | FirebaseException $e) {
            \Log::error('Firebase Push Error (sendToTopic): ' . $e->getMessage());
            report($e);
            return false;
        }
    }

    /**
     * Get the Messaging instance for advanced usage
     */
    public function getMessagingInstance(): Messaging
    {
        return $this->getMessaging();
    }
}