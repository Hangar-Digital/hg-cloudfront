<?php

// Remove as credenciais (criptografadas) do banco ao excluir o plugin.
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'hgcloudfront_settings' );
