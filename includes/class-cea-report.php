<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CEA_Report {
    const PRIMARY_COLOR   = '#1063ff';
    const SECONDARY_COLOR = '#f0b138';
    const COMPANY_NAME    = 'Mindset Digital';
    const COMPANY_URL     = 'mindsetdigital.io';
    const MAX_CHART_IMAGE_BYTES = 4194304;

    private $db;

    public function __construct( CEA_DB $db ) {
        $this->db = $db;
        add_action( 'admin_post_cea_export_pdf', array( $this, 'export' ) );
    }

    public function export() {
        if ( ! current_user_can( CEA_CAPABILITY )
            && ! current_user_can( 'vxcf_leads_read_entries' )
            && ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'No tienes permisos para generar este informe.', 'crm-entries-analytics' ), '', array( 'response' => 403 ) );
        }

        check_admin_referer( 'cea_export_pdf' );

        if ( ! $this->db->tables_exist() ) {
            wp_die( esc_html__( 'No se encuentran las tablas de Contact Form Entries.', 'crm-entries-analytics' ), '', array( 'response' => 503 ) );
        }

        $autoload = CEA_PATH . 'vendor/autoload.php';
        if ( ! file_exists( $autoload ) ) {
            wp_die( esc_html__( 'No está instalada la dependencia necesaria para generar PDF.', 'crm-entries-analytics' ), '', array( 'response' => 500 ) );
        }

        require_once $autoload;

        if ( ! class_exists( '\Mpdf\Mpdf' ) ) {
            wp_die( esc_html__( 'No se ha podido iniciar el generador de PDF.', 'crm-entries-analytics' ), '', array( 'response' => 500 ) );
        }

        if ( ! extension_loaded( 'mbstring' ) || ! extension_loaded( 'gd' ) ) {
            wp_die( esc_html__( 'El servidor necesita las extensiones PHP mbstring y gd para generar el PDF.', 'crm-entries-analytics' ), '', array( 'response' => 500 ) );
        }

        $filters = $this->parse_filters();
        if ( is_wp_error( $filters ) ) {
            wp_die( esc_html( $filters->get_error_message() ), '', array( 'response' => 400 ) );
        }

        $include_trend = '1' === sanitize_key( $this->request_value( 'trend' ) );
        $chart_image   = $this->get_posted_chart_image();
        $dashboard     = $this->db->get_dashboard( $filters );
        $temp_dir      = trailingslashit( get_temp_dir() ) . 'crm-entries-analytics-mpdf';

        if ( ! wp_mkdir_p( $temp_dir ) && ! is_dir( $temp_dir ) ) {
            wp_die( esc_html__( 'No se ha podido preparar el directorio temporal del informe.', 'crm-entries-analytics' ), '', array( 'response' => 500 ) );
        }

        try {
            $mpdf = new \Mpdf\Mpdf(
                array(
                    'mode'                => 'utf-8',
                    'format'              => 'A4',
                    'orientation'         => 'P',
                    'margin_left'         => 13,
                    'margin_right'        => 13,
                    'margin_top'          => 15,
                    'margin_bottom'       => 17,
                    'margin_header'       => 6,
                    'margin_footer'       => 7,
                    'tempDir'             => $temp_dir,
                    'default_font'        => 'dejavusans',
                    'setAutoTopMargin'    => 'stretch',
                    'setAutoBottomMargin' => 'stretch',
                )
            );

            $mpdf->SetTitle( sprintf( 'Informe de captación de leads · %s', self::COMPANY_NAME ) );
            $mpdf->SetAuthor( self::COMPANY_NAME );
            $mpdf->SetCreator( 'CRM Entries Analytics' );
            $mpdf->SetDisplayMode( 'fullpage' );
            $mpdf->WriteHTML( $this->get_styles(), \Mpdf\HTMLParserMode::HEADER_CSS );
            $mpdf->SetHTMLHeader( $this->get_header_html() );
            $mpdf->SetHTMLFooter( $this->get_footer_html() );
            $mpdf->WriteHTML( $this->get_report_html( $dashboard, $filters, $include_trend, $chart_image ), \Mpdf\HTMLParserMode::HTML_BODY );

            while ( ob_get_level() ) {
                ob_end_clean();
            }

            $filename = sanitize_file_name( 'informe-leads-' . $filters['start'] . '-a-' . $filters['end'] . '.pdf' );
            $mpdf->Output( $filename, \Mpdf\Output\Destination::DOWNLOAD );
            exit;
        } catch ( Throwable $error ) {
            wp_die( esc_html__( 'No se ha podido generar el informe PDF. Revisa la configuración del servidor.', 'crm-entries-analytics' ), '', array( 'response' => 500 ) );
        }
    }

    private function parse_filters() {
        $timezone = wp_timezone();
        $today    = new DateTimeImmutable( 'now', $timezone );
        $start    = $this->request_value( 'start' );
        $end      = $this->request_value( 'end' );

        $start_local = $this->parse_date( $start, $timezone );
        $end_local   = $this->parse_date( $end, $timezone );

        if ( ( '' !== $start && ! $start_local ) || ( '' !== $end && ! $end_local ) ) {
            return new WP_Error( 'cea_invalid_report_date', __( 'Las fechas del informe no son válidas.', 'crm-entries-analytics' ) );
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

        $start_local = $start_local->setTime( 0, 0, 0 );
        $end_local   = $end_local->setTime( 23, 59, 59 );
        $days        = (int) $start_local->diff( $end_local )->format( '%a' ) + 1;

        if ( $days > CEA_REST::MAX_RANGE_DAYS ) {
            return new WP_Error( 'cea_report_range_too_large', __( 'El rango del informe no puede superar los 10 años.', 'crm-entries-analytics' ) );
        }

        $interval = $days <= 45 ? 'day' : ( $days <= 240 ? 'week' : 'month' );
        $status   = sanitize_key( $this->request_value( 'read_status' ) );
        if ( ! in_array( $status, array( 'all', 'read', 'unread' ), true ) ) {
            $status = 'all';
        }

        return array(
            'start'       => $start_local->format( 'Y-m-d' ),
            'end'         => $end_local->format( 'Y-m-d' ),
            'start_utc'   => $start_local->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ),
            'end_utc'     => $end_local->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ),
            'form'        => $this->request_value( 'form' ),
            'read_status' => $status,
            'search'      => '',
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

    private function request_value( $key ) {
        if ( isset( $_POST[ $key ] ) ) {
            return sanitize_text_field( wp_unslash( $_POST[ $key ] ) );
        }

        return isset( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( $_GET[ $key ] ) ) : '';
    }

    private function get_posted_chart_image() {
        if ( empty( $_POST['chart_image'] ) || ! is_string( $_POST['chart_image'] ) ) {
            return '';
        }

        $image = trim( wp_unslash( $_POST['chart_image'] ) );
        if ( strlen( $image ) > ( self::MAX_CHART_IMAGE_BYTES * 4 / 3 ) + 256 ) {
            return '';
        }

        if ( 0 !== strpos( $image, 'data:image/png;base64,' ) ) {
            return '';
        }

        $encoded = preg_replace( '/\s+/', '', substr( $image, 22 ) );
        $binary  = base64_decode( $encoded, true );
        if ( false === $binary || 0 === strlen( $binary ) || strlen( $binary ) > self::MAX_CHART_IMAGE_BYTES ) {
            return '';
        }

        $info = getimagesizefromstring( $binary );
        if ( ! is_array( $info )
            || IMAGETYPE_PNG !== $info[2]
            || $info[0] < 400
            || $info[1] < 150
            || $info[0] > 5000
            || $info[1] > 2500 ) {
            return '';
        }

        return 'data:image/png;base64,' . base64_encode( $binary );
    }

    private function get_report_html( array $dashboard, array $filters, $include_trend, $chart_image = '' ) {
        $stats             = $dashboard['stats'];
        $total             = max( 1, (int) $stats['total'] );
        $read_percentage   = round( ( (int) $stats['read'] / $total ) * 100, 1 );
        $unread_percentage = round( ( (int) $stats['unread'] / $total ) * 100, 1 );
        $period           = $this->format_date( $filters['start'] ) . ' – ' . $this->format_date( $filters['end'] );
        $previous_period  = $this->format_date( $dashboard['previousPeriod']['start'] ) . ' – ' . $this->format_date( $dashboard['previousPeriod']['end'] );
        $generated        = wp_date( 'd/m/Y · H:i', time(), wp_timezone() );
        $form_name        = $this->get_selected_form_name( $dashboard['formCards'], $filters['form'] );
        $status_name      = $this->get_status_name( $filters['read_status'] );
        $chart            = $chart_image ? $chart_image : $this->get_chart_data_uri( $dashboard['timeline'], $dashboard['series'], $include_trend );
        $logo             = $this->get_logo_data_uri();

        ob_start();
        ?>
        <div class="report-hero">
            <table class="hero-head" width="100%">
                <tr>
                    <td width="58%"><?php if ( $logo ) : ?><img class="brand-logo" src="<?php echo esc_attr( $logo ); ?>"><?php endif; ?></td>
                    <td width="42%" class="hero-kicker">INFORME DE ANALÍTICA</td>
                </tr>
            </table>
            <h1>Informe de captación de leads</h1>
            <p class="hero-period"><?php echo esc_html( $period ); ?></p>
            <table class="report-meta" width="100%">
                <tr class="meta-labels">
                    <td width="38%">FORMULARIO</td>
                    <td width="20%">ESTADO</td>
                    <td width="42%">GENERADO</td>
                </tr>
                <tr class="meta-values">
                    <td><?php echo esc_html( $form_name ); ?></td>
                    <td><?php echo esc_html( $status_name ); ?></td>
                    <td><?php echo esc_html( $generated ); ?></td>
                </tr>
            </table>
        </div>

        <div class="section">
            <div class="section-kicker">VISIÓN GENERAL</div>
            <h2>Resumen ejecutivo</h2>
            <p class="section-copy">Resultados agregados del periodo seleccionado. Este documento no contiene información personal de los contactos.</p>
            <table class="stats-table" width="100%">
                <tr>
                    <td class="stat-card">
                        <table class="stat-content" width="100%">
                            <tr><td class="stat-label">LEADS TOTALES</td></tr>
                            <tr><td class="stat-value"><?php echo esc_html( number_format_i18n( $stats['total'] ) ); ?></td></tr>
                            <tr><td class="stat-caption">Periodo actual</td></tr>
                        </table>
                    </td>
                    <td class="stat-card">
                        <table class="stat-content" width="100%">
                            <tr><td class="stat-label">LEADS LEÍDOS</td></tr>
                            <tr><td class="stat-value"><?php echo esc_html( number_format_i18n( $stats['read'] ) ); ?></td></tr>
                            <tr><td class="stat-caption"><?php echo esc_html( number_format_i18n( $read_percentage, 1 ) ); ?> % del total</td></tr>
                        </table>
                    </td>
                    <td class="stat-card">
                        <table class="stat-content" width="100%">
                            <tr><td class="stat-label">LEADS NO LEÍDOS</td></tr>
                            <tr><td class="stat-value"><?php echo esc_html( number_format_i18n( $stats['unread'] ) ); ?></td></tr>
                            <tr><td class="stat-caption"><?php echo esc_html( number_format_i18n( $unread_percentage, 1 ) ); ?> % del total</td></tr>
                        </table>
                    </td>
                </tr>
            </table>
        </div>

        <div class="section chart-section">
            <table class="section-title" width="100%">
                <tr>
                    <td><div class="section-kicker">EVOLUCIÓN</div><h2>Evolución de leads</h2><p>Una serie por formulario y total agregado.</p></td>
                    <td class="section-badge"><?php echo $include_trend ? 'CON TENDENCIA' : 'SIN TENDENCIA'; ?></td>
                </tr>
            </table>
            <?php echo $this->get_legend_html( $dashboard['series'], $include_trend ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            <?php if ( $chart ) : ?>
                <div class="chart-frame"><img src="<?php echo esc_attr( $chart ); ?>"></div>
            <?php else : ?>
                <div class="empty-state">No hay datos suficientes para representar la evolución.</div>
            <?php endif; ?>
        </div>

        <pagebreak />

        <div class="section comparison-section">
            <div class="section-kicker">COMPARATIVA</div>
            <h2>Comparativa por formulario</h2>
            <p class="section-copy">Comparación con el periodo inmediatamente anterior de la misma duración: <?php echo esc_html( $previous_period ); ?>.</p>
            <?php echo $this->get_comparison_cards_html( $dashboard['formCards'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
        </div>

        <pagebreak />

        <div class="section heatmap-section">
            <div class="section-kicker">DISTRIBUCIÓN TEMPORAL</div>
            <h2>Mapa de calor por día y hora</h2>
            <p class="section-copy">La intensidad muestra las franjas con mayor volumen de leads en la zona horaria <?php echo esc_html( $dashboard['timezone'] ); ?>.</p>
            <?php echo $this->get_heatmap_html( $dashboard['heatmap'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
        </div>

        <div class="report-note">
            <strong>Privacidad del informe</strong>
            <p>El documento utiliza exclusivamente métricas agregadas. No incluye nombres, correos electrónicos, teléfonos, direcciones IP ni respuestas individuales.</p>
        </div>
        <?php
        return ob_get_clean();
    }

    private function get_header_html() {
        return '<table class="pdf-header" width="100%"><tr><td>' . esc_html( self::COMPANY_NAME ) . '</td><td class="pdf-header-right">CRM Entries Analytics</td></tr></table>';
    }

    private function get_footer_html() {
        return '<table class="pdf-footer" width="100%"><tr><td>' . esc_html( self::COMPANY_URL ) . '</td><td class="pdf-footer-right">Página {PAGENO} de {nbpg}</td></tr></table>';
    }

    private function get_styles() {
        return '
            body { font-family: dejavusans; color: #172033; font-size: 9.2pt; line-height: 1.5; }
            h1, h2, h3, p { margin-top: 0; }
            h1 { margin: 11mm 0 2.5mm; color: #101828; font-size: 24pt; line-height: 1.15; }
            h2 { margin: 0 0 2.5mm; color: #101828; font-size: 16pt; line-height: 1.2; }
            .pdf-header, .pdf-footer { color: #667085; font-size: 7.5pt; border-collapse: collapse; }
            .pdf-header { border-bottom: 0.35mm solid ' . self::PRIMARY_COLOR . '; padding-bottom: 2mm; }
            .pdf-footer { border-top: 0.25mm solid #dfe4ec; padding-top: 2mm; }
            .pdf-header-right, .pdf-footer-right { text-align: right; }
            .report-hero { margin-bottom: 11mm; padding: 9mm; background: #f4f7ff; border-left: 1.5mm solid ' . self::PRIMARY_COLOR . '; }
            .hero-head { border-collapse: collapse; }
            .brand-logo { width: 56mm; height: auto; }
            .hero-kicker { text-align: right; color: ' . self::PRIMARY_COLOR . '; font-size: 8pt; font-weight: bold; letter-spacing: 1.2px; }
            .hero-period { margin: 0 0 8mm; color: #475467; font-size: 11.5pt; }
            .report-meta { border-collapse: collapse; }
            .report-meta td { padding-right: 6mm; vertical-align: top; }
            .meta-labels td { padding-bottom: 1.4mm; color: #667085; font-size: 6.8pt; font-weight: bold; letter-spacing: .55px; }
            .meta-values td { color: #1d2939; font-size: 9pt; font-weight: bold; line-height: 1.3; }
            .section { margin-bottom: 10mm; }
            .section-kicker { margin-bottom: 1.5mm; color: ' . self::PRIMARY_COLOR . '; font-size: 6.8pt; font-weight: bold; letter-spacing: .8px; }
            .section-copy, .section-title p { margin: 0 0 5mm; color: #667085; font-size: 8.5pt; line-height: 1.5; }
            .stats-table { border-collapse: separate; border-spacing: 3mm 0; margin-left: -3mm; width: 103%; }
            .stats-table td.stat-card { width: 33.33%; padding: 4.5mm 5mm; background: #ffffff; border: .25mm solid #dfe4ec; vertical-align: top; }
            .stats-table td.stat-card:first-child { border-top: 1.2mm solid ' . self::PRIMARY_COLOR . '; }
            .stats-table td.stat-card:nth-child(2) { border-top: 1.2mm solid ' . self::SECONDARY_COLOR . '; }
            .stats-table td.stat-card:nth-child(3) { border-top: 1.2mm solid #ff6b6b; }
            .stat-content { border-collapse: collapse; }
            .stat-content td { padding: 0; border: 0; }
            .stat-content .stat-label { padding-bottom: 2.5mm; color: #667085; font-size: 6.8pt; font-weight: bold; letter-spacing: .45px; }
            .stat-content .stat-value { padding-bottom: 1.5mm; color: #101828; font-size: 23pt; font-weight: bold; line-height: 1; }
            .stat-content .stat-caption { color: #667085; font-size: 7.5pt; }
            .section-title { border-collapse: collapse; }
            .section-title td { vertical-align: top; }
            .section-title .section-kicker { margin-bottom: 1.5mm; }
            .section-badge { padding-top: 1mm; text-align: right; color: ' . self::PRIMARY_COLOR . '; font-size: 7pt; font-weight: bold; letter-spacing: .5px; }
            .chart-frame { padding: 3.5mm; border: .25mm solid #dfe4ec; background: #ffffff; }
            .chart-frame img { width: 100%; height: auto; }
            .legend-table { margin: 0 0 4mm; border-collapse: collapse; }
            .legend-table td { width: 33.33%; padding: 1.2mm 3mm 1.2mm 0; color: #475467; font-size: 7pt; line-height: 1.35; vertical-align: middle; }
            .legend-dot { display: inline-block; width: 2.2mm; height: 2.2mm; margin-right: 1.5mm; }
            .legend-trend { display: inline-block; width: 5mm; margin: 0 1.5mm 1mm 0; border-top: .5mm dashed #667085; }
            .comparison-section { margin-top: 3mm; }
            .cards-table { width: 100%; border-collapse: separate; border-spacing: 3mm 4mm; margin: -3mm; }
            .cards-table td.card-cell { width: 50%; padding: 5mm; border: .25mm solid #dfe4ec; vertical-align: top; page-break-inside: avoid; }
            .cards-table td.card-spacer { width: 50%; border: 0; }
            .card-main { width: 100%; border-collapse: collapse; }
            .card-main td { padding: 0; border: 0; }
            .card-main .card-title { padding-bottom: 3.5mm; color: #344054; font-size: 9.5pt; font-weight: bold; line-height: 1.3; }
            .card-main .card-current { padding-bottom: 1mm; color: #101828; font-size: 25pt; font-weight: bold; line-height: 1; }
            .card-main .card-caption { padding-bottom: 4mm; color: #667085; font-size: 7.5pt; }
            .card-main .card-divider { height: 3mm; border-top: .25mm solid #eaecf0; }
            .card-comparison { width: 100%; border-collapse: collapse; }
            .card-comparison td { width: 50%; padding: 0; border: 0; vertical-align: top; }
            .card-comparison .compare-label td { padding-bottom: 1.4mm; color: #667085; font-size: 6.5pt; font-weight: bold; letter-spacing: .35px; }
            .card-comparison .compare-value td { padding-bottom: 1mm; color: #101828; font-size: 12pt; font-weight: bold; line-height: 1.15; }
            .card-comparison .compare-detail td { color: #667085; font-size: 7.2pt; line-height: 1.3; }
            .card-comparison .compare-right { padding-left: 3mm; text-align: right; }
            .change-positive { color: #087a31; text-align: right; }
            .change-negative { color: #b42318; text-align: right; }
            .change-neutral { color: #667085; text-align: right; }
            .heatmap { width: 100%; border-collapse: separate; border-spacing: .6mm; }
            .heatmap th { color: #667085; font-size: 5.5pt; font-weight: normal; text-align: center; }
            .heatmap .day { width: 11mm; padding-right: 1.5mm; color: #475467; font-size: 7pt; font-weight: bold; text-align: right; }
            .heatmap td.cell { height: 5.6mm; border: .15mm solid #edf0f5; }
            .heatmap-legend { margin-top: 3mm; color: #667085; font-size: 7pt; text-align: right; }
            .heatmap-swatch { display: inline-block; width: 6mm; height: 2.5mm; margin-left: 1mm; vertical-align: middle; }
            .report-note { margin-top: 11mm; padding: 5.5mm; background: #fffaeb; border-left: 1.2mm solid ' . self::SECONDARY_COLOR . '; color: #475467; }
            .report-note strong { color: #7a4d00; font-size: 9.5pt; }
            .report-note p { margin: 2mm 0 0; font-size: 8pt; line-height: 1.5; }
            .empty-state { padding: 12mm; color: #667085; text-align: center; border: .25mm solid #dfe4ec; }
        ';
    }

    private function get_selected_form_name( array $cards, $selected_form ) {
        if ( ! $selected_form ) {
            return 'Todos los formularios';
        }

        foreach ( $cards as $card ) {
            if ( (string) $card['id'] === (string) $selected_form ) {
                return $card['label'];
            }
        }

        return $selected_form;
    }

    private function get_status_name( $status ) {
        $labels = array(
            'all'    => 'Todos',
            'read'   => 'Leídos',
            'unread' => 'No leídos',
        );

        return isset( $labels[ $status ] ) ? $labels[ $status ] : $labels['all'];
    }

    private function format_date( $date ) {
        try {
            $parsed = new DateTimeImmutable( $date . ' 12:00:00', wp_timezone() );
            return wp_date( get_option( 'date_format', 'd/m/Y' ), $parsed->getTimestamp(), wp_timezone() );
        } catch ( Exception $error ) {
            return $date;
        }
    }

    private function get_logo_data_uri() {
        $path = CEA_PATH . 'assets/logomindset.svg';
        if ( ! file_exists( $path ) ) {
            return '';
        }

        $contents = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
        return false !== $contents ? 'data:image/svg+xml;base64,' . base64_encode( $contents ) : '';
    }

    private function get_chart_data_uri( array $timeline, array $series, $include_trend ) {
        if ( empty( $timeline ) || empty( $series ) ) {
            return '';
        }

        $width   = 1060;
        $height  = 330;
        $left    = 52;
        $right   = 20;
        $top     = 18;
        $bottom  = 46;
        $chart_w = $width - $left - $right;
        $chart_h = $height - $top - $bottom;
        $count   = count( $timeline );
        $values  = array();

        foreach ( $series as $item ) {
            $values = array_merge( $values, array_map( 'floatval', $item['data'] ) );
        }

        $total_series = $series[0];
        foreach ( $series as $item ) {
            if ( 'total' === $item['id'] ) {
                $total_series = $item;
                break;
            }
        }

        $trend = $include_trend ? $this->linear_trend( $total_series['data'] ) : array();
        if ( $trend ) {
            $values = array_merge( $values, $trend );
        }

        $max = max( 1, $values ? max( $values ) : 1 );
        $x   = static function ( $index ) use ( $left, $chart_w, $count ) {
            return $left + ( 1 === $count ? $chart_w / 2 : ( $index / ( $count - 1 ) ) * $chart_w );
        };
        $y   = static function ( $value ) use ( $top, $chart_h, $max ) {
            return $top + $chart_h - ( (float) $value / $max ) * $chart_h;
        };

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="' . $width . '" height="' . $height . '" viewBox="0 0 ' . $width . ' ' . $height . '">';
        $svg .= '<rect width="100%" height="100%" fill="#ffffff"/>';

        for ( $index = 0; $index < 5; $index++ ) {
            $grid_y = $top + ( $index / 4 ) * $chart_h;
            $value  = round( $max - ( $index / 4 ) * $max );
            $svg   .= '<line x1="' . $left . '" y1="' . $grid_y . '" x2="' . ( $width - $right ) . '" y2="' . $grid_y . '" stroke="#e4e7ec" stroke-width="1"/>';
            $svg   .= '<text x="' . ( $left - 9 ) . '" y="' . ( $grid_y + 4 ) . '" text-anchor="end" font-family="DejaVu Sans" font-size="11" fill="#667085">' . $value . '</text>';
        }

        foreach ( $series as $series_index => $item ) {
            $points = array();
            foreach ( $item['data'] as $index => $value ) {
                $points[] = round( $x( $index ), 2 ) . ',' . round( $y( $value ), 2 );
            }

            $color = $this->get_series_color( $series_index );
            $size  = 'total' === $item['id'] ? 3.4 : 2;
            $svg  .= '<polyline points="' . implode( ' ', $points ) . '" fill="none" stroke="' . $color . '" stroke-width="' . $size . '" stroke-linecap="round" stroke-linejoin="round"/>';
        }

        if ( $trend ) {
            $points = array();
            foreach ( $trend as $index => $value ) {
                $points[] = round( $x( $index ), 2 ) . ',' . round( $y( $value ), 2 );
            }
            $svg .= '<polyline points="' . implode( ' ', $points ) . '" fill="none" stroke="#667085" stroke-width="2.4" stroke-dasharray="9 7"/>';
        }

        $label_step = max( 1, (int) ceil( $count / 7 ) );
        foreach ( $timeline as $index => $point ) {
            if ( 0 !== $index % $label_step && $index !== $count - 1 ) {
                continue;
            }
            $svg .= '<text x="' . round( $x( $index ), 2 ) . '" y="' . ( $height - 14 ) . '" text-anchor="middle" font-family="DejaVu Sans" font-size="10" fill="#667085">' . $this->xml_escape( $point['label'] ) . '</text>';
        }

        $svg .= '</svg>';
        return 'data:image/svg+xml;base64,' . base64_encode( $svg );
    }

    private function linear_trend( array $values ) {
        $length = count( $values );
        if ( $length < 2 ) {
            return array_map( 'floatval', $values );
        }

        $sum_x  = ( ( $length - 1 ) * $length ) / 2;
        $sum_y  = 0;
        $sum_xy = 0;
        $sum_xx = 0;

        foreach ( $values as $index => $value ) {
            $value   = (float) $value;
            $sum_y  += $value;
            $sum_xy += $index * $value;
            $sum_xx += $index * $index;
        }

        $denominator = $length * $sum_xx - $sum_x * $sum_x;
        $slope       = $denominator ? ( $length * $sum_xy - $sum_x * $sum_y ) / $denominator : 0;
        $intercept   = ( $sum_y - $slope * $sum_x ) / $length;
        $trend       = array();

        foreach ( $values as $index => $value ) {
            $trend[] = max( 0, $intercept + $slope * $index );
        }

        return $trend;
    }

    private function get_legend_html( array $series, $include_trend ) {
        $items = array();
        foreach ( $series as $index => $item ) {
            $items[] = '<span class="legend-dot" style="background:' . esc_attr( $this->get_series_color( $index ) ) . '"></span>' . esc_html( $item['label'] );
        }
        if ( $include_trend ) {
            $items[] = '<span class="legend-trend"></span>Tendencia del total';
        }

        $html = '<table class="legend-table" width="100%">';
        foreach ( array_chunk( $items, 3 ) as $row ) {
            $html .= '<tr>';
            for ( $column = 0; $column < 3; $column++ ) {
                $html .= '<td>' . ( isset( $row[ $column ] ) ? $row[ $column ] : '' ) . '</td>';
            }
            $html .= '</tr>';
        }
        $html .= '</table>';

        return $html;
    }

    private function get_comparison_cards_html( array $cards ) {
        if ( empty( $cards ) ) {
            return '<div class="empty-state">No hay formularios disponibles para este periodo.</div>';
        }

        $html = '<table class="cards-table">';
        foreach ( array_chunk( $cards, 2 ) as $row ) {
            $html .= '<tr>';
            foreach ( $row as $card ) {
                $difference = (int) $card['difference'];
                $class      = $difference > 0 ? 'change-positive' : ( $difference < 0 ? 'change-negative' : 'change-neutral' );

                if ( 0 === $difference ) {
                    $change = 'Sin cambios';
                } elseif ( 0 === (int) $card['previous'] && (int) $card['current'] > 0 ) {
                    $change = 'Nuevo en este periodo';
                } else {
                    $change = ( $difference > 0 ? '+' : '' ) . number_format_i18n( $card['changePercent'], 1 ) . ' %';
                }

                $difference_text = ( $difference > 0 ? '+' : '' ) . number_format_i18n( $difference ) . ' leads';
                $html           .= '<td class="card-cell">';
                $html           .= '<table class="card-main">';
                $html           .= '<tr><td class="card-title">' . esc_html( $card['label'] ) . '</td></tr>';
                $html           .= '<tr><td class="card-current">' . esc_html( number_format_i18n( $card['current'] ) ) . '</td></tr>';
                $html           .= '<tr><td class="card-caption">leads en el periodo actual</td></tr>';
                $html           .= '<tr><td class="card-divider"></td></tr>';
                $html           .= '</table>';
                $html           .= '<table class="card-comparison">';
                $html           .= '<tr class="compare-label"><td>PERIODO ANTERIOR</td><td class="compare-right">VARIACIÓN</td></tr>';
                $html           .= '<tr class="compare-value"><td>' . esc_html( number_format_i18n( $card['previous'] ) ) . '</td><td class="compare-right ' . esc_attr( $class ) . '">' . esc_html( $difference_text ) . '</td></tr>';
                $html           .= '<tr class="compare-detail"><td>leads</td><td class="compare-right ' . esc_attr( $class ) . '">' . esc_html( $change ) . '</td></tr>';
                $html           .= '</table></td>';
            }
            if ( 1 === count( $row ) ) {
                $html .= '<td class="card-spacer"></td>';
            }
            $html .= '</tr>';
        }
        $html .= '</table>';

        return $html;
    }

    private function get_heatmap_html( array $matrix ) {
        $days   = array( 'Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom' );
        $values = array();
        foreach ( $matrix as $row ) {
            $values = array_merge( $values, array_map( 'intval', $row ) );
        }
        $max = max( 1, $values ? max( $values ) : 1 );

        $html = '<table class="heatmap"><thead><tr><th></th>';
        for ( $hour = 0; $hour < 24; $hour++ ) {
            $html .= '<th>' . ( 0 === $hour % 3 ? sprintf( '%02d', $hour ) : '' ) . '</th>';
        }
        $html .= '</tr></thead><tbody>';

        foreach ( $days as $day_index => $day ) {
            $html .= '<tr><td class="day">' . esc_html( $day ) . '</td>';
            for ( $hour = 0; $hour < 24; $hour++ ) {
                $count = isset( $matrix[ $day_index ][ $hour ] ) ? (int) $matrix[ $day_index ][ $hour ] : 0;
                $ratio = $count > 0 ? 0.12 + ( $count / $max ) * 0.88 : 0.04;
                $html .= '<td class="cell" style="background:' . esc_attr( $this->blend_with_white( self::PRIMARY_COLOR, $ratio ) ) . '"></td>';
            }
            $html .= '</tr>';
        }
        $html .= '</tbody></table>';
        $html .= '<div class="heatmap-legend">Menor intensidad <span class="heatmap-swatch" style="background:#edf3ff"></span><span class="heatmap-swatch" style="background:' . self::PRIMARY_COLOR . '"></span> Mayor intensidad</div>';

        return $html;
    }

    private function blend_with_white( $hex, $ratio ) {
        $hex   = ltrim( $hex, '#' );
        $ratio = min( 1, max( 0, (float) $ratio ) );
        $red   = hexdec( substr( $hex, 0, 2 ) );
        $green = hexdec( substr( $hex, 2, 2 ) );
        $blue  = hexdec( substr( $hex, 4, 2 ) );

        $red   = (int) round( 255 + ( $red - 255 ) * $ratio );
        $green = (int) round( 255 + ( $green - 255 ) * $ratio );
        $blue  = (int) round( 255 + ( $blue - 255 ) * $ratio );

        return sprintf( '#%02x%02x%02x', $red, $green, $blue );
    }

    private function get_series_color( $index ) {
        $colors = array(
            self::PRIMARY_COLOR,
            '#ef4444',
            '#16a34a',
            self::SECONDARY_COLOR,
            '#8b5cf6',
            '#06b6d4',
            '#ec4899',
            '#64748b',
            '#f97316',
            '#14b8a6',
            '#a855f7',
            '#84cc16',
        );

        return $colors[ $index % count( $colors ) ];
    }

    private function xml_escape( $value ) {
        return htmlspecialchars( (string) $value, ENT_QUOTES | ENT_XML1, 'UTF-8' );
    }
}
