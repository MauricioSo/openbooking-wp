<?php


declare( strict_types=1 );
namespace OpenBooking\Application\Audit\Service;

use OpenBooking\Domain\Shared\Port\ActorContextInterface;
use OpenBooking\Domain\Shared\Port\SanitizerInterface;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Audit_Logger {


    public function __construct(
        private \OpenBooking\Domain\Audit\Repository\AuditRepositoryInterface $repository,
        private ?SanitizerInterface $sanitizer = null,
        private ?ActorContextInterface $actor_context = null,
    ) {
$this->sanitizer = $sanitizer ?? new \OpenBooking\Infrastructure\WordPress\Adapter\WP_Sanitizer();        $this->actor_context = $actor_context ?? new \OpenBooking\Infrastructure\WordPress\Adapter\WP_ActorContext();
    }

    public function log( array $event ): int {
        $event = \OpenBooking\Support\Request_Context::enrich_log_entry( $event );

        $context        = is_array( $event['context'] ?? null ) ? $event['context'] : [];
        $changed_fields = is_array( $event['changed_fields'] ?? null ) ? $event['changed_fields'] : [];
        $meta           = is_array( $event['meta'] ?? null ) ? $event['meta'] : [];

        return $this->repository->insert( [
            'entity_type'    => $this->sanitizer->text( $event['entity_type'] ?? '' ),
            'entity_id'      => $this->sanitizer->absint( $event['entity_id'] ?? 0 ),
            'action'         => $this->sanitizer->text( $event['action'] ?? '' ),
            'actor_type'     => $this->sanitizer->text( $event['actor_type'] ?? $this->detect_actor_type() ),
            'actor_id'       => isset( $event['actor_id'] ) ? $this->sanitizer->absint( $event['actor_id'] ) : $this->detect_actor_id(),
            'message'        => $this->sanitizer->textarea( $event['message'] ?? '' ),
            'context'        => $context,
            'request_id'     => $this->sanitizer->text( $event['request_id'] ?? '' ),
            'severity'       => $this->sanitizer->text( $event['severity'] ?? 'info' ),
            'source'         => $this->sanitizer->text( $event['source'] ?? 'system' ),
            'ip_address'     => $event['ip_address'] ?? null,
            'user_agent'     => $event['user_agent'] ?? \OpenBooking\Support\Request_Context::get_user_agent(),
            'route'          => $this->sanitizer->text( $event['route'] ?? '' ),
            'http_method'    => $this->sanitizer->text( $event['http_method'] ?? '' ),
            'changed_fields' => $changed_fields,
            'meta'           => $meta,
        ] );
    }

    public function log_entity_change( string $entity_type, int $entity_id, string $action, array $before, array $after, array $extra_context = [], array $args = [] ): int {
        $diff = $this->diff( $before, $after, $args['allowed_fields'] ?? [], $args['redacted_fields'] ?? [] );

        return $this->log( [
            'entity_type'    => $entity_type,
            'entity_id'      => $entity_id,
            'action'         => $action,
            'message'        => $args['message'] ?? '',
            'context'        => $extra_context,
            'changed_fields' => $diff,
            'severity'       => $args['severity'] ?? 'info',
            'source'         => $args['source'] ?? \OpenBooking\Support\Request_Context::get_source(),
            'actor_type'     => $args['actor_type'] ?? $this->detect_actor_type(),
            'actor_id'       => $args['actor_id'] ?? $this->detect_actor_id(),
            'meta'           => $args['meta'] ?? [],
        ] );
    }

    public function diff( array $before, array $after, array $allowed_fields = [], array $redacted_fields = [] ): array {
        $fields = ! empty( $allowed_fields ) ? $allowed_fields : array_unique( array_merge( array_keys( $before ), array_keys( $after ) ) );
        $changes = [];

        foreach ( $fields as $field ) {
            $old = $before[ $field ] ?? null;
            $new = $after[ $field ] ?? null;

            if ( $old === $new ) {
                continue;
            }

            if ( in_array( $field, $redacted_fields, true ) ) {
                $changes[ $field ] = [
                    'old' => $this->mask_secret_value( $old ),
                    'new' => $this->mask_secret_value( $new ),
                ];
                continue;
            }

            $changes[ $field ] = [
                'old' => $old,
                'new' => $new,
            ];
        }

        return $changes;
    }

    public function mask_secret_value( $value ): string {
        if ( null === $value || '' === $value ) {
            return '[missing]';
        }

        return '[configured]';
    }

    private function detect_actor_type(): string {
        return $this->actor_context->is_user_logged_in() && $this->actor_context->current_user_can( 'manage_options' ) ? 'admin' : 'customer';
    }

    private function detect_actor_id(): ?int {
        $user_id = $this->actor_context->get_current_user_id();
        return $user_id > 0 ? $user_id : null;
    }
}
