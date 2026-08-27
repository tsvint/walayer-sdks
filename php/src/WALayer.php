<?php

declare(strict_types=1);

namespace WALayer;

use WALayer\Resource\Business;
use WALayer\Resource\Channels;
use WALayer\Resource\Communities;
use WALayer\Resource\Contacts;
use WALayer\Resource\Labels;
use WALayer\Resource\Events;
use WALayer\Resource\Groups;
use WALayer\Resource\Inbox;
use WALayer\Resource\Media;
use WALayer\Resource\Messages;
use WALayer\Resource\Sessions;
use WALayer\Resource\Suppressions;
use WALayer\Resource\Webhooks;

/**
 * The WALayer client. Three lines to send:
 *
 *   use WALayer\WALayer;
 *   $wa = new WALayer(getenv('WALAYER_API_KEY'));
 *   $wa->messages->send($sessionId, ['type' => 'text', 'to' => '+9477…', 'body' => ['text' => 'hi']]);
 */
final class WALayer
{
    public readonly Sessions $sessions;
    public readonly Messages $messages;
    public readonly Webhooks $webhooks;
    public readonly Suppressions $suppressions;
    public readonly Groups $groups;
    public readonly Inbox $inbox;
    public readonly Media $media;
    public readonly Channels $channels;
    public readonly Events $events;
    public readonly Contacts $contacts;
    public readonly Communities $communities;
    public readonly Labels $labels;
    public readonly Business $business;

    /**
     * @param string         $apiKey    a `wsk_live_…` / `wsk_test_…` key
     * @param string         $baseUrl   override for self-hosted deployments
     * @param Transport|null $transport inject to test without a network, or to
     *                                  bridge an HTTP stack you already use
     */
    public function __construct(
        string $apiKey,
        string $baseUrl = Http::DEFAULT_BASE_URL,
        ?Transport $transport = null
    ) {
        $http = new Http($apiKey, $baseUrl, $transport);
        $this->sessions = new Sessions($http);
        $this->messages = new Messages($http);
        $this->webhooks = new Webhooks($http);
        $this->suppressions = new Suppressions($http);
        $this->groups = new Groups($http);
        $this->inbox = new Inbox($http);
        $this->media = new Media($http);
        $this->channels = new Channels($http);
        $this->events = new Events($http);
        $this->contacts = new Contacts($http);
        $this->communities = new Communities($http);
        $this->labels = new Labels($http);
        $this->business = new Business($http);
    }
}
