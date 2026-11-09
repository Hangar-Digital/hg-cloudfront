<?php
/*
Plugin Name: HG CloudFront
Description: Acesso rápido e fácil para limpar o cache do CloudFront.
Version: 3.0
Requires PHP: 7.2
Author: Hangar Digital
Author URI: https://hangar.digital/
*/

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/libraries/cripto.php';
require_once __DIR__ . '/libraries/aws-sigv4.php';

class HG_Cloudfront {

    const OPTION        = 'hgcloudfront_settings';
    const DISTRIBUTIONS = 2;
    const SAVE_ACTION   = 'hgcloudfront_save_settings';
    const PURGE_ACTION  = 'hg_cloudfront_purge';
    const PURGE_PARAM   = 'hg_cloudfront_purge';
    const PURGE_CAP     = 'publish_posts';

    const API_HOST    = 'cloudfront.amazonaws.com';
    const API_VERSION = '2020-05-31';
    const API_REGION  = 'us-east-1'; // CloudFront é global; a assinatura usa sempre us-east-1

    /** Existem configurações salvas que não puderam ser descriptografadas. */
    private $decrypt_failed = false;

    /** Existem configurações no formato antigo (v2.x, campos do Cloudflare), descartadas. */
    private $legacy_found = false;

    /** Uma invalidação já foi agendada para o fim desta requisição. */
    private $purge_scheduled = false;

    function __construct() {

        // Invalidar quando conteúdo publicado mudar (admin, REST/Gutenberg, cron de agendados).
        // Agrupado em uma única invalidação por requisição: a AWS cobra por invalidação
        // acima de 1.000/mês e limita a 15 invalidações com curinga simultâneas.
        add_action( 'transition_post_status', [$this, 'on_transition_post_status'], 10, 3 );

        if ( is_admin() ) {
            add_action( 'admin_init', [$this, 'handle_manual_purge'] );
            add_action( 'admin_bar_menu', [$this, 'add_adminbar'], 999 );

            add_action( 'admin_menu', [$this, 'admin_menu'] );
            add_action( 'admin_init', [$this, 'save_settings'] );

            add_action( 'admin_footer', [$this, 'show_message'] );
        }
    }

    function on_transition_post_status( $new_status, $old_status, $post ) {
        // Só interessa o que está ou estava visível no site
        if ( $new_status !== 'publish' && $old_status !== 'publish' ) {
            return;
        }

        if ( wp_is_post_revision( $post ) || wp_is_post_autosave( $post ) ) {
            return;
        }

        // Ignora post types não públicos (ex.: envios de formulário, menus)
        $post_type = get_post_type_object( $post->post_type );
        if ( ! $post_type || $post->post_type === 'nav_menu_item' || ! is_post_type_viewable( $post_type ) ) {
            return;
        }

        $this->schedule_purge();
    }

    private function schedule_purge() {
        if ( $this->purge_scheduled ) {
            return;
        }

        $this->purge_scheduled = true;
        add_action( 'shutdown', [$this, 'clean_cache'] );
    }

    function add_adminbar( $wp_admin_bar ) {
        if ( ! current_user_can( self::PURGE_CAP ) ) {
            return;
        }

        $wp_admin_bar->add_node( array(
            'id'    => 'hg-cloudfront-clean-cache',
            'title' => '☁ Limpar CloudFront',
            'href'  => wp_nonce_url( add_query_arg( self::PURGE_PARAM, '1' ), self::PURGE_ACTION ),
            'meta'  => array(
                'class' => 'hg-cloudfront-clean-cache',
                'title' => 'Limpar Cache do CloudFront'
            )
        ) );
    }

    function handle_manual_purge() {
        if ( ! isset( $_GET[ self::PURGE_PARAM ] ) || $_GET[ self::PURGE_PARAM ] !== '1' || wp_doing_ajax() ) {
            return;
        }

        if ( ! current_user_can( self::PURGE_CAP ) ) {
            wp_die( 'Você não tem permissão para limpar o cache.', 403 );
        }

        check_admin_referer( self::PURGE_ACTION );

        $this->clean_cache();

        // Remove os parâmetros da URL para que um F5 não dispare nova invalidação
        wp_safe_redirect( remove_query_arg( array( self::PURGE_PARAM, '_wpnonce' ) ) );
        exit;
    }

    function clean_cache() {
        $config = $this->get_config();
        $errors = [];
        $configured = false;

        if ( $config['access_key_id'] !== '' && $config['secret_access_key'] !== '' ) {
            foreach ( $config['distributions'] as $distribution ) {
                if ( $distribution['id'] === '' ) {
                    continue;
                }

                $configured = true;
                $error = $this->create_invalidation( $distribution['id'], $config['access_key_id'], $config['secret_access_key'] );

                if ( $error !== null ) {
                    $errors[] = $distribution['id'] . ': ' . $error;
                }
            }
        }

        if ( ! $configured ) {
            $this->store_message( 'error', 'Os dados de API não foram configurados!' );

        } elseif ( empty( $errors ) ) {
            $this->store_message( 'success', 'A invalidação do cache foi solicitada com sucesso!' );
            error_log( '[ CLOUDFRONT ] Invalidação solicitada com sucesso.' );

        } else {
            $message = 'Houve um erro ao limpar o cache! Mensagem da API: ' . implode( ' | ', $errors );
            $this->store_message( 'error', $message );
            error_log( '[ CLOUDFRONT ] ' . $message );
        }
    }

    private function store_message( $status, $message ) {
        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            return; // cron / WP-CLI: ninguém para exibir a mensagem
        }

        set_transient( 'hg_cloudfront_notice_' . $user_id, array( 'status' => $status, 'message' => $message ), 60 );
    }

    /**
     * Cria uma invalidação de "/*" na distribuição.
     * Permissão IAM necessária: cloudfront:CreateInvalidation.
     *
     * @return string|null mensagem de erro, ou null em caso de sucesso.
     */
    private function create_invalidation( $distribution_id, $access_key_id, $secret_access_key ) {
        if ( ! self::is_valid_distribution_id( $distribution_id )
            || ! self::is_valid_access_key_id( $access_key_id )
            || ! self::is_valid_secret_access_key( $secret_access_key ) ) {
            return 'Credenciais ou Distribution ID em formato inválido.';
        }

        $path = '/' . self::API_VERSION . '/distribution/' . $distribution_id . '/invalidation';
        $body = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<InvalidationBatch xmlns="http://cloudfront.amazonaws.com/doc/' . self::API_VERSION . '/">'
            . '<Paths><Quantity>1</Quantity><Items><Path>/*</Path></Items></Paths>'
            . '<CallerReference>hg-cloudfront-' . time() . '-' . bin2hex( random_bytes( 6 ) ) . '</CallerReference>'
            . '</InvalidationBatch>';

        $amz_date = gmdate( 'Ymd\THis\Z' );
        $headers  = array( 'Content-Type' => 'application/xml' );

        $headers['X-Amz-Date']    = $amz_date;
        $headers['Authorization'] = HG_Cloudfront_SigV4::authorization(
            'POST',
            self::API_HOST,
            $path,
            array( 'content-type' => 'application/xml' ),
            $body,
            $amz_date,
            self::API_REGION,
            'cloudfront',
            $access_key_id,
            $secret_access_key
        );

        $response = wp_remote_post( 'https://' . self::API_HOST . $path, array(
            'timeout'     => 15,
            'headers'     => $headers,
            'body'        => $body,
            'data_format' => 'body',
        ) );

        if ( is_wp_error( $response ) ) {
            return 'Falha de conexão: ' . $response->get_error_message();
        }

        $code = (int) wp_remote_retrieve_response_code( $response );
        if ( $code === 201 ) {
            return null;
        }

        $xml = wp_remote_retrieve_body( $response );
        $parts = [];
        if ( preg_match( '#<Code>(.*?)</Code>#s', $xml, $m ) ) {
            $parts[] = $m[1];
        }
        if ( preg_match( '#<Message>(.*?)</Message>#s', $xml, $m ) ) {
            $parts[] = html_entity_decode( $m[1], ENT_QUOTES | ENT_XML1, 'UTF-8' );
        }

        return $parts ? implode( ' - ', $parts ) : "Resposta inesperada da API (HTTP $code).";
    }

    function show_message() {
        $user_id = get_current_user_id();
        $notice = $user_id ? get_transient( 'hg_cloudfront_notice_' . $user_id ) : false;

        if ( ! is_array( $notice ) ) {
            return;
        }

        delete_transient( 'hg_cloudfront_notice_' . $user_id );

        $is_success = $notice['status'] === 'success';

        ?>
        <style>
            .hgcloudfront_msg {
                padding: 0 15px;
                margin: 10px !important;
                border-radius: 5px;
                position: fixed;
                z-index: 10000;
                bottom: 0;
                right: 0;
            }
            .hgcloudfront_success {
                background-color: #d4edda;
                color: #155724;
            }
            .hgcloudfront_error {
                background-color: #f8d7da;
                color: #721c24;
            }
        </style>
        <script>
            setTimeout(function() {
                document.querySelector('.hgcloudfront_msg').style.display = 'none';
            }, 5000);
        </script>
        <div class="hgcloudfront_msg <?php echo $is_success ? 'hgcloudfront_success' : 'hgcloudfront_error' ?>">
            <p><?php echo $is_success ? '✅' : '❌' ?> <strong>[ CLOUDFRONT ]</strong> <?php echo esc_html( $notice['message'] ) ?></p>
        </div>
        <?php
    }

    function admin_menu() {
        add_options_page(
            'CloudFront',
            'CloudFront',
            'manage_options',
            'hgcloudfront-settings',
            [$this, 'display_settings']
        );
    }

    function display_settings() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $config = $this->get_config();
        $secret_hint = self::mask_secret( $config['secret_access_key'] );
        unset( $config['secret_access_key'] ); // segredo nunca vai para o HTML

        $decrypt_failed    = $this->decrypt_failed;
        $legacy_found      = $this->legacy_found;
        $has_dedicated_key = HG_Cloudfront_Crypto::has_dedicated_key();

        include __DIR__ . '/settings.php';
    }

    /**
     * Configuração efetiva. Constantes no wp-config.php têm prioridade sobre o banco:
     * HG_CLOUDFRONT_ACCESS_KEY_ID + HG_CLOUDFRONT_SECRET_ACCESS_KEY e HG_CLOUDFRONT_DISTRIBUTION_ID_{n}.
     */
    function get_config() {
        $stored = $this->get_stored_settings();

        $credentials_locked = self::credentials_locked();
        $config = array(
            'credentials_locked' => $credentials_locked,
            'access_key_id'      => $credentials_locked ? (string) HG_CLOUDFRONT_ACCESS_KEY_ID : ( isset( $stored['access_key_id'] ) ? (string) $stored['access_key_id'] : '' ),
            'secret_access_key'  => $credentials_locked ? (string) HG_CLOUDFRONT_SECRET_ACCESS_KEY : ( isset( $stored['secret_access_key'] ) ? (string) $stored['secret_access_key'] : '' ),
            'distributions'      => [],
        );

        for ( $i = 1; $i <= self::DISTRIBUTIONS; $i++ ) {
            $locked = defined( "HG_CLOUDFRONT_DISTRIBUTION_ID_$i" );
            $config['distributions'][$i] = array(
                'id'     => $locked ? (string) constant( "HG_CLOUDFRONT_DISTRIBUTION_ID_$i" ) : ( isset( $stored["distribution_id_$i"] ) ? (string) $stored["distribution_id_$i"] : '' ),
                'locked' => $locked,
            );
        }

        return $config;
    }

    private static function credentials_locked() {
        return defined( 'HG_CLOUDFRONT_ACCESS_KEY_ID' ) && defined( 'HG_CLOUDFRONT_SECRET_ACCESS_KEY' );
    }

    private function get_stored_settings() {
        $stored = get_option( self::OPTION, '' );
        if ( ! is_string( $stored ) || $stored === '' ) {
            return [];
        }

        // Formato da v2.x: campos do Cloudflare, nunca funcionaram com o CloudFront
        if ( ! HG_Cloudfront_Crypto::is_current( $stored ) ) {
            $this->legacy_found = true;
            return [];
        }

        try {
            $json = HG_Cloudfront_Crypto::decrypt( $stored );
        } catch ( Throwable $e ) {
            error_log( '[ CLOUDFRONT ] ' . $e->getMessage() );
            $json = null;
        }

        $data = $json !== null ? json_decode( $json, true ) : null;

        if ( ! is_array( $data ) ) {
            $this->decrypt_failed = true;
            error_log( '[ CLOUDFRONT ] Não foi possível descriptografar as configurações (chave ou salts alterados?).' );
            return [];
        }

        return $data;
    }

    private function store_settings( array $data ) {
        try {
            update_option( self::OPTION, HG_Cloudfront_Crypto::encrypt( wp_json_encode( $data ) ), false );
            return true;
        } catch ( Throwable $e ) {
            error_log( '[ CLOUDFRONT ] ' . $e->getMessage() );
            return false;
        }
    }

    function save_settings() {
        if ( ! isset( $_POST['hgcloudfront_settings'] ) ) {
            return;
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Você não tem permissão para alterar estas configurações.', 403 );
        }

        check_admin_referer( self::SAVE_ACTION );

        $current = $this->get_stored_settings();
        $data = [];
        $errors = [];

        // Credenciais
        if ( self::credentials_locked() ) {
            $data['access_key_id'] = isset( $current['access_key_id'] ) ? $current['access_key_id'] : '';
            $data['secret_access_key'] = isset( $current['secret_access_key'] ) ? $current['secret_access_key'] : '';
        } else {
            $access_key_id = isset( $_POST['access_key_id'] ) ? strtoupper( trim( wp_unslash( $_POST['access_key_id'] ) ) ) : '';
            $secret_access_key = isset( $_POST['secret_access_key'] ) ? trim( wp_unslash( $_POST['secret_access_key'] ) ) : '';

            if ( $access_key_id === '' ) {
                // Access Key ID vazio = credenciais removidas
                $secret_access_key = '';
            } else {
                // Secret em branco = manter o atual
                if ( $secret_access_key === '' && isset( $current['secret_access_key'] ) ) {
                    $secret_access_key = $current['secret_access_key'];
                }

                if ( ! self::is_valid_access_key_id( $access_key_id ) ) {
                    $errors[] = 'Access Key ID inválido.';
                }
                if ( ! self::is_valid_secret_access_key( $secret_access_key ) ) {
                    $errors[] = 'Secret Access Key inválida ou não informada.';
                }
            }

            $data['access_key_id'] = $access_key_id;
            $data['secret_access_key'] = $secret_access_key;
        }

        // Distribuições
        for ( $i = 1; $i <= self::DISTRIBUTIONS; $i++ ) {
            if ( defined( "HG_CLOUDFRONT_DISTRIBUTION_ID_$i" ) ) {
                $data["distribution_id_$i"] = isset( $current["distribution_id_$i"] ) ? $current["distribution_id_$i"] : '';
                continue;
            }

            $distribution_id = isset( $_POST["distribution_id_$i"] ) ? strtoupper( trim( wp_unslash( $_POST["distribution_id_$i"] ) ) ) : '';

            if ( $distribution_id !== '' && ! self::is_valid_distribution_id( $distribution_id ) ) {
                $errors[] = "Distribuição $i: Distribution ID inválido.";
            }

            $data["distribution_id_$i"] = $distribution_id;
        }

        if ( $errors ) {
            add_settings_error( self::OPTION, self::OPTION, implode( '<br>', array_map( 'esc_html', $errors ) ), 'error' );
            return;
        }

        if ( ! $this->store_settings( $data ) ) {
            add_settings_error( self::OPTION, self::OPTION, 'Não foi possível criptografar as configurações. Verifique a constante HG_CLOUDFRONT_ENCRYPTION_KEY.', 'error' );
            return;
        }

        $this->decrypt_failed = false;
        $this->legacy_found = false;

        add_settings_error(
            self::OPTION,
            self::OPTION,
            'As configurações foram salvas com sucesso!',
            'updated'
        );
    }

    private static function is_valid_distribution_id( $id ) {
        return is_string( $id ) && preg_match( '/^[A-Z0-9]{8,32}$/', $id ) === 1;
    }

    private static function is_valid_access_key_id( $id ) {
        return is_string( $id ) && preg_match( '/^[A-Z0-9]{16,128}$/', $id ) === 1;
    }

    private static function is_valid_secret_access_key( $secret ) {
        return is_string( $secret ) && preg_match( '#^[A-Za-z0-9/+=]{30,128}$#', $secret ) === 1;
    }

    private static function mask_secret( $secret ) {
        if ( $secret === '' ) {
            return '';
        }

        return strlen( $secret ) > 8 ? '•••••••• ' . substr( $secret, -4 ) : '••••••••';
    }
}

new HG_Cloudfront();
