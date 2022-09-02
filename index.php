<?php
require('./vendor/autoload.php');
use CustomCurl\Client;

$originProtocol = 'http';
$originSite     = 'baidu.com';
$thisSite       = 'example.com';

function get_content($url){
    return <<<EOL
<meta content="always" name="referrer">
<meta name="robots" content="noindex, nofollow, noarchive, nosnippet" />
<meta http-equiv="Cache-control" content="no-cache">
<link rel="icon" href="data:,">
<script>
setTimeout(function(){window.location.replace("{$url}")},30);
window.opener = null;
</script>
<noscript>
<META http-equiv="refresh" content="0;URL='{$url}'">
</noscript>
EOL;
}
if(!empty($_COOKIE['d'])) {
header("X-Robots-Tag: noindex, nofollow", true);
header("Cache-Control: no-store, private", true);
setcookie("d", "",[
    'expires' => time() - 86400,
    'path' => '/',
    'domain' => 'example.com',
    'secure' => true,
    'samesite' => 'None'
]);
echo get_content($_COOKIE['d']);
exit;
}

if (!function_exists('getallheaders')) {
    function getallheaders() {
        foreach ($_SERVER as $name => $value) {
            if (substr($name, 0, 5) == 'HTTP_') {
                $headers[str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))))] = $value;
            }
        }
        return $headers;
    }
}

class ReverseProxy
{
    private $flags = [
            'FILENAME_SENT'       => false,
            'HEADER_SENT'         => false,
            'CURL_DOWNLOAD_FILE'  => false,
            'CURL_VALID_RESPONSE' => true
        ];
    private $runtimeData = [
            'headerSize'       => -1,
            'outputBuffer'     => [
                'header' => [],
                'body'   => ''
            ]
        ];
    private $config = [
            'replace' => null,
        ];

    public function curlCallback($ch, $data)
    {
        $info = curl_getinfo($ch);

        if ($this->runtimeData['headerSize'] < $info['header_size']) {
            $this->runtimeData['headerSize'] = $info['header_size'];

            if (!$this->flags['HEADER_SENT']) {
                if (strpos(strtolower($data), 'content-disposition: attachment') !== false) {
                    $this->flags['FILENAME_SENT'] = true;
                    $this->flags['CURL_DOWNLOAD_FILE'] = true;
                }
                $this->runtimeData['outputBuffer']['header'][] = $data;
            }
            // header 结束
            if ($data === "\r\n") {
                $this->runtimeData['headerSize'] += 2;
                if (!$this->flags['HEADER_SENT'] && $this->flags['CURL_DOWNLOAD_FILE']) {
                    foreach ($this->runtimeData['outputBuffer']['header'] as $header) {
                        if (strpos(strtolower($header), 'content-encoding:') !== false) {
                            continue;
                        }
                        if ($this->config['replace']) {
                            header(str_replace($this->config['replace'][0], $this->config['replace'][1], $header), false);
                        } else {
                            header($header, false);
                        }
                    }
                    if (!$this->flags['FILENAME_SENT']) {
                        header('Content-Disposition: attachment; filename=' . basename($this->config['filePath']));
                    }
                    $this->flags['HEADER_SENT'] = true;
                }
            }
        } else {
            // Data 段数据处理
            if ($this->flags['CURL_DOWNLOAD_FILE']) {
                echo $data;
                ob_flush();
                flush();
            } else {
                $this->runtimeData['outputBuffer']['body'] .= $data;
            }
        }

        return strlen($data);
    }

    public function __construct($config)
    {
        $this->config = array_merge($this->config, $config);

        $curlObj = Client::init("{$this->config['originProtocol']}://{$this->config['originSite']}{$_SERVER['REQUEST_URI']}", $_SERVER['REQUEST_METHOD'])
            ->setCurlOpt(CURLOPT_ENCODING, '')
            ->set('timeout', 0)
            ->set('reRequest', 1)
            ->set('followLocation', 0);

        $headers = getallheaders();

        foreach ($headers as $key => $value) {
            $keyArr = ['accept-encoding', 'host', 'referer', 'cookie', 'user-agent', 'content-length'];
            if (in_array(strtolower($key), $keyArr)) {
                continue;
            }
            $curlObj = $curlObj->setHeader($key, str_replace($this->config['thisSite'], $this->config['originSite'], $value));
        }

        if (isset($_SERVER['HTTP_REFERER']) && $_SERVER['HTTP_REFERER']) {
            $curlObj = $curlObj->set('referer', str_replace($this->config['thisSite'], $this->config['originSite'], $_SERVER['HTTP_REFERER']));
        }

        if (isset($_SERVER['HTTP_COOKIE']) && $_SERVER['HTTP_COOKIE']) {
            $curlObj = $curlObj->setCookies(str_replace($this->config['thisSite'], $this->config['originSite'], $_SERVER['HTTP_COOKIE']));
        }

        if (in_array($_SERVER['REQUEST_METHOD'], ['POST', 'PUT', 'DELETE'])) {
            $curlObj = $curlObj->set('postType', 'string')
                ->set('postFields', file_get_contents("php://input"));
        }

        $res = $curlObj->setCurlOpt(CURLOPT_WRITEFUNCTION, [$this, 'curlCallback'])->exec();

        if ($res->getStatus()) {
            if (!$this->flags['CURL_DOWNLOAD_FILE']) {
                // 公用替换1
                if(preg_match_all('/<script.*?<\/script>/s',$this->runtimeData['outputBuffer']['body'], $matches) !== false){
                  $flagsArray = [
                    'googletagmanager.com',
                    'window.dataLayer',
                    'googletag.cmd',
                    'skimresources.com',
                    'blogherads.com',
                    'blogherads.adq',
                    'adsbygoogle.js',
                    'amazon-adsystem.com',
                    'google-analytics-for-wordpress',
                    'mediavine.com'
                  ];
                  foreach ($matches[0] as $m){
                    foreach ($flagsArray as $f){
                      if(strpos($m, $f) !== false){
                        $this->runtimeData['outputBuffer']['body'] = str_replace($m,'<!--'.$f.'-->',$this->runtimeData['outputBuffer']['body']);
                      }
                    }
                  }
                }
                //公用替换2
                $this->runtimeData['outputBuffer']['body'] = preg_replace(
                  [
                    '/<meta.*google-site-verification.*?>/',
                    '/<meta.*name.*referrer.*content=.*?>/'
                  ], 
                  [
                    '<!-- google-site-verification -->',
                    ''
                  ], 
                  $this->runtimeData['outputBuffer']['body']
                );
                $this->runtimeData['outputBuffer']['body'] = str_replace('</head>','<meta name="referrer" content="no-referrer">'.PHP_EOL.'</head>',$this->runtimeData['outputBuffer']['body']);
                // 普通替换
                if ($this->config['replace']) {
                    $this->runtimeData['outputBuffer']['body'] = str_replace($this->config['replace'][0], $this->config['replace'][1], $this->runtimeData['outputBuffer']['body']);
                }
                // 正则替换
                if ($this->config['reg_replace']) {
                    $this->runtimeData['outputBuffer']['body'] = preg_replace($this->config['reg_replace'][0], $this->config['reg_replace'][1], $this->runtimeData['outputBuffer']['body']);
                }
                foreach ($this->runtimeData['outputBuffer']['header'] as $header) {
                    if (strpos(strtolower($header), 'content-length:') !== false) {
                        header('Content-Length: ' . strlen($this->runtimeData['outputBuffer']['body']), false);
                        continue;
                    }
                    if (strpos(strtolower($header), 'content-encoding:') !== false) {
                        continue;
                    }
                    if ($this->config['replace']) {
                        header(str_replace($this->config['replace'][0], $this->config['replace'][1], $header), false);
                    } else {
                        header($header, false);
                    }
                }

                echo $this->runtimeData['outputBuffer']['body'];
            }
        } else {
            throw new \Exception('Curl error (' . $res->getCurlErrNo() . '): ' . $res->getCurlErr(), $res->getCurlErrNo());
        }
    }
}

try {
    new ReverseProxy([
        'replace' => [
            [
                $originSite
            ], [
                $thisSite
            ]
        ],
        'reg_replace' => [
            [
              '/<span.*?data-cfemail.*?<\/span>/',
            ],[
              'contact@'.$_SERVER['HTTP_HOST'],
            ],
        ],
        'originProtocol' => $originProtocol,
        'originSite' =>$originSite,
        'thisSite' => $thisSite
    ]);
} catch (\Exception $e) {
    echo '<h1>Proxy Error</h1><p>' . $e->getMessage() . '</p>';
}
