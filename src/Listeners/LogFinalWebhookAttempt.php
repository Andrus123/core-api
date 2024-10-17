<?php

namespace Fleetbase\Listeners;

use Fleetbase\Models\ApiCredential;
use Fleetbase\Models\WebhookRequestLog;
use Fleetbase\Webhook\Events\FinalWebhookCallFailedEvent;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

class LogFinalWebhookAttempt
{
    /**
     * Handle the event.
     *
     * @return void
     */
    public function handle(FinalWebhookCallFailedEvent $event)
    {
        /** @var \GuzzleHttp\Psr7\Response $response */
        $response = $event->response;
        /** @var \GuzzleHttp\TransferStats $stats */
        $stats = $event->transferStats;
        /** @var float $transferTime The time it took for the webhook to get a response */
        $transferTime = $stats->getTransferTime();
        /** @var string $connection The db connection the webhook was called on */
        $connection = (bool) data_get($event, 'meta.is_sandbox') ? 'sandbox' : 'mysql';
        /** @var int $statusCode The response status code */
        $statusCode = $response ? $response->getStatusCode() : 500;

        // Get API credential
        $apiCredentialUuid = data_get($event, 'meta.api_credential_uuid');

        // Get API Access Token
        $accessTokenId = data_get($event, 'meta.access_token_id');

        // Prepare insert array
        $data = [
            '_key'                => data_get($event, 'meta.api_key'),
            'company_uuid'        => data_get($event, 'meta.company_uuid'),
            'webhook_uuid'        => data_get($event, 'meta.webhook_uuid'),
            'api_event_uuid'      => data_get($event, 'meta.api_event_uuid'),
            'method'              => $event->httpVerb,
            'status_code'         => $response ? $response->getStatusCode() : 500,
            'reason_phrase'       => $response ? $response->getReasonPhrase() : 'ERR',
            'duration'            => $transferTime,
            'url'                 => $event->webhookUrl,
            'attempt'             => $event->attempt,
            'response'            => $response ? $response->getBody() : null,
            'status'              => Str::startsWith((string) $statusCode, '2') ? 'successful' : 'failed',
            'headers'             => $event->headers,
            'meta'                => $event->meta,
            'sent_at'             => data_get($event, 'meta.sent_at'),
        ];

        // Validate api credential, if not uuid then it could be internal
        if ($apiCredentialUuid && Str::isUuid($apiCredentialUuid) && ApiCredential::where('uuid', $apiCredentialUuid)->exists()) {
            $data['api_credential_uuid'] = $apiCredentialUuid;
        }

        // Check if it was a personal access token which made the request
        if ($accessTokenId && PersonalAccessToken::where('id', $accessTokenId)->exists()) {
            $data['access_token_id'] = $accessTokenId;
        }

        // log webhook event
        WebhookRequestLog::on($connection)->create($data);
    }
}
