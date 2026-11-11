<?php

declare(strict_types=1);

namespace Nucleo\Abilities\Read;

defined('ABSPATH') || exit;

use Nucleo\Abilities\AbilityDefinition;
use Nucleo\Forms\DevMail;

/** nucleo/mail-log: email del registro di sviluppo (local e development), dalla più recente. */
final class MailLog implements AbilityDefinition
{
    private const DEFAULT_LIMIT = 20;
    private const MAX_LIMIT = 20;

    public function name(): string
    {
        return 'nucleo/mail-log';
    }

    public function args(): array
    {
        return [
            'label' => __('Mail log', 'nucleo'),
            'description' => 'Emails of Nucleo (one-time codes, form emails) written to the development mail log instead of being sent: on local sites, or on development sites with NUCLEO_DEV_MAIL true; never on staging or production. Other WordPress emails are not logged. Newest first; empty when the log is off. Entries older than 24 hours are dropped. body is cut to 5000 characters.',
            'input_schema' => [
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => [
                    'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_LIMIT, 'default' => self::DEFAULT_LIMIT],
                ],
            ],
            'output_schema' => [
                'type' => 'object',
                'required' => ['ok', 'active', 'entries'],
                'properties' => [
                    'ok' => ['type' => 'boolean'],
                    'active' => ['type' => 'boolean'],
                    'entries' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'required' => ['time', 'to', 'subject', 'body'],
                            'properties' => [
                                'time' => ['type' => 'integer'],
                                'to' => ['type' => 'array', 'items' => ['type' => 'string']],
                                'subject' => ['type' => 'string'],
                                'body' => ['type' => 'string'],
                            ],
                        ],
                    ],
                ],
            ],
            'meta' => [
                'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
            ],
        ];
    }

    /** @return array<string,mixed> */
    public function execute(array $input): array
    {
        $limit = is_int($input['limit'] ?? null) ? $input['limit'] : self::DEFAULT_LIMIT;
        $limit = max(1, min(self::MAX_LIMIT, $limit));

        return [
            'ok' => true,
            'active' => DevMail::active(),
            'entries' => array_reverse(DevMail::entries($limit)),
        ];
    }

    public function permitted(array $input): bool
    {
        return current_user_can('manage_options');
    }
}
