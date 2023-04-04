<?php

class ConnectionQualityTest {

    private array $httpPingUrls = [
        'https://www.yahoo.com' => 'Yahoo',
        'https://www.bing.com' => 'Bing',
        'https://duckduckgo.com' => 'DuckDuckGo'
    ];
    private array $publicIpDetectUrls = [
        'http://ipecho.net/plain',
        'http://api.ipify.org',
        'http://api64.ipify.org'
    ];

    private array $httpPipes = [];
    private array $httpProcesses = [];

    private string $netnsName, $log = '', $publicIp = '';

    private bool $httpPingOk = true;
    private int $testStartedAt, $timeout = 15;

    public function __construct(string $netnsName)
    {
        $this->netnsName = $netnsName;

        // ---

        $this->log('Starting Connection Quality Test');

        $descriptorSpec = array(
            0 => array("pipe", "r"),  // stdin
            1 => array("pipe", "w"),  // stdout
            2 => array("pipe", "a")   // stderr
        );

        $urls = array_merge($this->publicIpDetectUrls, array_keys($this->httpPingUrls));

        foreach ($urls as $url) {
            $this->httpProcesses[$url] = proc_open("ip netns exec $this->netnsName   curl  --header \"Accept-Language: en-US\" --silent  --max-time $this->timeout  --location  $url",  $descriptorSpec, $this->httpPipes[$url]);
        }

        $this->testStartedAt = time();
    }

    public function abort()
    {
        foreach ($this->httpProcesses as $process) {
            @proc_terminate($process, SIGKILL);
            @proc_close($process);
        }
    }

    public function process(): bool
    {
        $isTimeout = $this->testStartedAt + $this->timeout * 1.25 < time();

        $runningCount = 0;
        foreach ($this->httpProcesses as $url => $process) {
            if (is_resource($process)) {
                $processStatus = proc_get_status($process);
                if ($processStatus['running']) {
                    //echo "running $url\n";
                    $runningCount++;
                }
            }
        }

        if ($runningCount  &&  !$isTimeout) {
            return false;
        }

        // ---

        foreach ($this->httpPingUrls as $pingUrl => $titleMarker) {
            $stdout = streamReadLines($this->httpPipes[$pingUrl][1], 0);
            $pageTitle = '';
            if (preg_match('#<title>(.*?)</title>#i', $stdout, $matches) > 0) {
                $pageTitle = $matches[1] ?? '';
            }
            $pageTitle = trim($pageTitle);

            if (mb_strpos($pageTitle, $titleMarker) === false) {
                $this->log("Failed to connect to $pingUrl", true);
                if ($pageTitle) {
                    $this->log(". Instead got page \"$pageTitle\"", true);
                } else if ($stdout) {
                    $this->log("\n\n$stdout\n\n");
                }
                $this->log(". Response has " . strlen($stdout) . " bytes");
                $this->httpPingOk = false;
            } else {
                $this->log("Successfully connected to $pingUrl");
            }
        }

        // ---

        $publicIps = [];
        foreach ($this->publicIpDetectUrls as $url) {
            $stdout = streamReadLines($this->httpPipes[$url][1], 0);
            $ip  = filter_var(trim($stdout), FILTER_VALIDATE_IP);
            if ($ip) {
                $publicIps[$url] = $ip;
            }
        }

        if (count($publicIps)) {
            $this->publicIp = getArrayFirstValue($publicIps);
            $this->log("$this->publicIp public IP detected by " . array_key_first($publicIps));
        }

        $this->abort();
        return true;
    }

    function getLog(): string
    {
        return $this->log;
    }

    function wasHttpPingOk(): bool
    {
        return $this->httpPingOk;
    }

    function getPublicIp(): string
    {
        return $this->publicIp;
    }

    private function log($message = '', $noLineEnd = false)
    {
        $message .= $noLineEnd  ?  '' : "\n";
        $this->log .= $message;
    }
}