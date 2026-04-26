# Nginx域名管理方案

## 方案一：配置文件动态生成（推荐）

### 1. 数据库设计

```sql
-- 域名管理表
CREATE TABLE `system_domains` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `domain` varchar(100) NOT NULL COMMENT '域名',
  `status` tinyint(1) DEFAULT '1' COMMENT '状态: 0=关闭, 1=开启',
  `ssl_enabled` tinyint(1) DEFAULT '0' COMMENT 'SSL启用: 0=否, 1=是',
  `ssl_cert_path` varchar(255) DEFAULT '' COMMENT 'SSL证书路径',
  `ssl_key_path` varchar(255) DEFAULT '' COMMENT 'SSL密钥路径',
  `redirect_domain` varchar(100) DEFAULT '' COMMENT '重定向到的域名(关闭时)',
  `priority` int(11) DEFAULT '0' COMMENT '优先级',
  `notes` varchar(500) DEFAULT '' COMMENT '备注',
  `created_time` timestamp DEFAULT CURRENT_TIMESTAMP,
  `updated_time` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_domain` (`domain`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='域名管理表';

-- 插入现有域名
INSERT INTO `system_domains` (`domain`, `status`, `priority`) VALUES
('smsuser.net', 1, 1),
('uspackage.net', 1, 2),
('uspackage.org', 1, 3),
('usprhome.com', 1, 4);
```

### 2. Admin后台控制器

```php
<?php
namespace app\admin\controller;

use library\Controller;
use think\Db;

class Domain extends Controller
{
    /**
     * 域名管理列表
     * @auth true
     * @menu true
     */
    public function index()
    {
        $this->title = '域名管理';
        $this->_query('system_domains')->order('priority asc, id desc')->page();
    }

    /**
     * 添加域名
     * @auth true
     */
    public function add()
    {
        $this->applyCsrfToken();
        $this->_form('system_domains', 'form');
    }

    /**
     * 编辑域名
     * @auth true
     */
    public function edit()
    {
        $this->applyCsrfToken();
        $this->_form('system_domains', 'form');
    }

    /**
     * 表单数据处理
     */
    protected function _form_filter(&$vo)
    {
        if ($this->request->isGet()) {
            // 设置默认值
            $vo['status'] = isset($vo['status']) ? $vo['status'] : 1;
            $vo['ssl_enabled'] = isset($vo['ssl_enabled']) ? $vo['ssl_enabled'] : 0;
        }
        if ($this->request->isPost()) {
            // 域名格式验证
            if (!filter_var('http://' . $vo['domain'], FILTER_VALIDATE_URL)) {
                $this->error('请输入有效的域名格式');
            }
            
            // 检查域名是否已存在
            $exists = Db::name('system_domains')
                ->where('domain', $vo['domain'])
                ->where('id', 'neq', isset($vo['id']) ? $vo['id'] : 0)
                ->count();
            
            if ($exists) {
                $this->error('该域名已存在');
            }
        }
    }

    /**
     * 表单数据保存后的处理
     */
    protected function _form_result($result, $data)
    {
        if ($result) {
            // 重新生成nginx配置
            $this->generateNginxConfig();
            $this->success('保存成功，nginx配置已更新');
        }
    }

    /**
     * 启用/禁用域名
     * @auth true
     */
    public function toggle()
    {
        $this->applyCsrfToken();
        $id = input('post.id/d', 0);
        $status = input('post.status/d', 0);
        
        if (!$id) {
            $this->error('参数错误');
        }
        
        $result = Db::name('system_domains')->where('id', $id)->update([
            'status' => $status,
            'updated_time' => date('Y-m-d H:i:s')
        ]);
        
        if ($result) {
            $this->generateNginxConfig();
            $this->success($status ? '域名已启用' : '域名已禁用');
        } else {
            $this->error('操作失败');
        }
    }

    /**
     * 删除域名
     * @auth true
     */
    public function delete()
    {
        $this->applyCsrfToken();
        $id = input('post.id/d', 0);
        
        if (!$id) {
            $this->error('参数错误');
        }
        
        $domain = Db::name('system_domains')->where('id', $id)->find();
        if (!$domain) {
            $this->error('域名不存在');
        }
        
        $result = Db::name('system_domains')->where('id', $id)->delete();
        if ($result) {
            $this->generateNginxConfig();
            $this->success('删除成功，nginx配置已更新');
        } else {
            $this->error('删除失败');
        }
    }

    /**
     * 生成nginx配置文件
     */
    private function generateNginxConfig()
    {
        try {
            $domains = Db::name('system_domains')
                ->where('status', 1)
                ->order('priority asc')
                ->select();

            if (empty($domains)) {
                $this->error('至少需要保留一个启用的域名');
            }

            $activeDomains = array_column($domains, 'domain');
            $serverNames = implode(' ', $activeDomains);

            // 生成nginx配置
            $nginxConfig = $this->buildNginxConfig($serverNames, $domains);

            // 写入配置文件
            $configPath = '/var/www/html/nginx-php73-production/nginx/conf.d/default.conf';
            if (file_put_contents($configPath, $nginxConfig)) {
                // 重载nginx配置
                $this->reloadNginx();
                
                // 记录操作日志
                $this->logOperation('更新nginx域名配置', "启用域名: " . $serverNames);
            } else {
                throw new \Exception('配置文件写入失败');
            }
        } catch (\Exception $e) {
            $this->error('配置生成失败: ' . $e->getMessage());
        }
    }

    /**
     * 构建nginx配置内容
     */
    private function buildNginxConfig($serverNames, $domains)
    {
        $sslConfigs = '';
        
        // 检查是否有SSL域名
        foreach ($domains as $domain) {
            if ($domain['ssl_enabled'] && $domain['ssl_cert_path'] && $domain['ssl_key_path']) {
                $sslConfigs .= $this->buildSslConfig($domain);
            }
        }

        return <<<EOF
server {
    listen 80;
    server_name {$serverNames};
    root /var/www/html/public;
    index index.php index.html index.htm;

    # Real IP configuration for Cloudflare
    set_real_ip_from 173.245.48.0/20;
    set_real_ip_from 103.21.244.0/22;
    set_real_ip_from 103.22.200.0/22;
    set_real_ip_from 103.31.4.0/22;
    set_real_ip_from 141.101.64.0/18;
    set_real_ip_from 108.162.192.0/18;
    set_real_ip_from 190.93.240.0/20;
    set_real_ip_from 188.114.96.0/20;
    set_real_ip_from 197.234.240.0/22;
    set_real_ip_from 198.41.128.0/17;
    set_real_ip_from 162.158.0.0/15;
    set_real_ip_from 104.16.0.0/13;
    set_real_ip_from 104.24.0.0/14;
    set_real_ip_from 172.64.0.0/13;
    set_real_ip_from 131.0.72.0/22;
    real_ip_header CF-Connecting-IP;

    # Logging with domain-specific access log
    access_log /var/log/nginx/9color-access.log main;
    error_log /var/log/nginx/9color-error.log warn;

    # Security headers
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-XSS-Protection "1; mode=block" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header Referrer-Policy "no-referrer-when-downgrade" always;
    add_header Content-Security-Policy "default-src 'self' http: https: data: blob: 'unsafe-inline' 'unsafe-eval'; script-src 'self' 'unsafe-inline' 'unsafe-eval' https://*.salesmartly.com https://cdn.bootcss.com; connect-src 'self' https://*.salesmartly.com wss://*.salesmartly.com ws://*.salesmartly.com" always;

    # Handle custom routing through router.php for non-PHP requests
    location / {
        try_files \$uri \$uri/ @router;
    }
    
    # Custom router fallback
    location @router {
        rewrite ^.*\$ /router.php last;
    }

    # PHP-FPM configuration
    location ~ \.php\$ {
        try_files \$uri =404;
        fastcgi_split_path_info ^(.+\.php)(/.+)\$;
        fastcgi_pass php-fpm:9000;
        fastcgi_index index.php;
        
        # FastCGI parameters
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
        fastcgi_param PATH_INFO \$fastcgi_path_info;
        
        # Additional FastCGI settings
        fastcgi_intercept_errors on;
        fastcgi_buffer_size 16k;
        fastcgi_buffers 4 16k;
        fastcgi_connect_timeout 300;
        fastcgi_send_timeout 300;
        fastcgi_read_timeout 300;
    }

    # Static files caching
    location ~* \.(jpg|jpeg|png|gif|ico|css|js|pdf|txt)\$ {
        expires 1y;
        add_header Cache-Control "public, immutable";
        access_log off;
    }

    # Deny access to sensitive files
    location ~ /\. {
        deny all;
        access_log off;
        log_not_found off;
    }

    location ~ /\.ht {
        deny all;
        access_log off;
        log_not_found off;
    }

    # Deny access to config files
    location ~* \.(sql|log|conf)\$ {
        deny all;
        access_log off;
        log_not_found off;
    }
}

{$sslConfigs}
EOF;
    }

    /**
     * 构建SSL配置
     */
    private function buildSslConfig($domain)
    {
        return <<<EOF

server {
    listen 443 ssl http2;
    server_name {$domain['domain']};
    root /var/www/html/public;
    index index.php index.html index.htm;

    # SSL Configuration
    ssl_certificate {$domain['ssl_cert_path']};
    ssl_certificate_key {$domain['ssl_key_path']};
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_ciphers ECDHE+AESGCM:ECDHE+AES256:ECDHE+AES128:!aNULL:!MD5:!DSS;
    ssl_prefer_server_ciphers on;

    # 其他配置与HTTP相同...
    # (省略重复配置)
}
EOF;
    }

    /**
     * 重载nginx配置
     */
    private function reloadNginx()
    {
        // 在Docker容器中重载nginx
        $commands = [
            'docker exec 9color_nginx_prod nginx -t',  // 测试配置
            'docker exec 9color_nginx_prod nginx -s reload'  // 重载配置
        ];

        foreach ($commands as $cmd) {
            $output = [];
            $returnCode = 0;
            exec($cmd, $output, $returnCode);
            
            if ($returnCode !== 0) {
                throw new \Exception("Nginx命令执行失败: " . implode("\n", $output));
            }
        }
    }

    /**
     * 记录操作日志
     */
    private function logOperation($action, $content)
    {
        Db::name('system_log')->insert([
            'node' => 'admin/domain',
            'geoip' => $this->request->ip(),
            'action' => $action,
            'content' => $content,
            'username' => session('admin_user.username', 'system'),
            'create_at' => date('Y-m-d H:i:s')
        ]);
    }

    /**
     * 批量操作
     * @auth true
     */
    public function batch()
    {
        $this->applyCsrfToken();
        $action = input('post.action/s', '');
        $ids = input('post.ids/a', []);

        if (empty($ids) || !in_array($action, ['enable', 'disable', 'delete'])) {
            $this->error('参数错误');
        }

        switch ($action) {
            case 'enable':
                $result = Db::name('system_domains')->whereIn('id', $ids)->update(['status' => 1]);
                break;
            case 'disable':
                $result = Db::name('system_domains')->whereIn('id', $ids)->update(['status' => 0]);
                break;
            case 'delete':
                $result = Db::name('system_domains')->whereIn('id', $ids)->delete();
                break;
        }

        if ($result) {
            $this->generateNginxConfig();
            $this->success('批量操作成功');
        } else {
            $this->error('批量操作失败');
        }
    }
}
```

### 3. 前端管理界面

```html
<!-- application/admin/view/domain/index.html -->
{extend name="admin@layout/content"}

{block name="button"}
<button data-modal='{:url("add")}' data-title="添加域名" class='layui-btn layui-btn-sm layui-btn-primary'>
    <i class='fa fa-plus'></i> 添加域名
</button>
{/block}

{block name="content"}
<form class="layui-form layui-card" method="post" autocomplete="off">
    <div class="layui-card-header layui-form-item form-search">
        <span class="layui-btn layui-btn-sm" onclick="$('[data-check-target]').trigger('click')">刷新列表</span>
    </div>
    
    <div class="layui-card-body">
        <table class="layui-table" lay-skin="line">
            <thead>
                <tr>
                    <th class='list-table-check-box'><input data-check-target='.list-check-box' type='checkbox'></th>
                    <th class='text-left nowrap'>域名</th>
                    <th class='text-left nowrap'>状态</th>
                    <th class='text-left nowrap'>SSL</th>
                    <th class='text-left nowrap'>优先级</th>
                    <th class='text-left nowrap'>创建时间</th>
                    <th class='text-left nowrap'>备注</th>
                    <th class='text-left nowrap'>操作</th>
                </tr>
            </thead>
            <tbody>
            {foreach $list as $key=>$vo}
                <tr>
                    <td class='list-table-check-box'><input class="list-check-box" value='{$vo.id}' type='checkbox'></td>
                    <td class='text-left nowrap'>{$vo.domain}</td>
                    <td class='text-left nowrap'>
                        <span class="layui-badge {if $vo.status}layui-bg-green{else}layui-bg-gray{/if}">
                            {if $vo.status}启用{else}禁用{/if}
                        </span>
                    </td>
                    <td class='text-left nowrap'>
                        {if $vo.ssl_enabled}
                            <span class="layui-badge layui-bg-blue">已启用</span>
                        {else}
                            <span class="layui-badge layui-bg-gray">未启用</span>
                        {/if}
                    </td>
                    <td class='text-left nowrap'>{$vo.priority}</td>
                    <td class='text-left nowrap'>{$vo.created_time}</td>
                    <td class='text-left nowrap'>{$vo.notes|default='无'}</td>
                    <td class='text-left nowrap'>
                        <a class="layui-btn layui-btn-sm" data-modal='{:url("edit")}?id={$vo.id}' data-title="编辑域名">编辑</a>
                        
                        {if $vo.status}
                            <a class="layui-btn layui-btn-sm layui-btn-warm" data-action='{:url("toggle")}' 
                               data-value="id={$vo.id}&status=0" data-loading="禁用中...">禁用</a>
                        {else}
                            <a class="layui-btn layui-btn-sm layui-btn-normal" data-action='{:url("toggle")}' 
                               data-value="id={$vo.id}&status=1" data-loading="启用中...">启用</a>
                        {/if}
                        
                        <a class="layui-btn layui-btn-sm layui-btn-danger" data-action='{:url("delete")}' 
                           data-value="id={$vo.id}" data-loading="删除中..." data-confirm="确定要删除这个域名吗？">删除</a>
                    </td>
                </tr>
            {/foreach}
            </tbody>
        </table>
    </div>
</form>

<!-- 批量操作 -->
<script>
$(function(){
    // 批量操作
    $('.batch-action').click(function(){
        var checked = $('.list-check-box:checked');
        if(checked.length === 0){
            layer.msg('请选择要操作的域名');
            return false;
        }
        
        var ids = [];
        checked.each(function(){
            ids.push($(this).val());
        });
        
        var action = $(this).data('action');
        var actionText = $(this).text();
        
        layer.confirm('确定要' + actionText + '选中的域名吗？', function(index){
            $.post('{:url("batch")}', {
                action: action,
                ids: ids
            }, function(res){
                if(res.code === 1){
                    layer.msg(res.msg, {icon: 1});
                    setTimeout(function(){
                        window.location.reload();
                    }, 1000);
                } else {
                    layer.msg(res.msg, {icon: 2});
                }
            });
            layer.close(index);
        });
    });
});
</script>
{/block}
```

### 4. 部署和安全考虑

#### 文件权限设置
```bash
# 设置nginx配置文件权限
chmod 644 /var/www/html/nginx-php73-production/nginx/conf.d/default.conf
chown www-data:www-data /var/www/html/nginx-php73-production/nginx/conf.d/default.conf
```

#### 备份机制
```php
// 在生成新配置前备份原配置
private function backupNginxConfig()
{
    $configPath = '/var/www/html/nginx-php73-production/nginx/conf.d/default.conf';
    $backupPath = '/var/www/html/nginx-php73-production/nginx/conf.d/default.conf.backup.' . date('YmdHis');
    
    if (file_exists($configPath)) {
        copy($configPath, $backupPath);
    }
}
```

## 方案二：Nginx Map指令（较复杂）

**实现思路：**
使用nginx的map指令根据域名动态返回不同状态

**配置示例：**
```nginx
map $host $domain_status {
    ~^smsuser\.net$ "active";
    ~^uspackage\.net$ "active"; 
    ~^uspackage\.org$ "maintenance";
    ~^usprhome\.com$ "blocked";
    default "inactive";
}

server {
    listen 80;
    server_name smsuser.net uspackage.net uspackage.org usprhome.com;
    
    # 根据状态处理请求
    if ($domain_status = "blocked") {
        return 403 "Domain access denied";
    }
    
    if ($domain_status = "maintenance") {
        return 503 "Domain under maintenance";
    }
    
    if ($domain_status = "inactive") {
        return 404 "Domain not found";
    }
    
    # 正常处理
    root /var/www/html/public;
    # ... 其他配置
}
```

## 方案三：反向代理控制（最灵活）

**实现思路：**
在nginx前加一层控制代理，动态决定请求转发

**架构：**
```
用户请求 -> 控制代理 -> 检查域名状态 -> 转发到后端nginx
```

## 推荐实施方案

**建议采用方案一**，原因：
1. ✅ 实现简单，维护成本低
2. ✅ 完全兼容现有架构
3. ✅ 支持实时生效
4. ✅ 可扩展性强（支持SSL、重定向等）
5. ✅ 有完整的管理界面

**实施步骤：**
1. 创建数据库表
2. 添加admin控制器和视图
3. 创建nginx配置生成逻辑
4. 测试域名开关功能
5. 添加SSL和重定向支持

**风险控制：**
- 配置文件自动备份
- nginx配置语法检查
- 至少保留一个启用域名
- 操作日志记录

你觉得这个方案如何？需要我详细实施哪个部分？ 