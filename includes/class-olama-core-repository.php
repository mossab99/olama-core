<?php

if (!defined('ABSPATH')) {
    exit;
}

class Olama_Core_Repository {
    public function table($name) {
        global $wpdb;

        return $wpdb->prefix . $name;
    }

    public function get_row($table, $where) {
        global $wpdb;

        $clauses = array();
        $values = array();
        foreach ($where as $column => $value) {
            $clauses[] = "`{$column}` = %s";
            $values[] = $value;
        }

        return $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM `' . esc_sql($table) . '` WHERE ' . implode(' AND ', $clauses) . ' LIMIT 1',
            $values
        ), ARRAY_A);
    }

    public function rows($table, $args = array()) {
        global $wpdb;

        $limit = isset($args['limit']) ? max(1, min(500, absint($args['limit']))) : 50;
        $offset = isset($args['offset']) ? max(0, absint($args['offset'])) : 0;
        $orderby = !empty($args['orderby']) ? preg_replace('/[^a-zA-Z0-9_\.]/', '', $args['orderby']) : 'id';
        $order = !empty($args['order']) && strtoupper($args['order']) === 'ASC' ? 'ASC' : 'DESC';

        return $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM `' . esc_sql($table) . "` ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d",
            $limit,
            $offset
        ), ARRAY_A);
    }

    public function count($table, $where = array()) {
        global $wpdb;

        if (!$where) {
            return (int) $wpdb->get_var('SELECT COUNT(*) FROM `' . esc_sql($table) . '`');
        }

        $clauses = array();
        $values = array();
        foreach ($where as $column => $value) {
            $clauses[] = "`{$column}` = %s";
            $values[] = $value;
        }

        return (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM `' . esc_sql($table) . '` WHERE ' . implode(' AND ', $clauses),
            $values
        ));
    }

    public function insert($table, $data) {
        global $wpdb;

        $wpdb->insert($table, $data);

        return $wpdb->insert_id;
    }

    public function update($table, $data, $where) {
        global $wpdb;

        return $wpdb->update($table, $data, $where);
    }

    public function search($table, $columns, $term, $args = array()) {
        global $wpdb;

        $limit = isset($args['limit']) ? max(1, min(500, absint($args['limit']))) : 50;
        $offset = isset($args['offset']) ? max(0, absint($args['offset'])) : 0;
        $like = '%' . $wpdb->esc_like($term) . '%';
        $clauses = array();
        $values = array();

        foreach ($columns as $column) {
            $clauses[] = "`{$column}` LIKE %s";
            $values[] = $like;
        }

        $values[] = $limit;
        $values[] = $offset;

        return $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM `' . esc_sql($table) . '` WHERE (' . implode(' OR ', $clauses) . ') ORDER BY id DESC LIMIT %d OFFSET %d',
            $values
        ), ARRAY_A);
    }
}
