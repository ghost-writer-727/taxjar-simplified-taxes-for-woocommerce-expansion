<?php 
namespace TaxJarExpansion;

defined( 'ABSPATH' ) ||  exit; // Exit if accessed directly
/** 
 * Webhooker
 * @version 1.0.0
 * A simple class to simplify sending data to Zapier and other webhook services.
 */

if( ! class_exists( __NAMESPACE__ . '\Webhooker' ) ):
class Webhooker {
	/**
	 * Send data to Zapier
	 * @param string $webhookUrl The webhook URL
	 * @param array $data The data to send
	 * @param int $timeout The timeout in seconds
	 * @return array
	 */
	public static function send($webhookUrl, $data = [], $timeout = 10) {
		// Validate the webhook URL
        if (!filter_var($webhookUrl, FILTER_VALIDATE_URL)) {
            throw new \InvalidArgumentException('Invalid webhook URL.');
        }

        // Validate the data
        if (!is_array($data) && !is_string($data)) {
            throw new \InvalidArgumentException('Data must be an array or a string.');
        }

		// Validate that timeout is an integer
		if (!is_int($timeout)) {
			throw new \InvalidArgumentException('Timeout must be an integer.');
		}

		$ch = curl_init($webhookUrl);
		$timeout = 10; // Seconds
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
		// DEV: We're using http_build_query($data) and setting the Content-Type header to 'application/x-www-form-urlencoded'. This is appropriate for many webhook providers, but some might require JSON. Consider either detecting the required format from the $webhookUrl or adding a parameter to specify the content type.

		curl_setopt($ch, CURLOPT_POST, 1);
		curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/x-www-form-urlencoded'));
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);
	    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);

		$response = curl_exec($ch);

		if ($response === false) {
			throw new \Exception('cURL error: ' . curl_error($ch));
		}
		
		$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

		curl_close($ch);

		return [
			'success' => $httpCode >= 200 && $httpCode < 300,
			'response' => $response,
			'http_code' => $httpCode
		];
	}
}

endif;
