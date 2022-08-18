## php-apollo
php项目接入Apollo的包

## 最佳实践
* apollo不要安装在项目中，最好在项目外运行一个单独的守护进程，运行apollo脚本，生成.env文件，然后再用软链将该.env文件链接到项目指定目录
* 原因：防止apollo和项目产生强依赖关系，这样即使apollo挂了，我们依然可以使用我们最初的方法手动修改.env实现环境变量的修改

## 安装
* 配置composer.json
```json
{
  "require-dev": {
    "shershon/php-apollo": "^1.0.0"
  },
  "config": {
    "secure-http": false
  },
  "repositories": [
    {
      "type": "git",
      "url": "https://github.com/shershon-php-package/php-apollo.git"
    },
    {
      "type": "git",
      "url": "https://github.com/shershon-php-package/php-apollo.git"
    }
  ]
}
```
* composer require shershon-php-package/php-apollo
* rm -rf vendor/shershon/php-apollo/.git
