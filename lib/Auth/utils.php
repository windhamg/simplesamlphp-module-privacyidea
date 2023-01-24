<?php

use CirrusIdentity\SSP\Service\Cache\CacheManager;
use CirrusIdentity\SSP\Utils\ServiceLocatorUtil;
use SimpleSAML\Logger;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * The functions, which are needed in more than one class, are listed below.
 * @author Micha Preußer <micha.preusser@netknights.it>
 */
class sspmod_privacyidea_Auth_utils {


	/**
	 * @param $params
	 * All params, which are needed for the http request (e.g. user, pass, realm, etc.)
	 *
	 * @param $headers
	 * The headers for the http request (e.g. authentication token)
	 *
	 * @param $serverconfig
	 * The whole configuation for the server (e.g. url, verify host, verify peer)
	 *
	 * @param $api_endpoint
	 * This is the path for the request (e.g. /validate/samlcheck)
	 *
	 * @param $http_method
	 * Some requests need POST or GET method. This can be entered here.
	 *
	 * @return array
	 * We will return the JSON decoded body, because all the requests need different data.
	 *
	 * @throws SimpleSAML_Error_BadRequest
	 */
	public function curl($params, $headers, $serverconfig, $api_endpoint, $http_method) {
		$curl_instance = curl_init();
		$url = $serverconfig['privacyideaserver'] . $api_endpoint;

		curl_setopt($curl_instance, CURLOPT_URL, $url);
		curl_setopt($curl_instance, CURLOPT_HEADER, true);
		if ($headers != null) {
			curl_setopt($curl_instance, CURLOPT_HTTPHEADER, $headers);
		}
		curl_setopt($curl_instance, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($curl_instance, CURLOPT_USERAGENT, "simpleSAMLphp");

		if ($http_method === "POST") {
			curl_setopt($curl_instance, CURLOPT_POST, 3);
			curl_setopt($curl_instance, CURLOPT_POSTFIELDS, $params);
		} else {
			$params_str = '?';
			foreach ($params as $key => $value) {
				$params_str .=$key . "=" . urlencode($value) . "&";
			}
                        if ($http_method === 'DELETE') {
                            curl_setopt($curl_instance, CURLOPT_CUSTOMREQUEST, "DELETE");
                        }
			curl_setopt($curl_instance, CURLOPT_URL, $url . $params_str);
		}
		if ($serverconfig['sslverifyhost']) {
			curl_setopt($curl_instance, CURLOPT_SSL_VERIFYHOST, 2);
		} else {
			curl_setopt($curl_instance, CURLOPT_SSL_VERIFYHOST, 0);
		}

		if ($serverconfig['sslverifypeer']) {
			curl_setopt($curl_instance, CURLOPT_SSL_VERIFYPEER, 2);
		} else {
			curl_setopt($curl_instance, CURLOPT_SSL_VERIFYPEER, 0);
		}

		if (!$response = curl_exec($curl_instance)) {
			throw new SimpleSAML_Error_BadRequest("privacyIDEA: Bad request to PI server: " . curl_error($curl_instance));
		}
		$header_size = curl_getinfo($curl_instance, CURLINFO_HEADER_SIZE);
		$body = json_decode(substr($response, $header_size));
		return $body;
	}


	/**
	 * With this function you can get the authorization token with a service account.
	 *
	 * @param array $serverconfig
	 * The keys serviceAccount and servicePass must be set.
	 * It can be done in the config or in the metadata.
	 * The service account must have the correct rights. You can edit them in the policies in privacyIDEA
	 *
	 * @return String
	 * This is the authorization header, which is needed for some API requests.
	 */
	public function fetchAuthToken($serverconfig) {

        /** @var CacheManager $cacheManager */
        $cacheManager = ServiceLocatorUtil::getDefaultServiceLocator()->get(CacheManager::class);
        // If the format of the data getting stored in the cache changes, then change the cache name to avoid
        // conflicts with other instances
        $cache = $cacheManager->getCache('privacy-idea-c1');
        return $cache->get(
            'piApiTokenSsp',
            function (ItemInterface $item) use ($serverconfig) {
                Logger::debug('Cache miss for privacy idea API token. Getting token');
                $params = array(
                    "username" => $serverconfig['serviceAccount'],
                    "password" => $serverconfig['servicePass'],
                );

                $body = self::curl($params, null, $serverconfig, "/auth", "POST");
                try {
                    $result = $body->result;
                    $value = $result->value;
                    $token = $value->token;
                } catch (Exception $e) {
                    throw new SimpleSAML_Error_BadRequest("privacyIDEA: We were not able to read the response from the PI server");
                }
                return $token;
            }
        );

	}

	/**
	 * This function can edit the state to enter the needed token types for a user.
	 * The booleans 'use_u2f' and 'use_otp' will be added.
	 *
	 * @param array $state
	 * The state is needed to be changed in this function
	 *
	 * @param JSON $body
	 * The body contains the multi_challenge which will be used to check which token types are used.
	 *
	 * @return mixed
	 * The modified state will be returned. It now contains the token types for the user.
	 */
	public function checkTokenType($state, $body) {
		$detail = $body->detail;
		$multi_challenge = $detail->multi_challenge;
		$use_u2f = false;
		$use_otp = false;
		for ($i = 0; $i < count($multi_challenge); $i++) {
			if ($multi_challenge[$i]->type === "u2f") {
				$use_u2f = true;
			} else {
				$use_otp = true;
			}
		}
		$state['privacyidea:privacyidea:checkTokenType'] = array(
			"transaction_id" => $detail->transaction_id,
			"multi_challenge" => $multi_challenge,
		);
		if ($use_u2f === true) {
			SimpleSAML_Logger::debug("privacyIDEA: The user has u2f token");
		}
		if ($use_otp === true) {
			SimpleSAML_Logger::debug("privacyIDEA: The user has otp token");
		}
		$state['privacyidea:privacyidea:checkTokenType']['use_u2f'] = $use_u2f;
		$state['privacyidea:privacyidea:checkTokenType']['use_otp'] = $use_otp;

		return $state;
	}

}
