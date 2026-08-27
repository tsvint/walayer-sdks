<?php

declare(strict_types=1);

namespace WALayer;

/**
 * The 17 message types the unified send route accepts
 * (docs/04-api-spec.md §5.2). Constants rather than an enum so a caller can
 * still pass a type this SDK release has not heard of — the server, not the
 * client, is the authority on what is sendable.
 *
 * `BUTTONS` and `LIST` are gated by a per-engine feature flag; sending one to a
 * session whose engine lacks support answers `422 UNSUPPORTED_FOR_ENGINE`.
 */
final class MessageType
{
    public const TEXT = 'text';
    public const IMAGE = 'image';
    public const VIDEO = 'video';
    public const AUDIO = 'audio';
    public const DOCUMENT = 'document';
    public const STICKER = 'sticker';
    public const LOCATION = 'location';
    public const CONTACT = 'contact';
    public const REACTION = 'reaction';
    public const POLL = 'poll';
    public const BUTTONS = 'buttons';
    public const LIST = 'list';
    public const REPLY = 'reply';
    public const FORWARD = 'forward';
    public const REVOKE = 'revoke';
    public const EDIT = 'edit';
    public const PRESENCE = 'presence';

    /** @return list<string> every documented type, in spec order */
    public static function all(): array
    {
        return [
            self::TEXT, self::IMAGE, self::VIDEO, self::AUDIO, self::DOCUMENT,
            self::STICKER, self::LOCATION, self::CONTACT, self::REACTION, self::POLL,
            self::BUTTONS, self::LIST, self::REPLY, self::FORWARD, self::REVOKE,
            self::EDIT, self::PRESENCE,
        ];
    }

    private function __construct()
    {
    }
}
