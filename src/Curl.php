<?php
namespace Muyu;

use Muyu\Support\Tool;
use Muyu\Support\Traits\MuyuExceptionTrait;
use Muyu\Support\HttpStatus;
use Muyu\Support\Base\XML;

class Curl
{
    private $curl;
    private $url;
    private $path;
    private $query;
    private $method;
    private $data;
    private $file;
    private $stream;
    private $header;
    private $cookie;
    private $accept;
    private $result;
    private $transfer;
    private $timeout;
    private $retry;
    private $retryErrorCode;
    private $proxy;
    private $isJsonRequest;

    private static $GlobalSetting = [];

    use MuyuExceptionTrait;

    public function __construct($url = null) {
        $this->initError();
        $this->url($url);
        $this->transfer = true;
        $this->timeout = 60;
        $this->retryErrorCode = [28, 35, 52];
        $this->proxy = false;
        $this->isJsonRequest = false;
        $this->cookie = [];
        $this->header = [];
        $this->query = [];
        $this->file = [];
        $this->initCurl();
    }

    public function url($url = null) {
        if(! $url) {
            return $this->url;
        }
        $this->url = strpos($url, 'http') === 0 ? $url : 'http://' . $url;
        return $this;
    }

    public function fullUrl() {
        $url = $this->url;
        if($this->path) {
            mb_substr($this->url, -1) != '/' && $url .= '/';
            $url .= $this->path;
        }
        if($this->query) {
            $url .= '?' . http_build_query($this->query);
        }
        return $url;
    }

    public function path($path = null) {
        if(! $path) {
            return $this->path;
        }
        $this->path = $path;
        return $this;
    }

    public function query($query = null) {
        if(! $query) {
            return $this->query;
        }
        $this->query = $query;
        return $this;
    }

    public function transfer($transfer = null) {
        if($transfer === null) {
            return $this->transfer;
        }
        $this->transfer = $transfer;
        return $this;
    }

    public function accept($accept = null) {
        if(! $accept) {
            return $this->accept;
        }
        $this->accept = $accept;
        return $this;
    }

    public function data($data = null) {
        if(! $data) {
            return $this->isJsonRequest ? json_decode($this->data, true) : $this->data;
        }
        $this->data = $data;
        return $this;
    }

    public function file($key = null, $file = null, $name = null, $mime = null) {
        if($key === null) {
            return $this->file;
        }
        if(! $file) {
            return $this->file[$key] ?? null;
        }
        $name = $name ?? basename($file);
        $mime = $mime ?? mime_content_type($file);
        $this->file[$key] = [
            'file' => $file,
            'name' => $name,
            'mime' => $mime,
        ];
        return $this;
    }

    public function json($obj) {
        $this->data = json_encode($obj);
        $this->isJsonRequest = true;
        $this->header = array_merge($this->header, ['Content-Type' => 'application/json']);
        return $this;
    }

    public function cookie($cookie = null) {
        if(! $cookie) {
            return $this->cookie;
        }
        $this->cookie = $cookie;
        return $this;
    }

    public function header($header = null) {
        if(! $header) {
            return $this->header;
        }
        $this->header = $header;
        return $this;
    }

    public function responseHeader() {
        return $this->result['header'] ?? [];
    }

    public function isJsonRequest() {
        return $this->isJsonRequest;
    }

    public function responseCookie() {
        $cookie = [];
        foreach($this->result['header']['Set-Cookie'] as $info) {
            $info = explode(';', $info)[0];
            $key = explode('=', $info)[0];
            $val = explode('=', $info)[1];
            $cookie[$key] = $val;
        }
        return $cookie;
    }

    public function status() {
        return $this->result['header']['Status'] ?? '';
    }

    public function title() {
        return Tool::strBetween($this->content(), '<title>', '</title>');
    }

    public function content() {
        return $this->result['content'] ?? '';
    }

    public function timeout($second = null) {
        if(! $second) {
            return $this->timeout;
        }
        $this->timeout = $second;
        curl_setopt($this->curl, CURLOPT_TIMEOUT, $second);
        curl_setopt($this->curl, CURLOPT_CONNECTTIMEOUT, $second);
        return $this;
    }

    public function retry($times = null) {
        if(! $times) {
            return $this->retry;
        }
        $this->retry = $times;
        return $this;
    }

    public function retryErrorCode($errorCode = null) {
        if(! $errorCode) {
            return count($this->retryErrorCode) == 1 ? $this->retryErrorCode[0] : $this->retryErrorCode;
        }
        $this->retryErrorCode = is_array($errorCode) ? $errorCode : [$errorCode];
        return $this;
    }

    public function proxy($proxy) {
        if(! $proxy) {
            return $this->proxy;
        }
        $this->proxy = $proxy;
        return $this;
    }

    public function stream($file = null) {
        if($file) {
            $this->stream = $file;
            return $this;
        }
        return $this->stream;
    }

    public function get($returnResult = true) {
        $this->method = 'GET';
        $rs = $this->handle($this->curl);
        return $returnResult ? $rs : $this;
    }

    public function post($returnResult = true) {
        $this->method = 'POST';
        $rs = $this->handle($this->curl);
        return $returnResult ? $rs : $this;
    }

    public function put($returnResult = true) {
        $this->method = 'PUT';
        $rs = $this->handle($this->curl);
        return $returnResult ? $rs : $this;
    }

    public function delete($returnResult = true) {
        $this->method = 'DELETE';
        $rs = $this->handle($this->curl);
        return $returnResult ? $rs : $this;
    }

    public function patch($returnResult = true) {
        $this->method = 'PATCH';
        $rs = $this->handle($this->curl);
        return $returnResult ? $rs : $this;
    }

    private function format($raw) {
        if($raw === null || $raw === false) {
            return $raw;
        }
        $headers = $this->responseHeader();
        if(isset($headers['Content-Encoding'])) {
            if(strpos($headers['Content-Encoding'], 'deflate') !== false) {
                $raw = zlib_decode($raw);
            }
        }
        if($this->transfer) {
            if($this->accept) {
                switch($this->accept) {
                    case 'json': return json_decode($raw, true);
                    case 'xml': return XML::parse($raw);
                    default: return $raw;
                }
            }
            else {
                $contentType = $this->responseHeader()['Content-Type'] ?? null;
                switch ($contentType) {
                    case 'application/json': return json_decode($raw, true);
                    case 'application/xml' :
                    case 'text/xml'        : return XML::parse($raw);
                    default                : return $raw;
                }
            }
        }
        return $raw;
    }

    private function handle($curl, $redirect = false) {
        if(! $redirect) {
            curl_setopt($this->curl, CURLOPT_URL, $this->fullUrl());
        }
        switch($this->method) {
            case 'GET': break;
            case 'POST':  {
                curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'POST');
                $data = $this->data ?? [];
                if($this->file) {
                    $files = [];
                    foreach ($this->file as $key => $file) {
                        $files[$key] = new \CurlFile($file['file'], $file['mime'], $file['name']);
                    }
                    $data = array_merge($data, $files);
                }
                curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
                break;
            }
            case 'PUT': {
                curl_setopt($curl, CURLOPT_CUSTOMREQUEST, "PUT");
                if($this->stream) {
                    $file = $this->stream;
                    curl_setopt($curl, CURLOPT_PUT, 1);
                    $stream = fopen($file, 'r');
                    $size = filesize($file);
                    empty($this->header['Content-Length']) && $this->header['Content-Length'] = $size;
                    curl_setopt($curl, CURLOPT_INFILE, $stream);
                    curl_setopt($curl, CURLOPT_INFILESIZE, $size);
                }
                if($this->data)
                    curl_setopt($curl, CURLOPT_POSTFIELDS, is_array($this->data) ? http_build_query($this->data) : $this->data);
                break;
            }
            case 'DELETE': {
                curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'DELETE');
                if($this->data)
                    curl_setopt($curl, CURLOPT_POSTFIELDS, is_array($this->data) ? http_build_query($this->data) : $this->data);
                break;
            }
            case 'PATCH': {
                curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'PATCH');
                if($this->data)
                    curl_setopt($curl, CURLOPT_POSTFIELDS, is_array($this->data) ? http_build_query($this->data) : $this->data);
                break;
            }
        }
        if($this->proxy) {
            $this->proxy['type'] == 'socks5' && curl_setopt($curl, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS5_HOSTNAME);
            curl_setopt($curl, CURLOPT_PROXY, $this->proxy['host']);
        }
        if($this->cookie) {
            $cookieStr = '';
            foreach($this->cookie as $key => $val)
                $cookieStr .= $key . '=' . $val . ';';
            curl_setopt($curl, CURLOPT_COOKIE, $cookieStr);
        }
        if($this->header) {
            $headerData = [];
            foreach($this->header as $key => $value)
                array_push($headerData, $key . ': ' . $value);
            curl_setopt($curl, CURLOPT_HTTPHEADER, $headerData);
        }
        $content = curl_exec($curl);
        if($this->retry) {
            $times = $this->retry;
            while($times-- > 0 && in_array(curl_errno($curl), $this->retryErrorCode)) {
                $content = curl_exec($curl);
            }
        }
        if(curl_errno($curl) !== 0) {
            $this->addError(curl_errno($curl));
        }
        if($content === null || $content === false) {
            return $content;
        }
        $response = explode("\r\n", $content);
        $headers = [];
        $headers['Set-Cookie'] = [];
        $content = '';
        $endHeader = false;
        $count = 0;
        $continue = false;
        foreach($response as $row) {
            $count++;
            $redirectTime = 5;
            if(!$endHeader) {
                $header = explode(': ', $row);
                $key = $header[0];
                $val = $header[1] ?? null;
                if($val == null && strlen($row) != 0) {
                    if(strstr($key, '100')) {
                        $continue = true;
                    }
                    if((strstr($key, '301') || strstr($key, '302') || strstr($key, '307') || strstr($key, '308')) && $redirectTime-- > 0) {
                        $url = null;
                        foreach($response as $roww) {
                            if(strpos(strtolower($roww), 'location: ') !== false) {
                                $url = trim(str_replace('location: ', '', strtolower($roww)));
                                if(substr($url, 0, 1) == '/') {
                                    $info = parse_url($this->url);
                                    $url = $info['scheme'] . '://' . $info['host'] . $url;
                                }
                                $this->initCurl();
                                curl_setopt($this->curl, CURLOPT_URL, $url);
                                break;
                            }
                        }
                        if($url != null && $url != $this->fullUrl()) {
                            return $this->handle($this->curl, true);
                        }
                        $this->addError(-2, 'can not get redirect url');
                        return false;
                    } else if($redirectTime <= 0) {
                        $this->addError(1, 'redirect too many times');
                        return false;
                    }
                    $headers['Status'] = $key;
                    continue;
                }
                if($continue) {
                    $continue = false;
                    continue;
                }
                if(strlen($row)) {
                    if($key == 'Set-Cookie') {
                        $headers['Set-Cookie'][] = $val;
                    }
                    else {
                        $headers[$key] = $val;
                    }
                }
                else {
                    $endHeader = true;
                }
            }
            else {
                $content .= $row . ($count == count($response) ? '' : "\r\n");
            }
        }
        $this->result['header'] = $headers;
        $this->result['body'] = $content;
        $this->result['content'] = $this->format($content);
        return $this->content();
    }

    private function initCurl() {
        $this->curl = curl_init();
        curl_setopt($this->curl, CURLOPT_HEADER, 1);
        curl_setopt($this->curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($this->curl, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt($this->curl, CURLOPT_FOLLOWLOCATION, 0);
        curl_setopt($this->curl, CURLOPT_TIMEOUT, 60);
        curl_setopt($this->curl, CURLOPT_CONNECTTIMEOUT, 60);
        foreach(self::$GlobalSetting as $key => $val) {
            switch($key) {
                case 'ss': $this->ss();break;
                case 'proxy': $this->proxy($val);break;
                case 'retry': $this->retry($val);break;
                case 'retryErrorCode': $this->retryErrorCode($val);break;
                case 'cookie': $this->cookie($val);break;
                case 'header': $this->header($val);break;
                default: $this->addError(-1, 'unknown global setting: ' . $key);
            }
        }
    }

    static public function setGlobalSetting(array $setting, $overwrite = false) {
        if(! self::$GlobalSetting) {
            self::$GlobalSetting = $setting;
        }
        else if($overwrite) {
            self::$GlobalSetting = $setting;
        }
    }

    public function is404() {
        return $this->status() == HttpStatus::_404();
    }

    public function is200() {
        return $this->status() == HttpStatus::_200();
    }

    public function ss() {
        $this->proxy([
            'type' => 'socks5',
            'host' => 'localhost:1080',
        ]);
        return $this;
    }

    public function close() {
        if(is_resource($this->curl)) {
            curl_close($this->curl);
        }
    }

    public function __destruct() {
        $this->close();
    }
}