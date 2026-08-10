<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CEA_DB {
    private $wpdb;
    private $leads_table;
    private $detail_table;

    public function __construct() {
        global $wpdb;
        $this->wpdb         = $wpdb;
        $this->leads_table  = $wpdb->prefix . 'vxcf_leads';
        $this->detail_table = $wpdb->prefix . 'vxcf_leads_detail';
    }

    public function tables_exist() {
        $lead_exists = $this->wpdb->get_var(
            $this->wpdb->prepare( 'SHOW TABLES LIKE %s', $this->wpdb->esc_like( $this->leads_table ) )
        );
        $detail_exists = $this->wpdb->get_var(
            $this->wpdb->prepare( 'SHOW TABLES LIKE %s', $this->wpdb->esc_like( $this->detail_table ) )
        );

        return $lead_exists === $this->leads_table && $detail_exists === $this->detail_table;
    }

    public function get_forms() {
        $forms  = array();
        $stored = array();

        if ( is_callable( array( 'vxcf_form', 'get_forms' ) ) ) {
            try {
                $stored = vxcf_form::get_forms();
            } catch ( Throwable $error ) {
                $stored = array();
            }
        }

        if ( ! is_array( $stored ) || empty( $stored ) ) {
            $stored = get_option( 'vxcf_all_forms', array() );
        }

        if ( is_array( $stored ) ) {
            foreach ( $stored as $type => $group ) {
                if ( empty( $group['forms'] ) || ! is_array( $group['forms'] ) ) {
                    continue;
                }

                foreach ( $group['forms'] as $id => $label ) {
                    $form_id           = sanitize_text_field( $type . '_' . $id );
                    $forms[ $form_id ] = sanitize_text_field( wp_strip_all_tags( (string) $label ) );
                }
            }
        }

        if ( $this->tables_exist() ) {
            $ids = $this->wpdb->get_col( "SELECT DISTINCT form_id FROM {$this->leads_table} WHERE form_id <> '' ORDER BY form_id ASC" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            foreach ( $ids as $form_id ) {
                if ( ! isset( $forms[ $form_id ] ) ) {
                    $forms[ $form_id ] = $this->fallback_form_label( $form_id );
                }
            }
        }

        natcasesort( $forms );
        return $forms;
    }

    public function get_dashboard( array $filters ) {
        $rows             = $this->get_metric_rows( $filters );
        $previous_filters = $this->build_previous_filters( $filters );
        $previous_rows    = $this->get_metric_rows( $previous_filters );
        $forms            = $this->get_forms();
        $stats            = array( 'total' => 0, 'read' => 0, 'unread' => 0 );
        $form_counts      = array();
        $previous_counts  = array();
        $heatmap          = array_fill( 0, 7, array_fill( 0, 24, 0 ) );

        foreach ( $rows as $row ) {
            $stats['total']++;
            if ( (int) $row['is_read'] === 1 ) {
                $stats['read']++;
            } else {
                $stats['unread']++;
            }

            $form_id = (string) $row['form_id'];
            if ( ! isset( $form_counts[ $form_id ] ) ) {
                $form_counts[ $form_id ] = 0;
            }
            $form_counts[ $form_id ]++;

            $local = $this->utc_mysql_to_local( $row['created'] );
            if ( $local ) {
                $weekday = (int) $local->format( 'N' ) - 1;
                $hour    = (int) $local->format( 'G' );
                $heatmap[ $weekday ][ $hour ]++;
            }
        }

        foreach ( $previous_rows as $row ) {
            $form_id = (string) $row['form_id'];
            if ( ! isset( $previous_counts[ $form_id ] ) ) {
                $previous_counts[ $form_id ] = 0;
            }
            $previous_counts[ $form_id ]++;
        }

        arsort( $form_counts );
        $forms_chart = array();
        foreach ( $form_counts as $form_id => $count ) {
            $forms_chart[] = array(
                'id'    => $form_id,
                'label' => isset( $forms[ $form_id ] ) ? $forms[ $form_id ] : $this->fallback_form_label( $form_id ),
                'count' => (int) $count,
            );
        }

        $timeline = $this->build_timeline( $rows, $filters['start'], $filters['end'], $filters['interval'] );

        return array(
            'stats'          => $stats,
            'timeline'       => $timeline,
            'series'         => $this->build_timeline_series( $rows, $timeline, $forms, $filters ),
            'forms'          => $forms_chart,
            'formCards'      => $this->build_form_comparisons( $forms, $form_counts, $previous_counts, $filters ),
            'previousPeriod' => array(
                'start' => $previous_filters['start'],
                'end'   => $previous_filters['end'],
            ),
            'heatmap'        => $heatmap,
            'interval'       => $filters['interval'],
            'timezone'       => wp_timezone_string(),
        );
    }

    public function get_leads( array $filters, $page = 1, $per_page = 25 ) {
        $page     = max( 1, absint( $page ) );
        $per_page = min( 100, max( 10, absint( $per_page ) ) );
        $offset   = ( $page - 1 ) * $per_page;

        list( $where, $params ) = $this->build_where( $filters, true );

        $count_sql = "SELECT COUNT(*) FROM {$this->leads_table} l {$where}";
        $total     = (int) $this->wpdb->get_var( $this->prepare_query( $count_sql, $params ) );

        $params_with_pagination   = $params;
        $params_with_pagination[] = $per_page;
        $params_with_pagination[] = $offset;

        $sql = "SELECT l.id, l.form_id, l.is_read, l.url, l.created
                FROM {$this->leads_table} l
                {$where}
                ORDER BY l.created DESC, l.id DESC
                LIMIT %d OFFSET %d";

        $leads = $this->wpdb->get_results( $this->prepare_query( $sql, $params_with_pagination ), ARRAY_A );
        $ids   = wp_list_pluck( $leads, 'id' );
        $details = $this->get_details_for_leads( $ids );
        $forms   = $this->get_forms();
        $items   = array();

        foreach ( $leads as $lead ) {
            $lead_id = (int) $lead['id'];
            $fields  = isset( $details[ $lead_id ] ) ? $details[ $lead_id ] : array();
            $contact = $this->extract_contact_fields( $fields );
            $local   = $this->utc_mysql_to_local( $lead['created'] );

            $items[] = array(
                'id'         => $lead_id,
                'formId'     => (string) $lead['form_id'],
                'formName'   => isset( $forms[ $lead['form_id'] ] ) ? $forms[ $lead['form_id'] ] : $this->fallback_form_label( $lead['form_id'] ),
                'isRead'     => (bool) $lead['is_read'],
                'name'       => $contact['name'],
                'email'      => $contact['email'],
                'phone'      => $contact['phone'],
                'created'    => $local ? $local->format( DATE_ATOM ) : '',
                'createdRaw' => sanitize_text_field( (string) $lead['created'] ),
                'url'        => esc_url_raw( (string) $lead['url'] ),
                'crmUrl'     => admin_url( 'admin.php?page=vxcf_leads&tab=entries&id=' . $lead_id ),
            );
        }

        return array(
            'items'      => $items,
            'total'      => $total,
            'page'       => $page,
            'perPage'    => $per_page,
            'totalPages' => max( 1, (int) ceil( $total / $per_page ) ),
        );
    }

    public function get_lead( $lead_id ) {
        $lead_id = absint( $lead_id );
        if ( ! $lead_id ) {
            return null;
        }

        $sql  = "SELECT id, form_id, is_read, url, ip, browser, os, created FROM {$this->leads_table} WHERE id = %d LIMIT 1";
        $lead = $this->wpdb->get_row( $this->wpdb->prepare( $sql, $lead_id ), ARRAY_A );
        if ( ! $lead ) {
            return null;
        }

        $details = $this->get_details_for_leads( array( $lead_id ) );
        $fields  = isset( $details[ $lead_id ] ) ? $details[ $lead_id ] : array();
        $labels  = $this->get_field_labels( $lead['form_id'] );
        $output  = array();

        foreach ( $fields as $field_name => $value ) {
            $output[] = array(
                'name'  => sanitize_text_field( (string) $field_name ),
                'label' => isset( $labels[ $field_name ] ) ? $labels[ $field_name ] : $this->humanize_key( $field_name ),
                'value' => $this->normalize_value( $value ),
            );
        }

        $forms = $this->get_forms();
        $local = $this->utc_mysql_to_local( $lead['created'] );

        return array(
            'id'       => (int) $lead['id'],
            'formId'   => (string) $lead['form_id'],
            'formName' => isset( $forms[ $lead['form_id'] ] ) ? $forms[ $lead['form_id'] ] : $this->fallback_form_label( $lead['form_id'] ),
            'isRead'   => (bool) $lead['is_read'],
            'created'  => $local ? $local->format( DATE_ATOM ) : '',
            'url'      => esc_url_raw( (string) $lead['url'] ),
            'ip'       => sanitize_text_field( (string) $lead['ip'] ),
            'browser'  => sanitize_text_field( (string) $lead['browser'] ),
            'os'       => sanitize_text_field( (string) $lead['os'] ),
            'crmUrl'   => admin_url( 'admin.php?page=vxcf_leads&tab=entries&id=' . $lead_id ),
            'fields'   => $output,
        );
    }

    private function get_metric_rows( array $filters ) {
        list( $where, $params ) = $this->build_where( $filters, true );
        $sql = "SELECT l.id, l.form_id, l.is_read, l.created FROM {$this->leads_table} l {$where} ORDER BY l.created ASC";
        return $this->wpdb->get_results( $this->prepare_query( $sql, $params ), ARRAY_A );
    }

    private function build_where( array $filters, $include_search ) {
        $conditions = array( 'l.status = 0' );
        $params     = array();

        if ( ! empty( $filters['start_utc'] ) ) {
            $conditions[] = 'l.created >= %s';
            $params[]     = $filters['start_utc'];
        }

        if ( ! empty( $filters['end_utc'] ) ) {
            $conditions[] = 'l.created <= %s';
            $params[]     = $filters['end_utc'];
        }

        if ( ! empty( $filters['form'] ) ) {
            $conditions[] = 'l.form_id = %s';
            $params[]     = $filters['form'];
        }

        if ( 'read' === $filters['read_status'] ) {
            $conditions[] = 'l.is_read = 1';
        } elseif ( 'unread' === $filters['read_status'] ) {
            $conditions[] = 'l.is_read = 0';
        }

        if ( $include_search && ! empty( $filters['search'] ) ) {
            $like         = '%' . $this->wpdb->esc_like( $filters['search'] ) . '%';
            $conditions[] = "EXISTS (
                SELECT 1 FROM {$this->detail_table} d
                WHERE d.lead_id = l.id AND (d.value LIKE %s OR d.name LIKE %s)
            )";
            $params[] = $like;
            $params[] = $like;
        }

        return array( 'WHERE ' . implode( ' AND ', $conditions ), $params );
    }

    private function get_details_for_leads( array $lead_ids ) {
        $lead_ids = array_values( array_filter( array_map( 'absint', $lead_ids ) ) );
        if ( empty( $lead_ids ) ) {
            return array();
        }

        $placeholders = implode( ',', array_fill( 0, count( $lead_ids ), '%d' ) );
        $sql          = "SELECT lead_id, name, value FROM {$this->detail_table} WHERE lead_id IN ({$placeholders}) ORDER BY id ASC";
        $rows         = $this->wpdb->get_results( $this->wpdb->prepare( $sql, $lead_ids ), ARRAY_A );
        $grouped      = array();

        foreach ( $rows as $row ) {
            $lead_id = (int) $row['lead_id'];
            if ( ! isset( $grouped[ $lead_id ] ) ) {
                $grouped[ $lead_id ] = array();
            }
            $grouped[ $lead_id ][ (string) $row['name'] ] = $this->safe_unserialize( $row['value'] );
        }

        return $grouped;
    }

    private function extract_contact_fields( array $fields ) {
        $email = '';
        $phone = '';
        $names = array();

        foreach ( $fields as $key => $raw_value ) {
            $value = $this->normalize_value( $raw_value );
            $key_l = strtolower( remove_accents( (string) $key ) );

            if ( '' === $email && ( false !== strpos( $key_l, 'email' ) || false !== strpos( $key_l, 'correo' ) || is_email( $value ) ) ) {
                $candidate = sanitize_email( $value );
                if ( is_email( $candidate ) ) {
                    $email = $candidate;
                    continue;
                }
            }

            if ( '' === $phone && preg_match( '/(phone|telefono|tel|movil|mobile)/', $key_l ) ) {
                $phone = sanitize_text_field( $value );
                continue;
            }

            if ( preg_match( '/(^|[-_])(name|nombre|firstname|first-name|first_name|lastname|last-name|last_name)([-_]|$)/', $key_l )
                && false === strpos( $key_l, 'company' )
                && false === strpos( $key_l, 'empresa' ) ) {
                $clean_name = sanitize_text_field( $value );
                if ( '' !== $clean_name && ! is_email( $clean_name ) ) {
                    $names[] = $clean_name;
                }
            }
        }

        $name = trim( implode( ' ', array_unique( $names ) ) );

        return array(
            'name'  => $name,
            'email' => $email,
            'phone' => $phone,
        );
    }

    private function get_field_labels( $form_id ) {
        $fields = array();
        $labels = array();

        if ( is_callable( array( 'vxcf_form', 'get_form_fields' ) ) ) {
            try {
                $fields = vxcf_form::get_form_fields( $form_id );
            } catch ( Throwable $error ) {
                $fields = array();
            }
        }

        if ( ! is_array( $fields ) || empty( $fields ) ) {
            $stored = get_option( 'vxcf_all_fields', array() );
            $parts  = explode( '_', (string) $form_id, 2 );
            $type   = isset( $parts[0] ) ? $parts[0] : '';
            $id     = isset( $parts[1] ) ? $parts[1] : '';

            if ( $type && $id && isset( $stored[ $type ]['fields'][ $id ] ) && is_array( $stored[ $type ]['fields'][ $id ] ) ) {
                $fields = $stored[ $type ]['fields'][ $id ];
            }
        }

        if ( is_array( $fields ) ) {
            foreach ( $fields as $key => $field ) {
                if ( ! is_array( $field ) ) {
                    continue;
                }

                $field_key = isset( $field['name'] ) ? $field['name'] : ( isset( $field['id'] ) ? $field['id'] : $key );
                $label     = isset( $field['label'] ) ? $field['label'] : ( isset( $field['name'] ) ? $field['name'] : $field_key );

                if ( '' !== (string) $field_key ) {
                    $labels[ (string) $field_key ] = sanitize_text_field( wp_strip_all_tags( (string) $label ) );
                }
            }
        }

        return $labels;
    }

    private function build_timeline( array $rows, $start, $end, $interval ) {
        $timezone = wp_timezone();
        $start_dt = new DateTimeImmutable( $start . ' 00:00:00', $timezone );
        $end_dt   = new DateTimeImmutable( $end . ' 23:59:59', $timezone );
        $buckets  = array();

        if ( 'month' === $interval ) {
            $cursor = $start_dt->modify( 'first day of this month' );
            $last   = $end_dt->modify( 'first day of this month' );
            while ( $cursor <= $last ) {
                $key             = $cursor->format( 'Y-m' );
                $buckets[ $key ] = array( 'key' => $key, 'label' => wp_date( 'M Y', $cursor->getTimestamp(), $timezone ), 'count' => 0 );
                $cursor          = $cursor->modify( '+1 month' );
            }
        } elseif ( 'week' === $interval ) {
            $cursor = $start_dt->modify( 'monday this week' );
            $last   = $end_dt->modify( 'monday this week' );
            while ( $cursor <= $last ) {
                $key             = $cursor->format( 'o-\WW' );
                $buckets[ $key ] = array( 'key' => $key, 'label' => sprintf( 'Sem. %s', wp_date( 'd M', $cursor->getTimestamp(), $timezone ) ), 'count' => 0 );
                $cursor          = $cursor->modify( '+1 week' );
            }
        } else {
            $cursor = $start_dt;
            while ( $cursor->format( 'Y-m-d' ) <= $end_dt->format( 'Y-m-d' ) ) {
                $key             = $cursor->format( 'Y-m-d' );
                $buckets[ $key ] = array( 'key' => $key, 'label' => wp_date( 'd M', $cursor->getTimestamp(), $timezone ), 'count' => 0 );
                $cursor          = $cursor->modify( '+1 day' );
            }
        }

        foreach ( $rows as $row ) {
            $local = $this->utc_mysql_to_local( $row['created'] );
            if ( ! $local ) {
                continue;
            }

            if ( 'month' === $interval ) {
                $key = $local->format( 'Y-m' );
            } elseif ( 'week' === $interval ) {
                $key = $local->format( 'o-\WW' );
            } else {
                $key = $local->format( 'Y-m-d' );
            }

            if ( isset( $buckets[ $key ] ) ) {
                $buckets[ $key ]['count']++;
            }
        }

        return array_values( $buckets );
    }

    private function build_timeline_series( array $rows, array $timeline, array $forms, array $filters ) {
        $selected_form = isset( $filters['form'] ) ? (string) $filters['form'] : '';

        if ( $selected_form ) {
            $forms = array(
                $selected_form => isset( $forms[ $selected_form ] ) ? $forms[ $selected_form ] : $this->fallback_form_label( $selected_form ),
            );
        }

        foreach ( $rows as $row ) {
            $form_id = (string) $row['form_id'];
            if ( ! isset( $forms[ $form_id ] ) && ( ! $selected_form || $selected_form === $form_id ) ) {
                $forms[ $form_id ] = $this->fallback_form_label( $form_id );
            }
        }

        $bucket_indexes = array();
        foreach ( $timeline as $index => $bucket ) {
            $bucket_indexes[ $bucket['key'] ] = $index;
        }

        $form_data = array();
        foreach ( $forms as $form_id => $label ) {
            $form_data[ $form_id ] = array_fill( 0, count( $timeline ), 0 );
        }

        foreach ( $rows as $row ) {
            $form_id = (string) $row['form_id'];
            $key     = $this->get_timeline_key( $row['created'], $filters['interval'] );

            if ( null !== $key && isset( $bucket_indexes[ $key ], $form_data[ $form_id ] ) ) {
                $form_data[ $form_id ][ $bucket_indexes[ $key ] ]++;
            }
        }

        $series = array(
            array(
                'id'    => 'total',
                'label' => __( 'Total', 'crm-entries-analytics' ),
                'data'  => array_map( 'intval', wp_list_pluck( $timeline, 'count' ) ),
            ),
        );

        foreach ( $forms as $form_id => $label ) {
            $series[] = array(
                'id'    => (string) $form_id,
                'label' => (string) $label,
                'data'  => array_map( 'intval', $form_data[ $form_id ] ),
            );
        }

        return $series;
    }

    private function build_form_comparisons( array $forms, array $current_counts, array $previous_counts, array $filters ) {
        $selected_form = isset( $filters['form'] ) ? (string) $filters['form'] : '';

        foreach ( array_unique( array_merge( array_keys( $current_counts ), array_keys( $previous_counts ) ) ) as $form_id ) {
            if ( ! isset( $forms[ $form_id ] ) ) {
                $forms[ $form_id ] = $this->fallback_form_label( $form_id );
            }
        }

        if ( $selected_form ) {
            $forms = array(
                $selected_form => isset( $forms[ $selected_form ] ) ? $forms[ $selected_form ] : $this->fallback_form_label( $selected_form ),
            );
        }

        $cards = array();
        foreach ( $forms as $form_id => $label ) {
            $current  = isset( $current_counts[ $form_id ] ) ? (int) $current_counts[ $form_id ] : 0;
            $previous = isset( $previous_counts[ $form_id ] ) ? (int) $previous_counts[ $form_id ] : 0;

            $cards[] = array(
                'id'            => (string) $form_id,
                'label'         => (string) $label,
                'current'       => $current,
                'previous'      => $previous,
                'difference'    => $current - $previous,
                'changePercent' => $previous > 0 ? round( ( ( $current - $previous ) / $previous ) * 100, 1 ) : null,
            );
        }

        usort(
            $cards,
            static function ( $first, $second ) {
                if ( $first['current'] !== $second['current'] ) {
                    return $second['current'] <=> $first['current'];
                }

                if ( $first['previous'] !== $second['previous'] ) {
                    return $second['previous'] <=> $first['previous'];
                }

                return strnatcasecmp( $first['label'], $second['label'] );
            }
        );

        return $cards;
    }

    private function build_previous_filters( array $filters ) {
        $timezone     = wp_timezone();
        $current_from = new DateTimeImmutable( $filters['start'] . ' 00:00:00', $timezone );
        $current_to   = new DateTimeImmutable( $filters['end'] . ' 23:59:59', $timezone );
        $days         = (int) $current_from->diff( $current_to )->format( '%a' ) + 1;
        $previous_to  = $current_from->modify( '-1 day' )->setTime( 23, 59, 59 );
        $previous_from = $previous_to->modify( '-' . ( $days - 1 ) . ' days' )->setTime( 0, 0, 0 );
        $previous      = $filters;

        $previous['start']     = $previous_from->format( 'Y-m-d' );
        $previous['end']       = $previous_to->format( 'Y-m-d' );
        $previous['start_utc'] = $previous_from->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
        $previous['end_utc']   = $previous_to->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );

        return $previous;
    }

    private function get_timeline_key( $created, $interval ) {
        $local = $this->utc_mysql_to_local( $created );
        if ( ! $local ) {
            return null;
        }

        if ( 'month' === $interval ) {
            return $local->format( 'Y-m' );
        }

        if ( 'week' === $interval ) {
            return $local->format( 'o-\WW' );
        }

        return $local->format( 'Y-m-d' );
    }

    private function utc_mysql_to_local( $mysql_date ) {
        if ( empty( $mysql_date ) ) {
            return null;
        }

        try {
            $utc = new DateTimeImmutable( $mysql_date, new DateTimeZone( 'UTC' ) );
            return $utc->setTimezone( wp_timezone() );
        } catch ( Exception $e ) {
            return null;
        }
    }

    private function normalize_value( $value ) {
        $value = $this->safe_unserialize( $value );
        if ( is_array( $value ) ) {
            $value = implode( ', ', array_map( array( $this, 'normalize_value' ), $value ) );
        } elseif ( is_object( $value ) ) {
            $value = wp_json_encode( $value );
        }

        return sanitize_textarea_field( wp_strip_all_tags( (string) $value ) );
    }

    /**
     * Decodes arrays saved by CRM Entries without instantiating submitted objects.
     *
     * Form values are untrusted input. WordPress' maybe_unserialize() allows PHP
     * objects and can execute magic methods from classes provided by other plugins.
     *
     * @param mixed $value Stored field value.
     * @return mixed
     */
    private function safe_unserialize( $value ) {
        if ( ! is_string( $value ) || ! is_serialized( $value ) ) {
            return $value;
        }

        $value   = trim( $value );
        $decoded = @unserialize( // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize
            $value,
            array(
                'allowed_classes' => false,
                'max_depth'       => 32,
            )
        );

        if ( false === $decoded && 'b:0;' !== $value ) {
            return $value;
        }

        return $decoded;
    }

    private function fallback_form_label( $form_id ) {
        return ucwords( str_replace( array( '_', '-' ), ' ', (string) $form_id ) );
    }

    private function humanize_key( $key ) {
        return ucwords( str_replace( array( '_', '-' ), ' ', (string) $key ) );
    }

    private function prepare_query( $sql, array $params ) {
        if ( empty( $params ) ) {
            return $sql;
        }

        return $this->wpdb->prepare( $sql, $params );
    }
}
