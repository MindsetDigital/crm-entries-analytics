<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CEA_Admin {
    private $db;

    public function __construct( CEA_DB $db ) {
        $this->db = $db;
        add_action( 'admin_menu', array( $this, 'menu' ), 99 );
        add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
    }

    public function menu() {
        add_submenu_page(
            'vxcf_leads',
            __( 'Analítica CRM', 'crm-entries-analytics' ),
            __( 'Analítica', 'crm-entries-analytics' ),
            CEA_CAPABILITY,
            'crm-entries-analytics',
            array( $this, 'render' )
        );
    }

    public function assets() {
        if ( empty( $_GET['page'] ) || 'crm-entries-analytics' !== sanitize_key( wp_unslash( $_GET['page'] ) ) ) {
            return;
        }

        wp_enqueue_style( 'cea-admin', CEA_URL . 'assets/admin.css', array(), CEA_VERSION );
        wp_enqueue_script( 'cea-echarts', CEA_URL . 'assets/vendor/echarts/echarts.min.js', array(), '6.1.0', true );
        wp_enqueue_script( 'cea-admin', CEA_URL . 'assets/admin.js', array( 'cea-echarts' ), CEA_VERSION, true );
        wp_localize_script(
            'cea-admin',
            'CEA_CONFIG',
            array(
                'restUrl'     => esc_url_raw( rest_url( 'crm-entries-analytics/v1/' ) ),
                'nonce'       => wp_create_nonce( 'wp_rest' ),
                'locale'      => str_replace( '_', '-', determine_locale() ),
                'timezone'    => wp_timezone_string(),
                'crmUrl'      => admin_url( 'admin.php?page=vxcf_leads' ),
                'reportUrl'   => esc_url_raw( admin_url( 'admin-post.php' ) ),
                'reportNonce' => wp_create_nonce( 'cea_export_pdf' ),
                'exportUrl'   => esc_url_raw( admin_url( 'admin.php?page=' . CEA_Export::PAGE ) ),
            )
        );
    }

    public function render() {
        if ( ! current_user_can( CEA_CAPABILITY ) && ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'No tienes permisos para ver esta página.', 'crm-entries-analytics' ) );
        }

        ?>
        <div class="wrap cea-wrap">
            <div class="cea-header">
                <div>
                    <h1><?php esc_html_e( 'Analítica de leads', 'crm-entries-analytics' ); ?></h1>
                    <p><?php esc_html_e( 'Datos captados por Contact Form Entries. Las consultas son de solo lectura.', 'crm-entries-analytics' ); ?></p>
                </div>
                <div class="cea-header-actions">
                    <button type="button" class="button button-primary" id="cea-export-pdf"><?php esc_html_e( 'Descargar informe PDF', 'crm-entries-analytics' ); ?></button>
                    <button type="button" class="button" id="cea-export-csv"><?php esc_html_e( 'Exportar CSV', 'crm-entries-analytics' ); ?></button>
                    <a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=vxcf_leads' ) ); ?>"><?php esc_html_e( 'Abrir CRM Entries', 'crm-entries-analytics' ); ?></a>
                </div>
            </div>

            <?php if ( ! $this->db->tables_exist() ) : ?>
                <div class="notice notice-error inline"><p><?php esc_html_e( 'No se encuentran las tablas de Contact Form Entries. Activa el plugin y comprueba que haya registrado al menos un formulario.', 'crm-entries-analytics' ); ?></p></div>
            <?php endif; ?>

            <section class="cea-card cea-filters" aria-label="Filtros">
                <div class="cea-field">
                    <label for="cea-date-preset"><?php esc_html_e( 'Rango rápido', 'crm-entries-analytics' ); ?></label>
                    <select id="cea-date-preset">
                        <option value="custom"><?php esc_html_e( 'Personalizado', 'crm-entries-analytics' ); ?></option>
                        <option value="7"><?php esc_html_e( 'Últimos 7 días', 'crm-entries-analytics' ); ?></option>
                        <option value="30"><?php esc_html_e( 'Último mes', 'crm-entries-analytics' ); ?></option>
                        <option value="90"><?php esc_html_e( 'Último trimestre', 'crm-entries-analytics' ); ?></option>
                        <option value="365"><?php esc_html_e( 'Último año', 'crm-entries-analytics' ); ?></option>
                    </select>
                </div>
                <div class="cea-field">
                    <label for="cea-start"><?php esc_html_e( 'Desde', 'crm-entries-analytics' ); ?></label>
                    <input type="date" id="cea-start">
                </div>
                <div class="cea-field">
                    <label for="cea-end"><?php esc_html_e( 'Hasta', 'crm-entries-analytics' ); ?></label>
                    <input type="date" id="cea-end">
                </div>
                <div class="cea-field cea-field-wide">
                    <label for="cea-form"><?php esc_html_e( 'Formulario', 'crm-entries-analytics' ); ?></label>
                    <select id="cea-form"><option value=""><?php esc_html_e( 'Todos los formularios', 'crm-entries-analytics' ); ?></option></select>
                </div>
                <div class="cea-field">
                    <label for="cea-status"><?php esc_html_e( 'Lectura', 'crm-entries-analytics' ); ?></label>
                    <select id="cea-status">
                        <option value="all"><?php esc_html_e( 'Todos', 'crm-entries-analytics' ); ?></option>
                        <option value="unread"><?php esc_html_e( 'No leídos', 'crm-entries-analytics' ); ?></option>
                        <option value="read"><?php esc_html_e( 'Leídos', 'crm-entries-analytics' ); ?></option>
                    </select>
                </div>
                <div class="cea-filter-actions">
                    <button type="button" class="button button-primary" id="cea-apply"><?php esc_html_e( 'Aplicar filtros', 'crm-entries-analytics' ); ?></button>
                    <button type="button" class="button" id="cea-reset"><?php esc_html_e( 'Restablecer', 'crm-entries-analytics' ); ?></button>
                </div>
            </section>

            <div id="cea-error" class="notice notice-error inline cea-hidden"><p></p></div>
            <div id="cea-loading" class="cea-loading"><span class="spinner is-active"></span><?php esc_html_e( 'Cargando datos…', 'crm-entries-analytics' ); ?></div>

            <section class="cea-stats" aria-label="Resumen">
                <article class="cea-stat cea-card"><span><?php esc_html_e( 'Contactos totales', 'crm-entries-analytics' ); ?></span><strong id="cea-total">—</strong></article>
                <article class="cea-stat cea-card"><span><?php esc_html_e( 'Contactos leídos', 'crm-entries-analytics' ); ?></span><strong id="cea-read">—</strong><small id="cea-read-percent"></small></article>
                <article class="cea-stat cea-card"><span><?php esc_html_e( 'Contactos no leídos', 'crm-entries-analytics' ); ?></span><strong id="cea-unread">—</strong><small id="cea-unread-percent"></small></article>
            </section>

            <section class="cea-card cea-panel">
                <div class="cea-panel-head cea-chart-head">
                    <div>
                        <h2><?php esc_html_e( 'Evolución de leads por formulario', 'crm-entries-analytics' ); ?></h2>
                        <p id="cea-timeline-caption"></p>
                    </div>
                    <label class="cea-toggle" for="cea-trend-toggle">
                        <input type="checkbox" id="cea-trend-toggle">
                        <span><?php esc_html_e( 'Línea de tendencia', 'crm-entries-analytics' ); ?></span>
                    </label>
                </div>
                <div id="cea-series-legend" class="cea-series-legend" aria-label="<?php esc_attr_e( 'Leyenda del gráfico', 'crm-entries-analytics' ); ?>"></div>
                <div id="cea-line-chart" class="cea-chart" aria-label="<?php esc_attr_e( 'Gráfico de evolución por formulario', 'crm-entries-analytics' ); ?>"></div>
            </section>

            <section class="cea-form-comparison" aria-labelledby="cea-form-comparison-title">
                <div class="cea-section-head">
                    <div>
                        <h2 id="cea-form-comparison-title"><?php esc_html_e( 'Leads por formulario', 'crm-entries-analytics' ); ?></h2>
                        <p id="cea-comparison-caption"></p>
                    </div>
                </div>
                <div id="cea-form-cards" class="cea-form-cards"></div>
            </section>

            <section class="cea-card cea-panel">
                <div class="cea-panel-head"><div><h2><?php esc_html_e( 'Mapa de calor por día y hora', 'crm-entries-analytics' ); ?></h2><p><?php esc_html_e( 'La intensidad indica cuántos leads se reciben en cada franja horaria.', 'crm-entries-analytics' ); ?> <span id="cea-timezone"></span></p></div></div>
                <div id="cea-heatmap" class="cea-heatmap-wrap"></div>
            </section>

            <section class="cea-card cea-panel cea-leads-panel">
                <div class="cea-panel-head cea-table-head">
                    <div><h2><?php esc_html_e( 'Todos los leads', 'crm-entries-analytics' ); ?></h2><p id="cea-table-count"></p></div>
                    <div class="cea-search-wrap">
                        <label class="screen-reader-text" for="cea-search"><?php esc_html_e( 'Buscar por nombre o email', 'crm-entries-analytics' ); ?></label>
                        <input type="search" id="cea-search" placeholder="<?php esc_attr_e( 'Buscar por nombre o email…', 'crm-entries-analytics' ); ?>">
                        <button type="button" class="button" id="cea-search-button"><?php esc_html_e( 'Buscar', 'crm-entries-analytics' ); ?></button>
                    </div>
                </div>
                <div class="cea-table-scroll">
                    <table class="widefat fixed striped cea-table">
                        <thead><tr>
                            <th class="cea-col-status"><?php esc_html_e( 'Estado', 'crm-entries-analytics' ); ?></th>
                            <th><?php esc_html_e( 'Fecha', 'crm-entries-analytics' ); ?></th>
                            <th><?php esc_html_e( 'Nombre', 'crm-entries-analytics' ); ?></th>
                            <th><?php esc_html_e( 'Email', 'crm-entries-analytics' ); ?></th>
                            <th><?php esc_html_e( 'Formulario', 'crm-entries-analytics' ); ?></th>
                            <th><?php esc_html_e( 'Página de origen', 'crm-entries-analytics' ); ?></th>
                            <th class="cea-col-actions"><?php esc_html_e( 'Acciones', 'crm-entries-analytics' ); ?></th>
                        </tr></thead>
                        <tbody id="cea-leads-body"></tbody>
                    </table>
                </div>
                <div class="cea-pagination">
                    <button type="button" class="button" id="cea-prev"><?php esc_html_e( 'Anterior', 'crm-entries-analytics' ); ?></button>
                    <span id="cea-page-info"></span>
                    <button type="button" class="button" id="cea-next"><?php esc_html_e( 'Siguiente', 'crm-entries-analytics' ); ?></button>
                    <select id="cea-per-page" aria-label="Resultados por página"><option>25</option><option>50</option><option>100</option></select>
                </div>
            </section>
        </div>

        <div class="cea-modal cea-hidden" id="cea-modal" role="dialog" aria-modal="true" aria-labelledby="cea-modal-title">
            <div class="cea-modal-backdrop" data-cea-close></div>
            <div class="cea-modal-dialog">
                <button type="button" class="cea-modal-close" data-cea-close aria-label="Cerrar">×</button>
                <div id="cea-modal-content"></div>
            </div>
        </div>
        <?php
    }
}
