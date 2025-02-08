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
        $requestHeaders = $options['requestHeaders'] ?? false;
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

        if ($requestHeaders) {
            curl_setopt($curl, CURLOPT_HTTPHEADER, $requestHeaders);
        }

        // ---

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

class RecurrentHttpGet extends SFunctions {

    public array $urls;
    public string $path, $etag;
    public int $getCount;
    public bool $changed;


    public function __construct(array $urls, string $path)
    {
        $this->urls = $urls;
        $this->path = $path;
        $this->etag = '';
        $this->changed = false;
        $this->getCount = 0;
    }

    public function get()
    {
        $httpGetOptions = [];
        if ($this->etag) {
            $httpGetOptions = [
                'requestHeaders' => ['If-None-Match: ' . $this->etag]
            ];
        }

        $this->getCount++;
        $this->changed = false;

        shuffle($this->urls);
        foreach($this->urls as $url) {
            $response = static::httpGetExtended($url, $httpGetOptions);

            if ($response['httpCode'] === 304) {
                //MainLog::log("UUUU Etag not changed");
                return true;
            } else if (
                $response['httpCode'] < 200
                || $response['httpCode'] >= 300
            ) {
                //MainLog::log("UUUU Http error");
                continue;
            }

            // ---
            //MainLog::log("UUUU Changed");

            $this->changed = true;
            file_put_contents_secure($this->path, $response['body']);

            // ---

            $etag = '';
            if (preg_match('#etag[^"]*(.*)#', $response['responseHeadersRaw'], $matches) > 0) {
                $etag = $matches[1];
            }

            $this->etag = $etag;

            // ---

            return true;
        }

        return false;
    }
}



