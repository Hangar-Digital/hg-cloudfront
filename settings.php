<div class="wrap">
    <div id="icon-themes" class="icon32"></div>  
    <h2>CloudFront - Configurações</h2>

    <?php settings_errors('hgcloudfront-settings') ?>

    <form method="POST" action="<?php echo get_site_url() ?>/wp-admin/options-general.php?page=hgcloudfront-settings">
        <input type="hidden" name="hgcloudfront_settings" value="1" />

        <table class="form-table" role="presentation">
            <?php for ($i = 1; $i <= 2; $i++): ?>
                <tbody>
                    <tr>
                        <th>
                            <h2>Domínio <?php echo $i ?></h2>
                        </th>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="distribution_id_<?php echo $i ?>">
                                ID da Distribuição:
                            </label>
                        </th>
                        <td>
                            <input name="distribution_id_<?php echo $i ?>" type="text" id="distribution_id_<?php echo $i ?>" value="<?php echo isset($configs->{"distribution_id_$i"}) ? $configs->{"distribution_id_$i"} : '' ?>" class="regular-text">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="access_key_<?php echo $i ?>">
                                Access Key:
                            </label>
                        </th>
                        <td>
                            <input name="access_key_<?php echo $i ?>" type="text" id="access_key_<?php echo $i ?>" value="<?php echo isset($configs->{"access_key_$i"}) ? $configs->{"access_key_$i"} : '' ?>" class="regular-text">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="secret_key_<?php echo $i ?>">
                                Secret Key:
                            </label>
                        </th>
                        <td>
                            <input name="secret_key_<?php echo $i ?>" type="text" id="secret_key_<?php echo $i ?>" value="<?php echo isset($configs->{"secret_key_$i"}) ? $configs->{"secret_key_$i"} : '' ?>" class="regular-text">
                        </td>
                    </tr>
                </tbody>
            <?php endfor; ?>
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