<?php defined( 'ABSPATH' ) || exit; ?>
<div class="wrap">
    <div id="icon-themes" class="icon32"></div>
    <h2>CloudFront - Configurações</h2>

    <?php settings_errors('hgcloudfront-settings') ?>

    <?php if ( $legacy_found ): ?>
        <div class="notice notice-warning">
            <p>As configurações da versão anterior (formato Cloudflare, incompatível com o CloudFront) foram descartadas. Cadastre as credenciais da AWS abaixo.</p>
        </div>
    <?php endif; ?>

    <?php if ( $decrypt_failed ): ?>
        <div class="notice notice-error">
            <p>Não foi possível ler as credenciais salvas (a chave de criptografia ou os salts do WordPress foram alterados). Cadastre-as novamente.</p>
        </div>
    <?php endif; ?>

    <?php if ( ! $config['credentials_locked'] && ! $has_dedicated_key ): ?>
        <div class="notice notice-warning">
            <p>
                Recomendado: defina <code>HG_CLOUDFRONT_ACCESS_KEY_ID</code> e <code>HG_CLOUDFRONT_SECRET_ACCESS_KEY</code> no <code>wp-config.php</code>,
                ou ao menos <code>HG_CLOUDFRONT_ENCRYPTION_KEY</code> para que as credenciais salvas não dependam dos salts do WordPress.
            </p>
        </div>
    <?php endif; ?>

    <form method="POST" action="<?php echo esc_url( admin_url( 'options-general.php?page=hgcloudfront-settings' ) ) ?>" autocomplete="off">
        <input type="hidden" name="hgcloudfront_settings" value="1" />
        <?php wp_nonce_field( HG_Cloudfront::SAVE_ACTION ) ?>

        <table class="form-table" role="presentation">
            <tbody>
                <tr>
                    <th>
                        <h2>Credenciais AWS (IAM)</h2>
                    </th>
                </tr>
                <tr>
                    <td colspan="2">
                        <p class="description">
                            <?php if ( $config['credentials_locked'] ): ?>
                                Definidas via constantes no <code>wp-config.php</code>.
                            <?php else: ?>
                                Use um usuário IAM com permissão apenas para <code>cloudfront:CreateInvalidation</code> nas distribuições abaixo.
                            <?php endif; ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="access_key_id">Access Key ID:</label>
                    </th>
                    <td>
                        <input name="access_key_id" type="text" id="access_key_id" value="<?php echo esc_attr( $config['access_key_id'] ) ?>" class="regular-text" spellcheck="false" <?php disabled( $config['credentials_locked'] ) ?>>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="secret_access_key">Secret Access Key:</label>
                    </th>
                    <td>
                        <input name="secret_access_key" type="password" id="secret_access_key" value="" placeholder="<?php echo esc_attr( $secret_hint ) ?>" class="regular-text" autocomplete="new-password" spellcheck="false" <?php disabled( $config['credentials_locked'] ) ?>>
                        <?php if ( ! $config['credentials_locked'] && $secret_hint !== '' ): ?>
                            <p class="description">Deixe em branco para manter a chave atual. Para remover as credenciais, apague o Access Key ID.</p>
                        <?php endif; ?>
                    </td>
                </tr>
            </tbody>

            <?php foreach ( $config['distributions'] as $i => $distribution ): ?>
                <tbody>
                    <tr>
                        <th>
                            <h2>Distribuição <?php echo (int) $i ?></h2>
                        </th>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="distribution_id_<?php echo (int) $i ?>">Distribution ID:</label>
                        </th>
                        <td>
                            <input name="distribution_id_<?php echo (int) $i ?>" type="text" id="distribution_id_<?php echo (int) $i ?>" value="<?php echo esc_attr( $distribution['id'] ) ?>" class="regular-text" spellcheck="false" placeholder="E1A2B3C4D5E6F7" <?php disabled( $distribution['locked'] ) ?>>
                            <?php if ( $distribution['locked'] ): ?>
                                <p class="description">Definido via constante no <code>wp-config.php</code>.</p>
                            <?php endif; ?>
                        </td>
                    </tr>
                </tbody>
            <?php endforeach; ?>
        </table>

        <?php submit_button(); ?>
    </form>
</div>

<style>
.form-table th {
    padding:7px 0 0 0;
}
.form-table td {
    padding:0;
}
</style>
