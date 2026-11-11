<?php

declare(strict_types=1);

namespace Nucleo\Abilities\Read;

defined('ABSPATH') || exit;

use Nucleo\Abilities\AbilityDefinition;
use Nucleo\OAuth\Server;
use Nucleo\Storage\AuditLog;

/** nucleo/audit-list: ultime scritture eseguite dalle ability, dalla più recente. */
final class AuditList implements AbilityDefinition
{
    private const DEFAULT_LIMIT = 50;
    private const MAX_LIMIT = 200;

    public function name(): string
    {
        return 'nucleo/audit-list';
    }

    public function args(): array
    {
        return [
            'label' => __('AI activity', 'nucleo'),
            'description' => 'Latest writes performed by the abilities, with ability, outcome and summary.',
            'input_schema' => [
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => [
                    'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_LIMIT, 'default' => self::DEFAULT_LIMIT],
                ],
            ],
            'output_schema' => [
                'type' => 'object',
                'required' => ['ok', 'entries'],
                'properties' => [
                    'ok' => ['type' => 'boolean'],
                    'entries' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'required' => ['id', 'time', 'user_id', 'client_id', 'ability', 'post_id', 'ok', 'summary', 'user_name', 'client_name', 'post_title'],
                            'properties' => [
                                'id' => ['type' => 'integer', 'minimum' => 1],
                                'time' => ['type' => 'integer'],
                                'user_id' => ['type' => 'integer'],
                                'client_id' => ['type' => ['string', 'null']],
                                'ability' => ['type' => 'string'],
                                'post_id' => ['type' => 'integer'],
                                'ok' => ['type' => 'boolean'],
                                'summary' => ['type' => 'string'],
                                'user_name' => ['type' => 'string', 'description' => 'Display name of user_id, "" if unknown.'],
                                'client_name' => ['type' => 'string', 'description' => 'Name of the OAuth client, "" if none or unknown.'],
                                'post_title' => ['type' => 'string', 'description' => 'Title of post_id, "" if post_id is 0.'],
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

        // Senza edit_others_posts si vedono solo le proprie voci.
        $ownOnly = !current_user_can('edit_others_posts');
        $currentId = get_current_user_id();

        $clients = [];
        $entries = [];
        foreach (AuditLog::recent($limit) as $entry) {
            if ($ownOnly && (int) $entry['user_id'] !== $currentId) {
                continue;
            }

            $user = get_userdata($entry['user_id']);

            $clientName = '';
            $clientId = $entry['client_id'];
            if (is_string($clientId) && $clientId !== '') {
                if (!array_key_exists($clientId, $clients)) {
                    $client = (new Server())->findClient($clientId);
                    $clients[$clientId] = $client['client_name'] ?? '';
                }
                $clientName = $clients[$clientId];
            }

            $entries[] = $entry + [
                'user_name' => $user instanceof \WP_User ? $user->display_name : '',
                'client_name' => $clientName,
                'post_title' => $entry['post_id'] > 0 ? get_the_title($entry['post_id']) : '',
            ];
        }

        return ['ok' => true, 'entries' => $entries];
    }

    public function permitted(array $input): bool
    {
        return current_user_can('edit_posts');
    }
}
