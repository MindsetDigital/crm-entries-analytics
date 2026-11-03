<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Lossless, versioned migration for CRM Perks entries.
 *
 * The package deliberately stores database values as JSON strings. Values are
 * never unserialized with PHP classes enabled and local entry IDs are remapped.
 */
class CEA_Migration {
    const FORMAT             = 'cea-crm-entries';
    const FORMAT_VERSION     = 1;
    const DB_VERSION         = '1';
    const REST_NAMESPACE     = 'crm-entries-analytics/v1';
    const BATCH_SIZE         = 100;
    const MAX_PACKAGE_BYTES  = 536870912;
    const MAX_EXPANDED_BYTES = 1073741824;

    private $db;
    private $wpdb;
    private $leads_table;
    private $detail_table;
    private $map_table;

    public function __construct( CEA_DB $db ) {
        global $wpdb;

        $this->db           = $db;
        $this->wpdb         = $wpdb;
        $this->leads_table  = $wpdb->prefix . 'vxcf_leads';
        $this->detail_table = $wpdb->prefix . 'vxcf_leads_detail';
        $this->map_table    = $wpdb->prefix . 'cea_migration_map';

        add_action( 'admin_init', array( __CLASS__, 'maybe_install' ) );
        add_action( 'admin_init', array( $this, 'cleanup_expired_jobs' ) );
        add_action( 'admin_menu', array( $this, 'menu' ), 100 );
        add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
        add_action( 'rest_api_init', array( $this, 'register_routes' ) );
        add_action( 'admin_post_cea_export_entries', array( $this, 'export_download' ) );
        add_action( 'admin_post_cea_download_backup', array( $this, 'download_backup' ) );
    }

    public static function maybe_install() {
        if ( self::DB_VERSION !== get_option( 'cea_migration_db_version' ) ) {
            self::install();
        }
    }

    public static function install() {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table   = $wpdb->prefix . 'cea_migration_map';
        $charset = $wpdb->get_charset_collate();
        $sql     = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            source_site varchar(64) NOT NULL,
            source_entry_id varchar(191) NOT NULL,
            local_lead_id bigint(20) unsigned NOT NULL,
            fingerprint char(64) NOT NULL,
            imported_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY source_entry (source_site,source_entry_id),
            KEY local_lead_id (local_lead_id),
            KEY fingerprint (fingerprint)
        ) {$charset};";

        dbDelta( $sql );
        update_option( 'cea_migration_db_version', self::DB_VERSION, false );

        if ( ! get_option( 'cea_site_uuid' ) ) {
            update_option( 'cea_site_uuid', wp_generate_uuid4(), false );
        }
    }

    public function menu() {
        add_submenu_page(
            'vxcf_leads',
            __( 'Migración de datos', 'crm-entries-analytics' ),
            __( 'Migración de datos', 'crm-entries-analytics' ),
            CEA_MIGRATION_CAPABILITY,
            'crm-entries-migration',
            array( $this, 'render' )
        );
    }

    public function assets() {
        if ( empty( $_GET['page'] ) || 'crm-entries-migration' !== sanitize_key( wp_unslash( $_GET['page'] ) ) ) {
            return;
        }

        wp_enqueue_style( 'cea-admin', CEA_URL . 'assets/admin.css', array(), CEA_VERSION );
        wp_enqueue_script( 'cea-migration', CEA_URL . 'assets/migration.js', array(), CEA_VERSION, true );
        wp_localize_script(
            'cea-migration',
            'CEA_MIGRATION_CONFIG',
            array(
                'restUrl' => esc_url_raw( rest_url( self::REST_NAMESPACE . '/migration/' ) ),
                'nonce'   => wp_create_nonce( 'wp_rest' ),
            )
        );
    }

    public function render() {
        if ( ! current_user_can( CEA_MIGRATION_CAPABILITY ) && ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'No tienes permisos para migrar registros.', 'crm-entries-analytics' ) );
        }

        $tab     = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'import';
        $tab     = in_array( $tab, array( 'import', 'export' ), true ) ? $tab : 'import';
        $forms   = $this->db->tables_exist() ? $this->db->get_forms() : array();
        $zip_ok  = class_exists( 'ZipArchive' );
        $base    = admin_url( 'admin.php?page=crm-entries-migration' );
        $backups = $this->list_backups();
        ?>
        <div class="wrap cea-wrap cea-migration-wrap">
            <div class="cea-header">
                <div>
                    <h1><?php esc_html_e( 'Migración de datos', 'crm-entries-analytics' ); ?></h1>
                    <p><?php esc_html_e( 'Exporta o restaura los registros guardados por CRM Entries.', 'crm-entries-analytics' ); ?></p>
                </div>
            </div>

            <nav class="nav-tab-wrapper" aria-label="<?php esc_attr_e( 'Secciones de migración', 'crm-entries-analytics' ); ?>">
                <a class="nav-tab <?php echo 'import' === $tab ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'tab', 'import', $base ) ); ?>"><?php esc_html_e( 'Importar datos', 'crm-entries-analytics' ); ?></a>
                <a class="nav-tab <?php echo 'export' === $tab ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'tab', 'export', $base ) ); ?>"><?php esc_html_e( 'Exportar datos', 'crm-entries-analytics' ); ?></a>
            </nav>

            <?php if ( ! $this->db->tables_exist() ) : ?>
                <div class="notice notice-error inline"><p><?php esc_html_e( 'No se encuentran las tablas de CRM Entries.', 'crm-entries-analytics' ); ?></p></div>
            <?php elseif ( ! $zip_ok ) : ?>
                <div class="notice notice-error inline"><p><?php esc_html_e( 'La extensión PHP ZipArchive es necesaria para crear y restaurar paquetes de migración.', 'crm-entries-analytics' ); ?></p></div>
            <?php endif; ?>

            <?php if ( 'export' === $tab ) : ?>
                <section class="cea-card cea-migration-card">
                    <h2><?php esc_html_e( 'Crear una copia', 'crm-entries-analytics' ); ?></h2>
                    <p><?php esc_html_e( 'El paquete de migración conserva campos dinámicos, estados y metadatos. El CSV está pensado únicamente para consulta.', 'crm-entries-analytics' ); ?></p>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                        <input type="hidden" name="action" value="cea_export_entries">
                        <?php wp_nonce_field( 'cea_export_entries' ); ?>
                        <div class="cea-migration-fields">
                            <div class="cea-field cea-field-wide">
                                <label for="cea-export-form"><?php esc_html_e( 'Formulario', 'crm-entries-analytics' ); ?></label>
                                <select id="cea-export-form" name="form_id">
                                    <option value=""><?php esc_html_e( 'Todos los formularios', 'crm-entries-analytics' ); ?></option>
                                    <?php foreach ( $forms as $id => $label ) : ?>
                                        <option value="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="cea-field"><label for="cea-export-start"><?php esc_html_e( 'Desde', 'crm-entries-analytics' ); ?></label><input id="cea-export-start" name="start" type="date"></div>
                            <div class="cea-field"><label for="cea-export-end"><?php esc_html_e( 'Hasta', 'crm-entries-analytics' ); ?></label><input id="cea-export-end" name="end" type="date"></div>
                            <div class="cea-field"><label for="cea-export-format"><?php esc_html_e( 'Formato', 'crm-entries-analytics' ); ?></label><select id="cea-export-format" name="format"><option value="package"><?php esc_html_e( 'Paquete de migración (.zip)', 'crm-entries-analytics' ); ?></option><option value="csv"><?php esc_html_e( 'CSV para consulta', 'crm-entries-analytics' ); ?></option></select></div>
                        </div>
                        <div class="cea-check-list">
                            <label><input type="checkbox" name="include_deleted" value="1" checked> <?php esc_html_e( 'Incluir registros eliminados o en papelera', 'crm-entries-analytics' ); ?></label>
                            <label><input type="checkbox" name="include_files" value="1" checked> <?php esc_html_e( 'Incluir archivos locales encontrados en los campos', 'crm-entries-analytics' ); ?></label>
                        </div>
                        <p><button class="button button-primary" type="submit"><?php esc_html_e( 'Descargar exportación', 'crm-entries-analytics' ); ?></button></p>
                    </form>
                </section>
            <?php else : ?>
                <section class="cea-card cea-migration-card">
                    <h2><?php esc_html_e( 'Analizar una copia', 'crm-entries-analytics' ); ?></h2>
                    <p><?php esc_html_e( 'La carga inicial solo valida el paquete. No se modificará ningún registro hasta que confirmes la estrategia.', 'crm-entries-analytics' ); ?></p>
                    <form id="cea-import-upload" enctype="multipart/form-data">
                        <div class="cea-upload-row">
                            <input type="file" id="cea-import-file" name="package" accept=".zip,.cea.zip,application/zip" required>
                            <button class="button button-primary" type="submit" <?php disabled( ! $zip_ok ); ?>><?php esc_html_e( 'Analizar archivo', 'crm-entries-analytics' ); ?></button>
                        </div>
                    </form>
                    <div id="cea-import-error" class="notice notice-error inline cea-hidden"><p></p></div>
                    <div id="cea-import-preview" class="cea-hidden"></div>
                </section>

                <?php if ( $backups ) : ?>
                    <section class="cea-card cea-migration-card">
                        <h2><?php esc_html_e( 'Copias automáticas recientes', 'crm-entries-analytics' ); ?></h2>
                        <p><?php esc_html_e( 'Se crean antes de cada sustitución. Descárgalas y guárdalas en una ubicación segura.', 'crm-entries-analytics' ); ?></p>
                        <ul class="cea-backup-list">
                            <?php foreach ( $backups as $backup ) : ?>
                                <li><span><?php echo esc_html( $backup['name'] ); ?></span><a class="button button-small" href="<?php echo esc_url( $backup['url'] ); ?>"><?php esc_html_e( 'Descargar', 'crm-entries-analytics' ); ?></a></li>
                            <?php endforeach; ?>
                        </ul>
                    </section>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php
    }

    public function register_routes() {
        register_rest_route(
            self::REST_NAMESPACE,
            '/migration/preview',
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => array( $this, 'preview' ),
                'permission_callback' => array( $this, 'permissions' ),
            )
        );
        register_rest_route(
            self::REST_NAMESPACE,
            '/migration/import',
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => array( $this, 'import_batch' ),
                'permission_callback' => array( $this, 'permissions' ),
            )
        );
    }

    public function permissions() {
        return current_user_can( CEA_MIGRATION_CAPABILITY ) || current_user_can( 'manage_options' );
    }

    public function export_download() {
        $this->assert_admin_action( 'cea_export_entries' );
        if ( ! $this->db->tables_exist() ) {
            wp_die( esc_html__( 'No se encuentran las tablas de CRM Entries.', 'crm-entries-analytics' ) );
        }

        $args = $this->export_args_from_request();
        if ( 'csv' === $args['format'] ) {
            $this->stream_csv( $args );
        }
        if ( ! class_exists( 'ZipArchive' ) ) {
            wp_die( esc_html__( 'ZipArchive no está disponible en este servidor.', 'crm-entries-analytics' ) );
        }

        $name = 'crm-entries-' . gmdate( 'Y-m-d-His' ) . '.cea.zip';
        $path = $this->storage_root() . '/exports/' . $name;
        $this->ensure_directory( dirname( $path ) );
        $result = $this->build_package( $args, $path );
        if ( is_wp_error( $result ) ) {
            wp_die( esc_html( $result->get_error_message() ) );
        }
        $this->send_file( $path, $name, true );
    }

    public function download_backup() {
        $this->assert_admin_action( 'cea_download_backup' );
        $name = isset( $_GET['file'] ) ? sanitize_file_name( wp_unslash( $_GET['file'] ) ) : '';
        $path = $this->storage_root() . '/backups/' . $name;
        if ( ! $name || ! is_file( $path ) || basename( $path ) !== $name ) {
            wp_die( esc_html__( 'No se ha encontrado la copia solicitada.', 'crm-entries-analytics' ) );
        }
        $this->send_file( $path, $name, false );
    }

    public function preview( WP_REST_Request $request ) {
        if ( ! class_exists( 'ZipArchive' ) ) {
            return new WP_Error( 'cea_zip_missing', __( 'ZipArchive no está disponible.', 'crm-entries-analytics' ), array( 'status' => 503 ) );
        }
        $files = $request->get_file_params();
        $file  = isset( $files['package'] ) ? $files['package'] : null;
        if ( ! is_array( $file ) || UPLOAD_ERR_OK !== (int) $file['error'] || empty( $file['tmp_name'] ) ) {
            return new WP_Error( 'cea_upload_failed', __( 'No se ha recibido un archivo válido.', 'crm-entries-analytics' ), array( 'status' => 400 ) );
        }
        if ( (int) $file['size'] > (int) apply_filters( 'cea_max_package_bytes', self::MAX_PACKAGE_BYTES ) ) {
            return new WP_Error( 'cea_upload_too_large', __( 'El paquete supera el tamaño máximo permitido.', 'crm-entries-analytics' ), array( 'status' => 413 ) );
        }

        $token   = bin2hex( random_bytes( 16 ) );
        $job_dir = $this->storage_root() . '/jobs/' . $token;
        $this->ensure_directory( $job_dir );
        $zip_path = $job_dir . '/package.zip';
        if ( ! @move_uploaded_file( $file['tmp_name'], $zip_path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
            return new WP_Error( 'cea_upload_move_failed', __( 'No se ha podido guardar temporalmente el paquete.', 'crm-entries-analytics' ), array( 'status' => 500 ) );
        }

        $validated = $this->validate_package( $zip_path, $job_dir . '/entries.ndjson' );
        if ( is_wp_error( $validated ) ) {
            $this->remove_directory( $job_dir );
            return $validated;
        }

        $summary = array( 'total' => 0, 'new' => 0, 'existing' => 0, 'invalid' => 0 );
        $handle  = fopen( $job_dir . '/entries.ndjson', 'rb' );
        while ( $handle && false !== ( $line = fgets( $handle ) ) ) {
            $entry = json_decode( $line, true );
            if ( ! $this->valid_entry( $entry ) ) {
                $summary['invalid']++;
                continue;
            }
            $summary['total']++;
            $existing = $this->find_existing( $validated['manifest']['source']['siteUuid'], (string) $entry['sourceId'], $entry, (string) $entry['lead']['form_id'] );
            $summary[ $existing ? 'existing' : 'new' ]++;
        }
        if ( $handle ) {
            fclose( $handle );
        }
        if ( $summary['total'] + $summary['invalid'] !== (int) $validated['manifest']['entryCount'] ) {
            $this->remove_directory( $job_dir );
            return new WP_Error( 'cea_entry_count_mismatch', __( 'El número de registros no coincide con el manifiesto del paquete.', 'crm-entries-analytics' ), array( 'status' => 400 ) );
        }

        $state = array(
            'token'       => $token,
            'userId'      => get_current_user_id(),
            'createdAt'   => time(),
            'zipPath'     => $zip_path,
            'entriesPath' => $job_dir . '/entries.ndjson',
            'offset'      => 0,
            'processed'   => 0,
            'created'     => 0,
            'updated'     => 0,
            'skipped'     => 0,
            'failed'      => 0,
            'started'     => false,
            'done'        => false,
            'manifest'    => $validated['manifest'],
            'fileMap'     => array(),
        );
        $this->save_state( $state );

        return rest_ensure_response(
            array(
                'token'    => $token,
                'summary'  => $summary,
                'manifest' => $this->public_manifest( $validated['manifest'] ),
                'targets'  => $this->format_forms( $this->db->get_forms() ),
            )
        );
    }

    public function import_batch( WP_REST_Request $request ) {
        $token = sanitize_text_field( (string) $request->get_param( 'token' ) );
        $state = $this->load_state( $token );
        if ( is_wp_error( $state ) ) {
            return $state;
        }
        if ( ! $this->acquire_lock( $token ) ) {
            return new WP_Error( 'cea_import_busy', __( 'Ya hay otro lote de importación en curso.', 'crm-entries-analytics' ), array( 'status' => 409 ) );
        }

        try {
            $strategy = sanitize_key( (string) $request->get_param( 'strategy' ) );
            if ( ! in_array( $strategy, array( 'replace', 'upsert', 'create_only' ), true ) ) {
                return new WP_Error( 'cea_invalid_strategy', __( 'Selecciona una estrategia de importación válida.', 'crm-entries-analytics' ), array( 'status' => 400 ) );
            }
            $mapping = $this->sanitize_form_mapping( $request->get_param( 'formMapping' ) );
            $scope   = 'forms' === sanitize_key( (string) $request->get_param( 'replaceScope' ) ) ? 'forms' : 'all';

            if ( ! $state['started'] ) {
                $state['strategy'] = $strategy;
                $state['mapping']  = $mapping;
                $state['scope']    = $scope;
                if ( 'replace' === $strategy ) {
                    if ( empty( $state['manifest']['entryCount'] ) ) {
                        return new WP_Error( 'cea_empty_replace', __( 'No se permite sustituir datos utilizando un paquete vacío.', 'crm-entries-analytics' ), array( 'status' => 400 ) );
                    }
                    $backup = $this->create_automatic_backup();
                    if ( is_wp_error( $backup ) ) {
                        return $backup;
                    }
                    $state['backup'] = basename( $backup );
                    $cleared = $this->clear_records( $scope, $mapping, $state['manifest'] );
                    if ( is_wp_error( $cleared ) ) {
                        return $cleared;
                    }
                }
                $state['started'] = true;
                $this->save_state( $state );
            } elseif ( $strategy !== $state['strategy'] || $mapping !== $state['mapping'] || $scope !== $state['scope'] ) {
                return new WP_Error( 'cea_import_changed', __( 'No se puede cambiar la configuración después de iniciar la importación.', 'crm-entries-analytics' ), array( 'status' => 409 ) );
            }

            if ( $state['done'] ) {
                return rest_ensure_response( $this->progress_response( $state ) );
            }

            $handle = fopen( $state['entriesPath'], 'rb' );
            if ( ! $handle || 0 !== fseek( $handle, (int) $state['offset'] ) ) {
                return new WP_Error( 'cea_job_file_missing', __( 'El archivo temporal de la importación ya no está disponible.', 'crm-entries-analytics' ), array( 'status' => 410 ) );
            }

            $this->wpdb->query( 'START TRANSACTION' );
            $batch_count = 0;
            while ( $batch_count < self::BATCH_SIZE && false !== ( $line = fgets( $handle ) ) ) {
                $batch_count++;
                $state['processed']++;
                $entry = json_decode( $line, true );
                if ( ! $this->valid_entry( $entry ) ) {
                    $state['failed']++;
                    continue;
                }

                $source_form = (string) $entry['lead']['form_id'];
                $target_form = isset( $mapping[ $source_form ] ) && '' !== $mapping[ $source_form ] ? $mapping[ $source_form ] : $source_form;
                $entry['lead']['form_id'] = $target_form;
                $entry = $this->restore_entry_files( $entry, $state );
                if ( is_wp_error( $entry ) ) {
                    $state['failed']++;
                    continue;
                }

                $existing = $this->find_existing( $state['manifest']['source']['siteUuid'], (string) $entry['sourceId'], $entry, $target_form );
                if ( $existing && 'create_only' === $strategy ) {
                    $state['skipped']++;
                    continue;
                }

                $saved = $this->save_entry( $entry, $existing );
                if ( is_wp_error( $saved ) ) {
                    $this->wpdb->query( 'ROLLBACK' );
                    fclose( $handle );
                    return $saved;
                }
                $existing ? $state['updated']++ : $state['created']++;
                $this->save_mapping( $state['manifest']['source']['siteUuid'], (string) $entry['sourceId'], $saved, $this->fingerprint( $entry, $target_form ) );
            }

            $state['offset'] = ftell( $handle );
            $state['done']   = feof( $handle );
            fclose( $handle );
            $this->wpdb->query( 'COMMIT' );
            $this->save_state( $state );
            if ( $state['done'] ) {
                @unlink( $state['zipPath'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
                @unlink( $state['entriesPath'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
            }

            return rest_ensure_response( $this->progress_response( $state ) );
        } catch ( Throwable $error ) {
            $this->wpdb->query( 'ROLLBACK' );
            return new WP_Error( 'cea_import_exception', __( 'La operación se ha detenido de forma segura: ', 'crm-entries-analytics' ) . $error->getMessage(), array( 'status' => 500 ) );
        } finally {
            $this->release_lock( $token );
        }
    }

    private function build_package( array $args, $destination ) {
        $work = $this->storage_root() . '/work/' . bin2hex( random_bytes( 8 ) );
        $this->ensure_directory( $work );
        $entries_path = $work . '/entries.ndjson';
        $handle       = fopen( $entries_path, 'wb' );
        if ( ! $handle ) {
            return new WP_Error( 'cea_export_open', __( 'No se ha podido preparar la exportación.', 'crm-entries-analytics' ) );
        }

        $source_uuid = (string) get_option( 'cea_site_uuid' );
        $count       = 0;
        $last_id     = 0;
        $file_count  = 0;
        $exported_forms = array();
        do {
            $leads = $this->export_leads( $args, $last_id, 250 );
            if ( ! $leads ) {
                break;
            }
            $ids       = array_map( 'intval', wp_list_pluck( $leads, 'id' ) );
            $details   = $this->rows_by_lead( $this->detail_table, $ids );
            $relations = array();
            foreach ( $this->related_tables() as $key => $table ) {
                $relations[ $key ] = $this->rows_by_lead( $table, $ids );
            }

            foreach ( $leads as $lead ) {
                $last_id = (int) $lead['id'];
                $exported_forms[ (string) $lead['form_id'] ] = true;
                $entry   = array(
                    'sourceId' => (string) $lead['id'],
                    'lead'     => $lead,
                    'details'  => isset( $details[ $last_id ] ) ? $details[ $last_id ] : array(),
                    'relations'=> array(),
                    'files'    => array(),
                );
                foreach ( $relations as $key => $grouped ) {
                    $entry['relations'][ $key ] = isset( $grouped[ $last_id ] ) ? $grouped[ $last_id ] : array();
                }
                if ( $args['include_files'] ) {
                    $entry['files'] = $this->collect_files( $entry['details'], $work . '/files' );
                    $file_count += count( $entry['files'] );
                }
                $json = wp_json_encode( $entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE );
                if ( false === $json || false === fwrite( $handle, $json . "\n" ) ) {
                    fclose( $handle );
                    $this->remove_directory( $work );
                    return new WP_Error( 'cea_export_write', __( 'No se ha podido escribir la exportación.', 'crm-entries-analytics' ) );
                }
                $count++;
            }
        } while ( count( $leads ) === 250 );
        fclose( $handle );

        $forms = array();
        foreach ( $this->db->get_forms() as $id => $label ) {
            if ( isset( $exported_forms[ (string) $id ] ) ) {
                $forms[] = array( 'id' => (string) $id, 'label' => (string) $label );
            }
        }
        foreach ( array_keys( $exported_forms ) as $id ) {
            if ( ! in_array( $id, wp_list_pluck( $forms, 'id' ), true ) ) {
                $forms[] = array( 'id' => $id, 'label' => ucwords( str_replace( array( '_', '-' ), ' ', $id ) ) );
            }
        }
        $manifest = array(
            'format'        => self::FORMAT,
            'formatVersion' => self::FORMAT_VERSION,
            'createdAt'     => gmdate( DATE_ATOM ),
            'entryCount'    => $count,
            'fileCount'     => $file_count,
            'entriesSha256' => hash_file( 'sha256', $entries_path ),
            'source'        => array(
                'siteUuid'   => $source_uuid,
                'siteUrl'    => home_url( '/' ),
                'timezone'   => wp_timezone_string(),
                'ceaVersion' => CEA_VERSION,
                'crmVersion' => class_exists( 'vxcf_form' ) && isset( vxcf_form::$version ) ? (string) vxcf_form::$version : '',
            ),
            'filters'       => array(
                'formId'        => $args['form_id'],
                'start'         => $args['start'],
                'end'           => $args['end'],
                'includeDeleted'=> $args['include_deleted'],
            ),
            'forms'         => $forms,
            'schema'        => array(
                'leads'     => array_keys( $this->table_columns( $this->leads_table ) ),
                'details'   => array_keys( $this->table_columns( $this->detail_table ) ),
                'relations' => array_keys( $this->related_tables() ),
            ),
        );

        $zip = new ZipArchive();
        if ( true !== $zip->open( $destination, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
            $this->remove_directory( $work );
            return new WP_Error( 'cea_zip_create', __( 'No se ha podido crear el archivo ZIP.', 'crm-entries-analytics' ) );
        }
        $zip->addFromString( 'manifest.json', wp_json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
        $zip->addFile( $entries_path, 'entries.ndjson' );
        $files_dir = $work . '/files';
        if ( is_dir( $files_dir ) ) {
            foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $files_dir, FilesystemIterator::SKIP_DOTS ) ) as $file ) {
                if ( $file->isFile() ) {
                    $relative = str_replace( '\\', '/', substr( $file->getPathname(), strlen( $work ) + 1 ) );
                    $zip->addFile( $file->getPathname(), $relative );
                }
            }
        }
        $closed = $zip->close();
        $this->remove_directory( $work );
        return $closed ? $destination : new WP_Error( 'cea_zip_close', __( 'No se ha podido finalizar el archivo ZIP.', 'crm-entries-analytics' ) );
    }

    private function validate_package( $zip_path, $entries_path ) {
        $zip = new ZipArchive();
        if ( true !== $zip->open( $zip_path ) ) {
            return new WP_Error( 'cea_invalid_zip', __( 'El archivo no es un ZIP válido.', 'crm-entries-analytics' ), array( 'status' => 400 ) );
        }
        $expanded = 0;
        if ( $zip->numFiles > 10000 ) {
            $zip->close();
            return new WP_Error( 'cea_too_many_files', __( 'El paquete contiene demasiados archivos.', 'crm-entries-analytics' ), array( 'status' => 413 ) );
        }
        for ( $i = 0; $i < $zip->numFiles; $i++ ) {
            $stat = $zip->statIndex( $i );
            $name = isset( $stat['name'] ) ? (string) $stat['name'] : '';
            if ( ! $this->safe_archive_path( $name ) ) {
                $zip->close();
                return new WP_Error( 'cea_unsafe_zip', __( 'El paquete contiene una ruta de archivo no segura.', 'crm-entries-analytics' ), array( 'status' => 400 ) );
            }
            $expanded += isset( $stat['size'] ) ? (int) $stat['size'] : 0;
            if ( $expanded > (int) apply_filters( 'cea_max_expanded_bytes', self::MAX_EXPANDED_BYTES ) ) {
                $zip->close();
                return new WP_Error( 'cea_zip_bomb', __( 'El contenido descomprimido supera el límite permitido.', 'crm-entries-analytics' ), array( 'status' => 413 ) );
            }
        }

        $manifest_raw = $zip->getFromName( 'manifest.json', 1048576 );
        $manifest     = json_decode( $manifest_raw, true );
        if ( ! is_array( $manifest ) || self::FORMAT !== ( isset( $manifest['format'] ) ? $manifest['format'] : '' ) || self::FORMAT_VERSION !== (int) ( isset( $manifest['formatVersion'] ) ? $manifest['formatVersion'] : 0 ) ) {
            $zip->close();
            return new WP_Error( 'cea_invalid_manifest', __( 'El manifiesto no pertenece a una versión compatible de CRM Entries Analytics.', 'crm-entries-analytics' ), array( 'status' => 400 ) );
        }
        if ( empty( $manifest['source']['siteUuid'] ) || ! is_scalar( $manifest['source']['siteUuid'] ) || ! isset( $manifest['entryCount'], $manifest['entriesSha256'] ) || ! is_numeric( $manifest['entryCount'] ) || (int) $manifest['entryCount'] < 0 || ! is_string( $manifest['entriesSha256'] ) || ! preg_match( '/^[a-f0-9]{64}$/i', $manifest['entriesSha256'] ) ) {
            $zip->close();
            return new WP_Error( 'cea_incomplete_manifest', __( 'El manifiesto del paquete está incompleto.', 'crm-entries-analytics' ), array( 'status' => 400 ) );
        }

        $input  = $zip->getStream( 'entries.ndjson' );
        $output = fopen( $entries_path, 'wb' );
        if ( ! $input || ! $output ) {
            $zip->close();
            return new WP_Error( 'cea_entries_missing', __( 'El paquete no contiene el archivo de registros.', 'crm-entries-analytics' ), array( 'status' => 400 ) );
        }
        stream_copy_to_stream( $input, $output );
        fclose( $input );
        fclose( $output );
        $zip->close();

        if ( ! hash_equals( strtolower( (string) $manifest['entriesSha256'] ), hash_file( 'sha256', $entries_path ) ) ) {
            return new WP_Error( 'cea_checksum_failed', __( 'La comprobación de integridad del paquete ha fallado.', 'crm-entries-analytics' ), array( 'status' => 400 ) );
        }
        return array( 'manifest' => $manifest );
    }

    private function valid_entry( $entry ) {
        if ( ! is_array( $entry ) || ! isset( $entry['sourceId'], $entry['lead'], $entry['details'] ) || ! is_scalar( $entry['sourceId'] ) || ! is_array( $entry['lead'] ) || ! is_array( $entry['details'] ) ) {
            return false;
        }
        if ( empty( $entry['lead']['form_id'] ) || empty( $entry['lead']['created'] ) || ! is_scalar( $entry['lead']['form_id'] ) || ! is_scalar( $entry['lead']['created'] ) ) {
            return false;
        }
        if ( ! $this->safe_database_row( $entry['lead'] ) ) {
            return false;
        }
        foreach ( $entry['details'] as $row ) {
            if ( ! is_array( $row ) || ! array_key_exists( 'name', $row ) || ! array_key_exists( 'value', $row ) || ! is_scalar( $row['name'] ) || ! $this->safe_database_row( $row ) ) {
                return false;
            }
        }
        foreach ( isset( $entry['relations'] ) && is_array( $entry['relations'] ) ? $entry['relations'] : array() as $rows ) {
            if ( ! is_array( $rows ) ) {
                return false;
            }
            foreach ( $rows as $row ) {
                if ( ! is_array( $row ) || ! $this->safe_database_row( $row ) ) {
                    return false;
                }
            }
        }
        return true;
    }

    private function safe_database_row( array $row ) {
        foreach ( $row as $value ) {
            if ( ( ! is_scalar( $value ) && null !== $value ) || $this->contains_serialized_object( $value ) ) {
                return false;
            }
        }
        return true;
    }

    private function contains_serialized_object( $value ) {
        if ( ! is_string( $value ) || ! is_serialized( $value ) ) {
            return false;
        }
        $decoded = @unserialize( trim( $value ), array( 'allowed_classes' => false, 'max_depth' => 32 ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize
        return $this->contains_object( $decoded );
    }

    private function contains_object( $value ) {
        if ( is_object( $value ) ) {
            return true;
        }
        if ( is_array( $value ) ) {
            foreach ( $value as $child ) {
                if ( $this->contains_object( $child ) ) {
                    return true;
                }
            }
        }
        return false;
    }

    private function find_existing( $source_site, $source_id, array $entry, $target_form ) {
        $mapped = (int) $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT m.local_lead_id FROM {$this->map_table} m INNER JOIN {$this->leads_table} l ON l.id=m.local_lead_id WHERE m.source_site=%s AND m.source_entry_id=%s LIMIT 1",
                $source_site,
                $source_id
            )
        );
        if ( $mapped ) {
            return $mapped;
        }

        $created = isset( $entry['lead']['created'] ) ? (string) $entry['lead']['created'] : '';
        $ids     = $this->wpdb->get_col(
            $this->wpdb->prepare(
                "SELECT id FROM {$this->leads_table} WHERE form_id=%s AND created=%s ORDER BY id ASC LIMIT 25",
                $target_form,
                $created
            )
        );
        $wanted = $this->fingerprint( $entry, $target_form );
        foreach ( $ids as $id ) {
            $lead    = $this->wpdb->get_row( $this->wpdb->prepare( "SELECT * FROM {$this->leads_table} WHERE id=%d", $id ), ARRAY_A );
            $details = $this->wpdb->get_results( $this->wpdb->prepare( "SELECT * FROM {$this->detail_table} WHERE lead_id=%d ORDER BY id ASC", $id ), ARRAY_A );
            if ( $wanted === $this->fingerprint( array( 'lead' => $lead, 'details' => $details ), $target_form ) ) {
                return (int) $id;
            }
        }
        return 0;
    }

    private function fingerprint( array $entry, $form_id ) {
        $details = array();
        foreach ( $entry['details'] as $row ) {
            $details[] = array(
                'name'  => isset( $row['name'] ) ? (string) $row['name'] : '',
                'value' => isset( $row['value'] ) ? (string) $row['value'] : '',
            );
        }
        usort( $details, static function ( $a, $b ) {
            $name = strcmp( $a['name'], $b['name'] );
            return 0 !== $name ? $name : strcmp( $a['value'], $b['value'] );
        } );
        return hash( 'sha256', wp_json_encode( array( 'formId' => (string) $form_id, 'created' => (string) $entry['lead']['created'], 'details' => $details ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
    }

    private function save_entry( array $entry, $existing ) {
        $lead_columns = $this->table_columns( $this->leads_table );
        $lead         = $this->filter_row( $entry['lead'], $lead_columns, array( 'id', 'user_id' ) );
        if ( isset( $lead_columns['user_id'] ) ) {
            $lead['user_id'] = 0;
        }
        if ( empty( $lead['form_id'] ) || empty( $lead['created'] ) ) {
            return new WP_Error( 'cea_invalid_lead', __( 'Un registro no contiene los campos mínimos requeridos.', 'crm-entries-analytics' ) );
        }

        if ( $existing ) {
            if ( false === $this->wpdb->update( $this->leads_table, $lead, array( 'id' => (int) $existing ) ) ) {
                return new WP_Error( 'cea_lead_update', __( 'No se ha podido actualizar un registro.', 'crm-entries-analytics' ) );
            }
            $lead_id = (int) $existing;
            $this->delete_children( array( $lead_id ) );
        } else {
            if ( false === $this->wpdb->insert( $this->leads_table, $lead ) ) {
                return new WP_Error( 'cea_lead_insert', __( 'No se ha podido crear un registro.', 'crm-entries-analytics' ) );
            }
            $lead_id = (int) $this->wpdb->insert_id;
        }

        $result = $this->insert_child_rows( $this->detail_table, $lead_id, $entry['details'] );
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        $relations = isset( $entry['relations'] ) && is_array( $entry['relations'] ) ? $entry['relations'] : array();
        foreach ( $this->related_tables() as $key => $table ) {
            if ( ! empty( $relations[ $key ] ) && is_array( $relations[ $key ] ) ) {
                $result = $this->insert_child_rows( $table, $lead_id, $relations[ $key ] );
                if ( is_wp_error( $result ) ) {
                    return $result;
                }
            }
        }
        return $lead_id;
    }

    private function insert_child_rows( $table, $lead_id, array $rows ) {
        $columns = $this->table_columns( $table );
        foreach ( $rows as $row ) {
            $clean            = $this->filter_row( $row, $columns, array( 'id', 'lead_id' ) );
            $clean['lead_id'] = $lead_id;
            if ( false === $this->wpdb->insert( $table, $clean ) ) {
                return new WP_Error( 'cea_child_insert', __( 'No se han podido guardar todos los campos asociados.', 'crm-entries-analytics' ) );
            }
        }
        return true;
    }

    private function filter_row( array $row, array $columns, array $excluded ) {
        $clean = array();
        foreach ( $row as $key => $value ) {
            if ( isset( $columns[ $key ] ) && ! in_array( $key, $excluded, true ) && ( is_scalar( $value ) || null === $value ) ) {
                $clean[ $key ] = $value;
            }
        }
        return $clean;
    }

    private function save_mapping( $source_site, $source_id, $lead_id, $fingerprint ) {
        $this->wpdb->replace(
            $this->map_table,
            array(
                'source_site'     => substr( $source_site, 0, 64 ),
                'source_entry_id' => substr( $source_id, 0, 191 ),
                'local_lead_id'   => (int) $lead_id,
                'fingerprint'     => $fingerprint,
                'imported_at'     => current_time( 'mysql', true ),
            )
        );
    }

    private function clear_records( $scope, array $mapping, array $manifest ) {
        $ids = array();
        if ( 'forms' === $scope ) {
            $forms = array();
            foreach ( isset( $manifest['forms'] ) && is_array( $manifest['forms'] ) ? $manifest['forms'] : array() as $form ) {
                if ( ! is_array( $form ) || empty( $form['id'] ) || ! is_scalar( $form['id'] ) ) {
                    continue;
                }
                $source  = (string) $form['id'];
                $forms[] = isset( $mapping[ $source ] ) && '' !== $mapping[ $source ] ? $mapping[ $source ] : $source;
            }
            $forms = array_values( array_unique( array_filter( $forms ) ) );
            if ( ! $forms ) {
                return new WP_Error( 'cea_no_replace_forms', __( 'El paquete no contiene formularios que se puedan sustituir.', 'crm-entries-analytics' ) );
            }
            $placeholders = implode( ',', array_fill( 0, count( $forms ), '%s' ) );
            $ids          = $this->wpdb->get_col( $this->wpdb->prepare( "SELECT id FROM {$this->leads_table} WHERE form_id IN ({$placeholders})", $forms ) );
        } else {
            $ids = $this->wpdb->get_col( "SELECT id FROM {$this->leads_table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        }
        $ids = array_map( 'intval', $ids );
        if ( ! $ids ) {
            return true;
        }
        $this->wpdb->query( 'START TRANSACTION' );
        $this->delete_children( $ids );
        foreach ( array_chunk( $ids, 500 ) as $chunk ) {
            $list = implode( ',', array_map( 'intval', $chunk ) );
            if ( false === $this->wpdb->query( "DELETE FROM {$this->leads_table} WHERE id IN ({$list})" ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $this->wpdb->query( 'ROLLBACK' );
                return new WP_Error( 'cea_replace_delete', __( 'No se han podido borrar los registros actuales. No se ha iniciado la restauración.', 'crm-entries-analytics' ) );
            }
            $this->wpdb->query( "DELETE FROM {$this->map_table} WHERE local_lead_id IN ({$list})" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        }
        $this->wpdb->query( 'COMMIT' );
        return true;
    }

    private function delete_children( array $ids ) {
        foreach ( array_chunk( $ids, 500 ) as $chunk ) {
            $list = implode( ',', array_map( 'intval', $chunk ) );
            if ( false === $this->wpdb->query( "DELETE FROM {$this->detail_table} WHERE lead_id IN ({$list})" ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                throw new RuntimeException( 'Could not delete CRM entry details.' );
            }
            foreach ( $this->related_tables() as $table ) {
                if ( false === $this->wpdb->query( "DELETE FROM {$table} WHERE lead_id IN ({$list})" ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                    throw new RuntimeException( 'Could not delete CRM entry relations.' );
                }
            }
        }
    }

    private function create_automatic_backup() {
        $this->ensure_directory( $this->storage_root() . '/backups' );
        $path = $this->storage_root() . '/backups/automatic-' . gmdate( 'Y-m-d-His' ) . '-' . wp_generate_password( 8, false, false ) . '.cea.zip';
        $args = array( 'format' => 'package', 'form_id' => '', 'start' => '', 'end' => '', 'start_utc' => '', 'end_utc' => '', 'include_deleted' => true, 'include_files' => true );
        $result = $this->build_package( $args, $path );
        if ( ! is_wp_error( $result ) ) {
            $this->prune_backups();
        }
        return $result;
    }

    private function restore_entry_files( array $entry, array &$state ) {
        $files = isset( $entry['files'] ) && is_array( $entry['files'] ) ? $entry['files'] : array();
        if ( ! $files ) {
            return $entry;
        }
        $zip = new ZipArchive();
        if ( true !== $zip->open( $state['zipPath'] ) ) {
            return new WP_Error( 'cea_restore_zip', __( 'No se ha podido volver a abrir el paquete.', 'crm-entries-analytics' ) );
        }
        foreach ( $files as $old_url => $archive_path ) {
            if ( ! is_string( $old_url ) || ! is_string( $archive_path ) ) {
                $zip->close();
                return new WP_Error( 'cea_invalid_file_map', __( 'El mapa de archivos del paquete no es válido.', 'crm-entries-analytics' ) );
            }
            if ( isset( $state['fileMap'][ $old_url ] ) ) {
                continue;
            }
            if ( 0 !== strpos( $archive_path, 'files/' ) || ! $this->safe_archive_path( $archive_path ) ) {
                $zip->close();
                return new WP_Error( 'cea_unsafe_file', __( 'El paquete contiene una referencia de archivo no segura.', 'crm-entries-analytics' ) );
            }
            $stat = $zip->statName( $archive_path );
            $ext  = strtolower( pathinfo( $archive_path, PATHINFO_EXTENSION ) );
            if ( ! $stat || (int) $stat['size'] > 52428800 || in_array( $ext, array( 'php', 'phtml', 'phar', 'htaccess', 'ini' ), true ) || ! wp_check_filetype( basename( $archive_path ) )['type'] ) {
                $zip->close();
                return new WP_Error( 'cea_disallowed_file', __( 'El paquete contiene un archivo no permitido.', 'crm-entries-analytics' ) );
            }
            $upload = wp_upload_dir();
            $dir    = trailingslashit( $upload['basedir'] ) . 'cea-migrated/' . gmdate( 'Y/m' );
            $this->ensure_directory( $dir );
            $name = substr( hash( 'sha256', $state['manifest']['source']['siteUuid'] . '|' . $archive_path ), 0, 16 ) . '-' . sanitize_file_name( basename( $archive_path ) );
            $path = trailingslashit( $dir ) . $name;
            if ( ! is_file( $path ) ) {
                $input = $zip->getStream( $archive_path );
                $out   = fopen( $path, 'wb' );
                if ( ! $input || ! $out ) {
                    $zip->close();
                    return new WP_Error( 'cea_file_write', __( 'No se ha podido restaurar un archivo adjunto.', 'crm-entries-analytics' ) );
                }
                stream_copy_to_stream( $input, $out );
                fclose( $input );
                fclose( $out );
            }
            $state['fileMap'][ $old_url ] = trailingslashit( $upload['baseurl'] ) . 'cea-migrated/' . gmdate( 'Y/m' ) . '/' . rawurlencode( $name );
        }
        $zip->close();
        foreach ( $entry['details'] as &$row ) {
            if ( isset( $row['value'] ) ) {
                $row['value'] = $this->rewrite_stored_value( $row['value'], $state['fileMap'] );
            }
        }
        unset( $row );
        return $entry;
    }

    private function rewrite_stored_value( $value, array $map ) {
        if ( is_string( $value ) && is_serialized( $value ) ) {
            $decoded = @unserialize( trim( $value ), array( 'allowed_classes' => false, 'max_depth' => 32 ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize
            if ( ! $this->contains_object( $decoded ) ) {
                return maybe_serialize( $this->rewrite_recursive( $decoded, $map ) );
            }
        }
        return isset( $map[ $value ] ) ? $map[ $value ] : $value;
    }

    private function rewrite_recursive( $value, array $map ) {
        if ( is_array( $value ) ) {
            foreach ( $value as $key => $child ) {
                $value[ $key ] = $this->rewrite_recursive( $child, $map );
            }
            return $value;
        }
        return is_string( $value ) && isset( $map[ $value ] ) ? $map[ $value ] : $value;
    }

    private function collect_files( array $details, $files_dir ) {
        $upload = wp_upload_dir();
        $found  = array();
        foreach ( $details as $row ) {
            if ( ! isset( $row['value'] ) || $this->contains_serialized_object( $row['value'] ) ) {
                continue;
            }
            $value = $row['value'];
            if ( is_string( $value ) && is_serialized( $value ) ) {
                $value = @unserialize( trim( $value ), array( 'allowed_classes' => false, 'max_depth' => 32 ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize
            }
            foreach ( $this->flatten_strings( $value ) as $url ) {
                if ( 0 !== strpos( $url, $upload['baseurl'] ) ) {
                    continue;
                }
                $relative = ltrim( rawurldecode( substr( $url, strlen( $upload['baseurl'] ) ) ), '/\\' );
                $path     = wp_normalize_path( trailingslashit( $upload['basedir'] ) . $relative );
                $base     = trailingslashit( wp_normalize_path( $upload['basedir'] ) );
                if ( 0 !== strpos( $path, $base ) || ! is_file( $path ) || filesize( $path ) > 52428800 ) {
                    continue;
                }
                $archive = 'files/' . substr( hash_file( 'sha256', $path ), 0, 20 ) . '-' . sanitize_file_name( basename( $path ) );
                $target  = dirname( $files_dir ) . '/' . $archive;
                $this->ensure_directory( dirname( $target ) );
                if ( ! is_file( $target ) ) {
                    copy( $path, $target );
                }
                $found[ $url ] = $archive;
            }
        }
        return $found;
    }

    private function flatten_strings( $value ) {
        $out = array();
        if ( is_string( $value ) ) {
            $out[] = $value;
        } elseif ( is_array( $value ) ) {
            foreach ( $value as $child ) {
                $out = array_merge( $out, $this->flatten_strings( $child ) );
            }
        }
        return $out;
    }

    private function export_leads( array $args, $after_id, $limit ) {
        $where  = array( 'id > %d' );
        $params = array( (int) $after_id );
        if ( ! $args['include_deleted'] ) {
            $where[] = 'status = 0';
        }
        if ( $args['form_id'] ) {
            $where[]  = 'form_id = %s';
            $params[] = $args['form_id'];
        }
        if ( $args['start_utc'] ) {
            $where[]  = 'created >= %s';
            $params[] = $args['start_utc'];
        }
        if ( $args['end_utc'] ) {
            $where[]  = 'created <= %s';
            $params[] = $args['end_utc'];
        }
        $params[] = (int) $limit;
        $sql      = "SELECT * FROM {$this->leads_table} WHERE " . implode( ' AND ', $where ) . ' ORDER BY id ASC LIMIT %d';
        return $this->wpdb->get_results( $this->wpdb->prepare( $sql, $params ), ARRAY_A );
    }

    private function rows_by_lead( $table, array $ids ) {
        if ( ! $ids ) {
            return array();
        }
        $list = implode( ',', array_map( 'intval', $ids ) );
        $rows = $this->wpdb->get_results( "SELECT * FROM {$table} WHERE lead_id IN ({$list}) ORDER BY id ASC", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $out  = array();
        foreach ( $rows as $row ) {
            $id = (int) $row['lead_id'];
            if ( ! isset( $out[ $id ] ) ) {
                $out[ $id ] = array();
            }
            $out[ $id ][] = $row;
        }
        return $out;
    }

    private function related_tables() {
        static $tables = null;
        if ( null !== $tables ) {
            return $tables;
        }
        $tables = array();
        $base   = $this->wpdb->prefix . 'vxcf_leads_';
        $found  = $this->wpdb->get_col( $this->wpdb->prepare( 'SHOW TABLES LIKE %s', $this->wpdb->esc_like( $base ) . '%' ) );
        foreach ( $found as $table ) {
            $key = substr( $table, strlen( $base ) );
            if ( $this->detail_table !== $table && preg_match( '/^[a-zA-Z0-9_]+$/', $key ) && isset( $this->table_columns( $table )['lead_id'] ) ) {
                $tables[ $key ] = $table;
            }
        }
        return $tables;
    }

    private function table_columns( $table ) {
        static $cache = array();
        if ( isset( $cache[ $table ] ) ) {
            return $cache[ $table ];
        }
        $cache[ $table ] = array();
        foreach ( $this->wpdb->get_results( "DESCRIBE {$table}", ARRAY_A ) as $column ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $cache[ $table ][ $column['Field'] ] = $column;
        }
        return $cache[ $table ];
    }

    private function export_args_from_request() {
        $timezone = wp_timezone();
        $start    = isset( $_POST['start'] ) ? sanitize_text_field( wp_unslash( $_POST['start'] ) ) : '';
        $end      = isset( $_POST['end'] ) ? sanitize_text_field( wp_unslash( $_POST['end'] ) ) : '';
        $from     = $this->parse_date( $start, $timezone, false );
        $to       = $this->parse_date( $end, $timezone, true );
        if ( ( $start && ! $from ) || ( $end && ! $to ) ) {
            wp_die( esc_html__( 'Las fechas de exportación no son válidas.', 'crm-entries-analytics' ) );
        }
        return array(
            'format'          => isset( $_POST['format'] ) && 'csv' === sanitize_key( wp_unslash( $_POST['format'] ) ) ? 'csv' : 'package',
            'form_id'         => isset( $_POST['form_id'] ) ? sanitize_text_field( wp_unslash( $_POST['form_id'] ) ) : '',
            'start'           => $from ? $start : '',
            'end'             => $to ? $end : '',
            'start_utc'       => $from ? $from->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ) : '',
            'end_utc'         => $to ? $to->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ) : '',
            'include_deleted' => ! empty( $_POST['include_deleted'] ),
            'include_files'   => ! empty( $_POST['include_files'] ),
        );
    }

    private function parse_date( $date, DateTimeZone $timezone, $end ) {
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
            return null;
        }
        $parsed = DateTimeImmutable::createFromFormat( '!Y-m-d', $date, $timezone );
        return $parsed && $parsed->format( 'Y-m-d' ) === $date ? $parsed->setTime( $end ? 23 : 0, $end ? 59 : 0, $end ? 59 : 0 ) : null;
    }

    private function stream_csv( array $args ) {
        $name = 'crm-entries-' . gmdate( 'Y-m-d-His' ) . '.csv';
        nocache_headers();
        header( 'Content-Type: text/csv; charset=UTF-8' );
        header( 'Content-Disposition: attachment; filename="' . $name . '"' );
        $output = fopen( 'php://output', 'wb' );
        fwrite( $output, "\xEF\xBB\xBF" );
        $fields = $this->wpdb->get_col( "SELECT DISTINCT name FROM {$this->detail_table} ORDER BY name ASC" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $main   = array( 'id', 'form_id', 'is_read', 'status', 'url', 'ip', 'browser', 'os', 'created', 'updated' );
        $main   = array_values( array_intersect( $main, array_keys( $this->table_columns( $this->leads_table ) ) ) );
        fputcsv( $output, array_merge( $main, $fields ) );
        $last = 0;
        do {
            $leads   = $this->export_leads( $args, $last, 250 );
            $details = $this->rows_by_lead( $this->detail_table, array_map( 'intval', wp_list_pluck( $leads, 'id' ) ) );
            foreach ( $leads as $lead ) {
                $last = (int) $lead['id'];
                $row  = array();
                foreach ( $main as $key ) {
                    $row[] = $this->csv_safe( isset( $lead[ $key ] ) ? $lead[ $key ] : '' );
                }
                $values = array();
                foreach ( isset( $details[ $last ] ) ? $details[ $last ] : array() as $detail ) {
                    $values[ $detail['name'] ] = $detail['value'];
                }
                foreach ( $fields as $field ) {
                    $row[] = $this->csv_safe( isset( $values[ $field ] ) ? $values[ $field ] : '' );
                }
                fputcsv( $output, $row );
            }
        } while ( count( $leads ) === 250 );
        fclose( $output );
        exit;
    }

    private function csv_safe( $value ) {
        if ( is_string( $value ) && is_serialized( $value ) && ! $this->contains_serialized_object( $value ) ) {
            $decoded = @unserialize( trim( $value ), array( 'allowed_classes' => false, 'max_depth' => 32 ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize
            $value   = is_array( $decoded ) ? implode( ' | ', $this->flatten_strings( $decoded ) ) : $decoded;
        }
        $value = (string) $value;
        return preg_match( '/^[=+\-@\t\r]/', $value ) ? "'" . $value : $value;
    }

    private function sanitize_form_mapping( $mapping ) {
        $clean = array();
        if ( ! is_array( $mapping ) ) {
            return $clean;
        }
        foreach ( $mapping as $source => $target ) {
            $source = sanitize_text_field( (string) $source );
            if ( '' !== $source ) {
                $clean[ $source ] = sanitize_text_field( (string) $target );
            }
        }
        ksort( $clean );
        return $clean;
    }

    private function public_manifest( array $manifest ) {
        $forms = array();
        foreach ( isset( $manifest['forms'] ) && is_array( $manifest['forms'] ) ? $manifest['forms'] : array() as $form ) {
            if ( is_array( $form ) && isset( $form['id'], $form['label'] ) && is_scalar( $form['id'] ) && is_scalar( $form['label'] ) ) {
                $forms[] = array( 'id' => sanitize_text_field( (string) $form['id'] ), 'label' => sanitize_text_field( (string) $form['label'] ) );
            }
        }
        $source = isset( $manifest['source'] ) && is_array( $manifest['source'] ) ? $manifest['source'] : array();
        return array(
            'createdAt'  => isset( $manifest['createdAt'] ) ? $manifest['createdAt'] : '',
            'entryCount' => (int) $manifest['entryCount'],
            'fileCount'  => isset( $manifest['fileCount'] ) ? (int) $manifest['fileCount'] : 0,
            'source'     => array(
                'siteUrl'    => isset( $source['siteUrl'] ) && is_scalar( $source['siteUrl'] ) ? esc_url_raw( (string) $source['siteUrl'] ) : '',
                'timezone'   => isset( $source['timezone'] ) && is_scalar( $source['timezone'] ) ? sanitize_text_field( (string) $source['timezone'] ) : '',
                'ceaVersion' => isset( $source['ceaVersion'] ) && is_scalar( $source['ceaVersion'] ) ? sanitize_text_field( (string) $source['ceaVersion'] ) : '',
                'crmVersion' => isset( $source['crmVersion'] ) && is_scalar( $source['crmVersion'] ) ? sanitize_text_field( (string) $source['crmVersion'] ) : '',
            ),
            'forms'      => $forms,
        );
    }

    private function format_forms( array $forms ) {
        $out = array();
        foreach ( $forms as $id => $label ) {
            $out[] = array( 'id' => (string) $id, 'label' => (string) $label );
        }
        return $out;
    }

    private function progress_response( array $state ) {
        return array(
            'done'      => (bool) $state['done'],
            'processed' => (int) $state['processed'],
            'total'     => (int) $state['manifest']['entryCount'],
            'created'   => (int) $state['created'],
            'updated'   => (int) $state['updated'],
            'skipped'   => (int) $state['skipped'],
            'failed'    => (int) $state['failed'],
            'backup'    => isset( $state['backup'] ) ? $state['backup'] : '',
        );
    }

    private function state_path( $token ) {
        return $this->storage_root() . '/jobs/' . $token . '/state.json';
    }

    private function save_state( array $state ) {
        if ( false === file_put_contents( $this->state_path( $state['token'] ), wp_json_encode( $state ), LOCK_EX ) ) {
            throw new RuntimeException( 'Could not persist the migration state.' );
        }
    }

    private function load_state( $token ) {
        if ( ! preg_match( '/^[a-f0-9]{32}$/', $token ) || ! is_file( $this->state_path( $token ) ) ) {
            return new WP_Error( 'cea_job_missing', __( 'La sesión de importación no existe o ha caducado.', 'crm-entries-analytics' ), array( 'status' => 410 ) );
        }
        $state = json_decode( file_get_contents( $this->state_path( $token ) ), true );
        if ( ! is_array( $state ) || (int) $state['userId'] !== get_current_user_id() || time() - (int) $state['createdAt'] > DAY_IN_SECONDS ) {
            return new WP_Error( 'cea_job_expired', __( 'La sesión de importación no es válida.', 'crm-entries-analytics' ), array( 'status' => 403 ) );
        }
        return $state;
    }

    private function acquire_lock( $token ) {
        $lock = get_option( 'cea_migration_lock' );
        if ( is_array( $lock ) && ! empty( $lock['time'] ) && time() - (int) $lock['time'] < 120 && $token !== $lock['token'] ) {
            return false;
        }
        delete_option( 'cea_migration_lock' );
        return add_option( 'cea_migration_lock', array( 'token' => $token, 'time' => time() ), '', false );
    }

    public function cleanup_expired_jobs() {
        if ( get_transient( 'cea_migration_cleanup' ) ) {
            return;
        }
        set_transient( 'cea_migration_cleanup', 1, HOUR_IN_SECONDS );
        $dir = $this->storage_root() . '/jobs';
        if ( ! is_dir( $dir ) ) {
            return;
        }
        foreach ( glob( $dir . '/*', GLOB_ONLYDIR ) as $job ) {
            if ( filemtime( $job ) < time() - DAY_IN_SECONDS ) {
                $this->remove_directory( $job );
            }
        }
    }

    private function release_lock( $token ) {
        $lock = get_option( 'cea_migration_lock' );
        if ( is_array( $lock ) && isset( $lock['token'] ) && $token === $lock['token'] ) {
            delete_option( 'cea_migration_lock' );
        }
    }

    private function storage_root() {
        $upload = wp_upload_dir();
        $root   = trailingslashit( $upload['basedir'] ) . 'cea-private-migrations';
        $this->ensure_directory( $root );
        return $root;
    }

    private function ensure_directory( $path ) {
        if ( ! is_dir( $path ) ) {
            wp_mkdir_p( $path );
        }
        $root = trailingslashit( $path );
        if ( ! is_file( $root . 'index.php' ) ) {
            file_put_contents( $root . 'index.php', "<?php\n// Silence is golden.\n" );
        }
        if ( ! is_file( $root . '.htaccess' ) ) {
            file_put_contents( $root . '.htaccess', "Deny from all\n" );
        }
        if ( ! is_file( $root . 'web.config' ) ) {
            file_put_contents( $root . 'web.config', '<configuration><system.webServer><authorization><deny users="*" /></authorization></system.webServer></configuration>' );
        }
    }

    private function remove_directory( $path ) {
        $root = wp_normalize_path( $this->storage_root() );
        $path = wp_normalize_path( $path );
        if ( 0 !== strpos( trailingslashit( $path ), trailingslashit( $root ) ) || ! is_dir( $path ) ) {
            return;
        }
        $items = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
        foreach ( $items as $item ) {
            $item->isDir() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() );
        }
        rmdir( $path );
    }

    private function safe_archive_path( $path ) {
        return '' !== $path && false === strpos( $path, '..' ) && ! preg_match( '#^(?:[a-zA-Z]:|[\\/])#', $path ) && false === strpos( $path, '\\' );
    }

    private function assert_admin_action( $nonce_action ) {
        if ( ! current_user_can( CEA_MIGRATION_CAPABILITY ) && ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'No tienes permisos para realizar esta operación.', 'crm-entries-analytics' ) );
        }
        check_admin_referer( $nonce_action );
    }

    private function send_file( $path, $name, $delete ) {
        if ( ! is_file( $path ) ) {
            wp_die( esc_html__( 'No se ha podido generar el archivo.', 'crm-entries-analytics' ) );
        }
        nocache_headers();
        header( 'Content-Type: application/zip' );
        header( 'Content-Length: ' . filesize( $path ) );
        header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $name ) . '"' );
        readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
        if ( $delete ) {
            unlink( $path );
        }
        exit;
    }

    private function list_backups() {
        $dir = $this->storage_root() . '/backups';
        if ( ! is_dir( $dir ) ) {
            return array();
        }
        $files = glob( $dir . '/*.cea.zip' );
        usort( $files, static function ( $a, $b ) { return filemtime( $b ) <=> filemtime( $a ); } );
        $out = array();
        foreach ( array_slice( $files, 0, 5 ) as $file ) {
            $name  = basename( $file );
            $out[] = array(
                'name' => $name,
                'url'  => wp_nonce_url( add_query_arg( array( 'action' => 'cea_download_backup', 'file' => $name ), admin_url( 'admin-post.php' ) ), 'cea_download_backup' ),
            );
        }
        return $out;
    }

    private function prune_backups() {
        $files = glob( $this->storage_root() . '/backups/*.cea.zip' );
        usort( $files, static function ( $a, $b ) { return filemtime( $b ) <=> filemtime( $a ); } );
        foreach ( array_slice( $files, 5 ) as $file ) {
            unlink( $file );
        }
    }
}
