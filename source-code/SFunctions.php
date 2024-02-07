<?php

class SFunctions {


    protected static function httpGet(string $url, ?int &$httpCode = 0)
    {
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 60);
        curl_setopt($curl, CURLOPT_TIMEOUT, 60);
        curl_setopt($curl, CURLOPT_USERAGENT, 'curl');
        curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true );
        $content = curl_exec($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        if ($httpCode === 200) {
            return $content;
        } else {
            return false;
        }
    }

    /**
     * @param string $url
     * @param array $options
     *      userAgent
     *      timeout
     *      followRedirect
     *
     * @return array
     */

    protected static function httpGetExtended(string $url, array $options = []): array
    {
        $followRedirect = $options['followRedirect'] ?? true;
        $timeout = $options['timeout'] ?? 60;
        $userAgent = $options['userAgent'] ?? 'curl';

        // ---

        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_FOLLOWLOCATION, $followRedirect);
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, round($timeout / 2));
        curl_setopt($curl, CURLOPT_TIMEOUT, round($timeout / 2));
        curl_setopt($curl, CURLOPT_USERAGENT, $userAgent);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLINFO_HEADER_OUT, true);

        $responseHeadersRaw = '';
        curl_setopt($curl, CURLOPT_HEADERFUNCTION,
            function($curl, $headerRaw) use (&$responseHeadersRaw)
            {
                if ($headerRaw) {
                    $responseHeadersRaw .= trim($headerRaw) . "\n";
                }
                return strlen($headerRaw);
            }
        );

        $body = curl_exec($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $requestHeadersRaw = curl_getinfo($curl, CURLINFO_HEADER_OUT);
        curl_close($curl);

        return [
            'httpCode' => $httpCode,
            'requestHeadersRaw' => $requestHeadersRaw,
            'responseHeadersRaw' => $responseHeadersRaw,
            'body' => $body
        ];
    }

}



