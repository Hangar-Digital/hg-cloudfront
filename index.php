<?php
/*
Plugin Name: HG CloudFront
Description: Acesso rápido e fácil para limpar o cache do CloudFront.
Version: 1.0
Author: Hangar Digital
Author URI: https://hangar.digital/
*/

require 'libraries/cripto.php';

class HG_Cloudfront {

    function __construct() {

        if ( is_admin() ) {
            // Limpeza cache manualmente quando solicitado
            add_action( 'admin_init', function() {
                $clean_cache = isset($_GET['hg_clean_cache']) ? $_GET['hg_clean_cache'] : 0;
                if ($clean_cache == 1) {
                    $this->clean_cache();
                }
            });

            // Adicionar botão no admin bar
            add_action( 'admin_bar_menu', [$this, 'add_adminbar'], 999 );

            // Limpar cache do CloudFront depois de salvar qualquer CPT
            add_action( 'save_post', function($post_id) {
                // Verificar se é um auto-salvamento
                if (wp_is_post_autosave($post_id)) {
                    return;
                }

                // Verificar se o post é uma revisão
                if (wp_is_post_revision($post_id)) {
                    return;
                }

                // Verificar se o post é novo
                if (get_post_status($post_id) == 'auto-draft') {
                    return;
                }

                $this->clean_cache();
            });

            // Abrir e salvar opcoes
            add_action( 'admin_menu', [$this, 'admin_menu']);
            add_action( 'admin_init', [$this, 'save_settings']);

            // Adicionar conteúdo ao rodapé do painel de administração
            add_action( 'admin_footer', [$this, 'show_message'] );
        }
    }

    function add_adminbar( $wp_admin_bar ) {
        $http = isset($_SERVER['HTTPS']) ? 'https://' : 'http://';
        $link = $http.$_SERVER['HTTP_HOST'].$_SERVER['REQUEST_URI'];
        $simbolo = strpos($link, '?') > 0 ? '&' : '?';
        $link .= $simbolo.'hg_clean_cache=1';

        $args = array(
            'id'    => 'hg-clean-cache',
            'title' => '☁ Limpar Cache',
            'href'  => $link,
            'meta'  => array(
                'class' => 'hg-clean-cache',
                'title' => 'Limpar Cache do CloudFront'
            )
        );
        $wp_admin_bar->add_node( $args );
    }

    function clean_cache() {
        $cloudfront_msg = new stdClass();

        $configs = $this->get_options();

        if (empty($configs) || $configs->distribution_id_1 == '' || $configs->access_key_1 == '' || $configs->secret_key_1 == '') {
            $cloudfront_msg->error = 'Os dados de API não foram configurados!';
            $_SESSION['cloudfront_msg'] = $cloudfront_msg;
            return;
        }

        $res = [];
        for ($i = 1; $i <= 2; $i++) {
            $distribution_id = isset($configs->{"distribution_id_$i"}) ? $configs->{"distribution_id_$i"} : '';
            $access_key = isset($configs->{"access_key_$i"}) ? $configs->{"access_key_$i"} : '';
            $secret_key = isset($configs->{"secret_key_$i"}) ? $configs->{"secret_key_$i"} : '';

            if ($distribution_id == '' || $secret_key == '') {
                continue;
            }

            $res = $this->consult_rest($distribution_id, $access_key, $secret_key);

            if (!$res->success) {
                break;
            }
        }

        if ($res->success) {
            $cloudfront_msg->success = 'O cache foi limpo com sucesso!';
            $_SESSION['cloudfront_msg'] = $cloudfront_msg;
        
        } else {
            $msg = '';
            foreach ($res->errors as $reg) {
                $msg .= ' '.$reg->message;
            }

            $cloudfront_msg->error = 'Houve um erro ao limpar o cache! Mensagem da API: '.trim($msg);
            $_SESSION['cloudfront_msg'] = $cloudfront_msg;
        }
    }

    function consult_rest($distribution_id, $access_key, $secret_key) {
        try {
            // Data atual em formato ISO8601
            $date = gmdate('Ymd\THis\Z');
            
            $params = [
                'DistributionId' => $distribution_id,
                'InvalidationBatch' => [
                    'Paths' => [
                        'Quantity' => 1,
                        'Items' => ['/*']
                    ],
                    'CallerReference' => time()
                ]
            ];

            // Configuração do request
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, "https://cloudfront.amazonaws.com/2020-05-31/distribution/{$distribution_id}/invalidation");
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/xml',
                'X-Amz-Date: ' . $date,
                'Authorization: AWS4-HMAC-SHA256 Credential=' . $access_key . '/' . gmdate('Ymd') . '/us-east-1/cloudfront/aws4_request, SignedHeaders=host;x-amz-date, Signature=' . $this->generate_signature($date, $access_key, $secret_key, $distribution_id)
            ]);
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            
            $response = curl_exec($ch);
            curl_close($ch);

            echo var_export($response); exit;

            return json_decode($response);

        } catch (Exception $e) {
            $res = new stdClass();
            $res->success = false;
            $res->errors = [
                (object)['message' => $e->getMessage()]
            ];
            return $res;
        }
    }

    function show_message() {
        $cloudfront_msg = isset($_SESSION['cloudfront_msg']) ? $_SESSION['cloudfront_msg'] : null;
        unset($_SESSION['cloudfront_msg']);

        if (!isset($cloudfront_msg)) {
            return;
        }

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
        <?php

        if (isset($cloudfront_msg->success)) {
            echo '<div class="hgcloudfront_msg hgcloudfront_success">
                <p>✅ <strong>[ CLOUDFRONT ]</strong> '.$cloudfront_msg->success.'</p>
            </div>';

        } else {
            echo '<div class="hgcloudfront_msg hgcloudfront_error">
                <p>❌ <strong>[ CLOUDFRONT ]</strong> '.$cloudfront_msg->error.'</p>
            </div>';
        }
    }

    function admin_menu() {
		global $submenu;
		$cd_site_id = wp_get_current_user();
		
		if ( !in_array('administrator', $cd_site_id->roles) ) {
			return;
		}

		add_options_page(
			'CloudFront',
			'CloudFront',
			'manage_options',
			'hgcloudfront-settings',
			[$this, 'display_settings']
		);
	}

	function display_settings() {
        $configs = $this->get_options();

		include 'settings.php';
	}

    function get_options() {
        $configs = get_option('hgcloudfront_settings');
        if ($configs != '') {
            $configs = json_decode( Cripto::decrypt( $configs, NONCE_SALT) );
        }
        return $configs;
    }

	function save_settings() {
		if ( !isset($_POST['hgcloudfront_settings']) ) {
			return;
		}

        $data = [];
        for ($i = 1; $i <= 2; $i++) {
            $data['distribution_id_'.$i] = isset($_POST['distribution_id_'.$i]) ? $_POST['distribution_id_'.$i] : '';
            $data['access_key_'.$i] = isset($_POST['access_key_'.$i]) ? $_POST['access_key_'.$i] : '';
            $data['secret_key_'.$i] = isset($_POST['secret_key_'.$i]) ? $_POST['secret_key_'.$i] : '';
        } 
		update_option('hgcloudfront_settings', Cripto::encrypt( json_encode($data), NONCE_SALT) );
		
		add_settings_error(
			'hgcloudfront_settings',
			'hgcloudfront_settings',
			'As configurações foram salvas com sucesso!',
			'updated'
		);
    }

    function generate_signature($date, $access_key, $secret_key, $distribution_id) {
        // Implementação da geração da assinatura
        $string_to_sign = "AWS4-HMAC-SHA256\n" . $date . "\n" . gmdate('Ymd') . "/us-east-1/cloudfront/aws4_request\n" . hash('sha256', "<payload>");

        // Geração da chave
        $date_key = hash_hmac('sha256', gmdate('Ymd'), "AWS4" . $secret_key, true);
        $date_region_key = hash_hmac('sha256', 'us-east-1', $date_key, true);
        $date_region_service_key = hash_hmac('sha256', 'cloudfront', $date_region_key, true);
        $signing_key = hash_hmac('sha256', 'aws4_request', $date_region_service_key, true);

        // Geração da assinatura
        $signature = hash_hmac('sha256', $string_to_sign, $signing_key);

        return $signature;
    }
}

new HG_Cloudfront();