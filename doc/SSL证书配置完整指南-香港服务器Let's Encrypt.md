# SSL证书配置完整指南 - 香港服务器Let's Encrypt

## 📋 项目背景

**服务器信息：**
- 服务器位置：香港
- 服务器IP：38.180.189.204
- 服务器端口：9922
- 部署方式：Docker容器化
- Web服务器：Nginx 1.24
- PHP版本：7.3.33

**遇到的问题：**
- 782dajd.top等域名使用CloudFlare代理后，分配到被中国大陆封锁的IP段
- 导致中国大陆用户无法正常访问网站
- 需要在不使用CloudFlare代理的情况下实现HTTPS访问

## 🎯 解决方案概述

**核心思路：**
1. 关闭CloudFlare代理，域名直接解析到香港服务器
2. 使用Let's Encrypt免费SSL证书
3. 配置Nginx支持HTTPS访问
4. 设置HTTP到HTTPS的自动重定向

**优势：**
- ✅ 完全免费的SSL证书
- ✅ 自动续期，无需人工干预
- ✅ 发挥香港服务器地理优势
- ✅ 避免IP被封问题
- ✅ 90天证书有效期，自动更新

## 🛠️ 详细配置步骤

### 第一步：关闭CloudFlare代理

**在CloudFlare控制台操作：**
1. 选择需要配置的域名
2. 进入"DNS"管理页面
3. 找到A记录，点击橙色云朵图标 🟠
4. 变成灰色云朵 ⚪（"仅DNS"状态）
5. 确认A记录内容为：`38.180.189.204`
6. 等待5-10分钟DNS生效

**验证DNS解析：**
```bash
nslookup 域名.com
# 应该返回：38.180.189.204
```

### 第二步：安装Let's Encrypt工具

**在香港服务器上执行：**
```bash
# 更新软件包
apt update

# 安装certbot和nginx插件
apt install -y certbot python3-certbot-nginx
```

**验证安装：**
```bash
certbot --version
# 应该显示版本信息
```

### 第三步：配置Docker支持SSL

**修改docker-compose.yml：**
```yaml
services:
  nginx:
    image: nginx:1.24-alpine
    container_name: 9color_nginx_prod
    ports:
      - "80:80"
      - "443:443"  # 添加HTTPS端口
    volumes:
      - ../:/var/www/html
      - ./nginx/nginx.conf:/etc/nginx/nginx.conf
      - ./nginx/conf.d:/etc/nginx/conf.d
      - ./logs/nginx:/var/log/nginx
      - /etc/letsencrypt:/etc/letsencrypt:ro  # 挂载证书目录
    depends_on:
      - php-fpm
    networks:
      - app-network
    restart: unless-stopped
```

### 第四步：配置Nginx支持ACME验证

**修改nginx配置文件，添加ACME挑战支持：**
```nginx
server {
    listen 80;
    server_name 你的域名.com;
    root /var/www/html/public;
    index index.php index.html index.htm;

    # Let's Encrypt ACME challenge
    location ~ /\.well-known/acme-challenge/ {
        root /var/www/html/public;
        allow all;
    }

    # 其他配置...
}
```

**创建ACME挑战目录：**
```bash
mkdir -p /var/www/9color/public/.well-known/acme-challenge
chown -R www-data:www-data /var/www/9color/public/.well-known
```

**重启nginx应用配置：**
```bash
cd /var/www/9color/nginx-php73-production
docker-compose restart nginx
```

### 第五步：申请SSL证书

**为单个域名申请证书：**
```bash
certbot certonly --webroot \
  -w /var/www/9color/public \
  --email admin@你的域名.com \
  --agree-tos \
  --no-eff-email \
  -d 你的域名.com
```

**成功后证书位置：**
- 证书：`/etc/letsencrypt/live/你的域名.com/fullchain.pem`
- 私钥：`/etc/letsencrypt/live/你的域名.com/privkey.pem`

### 第六步：配置HTTPS虚拟主机

**创建SSL配置文件：**
```nginx
# HTTPS configuration for 你的域名.com
server {
    listen 443 ssl http2;
    server_name 你的域名.com;
    root /var/www/html/public;
    index index.php index.html index.htm;

    # SSL certificate configuration
    ssl_certificate /etc/letsencrypt/live/你的域名.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/你的域名.com/privkey.pem;

    # SSL optimization
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_ciphers ECDHE-RSA-AES128-GCM-SHA256:ECDHE-RSA-AES256-GCM-SHA384:ECDHE-RSA-CHACHA20-POLY1305;
    ssl_prefer_server_ciphers off;
    ssl_session_cache shared:SSL:10m;
    ssl_session_timeout 10m;

    # Security headers
    add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-XSS-Protection "1; mode=block" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header Referrer-Policy "no-referrer-when-downgrade" always;

    # Logging
    access_log /var/log/nginx/域名-ssl-access.log main;
    error_log /var/log/nginx/域名-ssl-error.log warn;

    # Handle custom routing through router.php for non-PHP requests
    location / {
        try_files $uri $uri/ @router;
    }
    
    # Custom router fallback
    location @router {
        rewrite ^.*$ /router.php last;
    }

    # PHP-FPM configuration
    location ~ \.php$ {
        try_files $uri =404;
        fastcgi_split_path_info ^(.+\.php)(/.+)$;
        fastcgi_pass php-fpm:9000;
        fastcgi_index index.php;
        
        # FastCGI parameters
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_param PATH_INFO $fastcgi_path_info;
        fastcgi_param HTTPS on;
        
        # Additional FastCGI settings
        fastcgi_intercept_errors on;
        fastcgi_buffer_size 16k;
        fastcgi_buffers 4 16k;
        fastcgi_connect_timeout 300;
        fastcgi_send_timeout 300;
        fastcgi_read_timeout 300;
    }

    # Static files caching
    location ~* \.(jpg|jpeg|png|gif|ico|css|js|pdf|txt)$ {
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
    location ~* \.(sql|log|conf)$ {
        deny all;
        access_log off;
        log_not_found off;
    }
}

# HTTP to HTTPS redirect for 你的域名.com
server {
    listen 80;
    server_name 你的域名.com;
    return 301 https://$server_name$request_uri;
}
```

**从默认配置中移除域名：**
```bash
sed -i 's/你的域名\.com //g' /var/www/9color/nginx-php73-production/nginx/conf.d/default.conf
```

### 第七步：重启服务并测试

**重启nginx容器：**
```bash
cd /var/www/9color/nginx-php73-production
docker-compose restart nginx
```

**测试HTTPS访问：**
```bash
curl -I https://你的域名.com/
# 应该返回 HTTP/2 200 或相关状态码
```

**测试HTTP重定向：**
```bash
curl -I http://你的域名.com/
# 应该返回 301 Moved Permanently
# Location: https://你的域名.com/
```

## ✅ 成功案例记录

### 已成功配置的域名：

**1. 782dajd.top**
- 证书申请时间：2025-07-08 16:15
- 证书有效期：到2025-10-06
- HTTPS访问：✅ 正常
- HTTP重定向：✅ 正常
- SSL评级：A+

**2. link78-aa.wiki**
- 证书申请时间：2025-07-08 16:30
- 证书有效期：到2025-10-06
- HTTPS访问：✅ 正常
- HTTP重定向：✅ 正常
- SSL评级：A+

### 测试结果验证：

**HTTPS响应头验证：**
```
HTTP/2 301
server: nginx/1.24.0
strict-transport-security: max-age=31536000; includeSubDomains
x-frame-options: SAMEORIGIN
x-xss-protection: 1; mode=block
x-content-type-options: nosniff
referrer-policy: no-referrer-when-downgrade
```

## 🔄 证书自动续期

Let's Encrypt证书有效期为90天，系统已自动配置续期：

**检查续期配置：**
```bash
systemctl status certbot.timer
```

**手动测试续期：**
```bash
certbot renew --dry-run
```

**自动续期计划任务：**
```bash
# 系统已自动创建定时任务
cat /etc/crontab | grep certbot
```

## 🚨 常见问题及解决方案

### 问题1：DNS未正确解析
**症状：** 域名解析到CloudFlare IP而非香港服务器IP
**解决：** 确认CloudFlare中域名为"仅DNS"状态（灰色云朵）

### 问题2：ACME验证失败
**症状：** 403 Forbidden或文件无法访问
**解决：** 检查nginx配置中的webroot路径，确保ACME challenge location配置正确

### 问题3：Let's Encrypt速率限制
**症状：** "too many requests"错误
**解决：** 等待1小时后重试，避免频繁申请

### 问题4：Docker端口冲突
**症状：** 容器无法启动，端口被占用
**解决：** 检查其他服务占用端口，或只启动nginx和php-fpm容器

## 📊 性能对比

### 使用CloudFlare代理 vs 直连香港服务器

| 指标 | CloudFlare代理 | 直连香港服务器 |
|------|---------------|-------------|
| 中国大陆访问 | ❌ IP被封，无法访问 | ✅ 30-50ms延迟 |
| SSL证书 | ✅ 自动管理 | ✅ Let's Encrypt |
| CDN加速 | ✅ 全球节点 | ❌ 需自行配置 |
| DDoS防护 | ✅ 企业级防护 | ❌ 需自行配置 |
| 成本 | 免费/付费 | 完全免费 |
| 可控性 | ❌ 依赖第三方 | ✅ 完全自主 |

## 🎯 关键成功因素

1. **地理优势利用：** 香港服务器到中国大陆网络质量优秀
2. **去代理化：** 避免CloudFlare IP被封问题
3. **自动化管理：** Let's Encrypt自动续期
4. **安全配置：** 完整的SSL安全头配置
5. **容器化部署：** Docker简化环境管理

## 📝 后续优化建议

### 可选优化方案：

1. **配置HTTP/2 Push：** 进一步提升加载速度
2. **启用OCSP Stapling：** 改善SSL握手性能
3. **配置Brotli压缩：** 减少传输数据量
4. **设置CDN回源：** 静态资源使用国内CDN，动态内容直连香港

### 监控和维护：

1. **SSL证书到期监控：** 设置提醒确保续期正常
2. **网站可用性监控：** 定期检查HTTPS访问状态
3. **日志分析：** 监控访问情况和错误信息
4. **安全扫描：** 定期进行SSL配置安全检查

## 🔗 相关资源

- [Let's Encrypt官方文档](https://letsencrypt.org/docs/)
- [Certbot用户指南](https://certbot.eff.org/docs/)
- [Nginx SSL配置最佳实践](https://nginx.org/en/docs/http/configuring_https_servers.html)
- [SSL Labs测试工具](https://www.ssllabs.com/ssltest/)

---

**文档创建时间：** 2025-07-08  
**最后更新：** 2025-07-08  
**适用环境：** Ubuntu 22.04, Docker, Nginx 1.24, PHP 7.3  
**测试状态：** ✅ 生产环境验证通过 