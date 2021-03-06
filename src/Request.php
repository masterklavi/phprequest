<?php

namespace phprequest;

/**
 * @author      Master Klavi <masterklavi@gmail.com>
 * @version     0.4
 */
class Request
{
    public static $silent_mode = false;

    
    public static function get($url, array $options = [])
    {
        return self::request($url, $options);
    }
    
    public static function post($url, array $options = [])
    {
        $options['method'] = 'POST';
        return self::request($url, $options);
    }

    public static function multiGet(array $urls, array $options = [])
    {
        return self::multi($urls, $options);
    }

    public static function multiPost(array $urls, array $options = [])
    {
        $options['method'] = 'POST';
        return self::multi($urls, $options);
    }


    public static function request($url, array $options = [])
    {
        // additional params
        $allowed_codes = isset($options['allowed_codes']) ? (array)$options['allowed_codes'] : [200];
        $allow_empty = isset($options['allow_empty']) ? (bool)$options['allow_empty'] : false;
        $filter = isset($options['filter']) ? $options['filter'] : null;
        $charset = isset($options['charset']) && $options['charset'] !== 'utf8' ? $options['charset'] : null;
        $attempts = isset($options['attempts']) ? (int)$options['attempts'] : 5;

        // curl options
        $set = Curl::getOptSet($options);
        $set[CURLOPT_URL] = $url;
        $set = Curl::setOptData($set, $options);

        // requests
        for ($i = 0; $i < $attempts; $i++)
        {
            $ch = curl_init();
            curl_setopt_array($ch, $set);
            $response = curl_exec($ch);
            $error = curl_error($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            curl_close($ch);

            // check errors
            if ($error)
            {
                self::$silent_mode OR trigger_error("curl: {$error} at {$url}");
                continue;
            }
            if (!in_array($code, $allowed_codes))
            {
                self::$silent_mode OR trigger_error("http: {$code} at {$url}");
                continue;
            }

            // handle response
            $header = substr($response, 0, $size);
            $body = substr($response, $size);
            unset($response);

            if ($charset)
            {
                $body = iconv($charset, 'utf8', $body);
            }

            $result = $filter ? Filter::apply($filter, null, $header, $body) : $body;
            unset($header, $body);

            if ($result === false || !$allow_empty && !$result)
            {
                self::$silent_mode OR trigger_error("empty result at {$url}");
                continue;
            }

            return $result;
        }

        self::$silent_mode OR trigger_error("no attemps");
        return false;
    }
    
    public static function multi(array $urls, array $options = [])
    {
        // additional params
        $allowed_codes = isset($options['allowed_codes']) ? (array)$options['allowed_codes'] : [200];
        $allow_empty = isset($options['allow_empty']) ? (bool)$options['allow_empty'] : false;
        $filter = isset($options['filter']) ? $options['filter'] : null;
        $charset = isset($options['charset']) && $options['charset'] !== 'utf8' ? $options['charset'] : null;
        $attempts = isset($options['attempts']) ? (int)$options['attempts'] : 5;
        $concurrency = isset($options['concurrency']) ? (int)$options['concurrency'] : 10;
        $attempts *= (int)ceil(count($urls) / $concurrency);

        // curl options
        $opt_set = Curl::getOptSet($options);

        // requests
        $results = [];
        $keys = array_keys($urls);
        for ($i = 0; $i < $attempts; $i++)
        {
            $mh = curl_multi_init();

            $chs = [];
            for ($j = 0; $j < $concurrency; $j++)
            {
                $key = key($urls);
                $value = current($urls);
                next($urls) or reset($urls);

                if (isset($chs[$key]))
                {
                    continue;
                }

                if (is_array($value) && is_array($value[1]))
                {
                    $custom_options = array_merge($options, $value[1]);
                    $set = Curl::getOptSet($custom_options);
                    $set[CURLOPT_URL] = $value[0];
                    $set = Curl::setOptData($set, $custom_options);
                }
                else
                {
                    $set = $opt_set;
                    $set[CURLOPT_URL] = $value;
                    $set = Curl::setOptData($set, $options);
                }

                $ch = curl_init();
                curl_setopt_array($ch, $set);
                $chs[$key] = $ch;
                curl_multi_add_handle($mh, $ch);
            }

            do
            {
                curl_multi_exec($mh, $running);
                curl_multi_select($mh);
            }
            while ($running > 0);

            foreach ($chs as $key => $ch)
            {
                $url = $urls[$key];
                $response = curl_multi_getcontent($ch);
                $error = curl_error($ch);
                $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
                curl_multi_remove_handle($mh, $ch);
                curl_close($ch);

                // check errors
                if ($error)
                {
                    self::$silent_mode OR trigger_error("curl: {$error} at {$url}");
                    continue;
                }
                if (!in_array($code, $allowed_codes))
                {
                    self::$silent_mode OR trigger_error("http: {$code} at {$url}");
                    continue;
                }

                // handle response
                $header = substr($response, 0, $size);
                $body = substr($response, $size);
                unset($response);

                if ($charset)
                {
                    $body = iconv($charset, 'utf8', $body);
                }

                $result = $filter ? Filter::apply($filter, $key, $header, $body) : $body;
                unset($header, $body);

                if ($result === false || !$allow_empty && !$result)
                {
                    self::$silent_mode OR trigger_error("empty result at {$url}");
                    continue;
                }

                $results[$key] = $result;
                unset($urls[$key]);
            }

            unset($chs);
            curl_multi_close($mh);

            if (count($urls) === 0)
            {
                break;
            }
        }

        // sort results
        $sorted = [];
        $failed = 0;
        foreach ($keys as $key)
        {
            if (isset($results[$key]))
            {
                $sorted[$key] = $results[$key];
            }
            else
            {
                $sorted[$key] = false;
                $failed++;
            }
        }
        
        self::$silent_mode OR $failed > 0 AND trigger_error("{$failed} requests failed");

        return $sorted;
    }
}
