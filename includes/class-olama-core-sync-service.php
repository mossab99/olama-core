<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Core-facing command gateway for external source refreshes.
 *
 * Core owns the contract while an ingestion plugin supplies the handler.
 * Consumers never depend on Oracle Sync functions or credentials directly.
 */
class Olama_Core_Sync_Service {
    public function family_contacts() {
        $result = apply_filters('olama_core_sync_family_contacts', null);
        if (null === $result) {
            return new WP_Error('olama_core_sync_unavailable', __('No family-contact synchronization provider is active.', 'olama-core'));
        }
        return $result;
    }

    public function available($target) {
        return (bool) apply_filters('olama_core_sync_available', false, sanitize_key((string) $target));
    }
}
