<?php

namespace NotificationChannels\PersianSms\IPPanel; 

use Illuminate\Notifications\Notification;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Exception\RequestException;
use NotificationChannels\PersianSms\Exceptions\CouldNotSendNotification;
// use NotificationChannels\PersianSms\IPPanelMessage;

class IPPanelChannel
{
    /**
     * Base API URL for IPPanel.
     */
    protected const API_BASE_URL = 'https://api2.ippanel.com/api/v1';

    /**
     * Endpoint for sending a single normal SMS.
     */
    protected const ENDPOINT_SEND_SINGLE = '/sms/send/webservice/single';

    /**
     * Endpoint for sending a pattern-based SMS.
     */
    protected const ENDPOINT_SEND_PATTERN = '/sms/pattern/normal/send';

    /**
     * Endpoint for checking account credit.
     */
    protected const ENDPOINT_CHECK_CREDIT = '/sms/accounting/credit/show';

    /**
     * The HTTP client instance.
     */
    protected HttpClient $client;

    /**
     * The API key for IPPanel.
     */
    protected string $apiKey;

    /**
     * The default sender number (line number) for IPPanel.
     * Can be overridden by IPPanelMessage.
     */
    protected string $defaultSenderNumber;

    /**
     * Create a new IPPanel channel instance.
     *
     * @param HttpClient $client The Guzzle HTTP client instance.
     * @param string $apiKey IPPanel API Key.
     * @param string $defaultSenderNumber Default IPPanel sender number (line number).
     */
    public function __construct(HttpClient $client, string $apiKey, string $defaultSenderNumber)
    {
        $this->client = $client;
        $this->apiKey = $apiKey;
        $this->defaultSenderNumber = $defaultSenderNumber;
    }

    /**
     * Send the given notification.
     *
     * @param  mixed  $notifiable
     * @param  \Illuminate\Notifications\Notification  $notification
     * @return \Psr\Http\Message\ResponseInterface|null
     * @throws \NotificationChannels\PersianSms\Exceptions\CouldNotSendNotification
     */
    public function send($notifiable, Notification $notification)
    {
        $recipient = $this->getRecipient($notifiable, $notification);

        if (!$recipient) {
            // No recipient found, do not proceed.
            // You might want to log this event.
            return null;
        }

        // Ensure recipient is an array for IPPanel API
        $recipients = is_array($recipient) ? $recipient : [$recipient];

        // Resolve the message from the notification.
        // It should return an IPPanelMessage instance.
        $message = $notification->toPersianSms($notifiable);

        if (!$message instanceof IPPanelMessage) {
            // If toPersianSms returns a string, we can try to create a default message
            // or throw an error. For now, we expect an IPPanelMessage.
            if (is_string($message)) {
                 // For simplicity, let's assume a string message means a normal SMS
                 // and we need to create an IPPanelMessage instance.
                 // This part depends on how you want to design the IPPanelMessage class.
                 // $message = IPPanelMessage::create($message); // Example
                 throw CouldNotSendNotification::invalidMessageObject("Message must be an instance of IPPanelMessage. String given: " . $message);
            }
            throw CouldNotSendNotification::invalidMessageObject($message);
        }

        $sender = $message->sender ?: $this->defaultSenderNumber; // Use message sender or default

        if (empty(trim($sender))) {
            throw CouldNotSendNotification::senderNotProvided();
        }

        $payload = [];
        $endpoint = '';

        if ($message->isPattern()) {
            // Sending with pattern
            if (empty($message->patternCode)) {
                throw CouldNotSendNotification::missingPatternCode();
            }
            if ($message->variables === null || !is_array($message->variables)) {
                 // IPPanel expects variables to be an object (associative array)
                 // even if empty for some patterns, or with actual values.
                throw CouldNotSendNotification::invalidPatternVariables();
            }

            $endpoint = self::API_BASE_URL . self::ENDPOINT_SEND_PATTERN;
            $payload = [
                'code'      => $message->patternCode,
                'sender'    => $sender,
                'recipient' => $recipients[0], // Pattern send seems to be for a single recipient based on docs
                'variable'  => (object) $message->variables, // Ensure it's an object (empty or with data)
            ];
        } else {
            // Sending normal SMS
            if (empty(trim((string) $message->content))) {
                throw CouldNotSendNotification::contentNotProvided();
            }
            $endpoint = self::API_BASE_URL . self::ENDPOINT_SEND_SINGLE;
            $payload = [
                'recipient' => $recipients,
                'sender'    => $sender,
                'message'   => (string) $message->content,
            ];
            // Optional: Add 'time' if IPPanelMessage supports it
            // if ($message->time) {
            //     $payload['time'] = $message->time; // Format: "2025-03-21T09:12:50.824Z"
            // }
        }

        try {
            $response = $this->client->post($endpoint, [
                'headers' => [
                    'apiKey' => $this->apiKey,
                    'Accept'        => 'application/json',
                    'Content-Type'  => 'application/json',
                ],
                'json' => $payload, // Send data as JSON
            ]);

            $statusCode = $response->getStatusCode();
            $responseBody = json_decode($response->getBody()->getContents(), true);

            // Check for successful status codes and IPPanel specific success status
            if ($statusCode >= 200 && $statusCode < 300 && isset($responseBody['status']) && $responseBody['status'] === 'OK') {
                // Optionally return the full response or just the message ID, etc.
                // return $responseBody['data']['message_id'] ?? $response;
                return $response; // Return the Guzzle response object for now
            }

            // If not explicitly 'OK', treat as an error
            $errorMessage = $responseBody['errorMessage'] ?? 'Unknown error from IPPanel.';
            if (is_array($errorMessage)) { // Sometimes error messages are arrays
                $errorMessage = implode(', ', array_map(
                    function ($v, $k) { return sprintf("%s: %s", $k, implode('|', (array)$v)); },
                    $errorMessage,
                    array_keys($errorMessage)
                ));
            }
            throw CouldNotSendNotification::serviceRespondedWithAnError($errorMessage, $statusCode, $responseBody);

        } catch (RequestException $exception) {
            // Guzzle specific exception
            $response = $exception->getResponse();
            $statusCode = $response ? $response->getStatusCode() : 503; // 503 Service Unavailable if no response
            $responseBody = $response ? json_decode($response->getBody()->getContents(), true) : null;
            $errorMessage = $responseBody['errorMessage'] ?? $exception->getMessage();
             if (is_array($errorMessage)) {
                $errorMessage = implode(', ', array_map(
                    function ($v, $k) { return sprintf("%s: %s", $k, implode('|',(array)$v)); },
                    $errorMessage,
                    array_keys($errorMessage)
                ));
            }
            throw CouldNotSendNotification::serviceRespondedWithAnError($errorMessage, $statusCode, $responseBody, $exception);
        } catch (\Exception $exception) {
            // Other general exceptions
            throw CouldNotSendNotification::genericError($exception->getMessage(), $exception);
        }
    }

    /**
     * Get the recipient's phone number(s).
     *
     * @param  mixed  $notifiable
     * @param  \Illuminate\Notifications\Notification  $notification
     * @return string|array|null
     */
    protected function getRecipient($notifiable, Notification $notification)
    {
        // Standard Laravel way to get routing information for a channel
        if ($route = $notifiable->routeNotificationFor('persianSms', $notification)) {
            return $route;
        }
        if ($route = $notifiable->routeNotificationFor(static::class, $notification)) {
            return $route;
        }

        // Custom method for this specific channel (if user defines it on notifiable)
        if (method_exists($notifiable, 'routeNotificationForIPPanel')) {
            return $notifiable->routeNotificationForIPPanel($notification);
        }

        // Fallback to common phone number attributes
        if (isset($notifiable->phone_number)) {
            return $notifiable->phone_number;
        }
        if (isset($notifiable->mobile)) {
            return $notifiable->mobile;
        }

        // If $notifiable itself is a string (phone number) or an array of strings
        if (is_string($notifiable) || (is_array($notifiable) && count(array_filter($notifiable, 'is_string')) === count($notifiable))) {
            return $notifiable;
        }

        return null;
    }

    /**
     * (Optional) Method to check account credit.
     * Not directly used by the send method but can be a utility for the package.
     *
     * @return array|null Parsed JSON response from credit check or null on failure.
     * @throws CouldNotSendNotification
     */
    public function getCredit(): ?array
    {
        $endpoint = self::API_BASE_URL . self::ENDPOINT_CHECK_CREDIT;

        try {
            $response = $this->client->get($endpoint, [
                'headers' => [
                    'apiKey' => $this->apiKey,
                    'Accept'        => 'application/json',
                ],
            ]);

            $statusCode = $response->getStatusCode();
            $responseBody = json_decode($response->getBody()->getContents(), true);

            if ($statusCode === 200 && isset($responseBody['status']) && $responseBody['status'] === 'OK') {
                return $responseBody['data'];
            }

            $errorMessage = $responseBody['errorMessage'] ?? 'Failed to retrieve credit information.';
            throw CouldNotSendNotification::serviceRespondedWithAnError($errorMessage, $statusCode, $responseBody);

        } catch (RequestException $exception) {
            $response = $exception->getResponse();
            $statusCode = $response ? $response->getStatusCode() : 503;
            $responseBody = $response ? json_decode($response->getBody()->getContents(), true) : null;
            $errorMessage = $responseBody['errorMessage'] ?? $exception->getMessage();
            throw CouldNotSendNotification::serviceRespondedWithAnError($errorMessage, $statusCode, $responseBody, $exception);
        } catch (\Exception $exception) {
            throw CouldNotSendNotification::genericError("Failed to get credit: " . $exception->getMessage(), $exception);
        }
    }
}