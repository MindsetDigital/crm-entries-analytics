<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CEA_REST {
    const MAX_RANGE_DAYS = 3660;

    private $db;
    private $namespace = 'crm-entries-analytics/v1';

    public function __construct( CEA_DB $db ) {
        $this->db = $db;
        add_action( 'rest_api_init', array( $this, 'register_routes' ) );
    }

    public function register_routes() {
        register_rest_route(
            $this->namespace,
            '/filters',
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array( $this, 'filters' ),
                'permission_callback' => array( $this, 'permissions' ),
            )
        );

        register_rest_route(
            $this->namespace,
            '/dashboard',
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array( $this, 'dashboard' ),
                'permission_callback' => array( $this, 'permissions' ),
            )
        );

        register_rest_route(
            $this->namespace,
            '/leads',
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array( $this, 'leads' ),
                'permission_callback' => array( $this, 'permissions' ),
            )
        );

        register_rest_route(
            $this->namespace,
            '/lead/(?P<id>\d+)',
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array( $this, 'lead' ),
                'permission_callback' => array( $this, 'permissions' ),
                'args'                => array(
                    'id' => array(
                        'validate_callback' => static function ( $value ) {
                            return absint( $value ) > 0;
                        },
                    ),
                ),
            )
        );
    }

    public function permissions() {
        return current_user_can( CEA_CAPABILITY )
            || current_user_can( 'vxcf_leads_read_entries' )
            || current_user_can( 'manage_options' );
    }

    public function filters() {
        $error = $this->dependency_error();
        if ( $error ) {
            return $error;
        }

        $timezone = wp_timezone();
        $today    = new DateTimeImmutable( 'now', $timezone );

        return rest_ensure_response(
            array(
                'forms' => $this->format_forms( $this->db->get_forms() ),
                'defaults' => array(
                    'start' => $today->modify( '-29 days' )->format( 'Y-m-d' ),
                    'end'   => $today->format( 'Y-m-d' ),
                ),
                'timezone' => wp_timezone_string(),
            )
        );
    }

    public function dashboard( WP_REST_Request $request ) {
        $error = $this->dependency_error();
        if ( $error ) {
            return $error;
        }

        $filters = $this->parse_filters( $request );
        if ( is_wp_error( $filters ) ) {
            return $filters;
        }

        return rest_ensure_response( $this->db->get_dashboard( $filters ) );
    }

    public function leads( WP_REST_Request $request ) {
        $error = $this->dependency_error();
        if ( $error ) {
            return $error;
        }

        $filters = $this->parse_filters( $request );
        if ( is_wp_error( $filters ) ) {
            return $filters;
        }

        $page     = max( 1, absint( $request->get_param( 'page' ) ) );
        $per_page = min( 100, max( 10, absint( $request->get_param( 'per_page' ) ?: 25 ) ) );

        return rest_ensure_response( $this->db->get_leads( $filters, $page, $per_page ) );
    }

    public function lead( WP_REST_Request $request ) {
        $error = $this->dependency_error();
        if ( $error ) {
            return $error;
        }

        $lead = $this->db->get_lead( $request->get_param( 'id' ) );
        if ( ! $lead ) {
            return new WP_Error( 'cea_not_found', __( 'No se ha encontrado el lead.', 'crm-entries-analytics' ), array( 'status' => 404 ) );
        }

        return rest_ensure_response( $lead );
    }

    private function parse_filters( WP_REST_Request $request ) {
        $timezone    = wp_timezone();
        $today       = new DateTimeImmutable( 'now', $timezone );
        $start       = sanitize_text_field( (string) $request->get_param( 'start' ) );
        $end         = sanitize_text_field( (string) $request->get_param( 'end' ) );
        $start_local = $this->parse_date( $start, $timezone );
        $end_local   = $this->parse_date( $end, $timezone );

        if ( ( '' !== $start && ! $start_local ) || ( '' !== $end && ! $end_local ) ) {
            return new WP_Error(
                'cea_invalid_date',
                __( 'Las fechas deben ser fechas reales con el formato AAAA-MM-DD.', 'crm-entries-analytics' ),
                array( 'status' => 400 )
            );
        }

        if ( ! $start_local ) {
            $start_local = $today->modify( '-29 days' )->setTime( 0, 0, 0 );
        }
        if ( ! $end_local ) {
            $end_local = $today->setTime( 23, 59, 59 );
        }

        if ( $start_local > $end_local ) {
            $start_temp  = $start_local;
            $start_local = $end_local->setTime( 0, 0, 0 );
            $end_local   = $start_temp->setTime( 23, 59, 59 );
        }

        $start       = $start_local->format( 'Y-m-d' );
        $end         = $end_local->format( 'Y-m-d' );
        $start_local = $start_local->setTime( 0, 0, 0 );
        $end_local   = $end_local->setTime( 23, 59, 59 );
        $days        = (int) $start_local->diff( $end_local )->format( '%a' ) + 1;

        if ( $days > self::MAX_RANGE_DAYS ) {
            return new WP_Error(
                'cea_date_range_too_large',
                __( 'El rango de fechas no puede superar los 10 años.', 'crm-entries-analytics' ),
                array( 'status' => 400 )
            );
        }

        $interval = sanitize_key( (string) $request->get_param( 'interval' ) );

        if ( ! in_array( $interval, array( 'day', 'week', 'month' ), true ) ) {
            $interval = $days <= 45 ? 'day' : ( $days <= 240 ? 'week' : 'month' );
        }

        $read_status = sanitize_key( (string) $request->get_param( 'read_status' ) );
        if ( ! in_array( $read_status, array( 'all', 'read', 'unread' ), true ) ) {
            $read_status = 'all';
        }

        return array(
            'start'       => $start,
            'end'         => $end,
            'start_utc'   => $start_local->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ),
            'end_utc'     => $end_local->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ),
            'form'        => sanitize_text_field( (string) $request->get_param( 'form' ) ),
            'read_status' => $read_status,
            'search'      => sanitize_text_field( (string) $request->get_param( 'search' ) ),
            'interval'    => $interval,
        );
    }

    private function parse_date( $date, DateTimeZone $timezone ) {
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
            return null;
        }

        $parsed = DateTimeImmutable::createFromFormat( '!Y-m-d', $date, $timezone );
        $errors = DateTimeImmutable::getLastErrors();

        if ( ! $parsed || ( is_array( $errors ) && ( $errors['warning_count'] > 0 || $errors['error_count'] > 0 ) ) ) {
            return null;
        }

        return $parsed->format( 'Y-m-d' ) === $date ? $parsed : null;
    }

    private function format_forms( array $forms ) {
        $output = array();
        foreach ( $forms as $id => $label ) {
            $output[] = array( 'id' => $id, 'label' => $label );
        }
        return $output;
    }

    private function dependency_error() {
        if ( ! $this->db->tables_exist() ) {
            return new WP_Error(
                'cea_missing_tables',
                __( 'No se encuentran las tablas de Contact Form Entries. Comprueba que el plugin esté activo y haya creado sus tablas.', 'crm-entries-analytics' ),
                array( 'status' => 503 )
            );
        }

        return null;
    }
}
