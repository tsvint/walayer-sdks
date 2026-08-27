<?php

declare(strict_types=1);

namespace WALayer\Tests;

use InvalidArgumentException;
use WALayer\MessageType;
use WALayer\TransportError;
use WALayer\WALayer;
use WALayer\WALayerError;

final class ClientTest extends TestCase
{
    private const BASE = 'https://api.example.com';

    /** @return array{0:WALayer,1:RecordingTransport} */
    private function client(int $status = 200, mixed $payload = null, string $apiKey = 'wsk_live_x'): array
    {
        $transport = new RecordingTransport($status, $payload);

        return [new WALayer($apiKey, self::BASE, $transport), $transport];
    }

    // ---------------------------------------------------------------- sending

    public function testSendAttachesBearerIdempotencyKeyAndUnwrapsData(): void
    {
        [$wa, $t] = $this->client(202, ['data' => ['id' => 'msg_1', 'status' => 'queued']]);

        $res = $wa->messages->send('sess_1', [
            'type' => 'text',
            'to' => '+94770000000',
            'body' => ['text' => 'hi'],
        ]);

        $this->assertSame(['id' => 'msg_1', 'status' => 'queued'], $res);

        $call = $t->lastCall();
        $this->assertSame('POST', $call['method']);
        $this->assertSame(self::BASE . '/v1/sessions/sess_1/messages', $call['url']);
        $this->assertSame('Bearer wsk_live_x', $call['headers']['authorization']);
        $this->assertSame('application/json', $call['headers']['content-type']);
        $this->assertArrayHasKey('idempotency-key', $call['headers']);
        $this->assertSame(
            ['type' => 'text', 'to' => '+94770000000', 'body' => ['text' => 'hi']],
            $call['body']
        );
    }

    public function testGeneratedIdempotencyKeyIsAUniqueUuidV4(): void
    {
        [$wa, $t] = $this->client(202, ['data' => []]);
        $wa->messages->send('sess_1', ['type' => 'text', 'to' => '+9477', 'body' => ['text' => 'a']]);
        $wa->messages->send('sess_1', ['type' => 'text', 'to' => '+9477', 'body' => ['text' => 'b']]);

        $first = $t->calls[0]['headers']['idempotency-key'];
        $second = $t->calls[1]['headers']['idempotency-key'];

        $this->assertSame(
            1,
            \preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $first),
            'auto key is not a v4 UUID: ' . $first
        );
        $this->assertFalse($first === $second, 'two sends reused the same idempotency key');
    }

    public function testCallerSuppliedIdempotencyKeyIsUsedVerbatim(): void
    {
        [$wa, $t] = $this->client(200, ['data' => ['id' => 'msg_1', 'replay' => true]]);
        $wa->messages->send('sess_1', ['type' => 'text', 'to' => '+9477', 'body' => ['text' => 'hi']], 'my-key-123');
        $this->assertSame('my-key-123', $t->lastCall()['headers']['idempotency-key']);
    }

    public function testResendCarriesItsOwnIdempotencyKey(): void
    {
        [$wa, $t] = $this->client(202, ['data' => ['id' => 'msg_2', 'status' => 'queued']]);
        $wa->messages->resend('msg_1');

        $call = $t->lastCall();
        $this->assertSame('POST', $call['method']);
        $this->assertSame(self::BASE . '/v1/messages/msg_1/resend', $call['url']);
        $this->assertArrayHasKey('idempotency-key', $call['headers']);
    }

    public function testEveryDocumentedMessageTypeIsSentThrough(): void
    {
        [$wa, $t] = $this->client(202, ['data' => ['id' => 'msg_1', 'status' => 'queued']]);

        $types = MessageType::all();
        $this->assertSame(17, \count($types), 'the spec documents 17 message types');

        foreach ($types as $i => $type) {
            $wa->messages->send('sess_1', ['type' => $type, 'to' => '+9477', 'body' => ['k' => $i]]);
            $call = $t->lastCall();
            $this->assertSame($type, $call['body']['type'] ?? null);
            $this->assertSame(self::BASE . '/v1/sessions/sess_1/messages', $call['url']);
        }
    }

    // ------------------------------------------------------- request building

    public function testRequestBuildingPerOperation(): void
    {
        /** @var list<array{0:string,1:callable(WALayer):mixed,2:string,3:string,4:array<string,mixed>|null}> */
        $cases = [
            ['sessions.list', static fn (WALayer $wa) => $wa->sessions->list(), 'GET', '/v1/sessions', null],
            ['sessions.get', static fn (WALayer $wa) => $wa->sessions->get('sess_1'), 'GET', '/v1/sessions/sess_1', null],
            [
                'sessions.create',
                static fn (WALayer $wa) => $wa->sessions->create('LK', 'Sales'),
                'POST',
                '/v1/sessions',
                ['country' => 'LK', 'label' => 'Sales'],
            ],
            [
                'sessions.create without label',
                static fn (WALayer $wa) => $wa->sessions->create('LK'),
                'POST',
                '/v1/sessions',
                ['country' => 'LK'],
            ],
            ['sessions.delete', static fn (WALayer $wa) => $wa->sessions->delete('sess_1'), 'DELETE', '/v1/sessions/sess_1', null],
            ['messages.get', static fn (WALayer $wa) => $wa->messages->get('msg_1'), 'GET', '/v1/messages/msg_1', null],
            ['webhooks.list', static fn (WALayer $wa) => $wa->webhooks->list(), 'GET', '/v1/webhooks', null],
            [
                'webhooks.create',
                static fn (WALayer $wa) => $wa->webhooks->create('https://x.test/wa', ['message.status'], 'sess_1'),
                'POST',
                '/v1/webhooks',
                ['url' => 'https://x.test/wa', 'events' => ['message.status'], 'session_id' => 'sess_1'],
            ],
            [
                'webhooks.update',
                static fn (WALayer $wa) => $wa->webhooks->update('wh_1', ['status' => 'disabled']),
                'PATCH',
                '/v1/webhooks/wh_1',
                ['status' => 'disabled'],
            ],
            ['webhooks.delete', static fn (WALayer $wa) => $wa->webhooks->delete('wh_1'), 'DELETE', '/v1/webhooks/wh_1', null],
            ['suppressions.list', static fn (WALayer $wa) => $wa->suppressions->list(), 'GET', '/v1/suppressions', null],
            [
                'suppressions.add',
                static fn (WALayer $wa) => $wa->suppressions->add('+94770000000', 'stop'),
                'POST',
                '/v1/suppressions',
                ['phone' => '+94770000000', 'reason' => 'stop'],
            ],
            [
                'suppressions.remove url-encodes the phone',
                static fn (WALayer $wa) => $wa->suppressions->remove('+94770000000'),
                'DELETE',
                '/v1/suppressions/%2B94770000000',
                null,
            ],
            ['events.list without params', static fn (WALayer $wa) => $wa->events->list(), 'GET', '/v1/events', null],
            [
                'events.list with params',
                static fn (WALayer $wa) => $wa->events->list(1700000000, 100),
                'GET',
                '/v1/events?since=1700000000&limit=100',
                null,
            ],
            [
                'groups.create',
                static fn (WALayer $wa) => $wa->groups->create('sess_1', 'Team', ['+9477']),
                'POST',
                '/v1/sessions/sess_1/groups',
                ['subject' => 'Team', 'participants' => ['+9477']],
            ],
            ['groups.list', static fn (WALayer $wa) => $wa->groups->list('sess_1'), 'GET', '/v1/sessions/sess_1/groups', null],
            [
                'groups.get',
                static fn (WALayer $wa) => $wa->groups->get('sess_1', '120363@g.us'),
                'GET',
                '/v1/sessions/sess_1/groups/120363%40g.us',
                null,
            ],
            [
                'groups.participants',
                static fn (WALayer $wa) => $wa->groups->participants('sess_1', 'grp_1', 'add', ['+9477']),
                'POST',
                '/v1/sessions/sess_1/groups/grp_1/participants',
                ['action' => 'add', 'participants' => ['+9477']],
            ],
            [
                'groups.leave',
                static fn (WALayer $wa) => $wa->groups->leave('sess_1', 'grp_1'),
                'POST',
                '/v1/sessions/sess_1/groups/grp_1/leave',
                null,
            ],
        ];

        foreach ($cases as [$name, $invoke, $method, $path, $body]) {
            [$wa, $t] = $this->client(200, ['data' => []]);
            $invoke($wa);
            $call = $t->lastCall();
            $this->assertSame($method, $call['method'], $name . ': wrong method');
            $this->assertSame(self::BASE . $path, $call['url'], $name . ': wrong url');
            $this->assertSame($body, $call['body'], $name . ': wrong body');
            $this->assertSame('Bearer wsk_live_x', $call['headers']['authorization'], $name . ': missing bearer');
            if ($body === null) {
                $this->assertArrayNotHasKey('content-type', $call['headers'], $name . ': content-type without a body');
            }
        }
    }

    public function testBaseUrlTrailingSlashIsNormalised(): void
    {
        $t = new RecordingTransport(200, ['data' => []]);
        $wa = new WALayer('k', 'https://api.example.com/', $t);
        $wa->sessions->list();
        $this->assertSame('https://api.example.com/v1/sessions', $t->lastCall()['url']);
    }

    // ----------------------------------------------------------- error mapping

    public function testNonSuccessMapsToTypedErrorWithCodeDetailAndRequestId(): void
    {
        [$wa] = $this->client(409, [
            'error' => [
                'code' => 'RECIPIENT_SUPPRESSED',
                'message' => 'recipient opted out',
                'detail' => ['phone' => '+9477'],
            ],
        ]);

        try {
            $wa->messages->send('sess_1', ['type' => 'text', 'to' => '+9477', 'body' => ['text' => 'hi']]);
            $this->fail('expected a WALayerError');
        } catch (WALayerError $e) {
            $this->assertSame(409, $e->status);
            // All three spellings must agree; `code` is the Node/Python parity alias.
            $this->assertSame('RECIPIENT_SUPPRESSED', $e->code);
            $this->assertSame('RECIPIENT_SUPPRESSED', $e->errorCode);
            $this->assertSame('RECIPIENT_SUPPRESSED', $e->getErrorCode());
            $this->assertSame(['phone' => '+9477'], $e->detail);
            $this->assertSame('req_1', $e->requestId);
            $this->assertSame('RECIPIENT_SUPPRESSED: recipient opted out', $e->getMessage());
        }
    }

    public function testRateLimitDetailSurvives(): void
    {
        [$wa] = $this->client(429, [
            'error' => [
                'code' => 'WARMUP_CAP_EXCEEDED',
                'message' => 'daily cap reached',
                'detail' => ['stage' => 2, 'of' => 4, 'daily_cap' => 180, 'retry_after' => 45],
            ],
        ]);

        try {
            $wa->messages->send('sess_1', ['type' => 'text', 'to' => '+9477', 'body' => ['text' => 'hi']]);
            $this->fail('expected a WALayerError');
        } catch (WALayerError $e) {
            $this->assertSame('WARMUP_CAP_EXCEEDED', $e->code);
            $this->assertSame(45, $e->detail['retry_after'] ?? null);
        }
    }

    public function testErrorBodyWithoutAnErrorObjectFallsBackToUnknown(): void
    {
        [$wa] = $this->client(500, ['nope' => true]);
        try {
            $wa->sessions->list();
            $this->fail('expected a WALayerError');
        } catch (WALayerError $e) {
            $this->assertSame('UNKNOWN', $e->code);
            $this->assertSame(500, $e->status);
            $this->assertNull($e->detail);
        }
    }

    public function testMalformedJsonRaisesTransportErrorWithoutEchoingTheBody(): void
    {
        $transport = new class () implements \WALayer\Transport {
            public function send(string $m, string $u, array $h, ?string $b): \WALayer\TransportResponse
            {
                return new \WALayer\TransportResponse(200, [], 'not json at all: SECRET-BODY-CONTENT');
            }
        };
        $wa = new WALayer('k', self::BASE, $transport);

        try {
            $wa->sessions->list();
            $this->fail('expected a TransportError');
        } catch (TransportError $e) {
            $this->assertStringNotContainsString('SECRET-BODY-CONTENT', $e->getMessage());
            $this->assertStringNotContainsString('SECRET-BODY-CONTENT', (string) $e);
        }
    }

    public function testNoContentResponsesResolveToNull(): void
    {
        [$wa, $t] = $this->client(204, null);
        $wa->webhooks->delete('wh_1');
        $this->assertSame('DELETE', $t->lastCall()['method']);
        $wa->sessions->delete('sess_1');
        $this->assertSame(self::BASE . '/v1/sessions/sess_1', $t->lastCall()['url']);
    }

    public function testMissingApiKeyIsRejected(): void
    {
        try {
            new WALayer('');
            $this->fail('expected an InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('apiKey is required', $e->getMessage());
        }
    }

    // ----------------------------------------------------------- secret hygiene

    public function testErrorStringFormNeverCarriesKeyOrPayload(): void
    {
        [$wa] = $this->client(403, ['error' => ['code' => 'FORBIDDEN', 'message' => 'nope']], 'wsk_live_SUPERSECRET');

        try {
            $wa->messages->send('sess_1', ['type' => 'text', 'to' => '+9477', 'body' => ['text' => 'PRIVATE-MESSAGE']]);
            $this->fail('expected a WALayerError');
        } catch (WALayerError $e) {
            foreach ([$e->getMessage(), (string) $e] as $rendered) {
                $this->assertStringNotContainsString('SUPERSECRET', $rendered);
                $this->assertStringNotContainsString('PRIVATE-MESSAGE', $rendered);
            }
            $this->assertStringContainsString('FORBIDDEN', (string) $e);
        }
    }

    public function testHttpDebugInfoRedactsTheApiKey(): void
    {
        $http = new \WALayer\Http('wsk_live_SUPERSECRET', self::BASE, new RecordingTransport());

        \ob_start();
        \var_dump($http);
        $dumped = (string) \ob_get_clean();

        $this->assertStringNotContainsString('SUPERSECRET', $dumped);
        $this->assertStringContainsString('[redacted]', $dumped);
    }

    // -------------------------------------------------- Phase 5–10 surface

    public function testNewWhatsAppMethodsMapToTheRightCall(): void
    {
        [$wa, $t] = $this->client(200, ['data' => []]);
        $S = 'sess_1'; $C = '9477@s.whatsapp.net'; $NL = '1@newsletter'; $COM = '1@g.us';

        $wa->sessions->settings($S);
        $wa->sessions->limits($S);
        $wa->messages->story($S, ['type' => 'text', 'body' => ['text' => 'hi']]);
        $wa->messages->star('msg_1', true);
        $wa->messages->pin('msg_1', ['duration_seconds' => 604800]);
        $wa->messages->receipts('msg_1');
        $wa->inbox->getChat($S, $C);
        $wa->inbox->patchChat($S, $C, ['pinned' => true]);
        $wa->inbox->presence($S, $C, 'typing');
        $wa->contacts->check($S, ['+9477']);
        $wa->contacts->resolve($S, [$C]);
        $wa->contacts->blocklist($S);
        $wa->contacts->updateProfile($S, ['about' => 'hi']);
        $wa->contacts->setPresence($S, 'online');
        $wa->groups->settings($S, 'grp_1', ['announce_only' => true]);
        $wa->groups->setIcon($S, 'grp_1', 'med_1');
        $wa->groups->resolveRequests($S, 'grp_1', 'approve', [$C]);
        $wa->communities->create($S, ['name' => 'HQ']);
        $wa->communities->linkGroup($S, $COM, '2@g.us');
        $wa->channels->create($S, ['name' => 'News']);
        $wa->channels->react($S, $NL, 7, "\u{1F525}");
        $wa->labels->create($S, ['name' => 'VIP', 'color' => 2]);
        $wa->labels->associate($S, '31', ['chat_jid' => $C]);
        $wa->business->profile($S, $C);
        $wa->business->rejectCall($S, 'call_1', $C);
        $wa->business->bots($S);
        $wa->media->list();
        $wa->webhooks->test('wh_1');
        $wa->events->types();

        $urls = \array_map(static fn ($c) => $c['method'] . ' ' . $c['url'], $t->calls);
        $has = fn (string $frag): bool => (bool) \array_filter($urls, static fn ($u) => \str_ends_with($u, $frag));

        $this->assertTrue($has('/v1/sessions/sess_1/settings'), 'settings');
        $this->assertTrue($has('/v1/sessions/sess_1/stories'), 'story');
        $this->assertTrue($has('/v1/messages/msg_1/star'), 'star');
        $this->assertTrue($has('/v1/messages/msg_1/receipts'), 'receipts');
        $this->assertTrue($has('/v1/sessions/sess_1/contacts/check'), 'contacts.check');
        $this->assertTrue($has('/v1/sessions/sess_1/blocklist'), 'blocklist');
        $this->assertTrue($has('/v1/sessions/sess_1/groups/grp_1/settings'), 'group settings');
        $this->assertTrue($has('/v1/sessions/sess_1/groups/grp_1/icon'), 'setIcon');
        $this->assertTrue($has('/v1/sessions/sess_1/communities'), 'community create');
        $this->assertTrue($has('/v1/sessions/sess_1/channels/1%40newsletter/messages/7/react'), 'channel react');
        $this->assertTrue($has('/v1/sessions/sess_1/labels/31/associations'), 'label associate');
        $this->assertTrue($has('/v1/sessions/sess_1/calls/call_1/reject'), 'rejectCall');
        $this->assertTrue($has('/v1/sessions/sess_1/bots'), 'bots');
        $this->assertTrue($has('/v1/media'), 'media list');
        $this->assertTrue($has('/v1/webhooks/wh_1/test'), 'webhook test');
        $this->assertTrue($has('/v1/events/types'), 'event types');
    }
}
