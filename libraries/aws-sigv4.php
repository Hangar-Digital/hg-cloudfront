<?php

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'HG_Cloudfront_SigV4' ) ) {

    /**
     * Assinatura AWS Signature Version 4 (somente o necessário para requisições sem query string).
     * https://docs.aws.amazon.com/IAM/latest/UserGuide/reference_sigv-create-signed-request.html
     */
    final class HG_Cloudfront_SigV4 {

        /**
         * @param array  $headers  cabeçalhos a assinar (além de host e x-amz-date, adicionados aqui)
         * @param string $amz_date formato Ymd\THis\Z (UTC)
         * @return string valor do cabeçalho Authorization
         */
        public static function authorization( $method, $host, $path, array $headers, $payload, $amz_date, $region, $service, $access_key_id, $secret_access_key ) {
            $headers['host']       = $host;
            $headers['x-amz-date'] = $amz_date;

            $normalized = [];
            foreach ( $headers as $name => $value ) {
                $normalized[ strtolower( trim( $name ) ) ] = preg_replace( '/\s+/', ' ', trim( (string) $value ) );
            }
            ksort( $normalized, SORT_STRING );

            $canonical_headers = '';
            foreach ( $normalized as $name => $value ) {
                $canonical_headers .= $name . ':' . $value . "\n";
            }
            $signed_headers = implode( ';', array_keys( $normalized ) );

            $canonical_request = implode( "\n", [
                $method,
                $path,
                '', // query string
                $canonical_headers,
                $signed_headers,
                hash( 'sha256', (string) $payload ),
            ] );

            $date  = substr( $amz_date, 0, 8 );
            $scope = "$date/$region/$service/aws4_request";

            $string_to_sign = implode( "\n", [
                'AWS4-HMAC-SHA256',
                $amz_date,
                $scope,
                hash( 'sha256', $canonical_request ),
            ] );

            $key = hash_hmac( 'sha256', $date, 'AWS4' . $secret_access_key, true );
            $key = hash_hmac( 'sha256', $region, $key, true );
            $key = hash_hmac( 'sha256', $service, $key, true );
            $key = hash_hmac( 'sha256', 'aws4_request', $key, true );

            $signature = hash_hmac( 'sha256', $string_to_sign, $key );

            return 'AWS4-HMAC-SHA256 Credential=' . $access_key_id . '/' . $scope
                . ', SignedHeaders=' . $signed_headers
                . ', Signature=' . $signature;
        }
    }
}
