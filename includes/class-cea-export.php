<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CEA_Export {
    const PAGE       = 'crm-entries-export';
    const ACTION     = 'cea_export_csv';
    const PREFS_META = 'cea_export_prefs';
    const BATCH_SIZE = 500;

    private $db;

    public function __construct( CEA_DB $db ) {
        $this->db = $db;
        add_action( 'admin_menu', array( $this, 'menu' ), 99 );
        add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
        add_action( 'admin_post_' . self::ACTION, array( $this, 'download' ) );
    }

    public static function separators() {
        return array(
            'semicolon' => array( 'char' => ';', 'label' => __( 'Punto y coma ( ; )', 'crm-entries-analytics' ) ),
            'comma'     => array( 'char' => ',', 'label' => __( 'Coma ( , )', 'crm-entries-analytics' ) ),
            'tab'       => array( 'char' => "\t", 'label' => __( 'Tabulador', 'crm-entries-analytics' ) ),
            'pipe'      => array( 'char' => '|', 'label' => __( 'Barra vertical ( | )', 'crm-entries-analytics' ) ),
            'custom'    => array( 'char' => '', 'label' => __( 'Personalizado…', 'crm-entries-analytics' ) ),
        );
    }

    public function can_export() {
        return current_user_can( CEA_CAPABILITY )
            || current_user_can( 'vxcf_leads_read_entries' )
            || current_user_can( 'manage_options' );
    }

    public function menu() {
        add_submenu_page(
            'vxcf_leads',
            __( 'Exportar leads', 'crm-entries-analytics' ),
            __( 'Exportar leads', 'crm-entries-analytics' ),
            CEA_CAPABILITY,
            self::PAGE,
            array( $this, 'render' )
        );
    }

    public function assets() {
        if ( empty( $_GET['page'] ) || self::PAGE !== sanitize_key( wp_unslash( $_GET['page'] ) ) ) {
            return;
        }

        wp_enqueue_style( 'cea-admin', CEA_URL . 'assets/admin.css', array(), CEA_VERSION );
        wp_enqueue_script( 'cea-export', CEA_URL . 'assets/export.js', array(), CEA_VERSION, true );
    }

    public function render() {
        if ( ! $this->can_export() ) {
            wp_die( esc_html__( 'No tienes permisos para exportar leads.', 'crm-entries-analytics' ) );
        }

        $tables = $this->db->tables_exist();
        $forms  = $tables ? $this->db->get_forms() : array();
        $prefs  = $this->get_prefs();
        $zip_ok = class_exists( 'ZipArchive' );
        $get    = static function ( $key ) {
            return isset( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( $_GET[ $key ] ) ) : '';
        };
        $form   = $get( 'form' );
        $status = $get( 'read_status' );
        $status = in_array( $status, array( 'all', 'read', 'unread' ), true ) ? $status : 'all';
        $scope  = 'zip' === $prefs['scope'] && $zip_ok ? 'zip' : 'combined';
        ?>
        <div class="wrap cea-wrap cea-migration-wrap cea-export-wrap">
            <div class="cea-header">
                <div>
                    <h1><?php esc_html_e( 'Exportar leads', 'crm-entries-analytics' ); ?></h1>
                    <p><?php esc_html_e( 'Descarga los leads en CSV listos para abrir en Excel, Google Sheets o importar en otro CRM.', 'crm-entries-analytics' ); ?></p>
                </div>
                <div class="cea-header-actions">
                    <a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=crm-entries-analytics' ) ); ?>"><?php esc_html_e( 'Volver a la analítica', 'crm-entries-analytics' ); ?></a>
                </div>
            </div>

            <?php if ( ! $tables ) : ?>
                <div class="notice notice-error inline"><p><?php esc_html_e( 'No se encuentran las tablas de Contact Form Entries.', 'crm-entries-analytics' ); ?></p></div>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="cea-export-csv-form">
                <input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
                <?php wp_nonce_field( self::ACTION ); ?>

                <section class="cea-card cea-migration-card">
                    <h2><?php esc_html_e( 'Qué leads exportar', 'crm-entries-analytics' ); ?></h2>
                    <p><?php esc_html_e( 'Deja las fechas vacías para exportar todo el histórico.', 'crm-entries-analytics' ); ?></p>
                    <div class="cea-migration-fields">
                        <div class="cea-field cea-field-wide">
                            <label for="cea-csv-form"><?php esc_html_e( 'Formulario', 'crm-entries-analytics' ); ?></label>
                            <select id="cea-csv-form" name="form">
                                <option value=""><?php esc_html_e( 'Todos los formularios', 'crm-entries-analytics' ); ?></option>
                                <?php foreach ( $forms as $id => $label ) : ?>
                                    <option value="<?php echo esc_attr( $id ); ?>" <?php selected( $form, (string) $id ); ?>><?php echo esc_html( $label ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="cea-field"><label for="cea-csv-start"><?php esc_html_e( 'Desde', 'crm-entries-analytics' ); ?></label><input id="cea-csv-start" name="start" type="date" value="<?php echo esc_attr( $get( 'start' ) ); ?>"></div>
                        <div class="cea-field"><label for="cea-csv-end"><?php esc_html_e( 'Hasta', 'crm-entries-analytics' ); ?></label><input id="cea-csv-end" name="end" type="date" value="<?php echo esc_attr( $get( 'end' ) ); ?>"></div>
                        <div class="cea-field">
                            <label for="cea-csv-status"><?php esc_html_e( 'Lectura', 'crm-entries-analytics' ); ?></label>
                            <select id="cea-csv-status" name="read_status">
                                <option value="all" <?php selected( $status, 'all' ); ?>><?php esc_html_e( 'Todos', 'crm-entries-analytics' ); ?></option>
                                <option value="unread" <?php selected( $status, 'unread' ); ?>><?php esc_html_e( 'No leídos', 'crm-entries-analytics' ); ?></option>
                                <option value="read" <?php selected( $status, 'read' ); ?>><?php esc_html_e( 'Leídos', 'crm-entries-analytics' ); ?></option>
                            </select>
                        </div>
                        <div class="cea-field cea-field-wide">
                            <label for="cea-csv-search"><?php esc_html_e( 'Contiene el texto (opcional)', 'crm-entries-analytics' ); ?></label>
                            <input id="cea-csv-search" name="search" type="search" value="<?php echo esc_attr( $get( 'search' ) ); ?>">
                        </div>
                    </div>

                    <fieldset class="cea-strategies cea-export-scope" id="cea-csv-scope">
                        <legend><?php esc_html_e( 'Con todos los formularios', 'crm-entries-analytics' ); ?></legend>
                        <label>
                            <input type="radio" name="scope" value="combined" <?php checked( $scope, 'combined' ); ?>>
                            <span><strong><?php esc_html_e( 'Todos en la misma hoja', 'crm-entries-analytics' ); ?></strong><small><?php esc_html_e( 'Un único CSV con una columna por cada campo de cualquier formulario. Los campos que no apliquen quedan vacíos.', 'crm-entries-analytics' ); ?></small></span>
                        </label>
                        <label>
                            <input type="radio" name="scope" value="zip" <?php checked( $scope, 'zip' ); ?> <?php disabled( ! $zip_ok ); ?>>
                            <span><strong><?php esc_html_e( 'Un CSV por formulario', 'crm-entries-analytics' ); ?></strong><small><?php echo $zip_ok ? esc_html__( 'Se descarga un .zip con un archivo por formulario y solo sus columnas.', 'crm-entries-analytics' ) : esc_html__( 'No disponible: el servidor no tiene la extensión ZipArchive.', 'crm-entries-analytics' ); ?></small></span>
                        </label>
                    </fieldset>
                </section>

                <section class="cea-card cea-migration-card">
                    <h2><?php esc_html_e( 'Formato del archivo', 'crm-entries-analytics' ); ?></h2>
                    <p><?php esc_html_e( 'Las preferencias se recuerdan para tu usuario en la próxima exportación.', 'crm-entries-analytics' ); ?></p>
                    <div class="cea-migration-fields">
                        <div class="cea-field">
                            <label for="cea-csv-separator"><?php esc_html_e( 'Separador', 'crm-entries-analytics' ); ?></label>
                            <select id="cea-csv-separator" name="separator">
                                <?php foreach ( self::separators() as $key => $separator ) : ?>
                                    <option value="<?php echo esc_attr( $key ); ?>" <?php selected( $prefs['separator'], $key ); ?>><?php echo esc_html( $separator['label'] ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="cea-field" id="cea-csv-custom-wrap">
                            <label for="cea-csv-custom"><?php esc_html_e( 'Carácter', 'crm-entries-analytics' ); ?></label>
                            <input id="cea-csv-custom" name="custom_separator" type="text" maxlength="1" size="3" value="<?php echo esc_attr( $prefs['custom_separator'] ); ?>">
                        </div>
                    </div>
                    <div class="cea-check-list">
                        <label><input type="checkbox" name="include_contact" value="1" <?php checked( $prefs['include_contact'] ); ?>> <?php esc_html_e( 'Añadir columnas unificadas de contacto (Nombre, Email, Teléfono) detectadas en cada formulario', 'crm-entries-analytics' ); ?></label>
                        <label><input type="checkbox" name="include_tech" value="1" <?php checked( $prefs['include_tech'] ); ?>> <?php esc_html_e( 'Incluir datos técnicos (ID del formulario, IP, navegador y sistema operativo)', 'crm-entries-analytics' ); ?></label>
                        <label><input type="checkbox" name="header_keys" value="1" <?php checked( $prefs['header_keys'] ); ?>> <?php esc_html_e( 'Usar los nombres internos de los campos en la cabecera en lugar de sus etiquetas', 'crm-entries-analytics' ); ?></label>
                        <label><input type="checkbox" name="bom" value="1" <?php checked( $prefs['bom'] ); ?>> <?php esc_html_e( 'Compatibilidad con Excel (UTF-8 con BOM, recomendado para acentos y ñ)', 'crm-entries-analytics' ); ?></label>
                    </div>
                    <p><button class="button button-primary button-hero" type="submit" <?php disabled( ! $tables ); ?>><?php esc_html_e( 'Descargar CSV', 'crm-entries-analytics' ); ?></button></p>
                </section>
            </form>
        </div>
        <?php
    }

    public function download() {
        if ( ! $this->can_export() ) {
            wp_die( esc_html__( 'No tienes permisos para exportar leads.', 'crm-entries-analytics' ), '', array( 'response' => 403 ) );
        }

        check_admin_referer( self::ACTION );

        if ( ! $this->db->tables_exist() ) {
            wp_die( esc_html__( 'No se encuentran las tablas de Contact Form Entries.', 'crm-entries-analytics' ), '', array( 'response' => 503 ) );
        }

        $filters = $this->parse_filters();
        if ( is_wp_error( $filters ) ) {
            wp_die( esc_html( $filters->get_error_message() ), '', array( 'response' => 400 ) );
        }

        $options = $this->parse_options();
        if ( is_wp_error( $options ) ) {
            wp_die( esc_html( $options->get_error_message() ), '', array( 'response' => 400 ) );
        }

        $this->save_prefs( $options );

        if ( function_exists( 'set_time_limit' ) ) {
            @set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        }

        $structure = $this->db->get_export_structure( $filters );

        if ( '' === $filters['form'] && 'zip' === $options['scope'] ) {
            $this->send_zip( $filters, $options, $structure );
        }

        $this->send_csv( $filters, $options, $structure );
    }

    private function send_csv( array $filters, array $options, array $structure ) {
        $label = '';
        if ( '' !== $filters['form'] ) {
            $forms = $this->db->get_forms();
            $label = isset( $forms[ $filters['form'] ] ) ? $forms[ $filters['form'] ] : $filters['form'];
        }
        $filename = $this->build_filename( $filters, $label, 'csv' );
        $columns  = $this->build_columns( $structure, $options, '' === $filters['form'] );

        $this->clean_output_buffers();
        nocache_headers();
        header( 'Content-Type: text/csv; charset=UTF-8' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        header( 'X-Content-Type-Options: nosniff' );

        $output = fopen( 'php://output', 'wb' );
        $this->write_csv( $output, $filters, $columns, $options, $structure );
        fclose( $output );
        exit;
    }

    private function send_zip( array $filters, array $options, array $structure ) {
        if ( ! class_exists( 'ZipArchive' ) ) {
            wp_die( esc_html__( 'ZipArchive no está disponible en este servidor.', 'crm-entries-analytics' ), '', array( 'response' => 500 ) );
        }

        if ( empty( $structure ) ) {
            wp_die( esc_html__( 'No hay leads que coincidan con los filtros seleccionados.', 'crm-entries-analytics' ), '', array( 'response' => 404 ) );
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';

        $zip_path = wp_tempnam( 'cea-leads.zip' );
        $temp     = array( $zip_path );
        $zip      = new ZipArchive();

        if ( true !== $zip->open( $zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
            wp_die( esc_html__( 'No se ha podido crear el archivo ZIP.', 'crm-entries-analytics' ), '', array( 'response' => 500 ) );
        }

        $used_names = array();
        foreach ( $structure as $form_id => $form ) {
            $form_filters         = $filters;
            $form_filters['form'] = (string) $form_id;
            $columns              = $this->build_columns( array( $form_id => $form ), $options, false );
            $csv_path             = wp_tempnam( 'cea-leads.csv' );
            $temp[]               = $csv_path;
            $handle               = fopen( $csv_path, 'wb' );

            $this->write_csv( $handle, $form_filters, $columns, $options, $structure );
            fclose( $handle );

            $base = sanitize_file_name( remove_accents( $form['label'] ) );
            $base = '' !== $base ? $base : sanitize_file_name( (string) $form_id );
            $name = $base . '.csv';
            for ( $i = 2; isset( $used_names[ strtolower( $name ) ] ); $i++ ) {
                $name = $base . '-' . $i . '.csv';
            }
            $used_names[ strtolower( $name ) ] = true;
            $zip->addFile( $csv_path, $name );
        }

        $zip->close();

        $this->clean_output_buffers();
        nocache_headers();
        header( 'Content-Type: application/zip' );
        header( 'Content-Disposition: attachment; filename="' . $this->build_filename( $filters, '', 'zip' ) . '"' );
        header( 'Content-Length: ' . filesize( $zip_path ) );
        readfile( $zip_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile

        foreach ( $temp as $file ) {
            if ( is_file( $file ) ) {
                wp_delete_file( $file );
            }
        }
        exit;
    }

    /**
     * Builds the ordered column list. Field columns are keyed by field name, so the
     * same field shared by several forms ends up in a single column.
     */
    private function build_columns( array $structure, array $options, $show_form ) {
        $use_keys = $options['header_keys'];
        $columns  = array(
            array( 'type' => 'base', 'key' => 'id', 'label' => $use_keys ? 'id' : __( 'ID', 'crm-entries-analytics' ) ),
            array( 'type' => 'base', 'key' => 'created', 'label' => $use_keys ? 'created' : __( 'Fecha', 'crm-entries-analytics' ) ),
        );

        if ( $show_form ) {
            $columns[] = array( 'type' => 'base', 'key' => 'form', 'label' => $use_keys ? 'form' : __( 'Formulario', 'crm-entries-analytics' ) );
        }

        $columns[] = array( 'type' => 'base', 'key' => 'status', 'label' => $use_keys ? 'is_read' : __( 'Estado', 'crm-entries-analytics' ) );

        if ( $options['include_contact'] ) {
            $columns[] = array( 'type' => 'contact', 'key' => 'name', 'label' => $use_keys ? 'contact_name' : __( 'Nombre', 'crm-entries-analytics' ) );
            $columns[] = array( 'type' => 'contact', 'key' => 'email', 'label' => $use_keys ? 'contact_email' : __( 'Email', 'crm-entries-analytics' ) );
            $columns[] = array( 'type' => 'contact', 'key' => 'phone', 'label' => $use_keys ? 'contact_phone' : __( 'Teléfono', 'crm-entries-analytics' ) );
        }

        $used = array();
        foreach ( $columns as $column ) {
            $used[ $this->label_key( $column['label'] ) ] = true;
        }

        $seen = array();
        foreach ( $structure as $form ) {
            foreach ( $form['fields'] as $key => $label ) {
                $key = (string) $key;
                if ( isset( $seen[ $key ] ) ) {
                    continue;
                }
                $seen[ $key ] = true;

                $label = $use_keys ? $key : $label;
                if ( isset( $used[ $this->label_key( $label ) ] ) && $label !== $key ) {
                    $label = sprintf( '%s (%s)', $label, $key );
                }
                $used[ $this->label_key( $label ) ] = true;
                $columns[] = array( 'type' => 'field', 'key' => $key, 'label' => $label );
            }
        }

        $columns[] = array( 'type' => 'base', 'key' => 'url', 'label' => $use_keys ? 'url' : __( 'Página de origen', 'crm-entries-analytics' ) );

        if ( $options['include_tech'] ) {
            $columns[] = array( 'type' => 'base', 'key' => 'form_id', 'label' => $use_keys ? 'form_id' : __( 'ID de formulario', 'crm-entries-analytics' ) );
            $columns[] = array( 'type' => 'base', 'key' => 'ip', 'label' => $use_keys ? 'ip' : __( 'IP', 'crm-entries-analytics' ) );
            $columns[] = array( 'type' => 'base', 'key' => 'browser', 'label' => $use_keys ? 'browser' : __( 'Navegador', 'crm-entries-analytics' ) );
            $columns[] = array( 'type' => 'base', 'key' => 'os', 'label' => $use_keys ? 'os' : __( 'Sistema operativo', 'crm-entries-analytics' ) );
        }

        return $columns;
    }

    private function write_csv( $handle, array $filters, array $columns, array $options, array $structure ) {
        if ( $options['bom'] ) {
            fwrite( $handle, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
        }

        $this->put_row( $handle, wp_list_pluck( $columns, 'label' ), $options['separator'] );

        $read_label   = __( 'Leído', 'crm-entries-analytics' );
        $unread_label = __( 'No leído', 'crm-entries-analytics' );
        $cursor       = null;

        do {
            $batch = $this->db->get_export_batch( $filters, $cursor, self::BATCH_SIZE );

            foreach ( $batch['items'] as $lead ) {
                $row = array();
                foreach ( $columns as $column ) {
                    $key = $column['key'];
                    if ( 'field' === $column['type'] ) {
                        $row[] = isset( $lead['fields'][ $key ] ) ? $lead['fields'][ $key ] : '';
                    } elseif ( 'contact' === $column['type'] ) {
                        $row[] = $lead['contact'][ $key ];
                    } elseif ( 'form' === $key ) {
                        $row[] = isset( $structure[ $lead['formId'] ] ) ? $structure[ $lead['formId'] ]['label'] : $lead['formId'];
                    } elseif ( 'status' === $key ) {
                        $row[] = $options['header_keys'] ? ( $lead['isRead'] ? '1' : '0' ) : ( $lead['isRead'] ? $read_label : $unread_label );
                    } elseif ( 'form_id' === $key ) {
                        $row[] = $lead['formId'];
                    } else {
                        $row[] = isset( $lead[ $key ] ) ? (string) $lead[ $key ] : '';
                    }
                }
                $this->put_row( $handle, $row, $options['separator'] );
            }

            $cursor = $batch['cursor'];
            if ( function_exists( 'wp_cache_flush_runtime' ) ) {
                wp_cache_flush_runtime();
            }
        } while ( count( $batch['items'] ) === self::BATCH_SIZE );
    }

    private function put_row( $handle, array $row, $separator ) {
        fputcsv( $handle, array_map( array( $this, 'safe_cell' ), $row ), $separator, '"', '' );
    }

    /**
     * Neutralizes spreadsheet formulas (CSV injection) without breaking phone numbers
     * or negative amounts such as "+34 600 000 000".
     */
    private function safe_cell( $value ) {
        $value = (string) $value;
        if ( '' === $value || ! preg_match( '/^[=+\-@\t\r]/', $value ) ) {
            return $value;
        }
        if ( preg_match( '/^[+\-]?[\d\s().\/-]+$/', $value ) ) {
            return $value;
        }
        return "'" . $value;
    }

    private function parse_filters() {
        $timezone = wp_timezone();
        $start    = $this->post_value( 'start' );
        $end      = $this->post_value( 'end' );
        $from     = $this->parse_date( $start, $timezone );
        $to       = $this->parse_date( $end, $timezone );

        if ( ( '' !== $start && ! $from ) || ( '' !== $end && ! $to ) ) {
            return new WP_Error( 'cea_export_invalid_date', __( 'Las fechas de exportación no son válidas.', 'crm-entries-analytics' ) );
        }

        if ( $from && $to && $from > $to ) {
            list( $from, $to ) = array( $to, $from );
        }

        $status = sanitize_key( $this->post_value( 'read_status' ) );
        $utc    = new DateTimeZone( 'UTC' );

        return array(
            'start'       => $from ? $from->format( 'Y-m-d' ) : '',
            'end'         => $to ? $to->format( 'Y-m-d' ) : '',
            'start_utc'   => $from ? $from->setTime( 0, 0, 0 )->setTimezone( $utc )->format( 'Y-m-d H:i:s' ) : '',
            'end_utc'     => $to ? $to->setTime( 23, 59, 59 )->setTimezone( $utc )->format( 'Y-m-d H:i:s' ) : '',
            'form'        => $this->post_value( 'form' ),
            'read_status' => in_array( $status, array( 'all', 'read', 'unread' ), true ) ? $status : 'all',
            'search'      => $this->post_value( 'search' ),
        );
    }

    private function parse_options() {
        $separators = self::separators();
        $choice     = sanitize_key( $this->post_value( 'separator' ) );
        $choice     = isset( $separators[ $choice ] ) ? $choice : 'semicolon';
        $custom     = isset( $_POST['custom_separator'] ) ? (string) wp_unslash( $_POST['custom_separator'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $custom     = function_exists( 'mb_substr' ) ? mb_substr( $custom, 0, 1 ) : substr( $custom, 0, 1 );
        $char       = $separators[ $choice ]['char'];

        if ( 'custom' === $choice ) {
            if ( 1 !== strlen( $custom ) || false !== strpos( "\"\r\n", $custom ) || ctype_alnum( $custom ) ) {
                return new WP_Error( 'cea_export_invalid_separator', __( 'El separador personalizado debe ser un único carácter que no sea letra, número, comillas ni salto de línea.', 'crm-entries-analytics' ) );
            }
            $char = $custom;
        }

        return array(
            'separator_choice' => $choice,
            'custom_separator' => 'custom' === $choice ? $custom : '',
            'separator'        => $char,
            'scope'            => 'zip' === sanitize_key( $this->post_value( 'scope' ) ) ? 'zip' : 'combined',
            'include_contact'  => ! empty( $_POST['include_contact'] ),
            'include_tech'     => ! empty( $_POST['include_tech'] ),
            'header_keys'      => ! empty( $_POST['header_keys'] ),
            'bom'              => ! empty( $_POST['bom'] ),
        );
    }

    private function get_prefs() {
        $defaults = array(
            'separator'        => 'semicolon',
            'custom_separator' => '',
            'scope'            => 'combined',
            'include_contact'  => true,
            'include_tech'     => false,
            'header_keys'      => false,
            'bom'              => true,
        );
        $stored = get_user_meta( get_current_user_id(), self::PREFS_META, true );

        return is_array( $stored ) ? array_merge( $defaults, array_intersect_key( $stored, $defaults ) ) : $defaults;
    }

    private function save_prefs( array $options ) {
        update_user_meta(
            get_current_user_id(),
            self::PREFS_META,
            array(
                'separator'        => $options['separator_choice'],
                'custom_separator' => $options['custom_separator'],
                'scope'            => $options['scope'],
                'include_contact'  => $options['include_contact'],
                'include_tech'     => $options['include_tech'],
                'header_keys'      => $options['header_keys'],
                'bom'              => $options['bom'],
            )
        );
    }

    private function build_filename( array $filters, $form_label, $extension ) {
        $parts = array( 'leads', '' !== $form_label ? remove_accents( $form_label ) : 'todos' );
        if ( $filters['start'] || $filters['end'] ) {
            $parts[] = $filters['start'] ? $filters['start'] : 'inicio';
            $parts[] = $filters['end'] ? $filters['end'] : 'hoy';
        } else {
            $parts[] = wp_date( 'Y-m-d' );
        }

        return sanitize_file_name( strtolower( implode( '-', $parts ) ) . '.' . $extension );
    }

    private function label_key( $label ) {
        return strtolower( remove_accents( trim( (string) $label ) ) );
    }

    private function parse_date( $date, DateTimeZone $timezone ) {
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
            return null;
        }

        $parsed = DateTimeImmutable::createFromFormat( '!Y-m-d', $date, $timezone );

        return $parsed && $parsed->format( 'Y-m-d' ) === $date ? $parsed : null;
    }

    private function post_value( $key ) {
        return isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
    }

    private function clean_output_buffers() {
        while ( ob_get_level() ) {
            ob_end_clean();
        }
    }
}
