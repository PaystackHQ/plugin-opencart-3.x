<?php

namespace Paystack;

class Http
{
    const BASE_URL = 'https://api.paystack.co/';
    const CONCURRENCY = 5;
    const DEFAULT_TIMEOUT = 4;
    const CONNECT_TIMEOUT = 3;
    const FAILED = array('status' => 0, 'body' => '', 'errno' => -1);

    // Results never carry curl_error() text: it can echo request details.
    // Request: id, method, path, body, key and an optional per-request timeout in seconds.
    public static function request(
        #[\SensitiveParameter]
        array $requests,
        $deadlineSeconds
    ) {
        $out = array();
        $queue = array_values($requests);
        $active = array();
        $mh = curl_multi_init();
        $end = microtime(true) + $deadlineSeconds;

        while (true) {
            while ($queue && count($active) < self::CONCURRENCY) {
                $req = array_shift($queue);
                $ch = curl_init();
                $headers = array('Authorization: Bearer ' . $req['key'], 'Accept: application/json', 'User-Agent: Paystack-OpenCart');
                $opts = array(
                    CURLOPT_URL => self::BASE_URL . $req['path'],
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => isset($req['timeout']) ? (int) $req['timeout'] : self::DEFAULT_TIMEOUT,
                    CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
                    CURLOPT_FOLLOWLOCATION => false,
                    CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                    CURLOPT_SSL_VERIFYPEER => true,
                    CURLOPT_SSL_VERIFYHOST => 2,
                    CURLOPT_VERBOSE => false,
                    CURLOPT_PRIVATE => $req['id'],
                );
                if ($req['method'] === 'POST') {
                    $opts[CURLOPT_POST] = true;
                    $opts[CURLOPT_POSTFIELDS] = (string) $req['body'];
                    $headers[] = 'Content-Type: application/json';
                }
                $opts[CURLOPT_HTTPHEADER] = $headers;
                curl_setopt_array($ch, $opts);
                curl_multi_add_handle($mh, $ch);
                $active[$req['id']] = $ch;
            }

            $completed = false;
            curl_multi_exec($mh, $running);
            while ($info = curl_multi_info_read($mh)) {
                $ch = $info['handle'];
                $id = curl_getinfo($ch, CURLINFO_PRIVATE);
                $out[$id] = array(
                    'status' => (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE),
                    'body' => (string) curl_multi_getcontent($ch),
                    'errno' => (int) $info['result'],
                );
                self::release($mh, $ch);
                unset($active[$id]);
                $completed = true;
            }

            if (!$active && !$queue) {
                break;
            }
            if (microtime(true) >= $end) {
                break;
            }
            if ($completed && $queue) {
                continue;
            }
            if (curl_multi_select($mh, 0.2) === -1) {
                usleep(10000);
            }
        }

        foreach ($active as $id => $ch) {
            self::release($mh, $ch);
            $out[$id] = self::FAILED;
        }
        foreach ($queue as $req) {
            $out[$req['id']] = self::FAILED;
        }
        curl_multi_close($mh);
        return $out;
    }

    private static function release($mh, $ch)
    {
        curl_multi_remove_handle($mh, $ch);
        if (PHP_VERSION_ID < 80000) {
            curl_close($ch);
        }
    }
}
