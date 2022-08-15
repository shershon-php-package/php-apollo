<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use Shershon\Client\ApolloClient;

//php apollo.php --appid=edu-user --server=172.16.101.100:8080 --env_dir=/Users/shershon/ProjectItem/PhpItem/php-package/php-apollo/conf --namespaces=application

$params = getopt(
    '',
    [
        'appid:',
        'server:',
        'cluster::',
        'namespaces::',
        'multi_env::',
        'env_dir::'
    ]
);

$appid = $params['appid'];
if (empty($appid)) {
    throw new Exception('请指定appid');
}
$server = $params['server'];
if (empty($server)) {
    throw new Exception('请指定server host');
}
$cluster    = !empty($params['cluster']) ? $params['cluster'] : 'default';
$namespaces = !empty($params['namespaces']) ? explode(',', $params['namespaces']) : ['application'];
$multi_env  = !empty($params['multi_env']) ? $params['multi_env'] : 0;
$env_dir    = !empty($params['env_dir']) ? rtrim($params['env_dir'], '/') : __DIR__;

//指定apollo支持二级配置，默认一级
define('MULTI_ENV', $multi_env);
//定义apollo配置本地化存储路径
define('SAVE_DIR', $env_dir);
//指定env目录和文件
define('ENV_DIR', $env_dir . DIRECTORY_SEPARATOR);
define('ENV_FILE', $env_dir . DIRECTORY_SEPARATOR . 'apollo_env.php');

$callback = function () {
    global $namespaces;
    $list   = $namespaces;
    $apollo = [];
    foreach ($list as $l) {
        if (!file_exists(ENV_DIR . 'apolloConfig.' . $l . '.php')) {
            continue;
        }
        $config = require ENV_DIR . 'apolloConfig.' . $l . '.php';
        if (is_array($config) && isset($config['configurations'])) {
            $apollo = array_merge($apollo, $config['configurations']);
        }
    }
    if (!$apollo) {
        throw new Exception('Load Apollo Config Failed, no config available');
    }
    $list = !empty(MULTI_ENV) ? toMutiArray($apollo) : $apollo;
    write(ENV_FILE, $list);
};

$apollo = new ApolloClient($server, $appid, $namespaces);
//如果需要灰度发布，指定clientIp
/*$clientIp = '10.160.2.131';
if (isset($clientIp) && filter_var($clientIp, FILTER_VALIDATE_IP)) {
    $apollo->setClientIp($clientIp);
}*/
//从apollo上拉取的配置默认保存在脚本目录，可自行设置保存目录
$apollo->save_dir = SAVE_DIR;
$apollo->setCluster($cluster);
ini_set('memory_limit', '128M');
$pid = getmypid();
echo "start [$pid]" . PHP_EOL;
$restart = 1; //失败自动重启
do {
    $error = $apollo->start($callback); //此处传入回调
    var_dump("error:" . $error);
} while ($error && $restart);

/**
 * 将一维数组中，key带有"."的变为二维数组
 * @param $arr
 * @return array
 */
function toMutiArray($arr)
{
    $list = [];
    //先处理一维环境变量
    foreach ($arr as $k => $item) {
        if (strpos($k, '.') === false) {
            $list[$k] = $item;
        }
    }
    //再展示二维环境变量
    foreach ($arr as $k => $item) {
        if (strpos($k, '.') !== false) {
            $dot                    = explode('.', $k);
            $list[$dot[0]][$dot[1]] = $item;
        }
    }
    return $list;
}

/**
 * 将数组写入ini文件
 * @param string $filename 文件名,带路径
 * @param array $arr 写入的文件内容，为一维或二维数组
 * @retrun array
 */
function write($filename, array $arr)
{
    $ret  = ["status" => false, "error" => ""];
    $path = dirname($filename);
    if (!is_dir($path)) {
        if (!mkdir($path, 0755, true)) {
            $ret['error'] = "创建目录失败：{$path}";
            return $ret;
        }
    }
    if (file_exists($filename)) {
        if (!is_writable($filename)) {
            $ret['error'] = "文件：{$filename}，无写权限";
            return $ret;
        }
    }
    return writeIniFile($filename, $arr);
}

/**
 * 写ini文件,不验证文件是否合法
 * @param string $filename 文件名,带路径
 * @param array $arr 写入的文件内容，为一维或二维数组
 * @retrun boolean
 */
function writeIniFile($filename, $arr)
{
    $ret = ["status" => false, "error" => ""];
    $fp  = fopen($filename, "w+");
    if (!$fp) {
        $ret['error'] = "打开文件失败：{$filename}";
        return $ret;
    }
    if (!is_array($arr) || empty($arr)) {
        $ret['error'] = "写内容不能为空";
        return $ret;
    }
    fwrite($fp, "<?php\nreturn [\n");
    foreach ($arr as $key => $val) {
        //处理二维结构
        if (is_array($val)) {
            if (strpbrk($key, '{}|&~![()')) {
                $key = '"' . $key . '"';
            }
            if (!fwrite($fp, "[{$key}]\n")) {
                fclose($fp);
                $ret['error'] = "写文件内容失败";
                return $ret;
            }
            foreach ($val as $k => $v) {
                if (strpbrk($k, '{}|&~![()')) {
                    $k = '"' . $k . '"';
                }
                //处理三维结构
                if (is_array($v)) {
                    foreach ($v as $i => $n) {
                        if (is_array($n)) {
                            fclose($fp);
                            $ret['error'] = "数组结构不能超过三维";
                            return $ret;
                        }
                        if (strpbrk($n, '{}|&~![()')) {
                            $n = '"' . $n . '"';
                        }
                        fwrite($fp, "{$k}[{$i}] = {$n}");
                    }
                    continue;
                }
                if (strpbrk($v, '{}|&~![()')) {
                    $v = '"' . $v . '"';
                }
                if (!fwrite($fp, "{$k} = {$v}\n")) {
                    $ret['error'] = "写文件内容失败";
                    return $ret;
                }
            }
            fwrite($fp, "\n");
            continue;
        }
        //处理一维结构
        if (!fwrite($fp, "'{$key}' => '{$val}',\n")) {
            $ret['error'] = "写文件内容失败";
            return $ret;
        }
    }
    fwrite($fp, "];");
    fclose($fp);
    $ret['status'] = true;
    return $ret;
}