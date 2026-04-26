#!/bin/bash

# 批量SSL证书配置脚本 - 香港服务器Let's Encrypt
# 适用于：Ubuntu 22.04, Docker, Nginx 1.24
# 创建时间：2025-07-08
# 使用方法：./ssl_batch_setup.sh 域名1.com 域名2.com ...

set -e # 遇到错误立即退出

# 配置变量
WEBROOT_PATH="/var/www/9color/public"
DOCKER_COMPOSE_DIR="/var/www/9color/nginx-php73-production"
NGINX_CONF_DIR="/var/www/9color/nginx-php73-production/nginx/conf.d"
DEFAULT_EMAIL="admin@example.com"

# 颜色输出函数
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

log_info() {
    echo -e "${BLUE}[INFO]${NC} $1"
}

log_success() {
    echo -e "${GREEN}[SUCCESS]${NC} $1"
}

log_warning() {
    echo -e "${YELLOW}[WARNING]${NC} $1"
}

log_error() {
    echo -e "${RED}[ERROR]${NC} $1"
}

# 检查必要的依赖
check_dependencies() {
    log_info "检查系统依赖..."

    if ! command -v certbot &>/dev/null; then
        log_error "certbot 未安装，请先安装：apt install -y certbot python3-certbot-nginx"
        exit 1
    fi

    if ! command -v docker-compose &>/dev/null; then
        log_error "docker-compose 未安装"
        exit 1
    fi

    if [ ! -d "$WEBROOT_PATH" ]; then
        log_error "网站根目录不存在：$WEBROOT_PATH"
        exit 1
    fi

    log_success "依赖检查通过"
}

# 验证域名DNS解析
verify_dns() {
    local domain=$1
    log_info "验证域名 $domain 的DNS解析..."

    # 获取当前服务器的公网IP
    SERVER_IP=$(curl -s ifconfig.me || curl -s ipinfo.io/ip || echo "38.180.189.204")

    # 检查域名解析
    RESOLVED_IP=$(nslookup $domain 8.8.8.8 | grep "Address:" | tail -1 | awk '{print $2}')

    if [ "$RESOLVED_IP" != "$SERVER_IP" ]; then
        log_warning "域名 $domain 解析到 $RESOLVED_IP，但服务器IP为 $SERVER_IP"
        log_warning "请确认已关闭CloudFlare代理（设为灰色云朵）"
        read -p "继续处理此域名吗？(y/N): " -n 1 -r
        echo
        if [[ ! $REPLY =~ ^[Yy]$ ]]; then
            return 1
        fi
    else
        log_success "域名 $domain 解析正确"
    fi
    return 0
}

# 申请SSL证书
request_certificate() {
    local domain=$1
    local email=${2:-$DEFAULT_EMAIL}

    log_info "为域名 $domain 申请SSL证书..."

    # 检查证书是否已存在
    if [ -d "/etc/letsencrypt/live/$domain" ]; then
        log_warning "域名 $domain 的证书已存在"
        read -p "是否重新申请？(y/N): " -n 1 -r
        echo
        if [[ ! $REPLY =~ ^[Yy]$ ]]; then
            return 0
        fi
    fi

    # 确保ACME挑战目录存在
    mkdir -p "$WEBROOT_PATH/.well-known/acme-challenge"
    chown -R www-data:www-data "$WEBROOT_PATH/.well-known" 2>/dev/null || true

    # 申请证书
    if certbot certonly --webroot \
        -w "$WEBROOT_PATH" \
        --email "$email" \
        --agree-tos \
        --no-eff-email \
        -d "$domain"; then
        log_success "域名 $domain 证书申请成功"
        return 0
    else
        log_error "域名 $domain 证书申请失败"
        return 1
    fi
}

# 创建SSL配置文件
create_ssl_config() {
    local domain=$1
    local config_file="$NGINX_CONF_DIR/ssl-${domain//./-}.conf"

    log_info "为域名 $domain 创建SSL配置文件..."

    cat >"$config_file" <<EOF
# HTTPS configuration for $domain
server {
    listen 443 ssl http2;
    server_name $domain;
    root /var/www/html/public;
    index index.php index.html index.htm;

    # SSL certificate configuration
    ssl_certificate /etc/letsencrypt/live/$domain/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/$domain/privkey.pem;

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
    access_log /var/log/nginx/${domain//./-}-ssl-access.log main;
    error_log /var/log/nginx/${domain//./-}-ssl-error.log warn;

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

# HTTP to HTTPS redirect for $domain
server {
    listen 80;
    server_name $domain;
    return 301 https://\$server_name\$request_uri;
}
EOF

    log_success "SSL配置文件创建完成：$config_file"
}

# 从默认配置中移除域名
remove_from_default_config() {
    local domain=$1
    local default_config="$NGINX_CONF_DIR/default.conf"

    log_info "从默认配置中移除域名 $domain..."

    if [ -f "$default_config" ]; then
        # 创建备份
        cp "$default_config" "${default_config}.bak.$(date +%Y%m%d_%H%M%S)"

        # 移除域名
        sed -i "s/${domain//./\.} //g" "$default_config"
        sed -i "s/ ${domain//./\.}//g" "$default_config"

        log_success "已从默认配置中移除域名 $domain"
    fi
}

# 重启nginx容器
restart_nginx() {
    log_info "重启nginx容器..."

    cd "$DOCKER_COMPOSE_DIR"
    if docker-compose restart nginx; then
        log_success "nginx容器重启成功"
        return 0
    else
        log_error "nginx容器重启失败"
        return 1
    fi
}

# 测试SSL配置
test_ssl() {
    local domain=$1

    log_info "测试域名 $domain 的SSL配置..."

    # 等待nginx重启完成
    sleep 3

    # 测试HTTPS访问
    if curl -I -k "https://$domain/" >/dev/null 2>&1; then
        log_success "HTTPS访问测试通过"
    else
        log_warning "HTTPS访问测试失败"
    fi

    # 测试HTTP重定向
    REDIRECT_LOCATION=$(curl -I "http://$domain/" 2>/dev/null | grep -i location | awk '{print $2}' | tr -d '\r')
    if [[ "$REDIRECT_LOCATION" == https://$domain/* ]]; then
        log_success "HTTP重定向测试通过"
    else
        log_warning "HTTP重定向测试失败，重定向到：$REDIRECT_LOCATION"
    fi
}

# 处理单个域名
process_domain() {
    local domain=$1
    local email=$2

    echo "========================================="
    log_info "开始处理域名：$domain"
    echo "========================================="

    # 验证DNS解析
    if ! verify_dns "$domain"; then
        log_error "跳过域名 $domain"
        return 1
    fi

    # 申请SSL证书
    if ! request_certificate "$domain" "$email"; then
        log_error "域名 $domain 证书申请失败，跳过后续步骤"
        return 1
    fi

    # 创建SSL配置
    create_ssl_config "$domain"

    # 从默认配置中移除域名
    remove_from_default_config "$domain"

    log_success "域名 $domain 配置完成"
    return 0
}

# 主函数
main() {
    echo "========================================"
    echo "批量SSL证书配置脚本"
    echo "香港服务器 Let's Encrypt 自动化配置"
    echo "========================================"

    # 检查参数
    if [ $# -eq 0 ]; then
        log_error "使用方法：$0 域名1.com 域名2.com ..."
        exit 1
    fi

    # 检查依赖
    check_dependencies

    # 询问邮箱地址
    read -p "请输入证书申请邮箱地址 (默认: $DEFAULT_EMAIL): " INPUT_EMAIL
    EMAIL=${INPUT_EMAIL:-$DEFAULT_EMAIL}

    # 处理每个域名
    SUCCESSFUL_DOMAINS=()
    FAILED_DOMAINS=()

    for domain in "$@"; do
        if process_domain "$domain" "$EMAIL"; then
            SUCCESSFUL_DOMAINS+=("$domain")
        else
            FAILED_DOMAINS+=("$domain")
        fi
    done

    # 如果有成功的域名，重启nginx
    if [ ${#SUCCESSFUL_DOMAINS[@]} -gt 0 ]; then
        restart_nginx

        # 测试所有成功配置的域名
        for domain in "${SUCCESSFUL_DOMAINS[@]}"; do
            test_ssl "$domain"
        done
    fi

    # 输出结果汇总
    echo "========================================="
    echo "配置结果汇总"
    echo "========================================="

    if [ ${#SUCCESSFUL_DOMAINS[@]} -gt 0 ]; then
        log_success "成功配置的域名 (${#SUCCESSFUL_DOMAINS[@]}个)："
        for domain in "${SUCCESSFUL_DOMAINS[@]}"; do
            echo "  ✅ $domain"
        done
    fi

    if [ ${#FAILED_DOMAINS[@]} -gt 0 ]; then
        log_error "配置失败的域名 (${#FAILED_DOMAINS[@]}个)："
        for domain in "${FAILED_DOMAINS[@]}"; do
            echo "  ❌ $domain"
        done
    fi

    echo "========================================="
    log_info "批量配置完成！"

    if [ ${#SUCCESSFUL_DOMAINS[@]} -gt 0 ]; then
        echo ""
        log_info "后续操作建议："
        echo "1. 使用 SSL Labs 测试SSL配置：https://www.ssllabs.com/ssltest/"
        echo "2. 设置证书到期监控"
        echo "3. 定期检查自动续期状态：certbot renew --dry-run"
    fi
}

# 执行主函数
main "$@"
